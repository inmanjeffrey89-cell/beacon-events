/* The "When" panel in the block editor sidebar — where a user actually looks. Uses the registered post meta over REST.
   The classic meta box remains as the fallback for the classic editor. No build step: vanilla wp.* globals. */
( function ( wp ) {
	var el = wp.element.createElement, F = wp.element.Fragment, __ = wp.i18n.__;
	var useSelect = wp.data.useSelect, useDispatch = wp.data.useDispatch;
	var useState = wp.element.useState, useEffect = wp.element.useEffect, apiFetch = wp.apiFetch;
	var Panel = wp.editPost.PluginDocumentSettingPanel, TextControl = wp.components.TextControl, SelectControl = wp.components.SelectControl, ToggleControl = wp.components.ToggleControl, CheckboxControl = wp.components.CheckboxControl, Flex = wp.components.Flex, FlexItem = wp.components.FlexItem;
	var DAYS = [ [ 'SU', 'Sun' ], [ 'MO', 'Mon' ], [ 'TU', 'Tue' ], [ 'WE', 'Wed' ], [ 'TH', 'Thu' ], [ 'FR', 'Fri' ], [ 'SA', 'Sat' ] ];
	var NAMES = { SU: 'Sunday', MO: 'Monday', TU: 'Tuesday', WE: 'Wednesday', TH: 'Thursday', FR: 'Friday', SA: 'Saturday' };

	function parse( rrule ) {
		var r = { FREQ: '', INTERVAL: 1, BYDAY: [], COUNT: '', UNTIL: '' };
		( rrule || '' ).toUpperCase().split( ';' ).forEach( function ( p ) {
			var kv = p.split( '=' ); if ( kv.length < 2 ) return;
			if ( kv[ 0 ] === 'FREQ' ) r.FREQ = kv[ 1 ];
			if ( kv[ 0 ] === 'INTERVAL' ) r.INTERVAL = parseInt( kv[ 1 ], 10 ) || 1;
			if ( kv[ 0 ] === 'BYDAY' ) r.BYDAY = kv[ 1 ].split( ',' ).filter( Boolean );
			if ( kv[ 0 ] === 'COUNT' ) r.COUNT = kv[ 1 ];
			if ( kv[ 0 ] === 'UNTIL' ) r.UNTIL = kv[ 1 ].slice( 0, 4 ) + '-' + kv[ 1 ].slice( 4, 6 ) + '-' + kv[ 1 ].slice( 6, 8 );
		} );
		return r;
	}
	function build( r ) {
		if ( ! r.FREQ ) return '';
		var s = 'FREQ=' + r.FREQ + ';INTERVAL=' + ( r.INTERVAL || 1 );
		if ( r.FREQ === 'WEEKLY' && r.BYDAY.length ) s += ';BYDAY=' + r.BYDAY.filter( function ( d ) { return NAMES[ d ]; } ).join( ',' );
		if ( r.FREQ === 'MONTHLY' && r.BYPOS ) s += ';BYDAY=' + r.BYPOS;
		if ( r.COUNT ) s += ';COUNT=' + parseInt( r.COUNT, 10 ); else if ( r.UNTIL ) s += ';UNTIL=' + r.UNTIL.replace( /-/g, '' );
		return s;
	}
	// "2TU,4TU" → "the second and fourth Tuesday", "1MO,3WE" → "the first Monday and the third Wednesday"
	function posText( bypos ) {
		var ordw = { '1': 'first', '2': 'second', '3': 'third', '4': 'fourth', '5': 'fifth', '-1': 'last' }, by = {}, order = [];
		var and = function ( l ) { return l.length > 1 ? l.slice( 0, -1 ).join( ', ' ) + ' and ' + l[ l.length - 1 ] : ( l[ 0 ] || '' ); };
		String( bypos ).split( ',' ).forEach( function ( p ) {
			var m = /^(-?\d)([A-Z]{2})$/.exec( p ); if ( ! m ) return;
			var day = NAMES[ m[ 2 ] ] || m[ 2 ];
			if ( ! by[ day ] ) { by[ day ] = []; order.push( day ); }
			by[ day ].push( ordw[ m[ 1 ] ] || m[ 1 ] );
		} );
		return and( order.map( function ( day ) { return 'the ' + and( by[ day ] ) + ' ' + day; } ) );
	}
	function describe( r ) {
		if ( ! r.FREQ ) return __( 'Does not repeat', 'beacon-events' );
		var unit = { DAILY: 'day', WEEKLY: 'week', MONTHLY: 'month', YEARLY: 'year' }[ r.FREQ ];
		var s = r.INTERVAL === 1 ? 'Every ' + unit : 'Every ' + r.INTERVAL + ' ' + unit + 's';
		var days = r.FREQ === 'WEEKLY' ? r.BYDAY.map( function ( d ) { return NAMES[ d ]; } ).filter( Boolean ) : [];
		if ( r.FREQ === 'MONTHLY' && r.BYPOS ) { s += ' on ' + posText( r.BYPOS ); days = []; }
		if ( days.length ) s += ' on ' + ( days.length > 1 ? days.slice( 0, -1 ).join( ', ' ) + ' and ' + days[ days.length - 1 ] : days[ 0 ] );
		if ( r.COUNT ) s += ', ' + r.COUNT + ' times';
		if ( r.UNTIL && ! r.COUNT ) s += ', until ' + r.UNTIL;
		return s;
	}

	var CFG = window.beaconPanel || {};
	var sprintf = wp.i18n.sprintf, ComboboxControl = wp.components.ComboboxControl;
	var MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12 };
	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
	function ymdOf( d ) { return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ); }
	var ORD = { '1': __( 'first', 'beacon-events' ), '2': __( 'second', 'beacon-events' ), '3': __( 'third', 'beacon-events' ), '4': __( 'fourth', 'beacon-events' ), '5': __( 'fifth', 'beacon-events' ), '-1': __( 'last', 'beacon-events' ) };

	// "Skip these dates" as people type them: 2026-12-25, 12/25/2026 (25/12/2026 on day-first sites), 12/25/26,
	// "Dec 25, 2026", "Dec 25" (the year of the event). Returns the dates it understood and the bits it didn't.
	function parseDates( text, startYmd ) {
		var ok = [], bad = [], sy = parseInt( ( startYmd || '' ).slice( 0, 4 ), 10 ) || new Date().getFullYear();
		( text || '' ).replace( /([a-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?),\s*(\d{4})/ig, '$1 $2' ).split( /[,;\n]+/ ).map( function ( t ) { return t.trim(); } ).filter( Boolean ).forEach( function ( t ) {
			var m, y, mo, d;
			if ( ( m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec( t ) ) ) { y = +m[ 1 ]; mo = +m[ 2 ]; d = +m[ 3 ]; }
			else if ( ( m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/.exec( t ) ) ) { y = +m[ 3 ]; if ( y < 100 ) { y += 2000; } if ( CFG.dmy ) { d = +m[ 1 ]; mo = +m[ 2 ]; } else { mo = +m[ 1 ]; d = +m[ 2 ]; } }
			// month names only by pattern: Date.parse reads any word + year ("someday 2026") as January 1st
			else if ( ( m = /^([a-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s+(\d{4}))?$/i.exec( t ) ) && MONTHS[ m[ 1 ].slice( 0, 3 ).toLowerCase() ] ) { mo = MONTHS[ m[ 1 ].slice( 0, 3 ).toLowerCase() ]; d = +m[ 2 ]; y = m[ 3 ] ? +m[ 3 ] : sy; if ( ! m[ 3 ] && startYmd && y + '-' + pad( mo ) + '-' + pad( d ) < startYmd ) { y += 1; } }
			else if ( ( m = /^(\d{1,2})(?:st|nd|rd|th)?\s+([a-z]{3,9})\.?(?:\s+(\d{4}))?$/i.exec( t ) ) && MONTHS[ m[ 2 ].slice( 0, 3 ).toLowerCase() ] ) { mo = MONTHS[ m[ 2 ].slice( 0, 3 ).toLowerCase() ]; d = +m[ 1 ]; y = m[ 3 ] ? +m[ 3 ] : sy; if ( ! m[ 3 ] && startYmd && y + '-' + pad( mo ) + '-' + pad( d ) < startYmd ) { y += 1; } }
			var check = y ? new Date( y, mo - 1, d ) : null;
			if ( check && check.getMonth() === mo - 1 && check.getDate() === d ) { ok.push( y + '-' + pad( mo ) + '-' + pad( d ) ); } else { bad.push( t ); }
		} );
		ok = ok.filter( function ( v, i ) { return ok.indexOf( v ) === i; } ).sort();
		return { ok: ok, bad: bad };
	}
	// "zoom.us/j/123" → "https://zoom.us/j/123"
	function withScheme( v ) { v = ( v || '' ).trim(); return v && ! /^[a-z][a-z0-9+.\-]*:/i.test( v ) ? 'https://' + v : v; }

	// Chrome and Edge show a US date and time as "10/06/2026, 06:00 --" until AM or PM is picked, and until every part
	// is filled in the field's value is empty. Two ways that lost dates (0.18.0 and earlier): saving a half-filled field
	// saved nothing, and typing over a time ("0" of "05" is briefly not an hour) pushed that empty value back into the
	// field, which wiped the date too. So: keep what is being typed here, pass on only complete values (or a field the
	// person emptied on purpose), and hold Save with a note while a field is half-filled.
	function DateField( props ) {
		var type = props.type || 'datetime-local';
		var done = type === 'date' ? /^\d{4}-\d{2}-\d{2}$/ : /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;
		var ts = useState( props.value || '' ), txt = ts[ 0 ], setTxt = ts[ 1 ];
		var bs = useState( false ), bad = bs[ 0 ], setBad = bs[ 1 ];
		var ed = useDispatch( 'core/editor' ), key = 'beacon-date-' + props.name;
		// a value changed from outside (the end moving with the start, undo, all day) replaces what is shown
		useEffect( function () { var v = props.value || ''; if ( v !== txt && ( v === '' || done.test( v ) ) ) { setTxt( v ); setBad( false ); } }, [ props.value ] );
		useEffect( function () {
			if ( bad ) { ed.lockPostSaving( key ); } else { ed.unlockPostSaving( key ); }
			return function () { ed.unlockPostSaving( key ); };
		}, [ bad ] );
		var check = function ( e ) { setBad( !! ( e.target.validity && e.target.validity.badInput ) ); }; // no input event fires while the value stays empty, so keys count too
		return el( F, {},
			el( TextControl, {
				label: props.label, type: type, value: txt, help: props.help,
				onChange: function ( v ) { setTxt( v ); if ( done.test( v ) ) { props.onChange( v ); } },
				onInput: check, onKeyUp: check,
				onBlur: function ( e ) { check( e ); if ( e.target.value === '' && ! ( e.target.validity && e.target.validity.badInput ) && ( props.value || '' ) !== '' ) { props.onChange( '' ); } }
			} ),
			bad ? el( 'p', { className: 'beacon-date-warning', role: 'alert', style: { color: '#cc1818', margin: '-8px 0 12px', fontSize: '12px' } }, type === 'date' ? __( 'Not saved yet: fill in the whole date.', 'beacon-events' ) : __( 'Not saved yet: fill in every part of the date and time, including AM or PM if it shows.', 'beacon-events' ) ) : null
		);
	}

	// A whole number that can be emptied and retyped: the old box snapped back to 1 when cleared, so typing 2 made 12.
	function NumberField( props ) {
		var ts = useState( String( props.value || '' ) ), txt = ts[ 0 ], setTxt = ts[ 1 ];
		useEffect( function () { var v = String( props.value || '' ); if ( v !== txt && ! ( txt === '' && v === String( props.min ) ) ) { setTxt( v ); } }, [ props.value ] );
		return el( TextControl, {
			label: props.label, type: 'number', min: props.min, max: props.max, value: txt,
			onChange: function ( v ) { setTxt( v ); var n = parseInt( v, 10 ); if ( n >= props.min ) { props.onChange( props.max ? Math.min( n, props.max ) : n ); } },
			onBlur: function () { if ( ! ( parseInt( txt, 10 ) >= props.min ) ) { setTxt( String( props.value || props.min ) ); } }
		} );
	}

	function WhenPanel() {
		var meta = useSelect( function ( sel ) { return sel( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}; }, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		var set = function ( k, v ) { var m = {}; m[ k ] = v; editPost( { meta: m } ); };
		var r = parse( meta._beacon_rrule ); r.BYPOS = r.BYDAY.length && r.BYDAY.every( function ( d ) { return /^-?\d[A-Z]{2}$/.test( d ); } ) ? r.BYDAY.join( ',' ) : '';
		var setRule = function ( patch ) { var n = Object.assign( {}, r, patch ); set( '_beacon_rrule', build( n ) ); };
		var allDay = meta._beacon_all_day === '1';
		var startYmd = ( meta._beacon_start || '' ).slice( 0, 10 );
		// Upcoming dates of a recurring event, from the last save, so one date can be cancelled or moved.
		var postId = useSelect( function ( sel ) { return sel( 'core/editor' ).getCurrentPostId(); }, [] );
		var occState = useState( null ), occ = occState[ 0 ], setOcc = occState[ 1 ];
		var savedRule = useSelect( function ( sel ) { var p = sel( 'core/editor' ).getCurrentPostAttribute( 'meta' ) || {}; return p._beacon_rrule || ''; }, [] );
		useEffect( function () {
			if ( ! postId || ! savedRule || ! apiFetch ) { setOcc( null ); return; }
			apiFetch( { path: '/beacon/v1/occurrences?post=' + postId } ).then( setOcc ).catch( function () { setOcc( [] ); } );
		}, [ postId, savedRule ] );
		var ov = {};
		try { ov = JSON.parse( meta._beacon_overrides || '{}' ) || {}; } catch ( e ) { ov = {}; }
		var setOv = function ( ymd, value ) { var n = Object.assign( {}, ov ); if ( value ) { n[ ymd ] = value; } else { delete n[ ymd ]; } set( '_beacon_overrides', Object.keys( n ).length ? JSON.stringify( n ) : '' ); };
		var ovStatus = function ( ymd ) { var o = ov[ ymd ]; if ( ! o ) return 'scheduled'; return o.status === 'cancelled' ? 'cancelled' : ( o.start ? 'moved' : 'scheduled' ); };
		var dateRows = occ && occ.length ? el( 'div', { className: 'beacon-dates-panel', style: { marginTop: '12px' } },
			el( 'p', { style: { fontWeight: 600, margin: '0 0 4px' } }, __( 'Upcoming dates', 'beacon-events' ) ),
			el( 'p', { style: { color: '#50575e', margin: '0 0 8px', fontSize: '12px' } }, __( 'Cancel one date (it stays listed as cancelled), move it, or give it its own ticket link. Dates reflect the last save.', 'beacon-events' ) ),
			occ.map( function ( o ) {
				var st = ovStatus( o.orig ), cur = ov[ o.orig ] || {};
				return el( 'div', { key: o.orig, style: { borderTop: '1px solid #dcdcde', padding: '6px 0' } },
					el( SelectControl, { label: o.orig === o.start.slice( 0, 10 ) ? o.label : o.orig, value: st, options: [ { label: __( 'As scheduled', 'beacon-events' ), value: 'scheduled' }, { label: __( 'Cancelled', 'beacon-events' ), value: 'cancelled' }, { label: __( 'Moved', 'beacon-events' ), value: 'moved' } ], onChange: function ( v ) {
						// a date's own ticket link survives moving it or putting it back as scheduled
						if ( v === 'cancelled' ) setOv( o.orig, { status: 'cancelled' } );
						else if ( v === 'moved' ) setOv( o.orig, { start: cur.start || o.start, end: cur.end || o.end, link: cur.link } );
						else setOv( o.orig, cur.link ? { link: cur.link } : null );
					} } ),
					st === 'moved' ? el( F, {},
						el( DateField, { name: 'ov-start-' + o.orig, label: __( 'New start', 'beacon-events' ), value: ( cur.start || o.start ).replace( ' ', 'T' ), onChange: function ( v ) { setOv( o.orig, { start: v.replace( 'T', ' ' ).slice( 0, 16 ), end: cur.end || o.end, link: cur.link } ); } } ),
						el( DateField, { name: 'ov-end-' + o.orig, label: __( 'New end', 'beacon-events' ), value: ( cur.end || o.end ).replace( ' ', 'T' ), onChange: function ( v ) { setOv( o.orig, { start: cur.start || o.start, end: v.replace( 'T', ' ' ).slice( 0, 16 ), link: cur.link } ); } } )
					) : null,
					st !== 'cancelled' ? el( TextControl, { label: __( 'Ticket link for this date', 'beacon-events' ), placeholder: __( 'Same as the event', 'beacon-events' ), value: cur.link || '', onChange: function ( v ) {
						var c = Object.assign( {}, cur );
						if ( v ) { c.link = v; } else { delete c.link; }
						setOv( o.orig, Object.keys( c ).length ? c : null );
					} } ) : null
				);
			} )
		) : null;
		var toLocal = function ( s ) { return ( s || '' ).replace( ' ', 'T' ).slice( 0, 16 ); }, fromLocal = function ( s ) { return ( s || '' ).replace( 'T', ' ' ).slice( 0, 16 ); };
		var plusHour = function ( s ) { var d = new Date( s.replace( ' ', 'T' ) ); if ( isNaN( d ) ) return ''; d.setHours( d.getHours() + 1 ); return ymdOf( d ) + ' ' + pad( d.getHours() ) + ':' + pad( d.getMinutes() ); };
		// stored values are "Y-m-d H:i"; an all-day event keeps 00:00 – 23:59 and shows date-only fields
		var setStart = function ( start ) {
			var m = { _beacon_start: start };
			if ( start && ( ! meta._beacon_end || meta._beacon_end <= start ) ) { m._beacon_end = allDay ? start.slice( 0, 10 ) + ' 23:59' : plusHour( start ); }
			editPost( { meta: m } );
		};
		var setAllDay = function ( on ) {
			var m = { _beacon_all_day: on ? '1' : '0' };
			if ( on && meta._beacon_start ) {
				m._beacon_start = meta._beacon_start.slice( 0, 10 ) + ' 00:00';
				m._beacon_end = ( meta._beacon_end && meta._beacon_end.slice( 0, 10 ) >= meta._beacon_start.slice( 0, 10 ) ? meta._beacon_end : meta._beacon_start ).slice( 0, 10 ) + ' 23:59';
			}
			editPost( { meta: m } );
		};
		var whenFields = allDay ? [
			el( DateField, { key: 'sd', name: 'start-date', type: 'date', label: __( 'Starts', 'beacon-events' ), value: startYmd, onChange: function ( v ) { setStart( v ? v + ' 00:00' : '' ); } } ),
			el( DateField, { key: 'ed', name: 'end-date', type: 'date', label: __( 'Ends', 'beacon-events' ), help: __( 'The last day. Leave it as the start for a one-day event.', 'beacon-events' ), value: ( meta._beacon_end || '' ).slice( 0, 10 ), onChange: function ( v ) { set( '_beacon_end', v ? v + ' 23:59' : '' ); } } )
		] : [
			el( DateField, { key: 'st', name: 'start', label: __( 'Starts', 'beacon-events' ), value: toLocal( meta._beacon_start ), onChange: function ( v ) { setStart( fromLocal( v ) ); } } ),
			el( DateField, { key: 'et', name: 'end', label: __( 'Ends', 'beacon-events' ), value: toLocal( meta._beacon_end ), onChange: function ( v ) { set( '_beacon_end', fromLocal( v ) ); } } )
		];
		// Monthly: the choices a person means, worked out from the start date (like Google Calendar), not "2TU" syntax
		var monthly = [];
		if ( r.FREQ === 'MONTHLY' ) {
			var sd = startYmd ? new Date( +startYmd.slice( 0, 4 ), +startYmd.slice( 5, 7 ) - 1, +startYmd.slice( 8, 10 ) ) : null;
			var codes = [ 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ];
			monthly.push( { value: '', label: sd ? sprintf( __( 'Monthly on day %d', 'beacon-events' ), sd.getDate() ) : __( 'Same day of the month as the start', 'beacon-events' ) } );
			if ( sd ) {
				var nth = Math.ceil( sd.getDate() / 7 ), dow = codes[ sd.getDay() ], wname = NAMES[ dow ];
				if ( nth <= 4 ) { monthly.push( { value: nth + dow, label: sprintf( __( 'Monthly on the %1$s %2$s', 'beacon-events' ), ORD[ String( nth ) ], wname ) } ); }
				// the pair people mean by "first and third Thursday" / "second and fourth Tuesday", including this date's week
				if ( nth <= 4 ) { var a = nth % 2 ? 1 : 2; monthly.push( { value: a + dow + ',' + ( a + 2 ) + dow, label: sprintf( __( 'Monthly on the %1$s and %2$s %3$s', 'beacon-events' ), ORD[ String( a ) ], ORD[ String( a + 2 ) ], wname ) } ); }
				if ( sd.getDate() + 7 > new Date( sd.getFullYear(), sd.getMonth() + 1, 0 ).getDate() ) { monthly.push( { value: '-1' + dow, label: sprintf( __( 'Monthly on the last %s', 'beacon-events' ), wname ) } ); }
			}
			if ( r.BYPOS && ! monthly.some( function ( o ) { return o.value === r.BYPOS; } ) ) {
				monthly.push( { value: r.BYPOS, label: 'Monthly on ' + posText( r.BYPOS ) } );
			}
		}
		// Ends: never / after a number of times / on a date, one at a time
		var endsState = useState( r.COUNT ? 'count' : ( r.UNTIL ? 'until' : 'never' ) ), ends = endsState[ 0 ], setEnds = endsState[ 1 ];
		useEffect( function () { if ( r.COUNT ) { setEnds( 'count' ); } else if ( r.UNTIL ) { setEnds( 'until' ); } }, [ r.COUNT, r.UNTIL ] );
		// Skip dates: read what people type, store Y-m-d, say what was understood
		var skipState = useState( ( meta._beacon_exdates || '' ).split( ',' ).filter( Boolean ).join( ', ' ) ), skipTxt = skipState[ 0 ], setSkipTxt = skipState[ 1 ];
		useEffect( function () { var stored = ( meta._beacon_exdates || '' ).split( ',' ).filter( Boolean ); if ( stored.join( ',' ) !== parseDates( skipTxt, startYmd ).ok.join( ',' ) ) { setSkipTxt( stored.join( ', ' ) ); } }, [ meta._beacon_exdates ] );
		var parsedSkip = parseDates( skipTxt, startYmd );
		var commitSkip = function () { var p = parseDates( skipTxt, startYmd ); set( '_beacon_exdates', p.ok.join( ',' ) ); if ( ! p.bad.length ) { setSkipTxt( p.ok.join( ', ' ) ); } };
		var fmtDay = function ( ymd ) { var d = new Date( +ymd.slice( 0, 4 ), +ymd.slice( 5, 7 ) - 1, +ymd.slice( 8, 10 ) ); return d.toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' } ); };
		// The site's own zone is the FIRST option (and what the field shows by default): the list used to start at "Africa/Abidjan",
		// and a stray click or Enter in the open list saved that zone (real site 2026-10-16: a Berlin class shown as "19:00 GMT").
		var SITE_TZ = '__site__';
		var zones = [ { value: SITE_TZ, label: sprintf( __( 'Site time zone (%s)', 'beacon-events' ), ( CFG.siteTz || 'UTC' ).replace( /_/g, ' ' ) ) } ].concat( ( CFG.zones || [] ).filter( function ( z ) { return z !== CFG.siteTz; } ).map( function ( z ) { return { value: z, label: z.replace( /_/g, ' ' ) }; } ) );
		return el( Panel, { name: 'beacon-when', title: __( 'When', 'beacon-events' ), initialOpen: true },
			whenFields,
			el( ToggleControl, { label: __( 'All day', 'beacon-events' ), checked: allDay, onChange: setAllDay } ),
			ComboboxControl && zones.length ? el( ComboboxControl, { label: __( 'Time zone', 'beacon-events' ), help: __( 'Change it only when this event happens somewhere else.', 'beacon-events' ), value: meta._beacon_tz && meta._beacon_tz !== CFG.siteTz ? meta._beacon_tz : SITE_TZ, options: zones, onChange: function ( v ) { set( '_beacon_tz', v && v !== SITE_TZ ? v : '' ); } } ) : null,
			ComboboxControl && zones.length && meta._beacon_tz && meta._beacon_tz !== CFG.siteTz ? el( 'p', { className: 'beacon-tz-note', role: 'status', style: { margin: '-8px 0 12px', fontSize: '12px', color: '#996800' } }, sprintf( __( 'This event\'s times are in %1$s, not the site\'s time zone (%2$s). Pick "Site time zone" if that was a mistake.', 'beacon-events' ), meta._beacon_tz.replace( /_/g, ' ' ), ( CFG.siteTz || 'UTC' ).replace( /_/g, ' ' ) ) ) : null,
			el( ToggleControl, { label: __( 'Online event', 'beacon-events' ), checked: meta._beacon_online === '1', onChange: function ( v ) { set( '_beacon_online', v ? '1' : '0' ); } } ),
			meta._beacon_online === '1' ? el( TextControl, { label: __( 'Meeting link', 'beacon-events' ), type: 'url', value: meta._beacon_online_url || '', placeholder: 'https://zoom.us/j/…', onChange: function ( v ) { set( '_beacon_online_url', v ); }, onBlur: function () { var v = withScheme( meta._beacon_online_url ); if ( v !== ( meta._beacon_online_url || '' ) ) { set( '_beacon_online_url', v ); } } } ) : null,
			el( TextControl, { label: __( 'Tickets & info link', 'beacon-events' ), help: __( 'Optional: a button to your Eventbrite, Square or other page.', 'beacon-events' ), type: 'url', value: meta._beacon_link || '', placeholder: 'https://', onChange: function ( v ) { set( '_beacon_link', v ); }, onBlur: function () { var v = withScheme( meta._beacon_link ); if ( v !== ( meta._beacon_link || '' ) ) { set( '_beacon_link', v ); } } } ),
			meta._beacon_link ? el( TextControl, { label: __( 'Button text', 'beacon-events' ), value: meta._beacon_link_label || '', placeholder: __( 'Tickets & info', 'beacon-events' ), onChange: function ( v ) { set( '_beacon_link_label', v ); } } ) : null,
			el( SelectControl, { label: __( 'Repeats', 'beacon-events' ), value: r.FREQ, options: [ { label: __( 'Does not repeat', 'beacon-events' ), value: '' }, { label: __( 'Daily', 'beacon-events' ), value: 'DAILY' }, { label: __( 'Weekly', 'beacon-events' ), value: 'WEEKLY' }, { label: __( 'Monthly', 'beacon-events' ), value: 'MONTHLY' }, { label: __( 'Yearly', 'beacon-events' ), value: 'YEARLY' } ], onChange: function ( v ) { setRule( { FREQ: v } ); } } ),
			r.FREQ ? el( F, {},
				el( NumberField, { label: __( 'Every (interval)', 'beacon-events' ), min: 1, max: 52, value: r.INTERVAL, onChange: function ( n ) { setRule( { INTERVAL: n } ); } } ),
				r.FREQ === 'WEEKLY' ? el( Flex, { wrap: true, gap: 2 }, DAYS.map( function ( d ) { return el( FlexItem, { key: d[ 0 ] }, el( CheckboxControl, { label: d[ 1 ], checked: r.BYDAY.indexOf( d[ 0 ] ) !== -1, onChange: function ( on ) { var b = r.BYDAY.filter( function ( x ) { return x !== d[ 0 ]; } ); if ( on ) b.push( d[ 0 ] ); setRule( { BYDAY: b } ); } } ) ); } ) ) : null,
				r.FREQ === 'MONTHLY' ? el( SelectControl, { label: __( 'On', 'beacon-events' ), value: r.BYPOS, options: monthly, onChange: function ( v ) { setRule( { BYPOS: v } ); } } ) : null,
				el( SelectControl, { label: __( 'Repeat ends', 'beacon-events' ), value: ends, options: [ { label: __( 'Never', 'beacon-events' ), value: 'never' }, { label: __( 'After a number of times', 'beacon-events' ), value: 'count' }, { label: __( 'On a date', 'beacon-events' ), value: 'until' } ], onChange: function ( v ) {
					setEnds( v );
					if ( v === 'never' ) { setRule( { COUNT: '', UNTIL: '' } ); }
					if ( v === 'count' ) { setRule( { COUNT: r.COUNT || 10, UNTIL: '' } ); }
					if ( v === 'until' ) { setRule( { COUNT: '' } ); }
				} } ),
				ends === 'count' ? el( NumberField, { label: __( 'Number of times', 'beacon-events' ), min: 1, value: r.COUNT, onChange: function ( n ) { setRule( { COUNT: n, UNTIL: '' } ); } } ) : null,
				ends === 'until' ? el( DateField, { name: 'until', type: 'date', label: __( 'Last date', 'beacon-events' ), value: r.UNTIL, onChange: function ( v ) { setRule( { UNTIL: v, COUNT: '' } ); } } ) : null,
				el( TextControl, { label: __( 'Skip these dates', 'beacon-events' ), help: __( 'Separate dates with commas, e.g. 12/24/2026, Dec 31', 'beacon-events' ), value: skipTxt, onChange: setSkipTxt, onBlur: commitSkip } ),
				parsedSkip.ok.length ? el( 'p', { className: 'beacon-skip-read', style: { margin: '-8px 0 8px', fontSize: '12px', color: '#50575e' } }, __( 'Skipping:', 'beacon-events' ) + ' ' + parsedSkip.ok.map( fmtDay ).join( ' · ' ) ) : null,
				parsedSkip.bad.length ? el( 'p', { className: 'beacon-skip-bad', role: 'alert', style: { margin: '-4px 0 8px', fontSize: '12px', color: '#cc1818' } }, sprintf( __( 'Couldn\'t read: %s. Use a date like 12/25/2026.', 'beacon-events' ), parsedSkip.bad.join( ', ' ) ) ) : null
			) : null,
			r.FREQ ? el( 'p', { style: { color: '#50575e', marginTop: '8px' } }, describe( r ) ) : null,
			dateRows
		);
	}
	wp.plugins.registerPlugin( 'beacon-when', { render: WhenPanel, icon: 'calendar-alt' } );
	// Without Pro: a collapsed panel where Pro's own ticket settings would sit. Closed by default, no notice anywhere.
	var cfg = window.beaconPanel || {};
	if ( ! cfg.pro && cfg.proUrl ) {
		wp.plugins.registerPlugin( 'beacon-tickets-info', {
			icon: 'tickets-alt',
			render: function () {
				return el( Panel, { name: 'beacon-tickets-info', title: __( 'Tickets & RSVP', 'beacon-events' ), initialOpen: false },
					el( 'p', { style: { margin: '0 0 8px' } }, __( 'Let people sign up for this event or buy tickets with Beacon Events Pro. Payments go through your own Stripe account or WooCommerce, with no per-ticket fee on top. Try it free for 14 days, no card needed.', 'beacon-events' ) ),
					el( 'a', { href: cfg.proUrl }, __( 'See what Pro adds', 'beacon-events' ) )
				);
			}
		} );
	}
	// Open the panel by default the first time; after that, respect whatever the user leaves it as.
	try {
		var sel = wp.data.select( 'core/edit-post' ), disp = wp.data.dispatch( 'core/edit-post' ), key = 'beacon-when/beacon-when';
		if ( sel && disp && ! sel.isEditorPanelOpened( key ) && ! window.localStorage.getItem( 'beacon-when-seen' ) ) { disp.toggleEditorPanelOpened( key ); window.localStorage.setItem( 'beacon-when-seen', '1' ); }
	} catch ( e ) {}
} )( window.wp );
