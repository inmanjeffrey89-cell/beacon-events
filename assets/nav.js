/**
 * Beacon Events: calendar paging without a full page reload. The previous / next arrows, "This week", "Back to month" and a
 * month grid's day numbers fetch the same page and swap in its calendars, so the page doesn't jump back to the top. The
 * links stay ordinary links: without JavaScript, or if anything unexpected comes back, the browser simply loads the page.
 */
( function () {
	var ROOT = '.beacon-month, .beacon-week, .beacon-dayview';
	var LINK = '.beacon-month-nav a, .beacon-back a, a.beacon-daynum';
	var live;

	function roots( doc ) {
		return Array.prototype.slice.call( doc.querySelectorAll( ROOT ) );
	}

	// Our flag on top of what is already in the history entry: WordPress's Interactivity API (loaded by the Navigation block
	// on most block themes) reloads the page on Back when its own id is missing from the entry.
	function mark() {
		var st = history.state && typeof history.state === 'object' ? history.state : {};
		return Object.assign( {}, st, { beaconNav: 1 } );
	}

	function say( text ) {
		if ( ! live ) {
			live = document.createElement( 'div' );
			live.className = 'beacon-sr';
			live.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( live );
		}
		live.textContent = text;
	}

	// Week, month and day links move every calendar on the page (the date is in the page address), so all of them swap.
	function swap( url, push, at, keep ) {
		var old = roots( document );
		old.forEach( function ( r ) {
			r.classList.add( 'beacon-loading' );
			r.setAttribute( 'aria-busy', 'true' );
		} );
		return fetch( url, { credentials: 'same-origin' } )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				return res.text();
			} )
			.then( function ( html ) {
				var doc = new DOMParser().parseFromString( html, 'text/html' );
				var fresh = roots( doc );
				if ( fresh.length !== old.length || ! fresh.length ) {
					throw new Error( 'calendars changed' );
				}
				var made = old.map( function ( r, i ) {
					var n = document.importNode( fresh[ i ], true );
					r.replaceWith( n );
					return n;
				} );
				if ( push ) {
					history.pushState( mark(), '', url );
				}
				var root = made[ at ] || made[ 0 ];
				var title = root.querySelector( '.beacon-month-title' );
				if ( title ) {
					say( title.textContent );
				}
				// keyboard users keep their place: the same arrow in the new calendar, else its heading
				var target = ( keep && root.querySelector( keep ) ) || title;
				if ( target ) {
					if ( target === title ) {
						title.setAttribute( 'tabindex', '-1' );
					}
					target.focus( { preventScroll: true } );
				}
				document.dispatchEvent( new CustomEvent( 'beacon:navigated', { detail: { url: url } } ) );
			} )
			.catch( function () {
				location.href = url;
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( LINK ) : null;
		if ( ! a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.target ) {
			return;
		}
		var root = a.closest( ROOT );
		if ( ! root || ! window.fetch || ! window.DOMParser || ! history.pushState ) {
			return;
		}
		var url = new URL( a.href, location.href );
		if ( url.origin !== location.origin ) {
			return;
		}
		e.preventDefault();
		if ( ! history.state || ! history.state.beaconNav ) {
			history.replaceState( mark(), '' ); // so Back to the first page swaps too
		}
		var keep = a.classList.contains( 'beacon-prev' ) ? '.beacon-prev' : a.classList.contains( 'beacon-next' ) ? '.beacon-next' : '';
		swap( url.href, true, roots( document ).indexOf( root ), keep );
	} );

	window.addEventListener( 'popstate', function ( e ) {
		if ( e.state && e.state.beaconNav ) {
			swap( location.href, false, 0, '' );
		}
	} );
} )();
