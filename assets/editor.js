( function ( wp ) {
	var el = wp.element.createElement, __ = wp.i18n.__, useBlockProps = wp.blockEditor.useBlockProps, InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody, SelectControl = wp.components.SelectControl, RangeControl = wp.components.RangeControl, TextControl = wp.components.TextControl, ToggleControl = wp.components.ToggleControl;
	wp.blocks.registerBlockType( 'beacon/events', {
		title: __( 'Events', 'beacon-events' ), icon: 'calendar-alt', category: 'widgets', description: __( 'Your events as a list, a week timetable, a month grid or one day.', 'beacon-events' ), supports: { align: [ 'wide', 'full' ] },
		edit: function ( props ) {
			var a = props.attributes, set = props.setAttributes;
			return el( wp.element.Fragment, {},
				el( InspectorControls, {}, el( PanelBody, { title: __( 'Events', 'beacon-events' ) },
					el( SelectControl, { label: __( 'View', 'beacon-events' ), value: a.view, options: [ { label: __( 'List', 'beacon-events' ), value: 'list' }, { label: __( 'Week', 'beacon-events' ), value: 'week' }, { label: __( 'Month', 'beacon-events' ), value: 'month' }, { label: __( 'Day', 'beacon-events' ), value: 'day' } ], onChange: function ( v ) { set( { view: v } ); } } ),
					a.view === 'list' ? el( SelectControl, { label: __( 'Layout', 'beacon-events' ), value: a.layout || '', options: [ { label: __( 'Grouped by day', 'beacon-events' ), value: '' }, { label: __( 'Compact (one line per event)', 'beacon-events' ), value: 'compact' } ], onChange: function ( v ) { set( { layout: v } ); } } ) : null,
					a.view === 'list' ? el( ToggleControl, { label: __( 'Each event once', 'beacon-events' ), help: __( 'A repeating event shows only its next date, so a weekly class doesn\'t fill the list.', 'beacon-events' ), checked: !! a.once, onChange: function ( v ) { set( { once: !! v } ); } } ) : null,
					el( ToggleControl, { label: __( 'Filter bar (category, venue, search, view)', 'beacon-events' ), checked: !! a.filters, onChange: function ( v ) { set( { filters: !! v } ); } } ),
					a.view === 'month' ? el( ToggleControl, { label: __( '"Print this month" link', 'beacon-events' ), help: __( 'Opens the month on its own page, ready to print.', 'beacon-events' ), checked: a.printLink !== false, onChange: function ( v ) { set( { printLink: !! v } ); } } ) : null,
					el( RangeControl, { label: __( 'Days ahead (list)', 'beacon-events' ), value: a.days, min: 7, max: 365, onChange: function ( v ) { set( { days: v } ); } } ),
					el( RangeControl, { label: __( 'Max events (list)', 'beacon-events' ), value: a.limit, min: 1, max: 100, onChange: function ( v ) { set( { limit: v } ); } } ),
					el( TextControl, { label: __( 'Category slugs, comma-separated (optional)', 'beacon-events' ), value: a.category, onChange: function ( v ) { set( { category: v } ); } } ),
					el( TextControl, { label: __( 'Venue slugs, comma-separated (optional)', 'beacon-events' ), value: a.venue, onChange: function ( v ) { set( { venue: v } ); } } ),
					el( TextControl, { label: __( 'Organizer slugs, comma-separated (optional)', 'beacon-events' ), value: a.organizer, onChange: function ( v ) { set( { organizer: v } ); } } ) ) ),
				el( 'div', useBlockProps(), el( wp.serverSideRender, { block: 'beacon/events', attributes: a } ) ) );
		},
		save: function () { return null; }
	} );
	wp.blocks.registerBlockVariation( 'beacon/events', {
		name: 'upcoming', title: __( 'Upcoming events', 'beacon-events' ), description: __( 'A short one-line-per-event list for sidebars and footers.', 'beacon-events' ), icon: 'list-view',
		attributes: { view: 'list', layout: 'compact', days: 90, limit: 5 }, scope: [ 'inserter' ],
		isActive: function ( attrs ) { return attrs.layout === 'compact'; }
	} );
	wp.blocks.registerBlockType( 'beacon/term-details', {
		title: __( 'Venue / category details', 'beacon-events' ), icon: 'location', category: 'widgets', description: __( 'On a venue, organizer or category page: address and map link, description, subscribe link. Empty elsewhere.', 'beacon-events' ),
		edit: function () { return el( 'div', useBlockProps(), el( 'p', { style: { opacity: .7 } }, __( 'Venue / category details (shown on venue, organizer and category pages)', 'beacon-events' ) ) ); },
		save: function () { return null; }
	} );
} )( window.wp );
