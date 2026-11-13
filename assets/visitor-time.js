/**
 * Beacon Events: times in the visitor's own time zone (Settings → Visitors in other time zones). The server writes each
 * time in its event's zone and marks it (.beacon-vt, data-bt-s/e = UTC start/end, data-bt-off = the written offset in
 * minutes, data-bt-day = the written date). In a browser whose clock is at another offset at that moment, the time is
 * rewritten in the visitor's zone with its short name, the written time stays as the tooltip, a view says so once, and
 * a time that falls on another day for the visitor names that day ("Wed 3:30 AM"). An event page's "Your time" row is
 * filled in and shown. Re-runs after in-place paging (beacon:navigated). Plain links and the server's times still work
 * without it.
 */
( function () {
	var C = window.beaconVT || {};
	var lang = document.documentElement.lang || undefined;
	var fmt;
	function formats() {
		if ( fmt !== undefined ) {
			return fmt;
		}
		try {
			fmt = {
				time: new Intl.DateTimeFormat( lang, { hour: 'numeric', minute: '2-digit', hour12: C.h12 !== false } ),
				wday: new Intl.DateTimeFormat( lang, { weekday: 'short' } ),
				date: new Intl.DateTimeFormat( lang, { weekday: 'short', month: 'short', day: 'numeric' } ),
				zone: new Intl.DateTimeFormat( lang, { hour: 'numeric', timeZoneName: 'short' } ),
				ymd: new Intl.DateTimeFormat( 'en-US', { year: 'numeric', month: '2-digit', day: '2-digit' } ),
			};
		} catch ( e ) {
			fmt = null;
		}
		return fmt;
	}
	function part( f, d, type ) {
		var p = f.formatToParts( d );
		for ( var i = 0; i < p.length; i++ ) {
			if ( p[ i ].type === type ) {
				return p[ i ].value;
			}
		}
		return '';
	}
	function ymd( f, d ) {
		return part( f.ymd, d, 'year' ) + '-' + part( f.ymd, d, 'month' ) + '-' + part( f.ymd, d, 'day' );
	}
	function run() {
		var f = formats();
		if ( ! f ) {
			return;
		}
		var roots = [];
		Array.prototype.forEach.call( document.querySelectorAll( '.beacon-vt[data-bt-s]:not([data-bt-done])' ), function ( el ) {
			el.setAttribute( 'data-bt-done', '1' );
			var s = new Date( el.getAttribute( 'data-bt-s' ) );
			if ( isNaN( s ) || -s.getTimezoneOffset() === parseInt( el.getAttribute( 'data-bt-off' ), 10 ) ) {
				return; // the visitor's clock reads the same as the written time
			}
			var e = el.getAttribute( 'data-bt-e' ) ? new Date( el.getAttribute( 'data-bt-e' ) ) : null;
			var zone = part( f.zone, s, 'timeZoneName' );
			var shift = ymd( f, s ) !== el.getAttribute( 'data-bt-day' );
			var times = f.time.format( s ) + ( e && ! isNaN( e ) ? ' – ' + f.time.format( e ) : '' );
			var row = el.closest( '.beacon-your-time' );
			if ( row ) { // an event page: its own "Your time" row, with the date
				el.textContent = f.date.format( s ) + ', ' + times + ( zone ? ' ' + zone : '' );
				row.hidden = false;
				return;
			}
			if ( el.textContent && ! el.hasAttribute( 'title' ) ) {
				el.setAttribute( 'title', el.textContent );
			}
			el.textContent = ( shift ? f.wday.format( s ) + ' ' : '' ) + ( el.hasAttribute( 'data-bt-short' ) ? f.time.format( s ) : times + ( zone ? ' ' + zone : '' ) );
			var root = el.closest( '.beacon-list, .beacon-compact, .beacon-week, .beacon-month, .beacon-dayview' );
			if ( root && roots.indexOf( root ) < 0 ) {
				roots.push( root );
				root.setAttribute( 'data-bt-zone', zone );
			}
		} );
		roots.forEach( function ( root ) {
			if ( C.note && ! root.querySelector( '.beacon-tz-note' ) ) {
				var p = document.createElement( 'p' );
				p.className = 'beacon-tz-note';
				p.textContent = C.note.replace( '%s', root.getAttribute( 'data-bt-zone' ) || '' );
				root.insertBefore( p, root.firstChild );
			}
		} );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
	document.addEventListener( 'beacon:navigated', run );
} )();
