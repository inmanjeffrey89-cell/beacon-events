<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Front-end: list and month views as shortcodes and blocks. Server-rendered, so there is no build step and the
 * markup inherits the theme. Our CSS does layout only — no colours, no fonts — and every element carries a
 * `beacon-*` class so a site owner can restyle anything without fighting us.
 */
final class Views {
	public static function init(): void {
		add_shortcode( 'beacon_events', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'beacon_event_details', array( __CLASS__, 'details_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'init', array( __CLASS__, 'register_templates' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'single_details' ) );
		add_filter( 'the_excerpt', array( __CLASS__, 'archive_details' ) );
		add_filter( 'render_block_core/post-date', array( __CLASS__, 'archive_date_block' ), 10, 3 );
		add_action( 'pre_get_posts', array( __CLASS__, 'archive_query' ) );
		add_filter( 'get_the_date', array( __CLASS__, 'event_date' ), 10, 3 );
		add_filter( 'get_the_time', array( __CLASS__, 'event_time' ), 10, 3 );
		add_action( 'wp_head', array( __CLASS__, 'schema' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots_meta' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'print_page' ), 1 );
		add_filter( 'get_the_archive_description', array( __CLASS__, 'archive_description' ) );
	}

	/** The events archive lists UPCOMING events in date order, not posts in publish order. */
	public static function archive_query( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_post_type_archive( Events::CPT ) ) {
			return; }
		$q->set( 'meta_key', Events::META['start'] ); // phpcs:ignore WordPress.DB.SlowDBQuery -- archive ordering by event start
		$q->set( 'orderby', 'meta_value' );
		$q->set( 'order', 'ASC' );
		$q->set(
			'meta_query',
			array(
				array(
					'key'     => Events::META['start'],
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'CHAR',
				),
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/** Themes print the post date under a title; for an event that should be the event's date. */
	public static function event_date( $date, $format, $post ) {
		if ( is_admin() || ! $post || get_post_type( $post ) !== Events::CPT ) {
			return $date; }
		$e = Events::get( $post->ID );
		if ( ! $e['start'] ) {
			return $date; }
		try {
			$s = new \DateTimeImmutable( $e['start'], new \DateTimeZone( $e['tz'] ) );
		} catch ( \Exception $x ) {
			return $date; }
		return wp_date( $format ? $format : get_option( 'date_format' ), $s->getTimestamp(), $s->getTimezone() );
	}

	public static function event_time( $time, $format, $post ) {
		if ( is_admin() || ! $post || get_post_type( $post ) !== Events::CPT ) {
			return $time; }
		$e = Events::get( $post->ID );
		if ( ! $e['start'] || $e['all_day'] ) {
			return $time; }
		try {
			$s = new \DateTimeImmutable( $e['start'], new \DateTimeZone( $e['tz'] ) );
		} catch ( \Exception $x ) {
			return $time; }
		return wp_date( $format ? $format : get_option( 'time_format' ), $s->getTimestamp(), $s->getTimezone() );
	}

	/** Posts whose When/Where already rendered in this request, so a theme showing both a date block and an excerpt gets it once. */
	private static $shown = array();

	/** On archive/list templates that show excerpts, put When/Where above the excerpt. */
	public static function archive_details( string $excerpt ): string {
		if ( is_singular() || ! in_the_loop() || get_post_type() !== Events::CPT || isset( self::$shown[ get_the_ID() ] ) ) {
			return $excerpt; }
		self::$shown[ get_the_ID() ] = true;
		return self::details_block( get_the_ID() ) . $excerpt;
	}

	/**
	 * Block themes (Twenty Twenty-Four/Five and most others) list posts as title + Post Date block, no excerpt.
	 * For an event, "September 24, 2026" alone is useless — add the time, venue and repeat rule under it, inside the
	 * block so it inherits the block's font size and alignment. Skipped on the event's own page (the content has it).
	 */
	public static function archive_date_block( string $html, array $block, \WP_Block $instance ): string {
		$id = (int) ( $instance->context['postId'] ?? get_the_ID() );
		if ( ! $id || get_post_type( $id ) !== Events::CPT || ( is_singular() && get_queried_object_id() === $id ) || isset( self::$shown[ $id ] ) ) {
			return $html; }
		$line = self::summary_line( $id );
		if ( ! $line ) {
			return $html; }
		self::$shown[ $id ] = true;
		wp_enqueue_style( 'beacon-events' );
		$line = '<div class="beacon-archive-meta">' . $line . '</div>';
		return preg_match( '#</div>\s*$#', $html ) ? preg_replace( '#</div>\s*$#', $line . '</div>', $html, 1 ) : $html . $line;
	}

	/** One line: "7:00 pm – 9:00 pm · Community Hall · Every week on Tuesday". Escaped. */
	public static function summary_line( int $id ): string {
		$e = Events::get( $id );
		if ( ! $e['start'] ) {
			return ''; }
		try {
			$tz = new \DateTimeZone( $e['tz'] );
		} catch ( \Exception $x ) {
			$tz = self::tz(); }
		$s     = new \DateTimeImmutable( $e['start'], $tz );
		$parts = array();
		if ( $e['all_day'] ) {
			$parts[] = esc_html__( 'All day', 'beacon-events' );
		} else {
			$t = wp_date( get_option( 'time_format' ), $s->getTimestamp(), $tz );
			if ( $e['end'] ) {
				$en = new \DateTimeImmutable( $e['end'], $tz );
				$t .= ' – ' . wp_date( ( $en->format( 'Y-m-d' ) === $s->format( 'Y-m-d' ) ? '' : get_option( 'date_format' ) . ', ' ) . get_option( 'time_format' ), $en->getTimestamp(), $tz ); }
			$parts[] = esc_html( $t );
		}
		$venue = self::venue( $id );
		if ( $venue ) {
			$parts[] = $venue; }
		if ( $e['rrule'] ) {
			$parts[] = esc_html( Recurrence::describe( $e['rrule'] ) ); }
		return implode( ' <span class="beacon-sep">·</span> ', $parts );
	}

	/**
	 * Block themes fall back to single.html / archive.html for our post type, which prints blog furniture that makes
	 * no sense on an event ("Written by … in …", publish date, post categories). Give the theme proper event
	 * templates instead: its own header and footer parts around the title, When/Where and content. Site owners can
	 * edit these in the Site Editor like any other template. WordPress 6.7+; older versions keep the theme's fallback.
	 */
	public static function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return; }
		$wrap = '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->' .
			'<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"2.5rem","bottom":"3rem"}}},"layout":{"type":"constrained"}} --><main class="wp-block-group" style="padding-top:2.5rem;padding-bottom:3rem">%s</main><!-- /wp:group -->' .
			'<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->';
		register_block_template(
			'beacon-events//single-' . Events::CPT,
			array(
				'title'       => __( 'Single Event', 'beacon-events' ),
				'description' => __( 'One event: title, when and where, details, and the .ics link.', 'beacon-events' ),
				'post_types'  => array( Events::CPT ),
				'content'     => sprintf(
					$wrap,
					'<!-- wp:post-title {"level":1} /-->' .
					'<!-- wp:post-featured-image {"style":{"spacing":{"margin":{"top":"1.5rem"}}}} /-->' .
					'<!-- wp:post-content {"layout":{"type":"constrained"}} /-->' .
					'<!-- wp:post-terms {"term":"beacon_category","prefix":"' . esc_attr__( 'Categories: ', 'beacon-events' ) . '","style":{"spacing":{"margin":{"top":"1.5rem"}}}} /-->'
				),
			)
		);
		foreach ( array(
			'beacon_venue'     => array( __( 'Venue', 'beacon-events' ), __( 'One venue: its address, a map link, and its upcoming events.', 'beacon-events' ) ),
			'beacon_organizer' => array( __( 'Organizer', 'beacon-events' ), __( 'One organizer and their upcoming events.', 'beacon-events' ) ),
			'beacon_category'  => array( __( 'Event Category', 'beacon-events' ), __( 'One event category: description, subscribe link, upcoming events.', 'beacon-events' ) ),
		) as $tax => $labels ) {
			register_block_template(
				'beacon-events//taxonomy-' . $tax,
				array(
					'title'       => $labels[0],
					'description' => $labels[1],
					'content'     => sprintf(
						$wrap,
						'<!-- wp:query-title {"type":"archive","level":1,"showPrefix":false} /-->' .
						'<!-- wp:beacon/term-details /-->' .
						'<!-- wp:beacon/events {"view":"list","days":365,"limit":100,"scope":"current"} /-->'
					),
				)
			);
		}
		register_block_template(
			'beacon-events//archive-' . Events::CPT,
			array(
				'title'       => __( 'Events Archive', 'beacon-events' ),
				'description' => __( 'The /events/ page: upcoming events grouped by day.', 'beacon-events' ),
				'content'     => sprintf(
					$wrap,
					'<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . esc_html__( 'Upcoming events', 'beacon-events' ) . '</h1><!-- /wp:heading -->' .
					'<!-- wp:beacon/events {"view":"list","days":365,"limit":100} /-->'
				),
			)
		);
	}

	public static function register_assets(): void {
		wp_register_style( 'beacon-events', BEACON_URL . 'assets/beacon.css', array(), BEACON_VERSION );   // enqueued only when a view renders
	}

	public static function register_blocks(): void {
		$attrs = array(
			'view'     => array(
				'type'    => 'string',
				'default' => 'list',
			),
			'days'     => array(
				'type'    => 'number',
				'default' => 60,
			),
			'limit'    => array(
				'type'    => 'number',
				'default' => 20,
			),
			'category' => array(
				'type'    => 'string',
				'default' => '',
			),
			'venue'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'organizer' => array(
				'type'    => 'string',
				'default' => '',
			),
			'scope'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'layout'    => array(
				'type'    => 'string',
				'default' => '',
			),
			'filters'   => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'printLink' => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
		wp_register_script( 'beacon-events-editor', BEACON_URL . 'assets/editor.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), BEACON_VERSION, true );
		wp_set_script_translations( 'beacon-events-editor', 'beacon-events', BEACON_DIR . 'languages' );
		register_block_type(
			'beacon/events',
			array(
				'api_version'     => 3,
				'editor_script'   => 'beacon-events-editor',
				'attributes'      => $attrs,
				'render_callback' => fn( $a ) => self::render( $a ),
			)
		);
		// Venue address + map link, category description + subscribe link: the header of a venue/category/organizer page.
		register_block_type(
			'beacon/term-details',
			array(
				'api_version'     => 3,
				'editor_script'   => 'beacon-events-editor',
				'render_callback' => fn() => self::term_details(),
			)
		);
	}

	public static function shortcode( $atts ): string {
		return self::render(
			shortcode_atts(
				array(
					'view'     => 'list',
					'days'     => 60,
					'limit'    => 20,
					'category'  => '',
					'venue'     => '',
					'organizer' => '',
					'scope'     => '',
					'layout'    => '',
					'filters'   => '',
					'month'     => '',
					'day'       => '',
					'week'      => '',
					'print'     => '',
				),
				$atts,
				'beacon_events'
			)
		);
	}

	public static function render( array $a ): string {
		wp_enqueue_style( 'beacon-events' );
		$a = array_merge(
			array(
				'category'  => '',
				'venue'     => '',
				'organizer' => '',
				'scope'     => '',
				'layout'    => '',
				'filters'   => false,
				'q'         => '',
				'day'       => '',
				'week'      => '',
			),
			$a
		);
		// scope="current": on a venue / organizer / category page, show that term's events (used by the page templates).
		if ( $a['scope'] === 'current' ) {
			$q = get_queried_object();
			if ( $q instanceof \WP_Term && isset( self::TAX_ATTR[ $q->taxonomy ] ) ) {
				$a[ self::TAX_ATTR[ $q->taxonomy ] ] = $q->slug; }
		}
		$view = in_array( $a['view'] ?? 'list', array( 'list', 'week', 'month', 'day' ), true ) ? $a['view'] : 'list';
		$bar  = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filtering and navigation; nothing changes state
		if ( filter_var( $a['filters'], FILTER_VALIDATE_BOOLEAN ) ) {
			$g = fn( string $k ) => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
			// A block limited to some categories / venues keeps that limit: the bar narrows inside it, never widens it.
			$own_cat   = self::slugs( (string) $a['category'] );
			$own_venue = self::slugs( (string) $a['venue'] );
			$pick      = sanitize_title( $g( 'bf_cat' ) );
			if ( $pick && ( ! $own_cat || in_array( $pick, $own_cat, true ) ) ) {
				$a['category'] = $pick; }
			$pick = sanitize_title( $g( 'bf_venue' ) );
			if ( $pick && ( ! $own_venue || in_array( $pick, $own_venue, true ) ) ) {
				$a['venue'] = $pick; }
			$a['q'] = $g( 'bf_q' );
			if ( in_array( $g( 'bf_view' ), array( 'list', 'week', 'month' ), true ) ) {
				$view = $g( 'bf_view' ); }
			$bar = self::filter_bar( $a, $view, $own_cat, $own_venue );
		}
		// a month grid's day numbers link to that day; ?beacon_day=YYYY-MM-DD switches the same block to the day view
		$bd = isset( $_GET['beacon_day'] ) ? sanitize_text_field( wp_unslash( $_GET['beacon_day'] ) ) : '';
		if ( self::valid_date( $bd ) ) {
			if ( $view === 'month' ) {
				$view            = 'day';
				$a['from_month'] = true;
			}
			if ( $view === 'day' ) { // a standalone day view's prev/next links use the same arg
				$a['day'] = $bd;
			}
		}
		// ?beacon_week=YYYY-MM-DD (any day of the week) moves a week view
		$bw = isset( $_GET['beacon_week'] ) ? sanitize_text_field( wp_unslash( $_GET['beacon_week'] ) ) : '';
		if ( $view === 'week' && self::valid_date( $bw ) ) {
			$a['week'] = $bw; }
		// phpcs:enable
		if ( $view === 'week' ) {
			return $bar . self::week( $a ); }
		if ( $view === 'month' ) {
			return $bar . self::month( $a ); }
		if ( $view === 'day' ) {
			return $bar . self::day( $a ); }
		return $bar . self::list( $a );
	}

	/**
	 * Category / venue selects, a search box and a list/month switch. Plain GET form: works without JavaScript.
	 * $own_cat / $own_venue: the block's own limits. The selects then offer only those categories, and only the venues
	 * those events use, so a class timetable's bar doesn't list the site's other calendars.
	 */
	private static function filter_bar( array $a, string $view, array $own_cat = array(), array $own_venue = array() ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only
		$cur = fn( string $k ) => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		// phpcs:enable
		$cats   = get_terms( array( 'taxonomy' => 'beacon_category', 'hide_empty' => true ) );
		$venues = get_terms( array( 'taxonomy' => 'beacon_venue', 'hide_empty' => true ) );
		if ( $own_cat && $cats && ! is_wp_error( $cats ) ) {
			$cats = array_values( array_filter( $cats, fn( $t ) => in_array( $t->slug, $own_cat, true ) ) ); }
		if ( $own_venue && $venues && ! is_wp_error( $venues ) ) {
			$venues = array_values( array_filter( $venues, fn( $t ) => in_array( $t->slug, $own_venue, true ) ) );
		} elseif ( $own_cat ) {
			$ids    = $cats && ! is_wp_error( $cats ) ? get_objects_in_term( wp_list_pluck( $cats, 'term_id' ), 'beacon_category' ) : array();
			$used   = $ids && ! is_wp_error( $ids ) ? wp_get_object_terms( array_map( 'intval', $ids ), 'beacon_venue', array( 'fields' => 'ids' ) ) : array();
			$used   = is_wp_error( $used ) ? array() : array_map( 'intval', $used );
			$venues = $venues && ! is_wp_error( $venues ) ? array_values( array_filter( $venues, fn( $t ) => in_array( (int) $t->term_id, $used, true ) ) ) : $venues;
		}
		$out    = '<form class="beacon-filters" method="get" action="' . esc_url( remove_query_arg( array( 'bf_cat', 'bf_venue', 'bf_q', 'bf_view', 'beacon_month', 'beacon_day', 'beacon_week' ) ) ) . '">';
		// keep the page's own query (e.g. ?pagename=calendar on plain permalinks)
		foreach ( wp_parse_args( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ) ) as $k => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- keys/values re-escaped below
			if ( ! in_array( $k, array( 'bf_cat', 'bf_venue', 'bf_q', 'bf_view', 'beacon_month', 'beacon_day', 'beacon_week' ), true ) && is_string( $v ) ) {
				$out .= '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( sanitize_text_field( $v ) ) . '">'; }
		}
		if ( $cats && ! is_wp_error( $cats ) ) {
			$out .= '<select name="bf_cat" aria-label="' . esc_attr__( 'Category', 'beacon-events' ) . '"><option value="">' . esc_html__( 'All categories', 'beacon-events' ) . '</option>';
			foreach ( $cats as $t ) {
				$out .= '<option value="' . esc_attr( $t->slug ) . '" ' . selected( $cur( 'bf_cat' ), $t->slug, false ) . '>' . esc_html( $t->name ) . '</option>'; }
			$out .= '</select>';
		}
		if ( $venues && ! is_wp_error( $venues ) && count( $venues ) > 1 ) {
			$out .= '<select name="bf_venue" aria-label="' . esc_attr__( 'Venue', 'beacon-events' ) . '"><option value="">' . esc_html__( 'All venues', 'beacon-events' ) . '</option>';
			foreach ( $venues as $t ) {
				$out .= '<option value="' . esc_attr( $t->slug ) . '" ' . selected( $cur( 'bf_venue' ), $t->slug, false ) . '>' . esc_html( $t->name ) . '</option>'; }
			$out .= '</select>';
		}
		$out .= '<input type="search" name="bf_q" value="' . esc_attr( $cur( 'bf_q' ) ) . '" placeholder="' . esc_attr__( 'Search events', 'beacon-events' ) . '" aria-label="' . esc_attr__( 'Search events', 'beacon-events' ) . '">';
		$out .= '<span class="beacon-view-switch" role="radiogroup" aria-label="' . esc_attr__( 'View', 'beacon-events' ) . '"><label><input type="radio" name="bf_view" value="list" ' . checked( ! in_array( $view, array( 'week', 'month' ), true ), true, false ) . '> ' . esc_html__( 'List', 'beacon-events' ) . '</label> <label><input type="radio" name="bf_view" value="week" ' . checked( $view === 'week', true, false ) . '> ' . esc_html__( 'Week', 'beacon-events' ) . '</label> <label><input type="radio" name="bf_view" value="month" ' . checked( $view === 'month', true, false ) . '> ' . esc_html__( 'Month', 'beacon-events' ) . '</label></span>';
		$out .= '<button type="submit" class="beacon-apply wp-element-button">' . esc_html__( 'Apply', 'beacon-events' ) . '</button></form>';
		return $out;
	}

	/** Title search on already-fetched rows (they are few and already filtered by date). */
	private static function filter_search( array $rows, string $q ): array {
		$q = trim( $q );
		if ( $q === '' ) {
			return $rows; }
		return array_values( array_filter( $rows, fn( $r ) => mb_stripos( (string) $r['post_title'], $q ) !== false ) );
	}

	/** Inline colour hook from the event's first coloured category, or ''. */
	private static function color_attr( int $id ): string {
		$terms = get_the_terms( $id, 'beacon_category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return ''; }
		foreach ( $terms as $t ) {
			$c = (string) get_term_meta( $t->term_id, 'beacon_color', true );
			if ( $c && sanitize_hex_color( $c ) ) {
				return ' style="--beacon-color:' . esc_attr( $c ) . '"'; }
		}
		return '';
	}

	const TAX_ATTR = array(
		'beacon_category'  => 'category',
		'beacon_venue'     => 'venue',
		'beacon_organizer' => 'organizer',
	);

	/** Apply every term filter the caller set (each accepts one slug or several, comma-separated; any match shows). */
	private static function filter_all( array $rows, array $a ): array {
		foreach ( self::TAX_ATTR as $tax => $attr ) {
			$rows = self::filter_terms( $rows, $tax, (string) ( $a[ $attr ] ?? '' ) ); }
		return self::filter_search( $rows, (string) ( $a['q'] ?? '' ) );
	}

	/**
	 * Occurrences in [ $from, $to ) that pass the view's category / venue / organizer / search filters, at most $want.
	 * Filtering happens in PHP, so on a busy calendar a filtered view keeps reading further pages until it has enough.
	 */
	private static function fetch( string $from, string $to, int $want, array $a ): array {
		$want = max( 1, $want );
		$page = max( 100, $want * 3 );
		$off  = 0;
		$out  = array();
		do { // each page twice the last: a rare category on a busy calendar costs a handful of queries, not hundreds
			$chunk = Events::upcoming( $from, $to, $page, array(), $off );
			$out   = array_merge( $out, self::filter_all( $chunk, $a ) );
			$off  += $page;
			$page *= 2;
		} while ( count( $out ) < $want && count( $chunk ) === $page / 2 && $off < 50000 );
		return array_slice( $out, 0, $want );
	}

	private static function tz(): \DateTimeZone {
		return wp_timezone(); }
	/**
	 * A real calendar date: "Y-m-d", or "Y-m" with $month_only. Navigation dates come from the URL and the block, and
	 * DateTimeImmutable throws on "2026-13-01" or "2026-12-32" — an uncaught throw there was a 500 for the whole page.
	 */
	private static function valid_date( string $s, bool $month_only = false ): bool {
		$m = array();
		if ( $month_only ) {
			return (bool) preg_match( '/^(\d{4})-(\d{2})$/', $s, $m ) && checkdate( (int) $m[2], 1, (int) $m[1] );
		}
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
	private static function utc( \DateTimeImmutable $d ): string {
		return $d->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); }
	/** An occurrence in the zone it is shown in: the event's own (as the organizer entered it), else the site's. */
	private static function local( string $utc, int $event_id = 0 ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $event_id ? self::event_tz( $event_id ) : self::tz() ); }

	private static function event_tz( int $id ): \DateTimeZone {
		static $cache = array();
		if ( ! isset( $cache[ $id ] ) ) {
			$name = (string) get_post_meta( $id, Events::META['tz'], true );
			try {
				$cache[ $id ] = $name !== '' ? new \DateTimeZone( $name ) : self::tz();
			} catch ( \Exception $x ) {
				$cache[ $id ] = self::tz(); }
		}
		return $cache[ $id ];
	}

	/** " EDT" when an event's clock differs from the site's at that moment, so visitors know whose time it is; else ''. */
	private static function zone_label( \DateTimeImmutable $d ): string {
		return $d->getOffset() === $d->setTimezone( self::tz() )->getOffset() ? '' : ' ' . $d->format( 'T' ); }

	/** Rows whose date, in their event's own zone, falls in [ $from, $to ) — for views that fetch a day either side. */
	private static function in_days( array $rows, string $from, string $to ): array {
		return array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $from, $to ) {
					$d = self::local( $r['start_utc'], (int) $r['event_id'] )->format( 'Y-m-d' );
					return $d >= $from && $d < $to;
				}
			)
		);
	}

	/** "a, b,c" → array( 'a', 'b', 'c' ) as term slugs. */
	private static function slugs( string $csv ): array {
		return array_values( array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $csv ) ) ) ) );
	}

	private static function filter_terms( array $rows, string $taxonomy, string $csv ): array {
		$slugs = self::slugs( $csv );
		if ( ! $slugs ) {
			return $rows; }
		return array_values( array_filter( $rows, fn( $r ) => has_term( $slugs, $taxonomy, (int) $r['event_id'] ) ) );
	}

	/** "Cancelled" / "Rescheduled" marker for an occurrence row, or ''. */
	private static function badge( array $r ): string {
		$st = (string) ( $r['status'] ?? 'scheduled' );
		if ( $st === 'cancelled' ) {
			return ' <span class="beacon-badge beacon-badge-cancelled">' . esc_html__( 'Cancelled', 'beacon-events' ) . '</span>'; }
		if ( $st === 'moved' ) {
			return ' <span class="beacon-badge beacon-badge-moved">' . esc_html__( 'Rescheduled', 'beacon-events' ) . '</span>'; }
		return '';
	}

	private static function row_class( array $r ): string {
		$st = (string) ( $r['status'] ?? 'scheduled' );
		return $st === 'scheduled' ? '' : ' beacon-' . $st;
	}

	private static function when( array $r ): string {
		$s = self::local( $r['start_utc'], (int) $r['event_id'] );
		$e = self::local( $r['end_utc'], (int) $r['event_id'] );
		if ( (int) $r['all_day'] ) {
			return esc_html__( 'All day', 'beacon-events' ); }
		$t = wp_date( get_option( 'time_format' ), $s->getTimestamp(), $s->getTimezone() );
		if ( $e > $s && $e->format( 'Y-m-d' ) === $s->format( 'Y-m-d' ) ) {
			$t .= ' – ' . wp_date( get_option( 'time_format' ), $e->getTimestamp(), $e->getTimezone() ); }
		return esc_html( $t . self::zone_label( $s ) );
	}

	/** The event's link text: what the organizer typed, else "Tickets & info". */
	public static function link_label( array $e ): string {
		return trim( (string) ( $e['link_label'] ?? '' ) ) !== '' ? trim( (string) $e['link_label'] ) : __( 'Tickets & info', 'beacon-events' );
	}

	/**
	 * "Tickets & info" after a list row when the event (or this date) links out; '' for a cancelled date. The no-break
	 * space before it (and before the venue's "·") keeps a wrapped line from starting with the link or the separator.
	 */
	private static function row_link( array $r ): string {
		$id = (int) $r['event_id'];
		if ( ( $r['status'] ?? '' ) === 'cancelled' ) {
			return ''; }
		$url = Events::link_for( $id, (string) ( $r['orig_ymd'] ?? '' ) );
		if ( $url === '' ) {
			return ''; }
		$label = self::link_label( array( 'link_label' => get_post_meta( $id, Events::META['link_label'], true ) ) );
		return '&nbsp;<a class="beacon-row-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
	}

	/** Start time only (month cells), in the event's zone. Escaped. */
	private static function when_start( array $r ): string {
		$s = self::local( $r['start_utc'], (int) $r['event_id'] );
		return esc_html( wp_date( get_option( 'time_format' ), $s->getTimestamp(), $s->getTimezone() ) . self::zone_label( $s ) );
	}

	/** ' beacon-24h' when the site's time format has no am/pm ("18:00"), so lists can use a narrower time column. */
	private static function clock_class(): string {
		return preg_match( '/(?<!\\\\)[aA]/', (string) get_option( 'time_format' ) ) ? '' : ' beacon-24h';
	}

	/** An event saved without a title still needs something to click. */
	private static function title( array $r ): string {
		return esc_html( trim( (string) $r['post_title'] ) !== '' ? $r['post_title'] : __( '(Untitled event)', 'beacon-events' ) );
	}

	private static function venue( int $id ): string {
		$v = get_the_terms( $id, 'beacon_venue' );
		if ( get_post_meta( $id, Events::META['online'], true ) === '1' ) {
			return $v && ! is_wp_error( $v ) ? esc_html( self::online_prefix( $v[0]->name ) . $v[0]->name ) : esc_html__( 'Online', 'beacon-events' ); }
		return $v && ! is_wp_error( $v ) ? esc_html( $v[0]->name ) : '';
	}

	/**
	 * Plain text for places that are not HTML (.ics files, calendar links, JSON-LD, emails). Titles, venue names and
	 * the site name come out of WordPress HTML-encoded ("Paint &#038; Sip", "Smith &amp; Sons"), which those places
	 * would show literally. Escape the result again wherever it goes into HTML.
	 */
	public static function plain( string $s ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/** Venue name and address as plain text (see plain()). */
	public static function location( int $id ): string {
		$v      = get_the_terms( $id, 'beacon_venue' );
		$online = get_post_meta( $id, Events::META['online'], true ) === '1';
		if ( ! $v || is_wp_error( $v ) ) {
			return $online ? __( 'Online', 'beacon-events' ) : ''; }
		$addr = self::plain( (string) get_term_meta( $v[0]->term_id, 'beacon_address', true ) );
		return ( $online ? self::online_prefix( $v[0]->name ) : '' ) . self::plain( $v[0]->name ) . ( $addr ? ', ' . $addr : '' );
	}

	/** "Online + " before an online event's venue, unless the venue's own name already says so ("Online (Zoom Webinar)"). */
	private static function online_prefix( string $name ): string {
		$word = __( 'Online', 'beacon-events' );
		return false !== stripos( $name, $word ) || false !== stripos( $name, 'online' ) ? '' : $word . ' + ';
	}

	/** The "Where" value: name, address, and a map link — no API key, no embed, nothing loaded from third parties. */
	private static function where( int $id ): string {
		$v   = get_the_terms( $id, 'beacon_venue' );
		$out = '';
		if ( get_post_meta( $id, Events::META['online'], true ) === '1' ) {
			$url = Events::public_join_url( $id );
			$out = esc_html__( 'Online', 'beacon-events' ) . ( $url ? ' <a class="beacon-join" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join online', 'beacon-events' ) . '</a>' : '' )
				. ( Events::join_private( $id ) ? ' <span class="beacon-join-note">' . esc_html__( '(the joining link is sent when you sign up)', 'beacon-events' ) . '</span>' : '' );
			if ( ! $v || is_wp_error( $v ) ) {
				return $out; }
			$out .= '<span class="beacon-address">';
		}
		if ( ! $v || is_wp_error( $v ) ) {
			return ''; }
		$addr = (string) get_term_meta( $v[0]->term_id, 'beacon_address', true );
		$out .= esc_html( $v[0]->name );
		if ( $addr ) {
			$out .= '<span class="beacon-address">' . esc_html( $addr ) . '</span> <a class="beacon-map" href="' . esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $v[0]->name . ', ' . $addr ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Map', 'beacon-events' ) . '</a>'; }
		if ( get_post_meta( $id, Events::META['online'], true ) === '1' ) {
			$out .= '</span>'; }
		return $out;
	}

	/** Upcoming cancelled or rescheduled dates of a recurring event, as one escaped line, or ''. */
	private static function changes_line( int $id ): string {
		$parts = array();
		try {
			$tz = new \DateTimeZone( Events::get( $id )['tz'] );
		} catch ( \Exception $x ) {
			$tz = self::tz(); }
		// the original date is a plain Y-m-d in the event's zone; format it there so it can't slip a day
		$day = fn( string $ymd ) => esc_html( wp_date( get_option( 'date_format' ), ( new \DateTimeImmutable( $ymd . ' 12:00', $tz ) )->getTimestamp(), $tz ) );
		foreach ( Events::occurrences_for_edit( $id, 20 ) as $o ) {
			if ( $o['status'] === 'cancelled' ) {
				$parts[] = sprintf( /* translators: %s: date */ esc_html__( '%s cancelled', 'beacon-events' ), $day( $o['orig'] ) );
			} elseif ( $o['status'] === 'moved' ) {
				$parts[] = sprintf( /* translators: 1: original date, 2: new date and time */ esc_html__( '%1$s moved to %2$s', 'beacon-events' ), $day( $o['orig'] ), esc_html( $o['label'] ) ); }
			if ( count( $parts ) >= 5 ) {
				break; }
		}
		return implode( ' <span class="beacon-sep">·</span> ', $parts );
	}

	/** "Add to Google Calendar" URL for an event: first occurrence in UTC, plus the repeat rule when there is one. */
	public static function google_url( int $id ): string {
		$e = Events::get( $id );
		if ( ! $e['start'] ) {
			return ''; }
		try {
			$tz = new \DateTimeZone( $e['tz'] );
		} catch ( \Exception $x ) {
			$tz = self::tz(); }
		$s   = new \DateTimeImmutable( $e['start'], $tz );
		$en  = $e['end'] ? new \DateTimeImmutable( $e['end'], $tz ) : $s->modify( '+1 hour' );
		$utc   = new \DateTimeZone( 'UTC' );
		$named = false !== strpos( $tz->getName(), '/' ) || 'UTC' === $tz->getName(); // "+02:00" sites have no zone name Google knows
		// Timed events go over in their own wall-clock time with ctz, so a weekly rule's days are counted in that zone:
		// sent as UTC ("…Z"), 7 pm Tuesday in Chicago is Wednesday 00:00 and "every Tuesday" landed on Mondays (0.18.3 and earlier).
		if ( $e['all_day'] ) {
			$dt = $s->format( 'Ymd' ) . '/' . $en->modify( '+1 day' )->format( 'Ymd' );
		} elseif ( $named ) {
			$dt = $s->format( 'Ymd\THis' ) . '/' . $en->format( 'Ymd\THis' );
		} else {
			$dt = $s->setTimezone( $utc )->format( 'Ymd\THis\Z' ) . '/' . $en->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		}
		$q = array(
			'action'   => 'TEMPLATE',
			'text'     => self::plain( get_the_title( $id ) ),
			'dates'    => $dt,
			'ctz'      => ! $e['all_day'] && $named ? $tz->getName() : '',
			'details'  => get_permalink( $id ),
			'location' => self::location( $id ),
		);
		if ( $e['rrule'] ) {
			$rule = $e['rrule'];
			// a timed series needs UNTIL as a UTC moment: the end of that local day
			if ( ! $e['all_day'] && preg_match( '/UNTIL=(\d{8})(?=;|$)/', $rule, $u ) ) {
				$end  = ( new \DateTimeImmutable( $u[1] . ' 23:59:59', $tz ) )->setTimezone( $utc )->format( 'Ymd\THis\Z' );
				$rule = str_replace( 'UNTIL=' . $u[1], 'UNTIL=' . $end, $rule );
			}
			$q['recur'] = 'RRULE:' . $rule;
		}
		return 'https://calendar.google.com/calendar/render?' . http_build_query( array_filter( $q ), '', '&', PHP_QUERY_RFC3986 );
	}

	/** Upcoming list, grouped by day — or, with layout="compact", one line per event (sidebars, footers). */
	public static function list( array $a ): string {
		$now  = new \DateTimeImmutable( 'today', self::tz() );
		$rows = self::fetch( self::utc( $now ), self::utc( $now->modify( '+' . max( 1, (int) $a['days'] ) . ' days' ) ), (int) $a['limit'], $a );
		if ( ! $rows ) {
			return '<div class="beacon-list beacon-empty">' . esc_html__( 'No upcoming events.', 'beacon-events' ) . '</div>'; }
		if ( ( $a['layout'] ?? '' ) === 'compact' ) {
			$out = '<ul class="beacon-list beacon-compact">';
			foreach ( $rows as $r ) {
				$id   = (int) $r['event_id'];
				$s    = self::local( $r['start_utc'], $id );
				$out .= '<li class="beacon-event' . self::row_class( $r ) . '"' . self::color_attr( $id ) . '><span class="beacon-date">' . esc_html( wp_date( 'D, M j', $s->getTimestamp(), $s->getTimezone() ) ) . '</span> <span class="beacon-time">' . self::when( $r ) . '</span> <span class="beacon-body"><a class="beacon-title" href="' . esc_url( get_permalink( $id ) ) . '">' . self::title( $r ) . '</a>' . self::badge( $r ) . '</span></li>';
			}
			return $out . '</ul>';
		}
		// Group by each event's own date; rows come in UTC order, so sort by that date first (events in other zones).
		usort( $rows, fn( $x, $y ) => array( self::local( $x['start_utc'], (int) $x['event_id'] )->format( 'Y-m-d' ), $x['start_utc'] ) <=> array( self::local( $y['start_utc'], (int) $y['event_id'] )->format( 'Y-m-d' ), $y['start_utc'] ) );
		$out = '<div class="beacon-list' . self::clock_class() . '">';
		$day = '';
		foreach ( $rows as $r ) {
			$s = self::local( $r['start_utc'], (int) $r['event_id'] );
			$d = $s->format( 'Y-m-d' );
			if ( $d !== $day ) {
				if ( $day ) {
					$out .= '</ul></section>';
				} $day = $d;
				$out  .= '<section class="beacon-day"><h3 class="beacon-day-heading">' . esc_html( wp_date( 'l, F j', $s->getTimestamp(), $s->getTimezone() ) ) . '</h3><ul class="beacon-day-events">'; }
			$id    = (int) $r['event_id'];
			$venue = self::venue( $id );
			$out  .= '<li class="beacon-event' . self::row_class( $r ) . '"' . self::color_attr( $id ) . '><span class="beacon-time">' . self::when( $r ) . '</span> <span class="beacon-body"><a class="beacon-title" href="' . esc_url( get_permalink( $id ) ) . '">' . self::title( $r ) . '</a>' . self::badge( $r ) . ( $venue ? '&nbsp;<span class="beacon-venue">' . $venue . '</span>' : '' ) . self::row_link( $r ) . '</span></li>';
		}
		return $out . '</ul></section></div>';
	}

	/** Query args that only re-slice the calendar page: every date and filter combination would otherwise be a new URL for crawlers. */
	const NAV_ARGS = array( 'beacon_day', 'beacon_week', 'beacon_month', 'bf_cat', 'bf_venue', 'bf_q', 'bf_view', 'beacon_print' );

	/** Calendar navigation and filter pages: noindex, but keep following links to the events themselves. */
	public static function robots_meta( array $robots ): array {
		foreach ( self::NAV_ARGS as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check.
				$robots['noindex'] = true;
				$robots['follow']  = true;
				break;
			}
		}
		return $robots;
	}

	/** Adds Disallow lines for the navigation args to the robots.txt WordPress generates (not to a physical file). Filter beacon_robots_txt to false to skip. */
	public static function robots_txt( $output, $is_public ) {
		if ( ! $is_public || ! apply_filters( 'beacon_robots_txt', true ) ) {
			return $output;
		}
		$lines = '';
		foreach ( self::NAV_ARGS as $arg ) {
			$lines .= 'Disallow: /*?*' . $arg . "=\n";
		}
		$group = "User-agent: *\n";
		$pos   = strpos( (string) $output, $group );
		if ( false !== $pos ) {
			$at = $pos + strlen( $group );
			return substr( $output, 0, $at ) . $lines . substr( $output, $at );
		}
		return rtrim( (string) $output ) . "\n\n" . $group . $lines;
	}

	/** One day. ?beacon_day=YYYY-MM-DD navigates; reached from a month grid's day numbers or view="day". */
	public static function day( array $a ): string {
		$req   = (string) ( $a['day'] ?? '' );
		$start = self::valid_date( $req ) ? new \DateTimeImmutable( $req, self::tz() ) : new \DateTimeImmutable( 'today', self::tz() );
		$start = $start->setTime( 0, 0 );
		$end   = $start->modify( '+1 day' );
		$rows  = self::in_days( self::fetch( self::utc( $start->modify( '-1 day' ) ), self::utc( $end->modify( '+1 day' ) ), 1000, $a ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
		$nav   = fn( $d ) => esc_url( add_query_arg( 'beacon_day', $d->format( 'Y-m-d' ) ) );
		$out   = '<div class="beacon-dayview' . self::clock_class() . '"><header class="beacon-month-nav"><a class="beacon-prev" rel="nofollow" href="' . $nav( $start->modify( '-1 day' ) ) . '" aria-label="' . esc_attr__( 'Previous day', 'beacon-events' ) . '">‹</a><h3 class="beacon-month-title">' . esc_html( wp_date( 'l, F j, Y', $start->getTimestamp() ) ) . '</h3><a class="beacon-next" rel="nofollow" href="' . $nav( $end ) . '" aria-label="' . esc_attr__( 'Next day', 'beacon-events' ) . '">›</a></header>';
		if ( ! empty( $a['from_month'] ) ) {
			$out .= '<p class="beacon-back"><a rel="nofollow" href="' . esc_url( add_query_arg( 'beacon_month', $start->format( 'Y-m' ), remove_query_arg( 'beacon_day' ) ) ) . '">' . esc_html__( '← Back to month', 'beacon-events' ) . '</a></p>'; }
		if ( ! $rows ) {
			return $out . '<p class="beacon-empty">' . esc_html__( 'No events on this day.', 'beacon-events' ) . '</p></div>'; }
		$out .= '<ul class="beacon-day-events">';
		foreach ( $rows as $r ) {
			$id    = (int) $r['event_id'];
			$venue = self::venue( $id );
			$out  .= '<li class="beacon-event' . self::row_class( $r ) . '"' . self::color_attr( $id ) . '><span class="beacon-time">' . self::when( $r ) . '</span> <span class="beacon-body"><a class="beacon-title" href="' . esc_url( get_permalink( $id ) ) . '">' . self::title( $r ) . '</a>' . self::badge( $r ) . ( $venue ? '&nbsp;<span class="beacon-venue">' . $venue . '</span>' : '' ) . '</span></li>';
		}
		return $out . '</ul></div>';
	}

	/**
	 * One week as a timetable: a column per day (from the site's "Week starts on"), each day's events in time order,
	 * all-day ones first. ?beacon_week=YYYY-MM-DD (any day of that week) navigates; no JS required.
	 */
	public static function week( array $a ): string {
		$req       = (string) ( $a['week'] ?? '' );
		$day       = self::valid_date( $req ) ? new \DateTimeImmutable( $req, self::tz() ) : new \DateTimeImmutable( 'today', self::tz() );
		$day       = $day->setTime( 0, 0 );
		$start_dow = (int) get_option( 'start_of_week', 0 );
		$start     = $day->modify( '-' . ( ( (int) $day->format( 'w' ) - $start_dow + 7 ) % 7 ) . ' days' );
		$end       = $start->modify( '+7 days' );
		$rows      = self::in_days( self::fetch( self::utc( $start->modify( '-1 day' ) ), self::utc( $end->modify( '+1 day' ) ), 2000, $a ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
		$by        = array();
		foreach ( $rows as $r ) {
			$by[ self::local( $r['start_utc'], (int) $r['event_id'] )->format( 'Y-m-d' ) ][] = $r; }
		$last  = $end->modify( '-1 day' );
		$title = $start->format( 'Y' ) === $last->format( 'Y' )
			? wp_date( 'M j', $start->getTimestamp() ) . ' – ' . wp_date( 'M j, Y', $last->getTimestamp() )
			: wp_date( 'M j, Y', $start->getTimestamp() ) . ' – ' . wp_date( 'M j, Y', $last->getTimestamp() );
		$nav   = fn( $d ) => esc_url( add_query_arg( 'beacon_week', $d->format( 'Y-m-d' ) ) );
		$today = ( new \DateTimeImmutable( 'today', self::tz() ) )->format( 'Y-m-d' );
		$out   = '<div class="beacon-week"><header class="beacon-month-nav"><a class="beacon-prev" rel="nofollow" href="' . $nav( $start->modify( '-7 days' ) ) . '" aria-label="' . esc_attr__( 'Previous week', 'beacon-events' ) . '">‹</a><h3 class="beacon-month-title">' . esc_html( $title ) . '</h3><a class="beacon-next" rel="nofollow" href="' . $nav( $end ) . '" aria-label="' . esc_attr__( 'Next week', 'beacon-events' ) . '">›</a></header>';
		if ( $today < $start->format( 'Y-m-d' ) || $today >= $end->format( 'Y-m-d' ) ) {
			$out .= '<p class="beacon-back"><a rel="nofollow" href="' . esc_url( remove_query_arg( 'beacon_week' ) ) . '">' . esc_html__( 'This week', 'beacon-events' ) . '</a></p>'; }
		if ( ! $rows ) {
			$out .= '<p class="beacon-empty">' . esc_html__( 'No events this week.', 'beacon-events' ) . '</p>'; }
		$out .= '<div class="beacon-week-grid">';
		for ( $i = 0; $i < 7; $i++ ) {
			$d    = $start->modify( "+$i days" );
			$ymd  = $d->format( 'Y-m-d' );
			$list = $by[ $ymd ] ?? array();
			usort( $list, fn( $x, $y ) => array( -(int) $x['all_day'], $x['start_utc'] ) <=> array( -(int) $y['all_day'], $y['start_utc'] ) );
			$out .= '<section class="beacon-week-day' . ( $ymd === $today ? ' beacon-today' : '' ) . ( $list ? '' : ' beacon-week-empty' ) . '"><h4 class="beacon-week-heading"><span class="beacon-week-dow">' . esc_html( wp_date( 'D', $d->getTimestamp() ) ) . '</span> <span class="beacon-week-date">' . esc_html( wp_date( 'M j', $d->getTimestamp() ) ) . '</span></h4>';
			if ( ! $list ) {
				$out .= '<p class="beacon-week-none">' . esc_html__( 'No events', 'beacon-events' ) . '</p></section>';
				continue;
			}
			$out .= '<ul class="beacon-day-events">';
			foreach ( $list as $r ) {
				$id    = (int) $r['event_id'];
				$venue = self::venue( $id );
				$time  = (int) $r['all_day'] ? esc_html__( 'All day', 'beacon-events' ) : self::when_start( $r ); // the full range is on the event page
				$out  .= '<li class="beacon-event' . self::row_class( $r ) . '"' . self::color_attr( $id ) . '><span class="beacon-time">' . $time . '</span> <span class="beacon-body"><a class="beacon-title" href="' . esc_url( get_permalink( $id ) ) . '">' . self::title( $r ) . '</a>' . self::badge( $r ) . ( $venue ? '&nbsp;<span class="beacon-venue">' . $venue . '</span>' : '' ) . '</span></li>';
			}
			$out .= '</ul></section>';
		}
		return $out . '</div></div>';
	}

	/** The first day of the month a month view shows: ?beacon_month=YYYY-MM, else the block's month, else this month. */
	private static function month_first( array $a ): \DateTimeImmutable {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only month navigation; no state changes.
		$req = isset( $_GET['beacon_month'] ) ? sanitize_text_field( wp_unslash( $_GET['beacon_month'] ) ) : (string) ( $a['month'] ?? '' );
		// phpcs:enable
		$first = self::valid_date( $req, true ) ? new \DateTimeImmutable( $req . '-01', self::tz() ) : new \DateTimeImmutable( 'first day of this month', self::tz() );
		return $first->setTime( 0, 0 );
	}

	/** The month's dates that pass the view's filters, each on its event's own local day. */
	private static function month_rows( array $a, \DateTimeImmutable $first ): array {
		$next = $first->modify( '+1 month' );
		return self::in_days( self::fetch( self::utc( $first->modify( '-1 day' ) ), self::utc( $next->modify( '+1 day' ) ), 5000, $a ), $first->format( 'Y-m-d' ), $next->format( 'Y-m-d' ) );
	}

	/** Whether a month view shows its "Print this month" link: block toggle, shortcode print="no", filter beacon_print_link. */
	private static function print_link_on( array $a ): bool {
		$on = ! ( array_key_exists( 'printLink', $a ) && ! $a['printLink'] )
			&& ! in_array( strtolower( (string) ( $a['print'] ?? '' ) ), array( 'no', '0', 'false', 'off' ), true );
		return (bool) apply_filters( 'beacon_print_link', $on, $a );
	}

	/** Month grid. ?beacon_month=YYYY-MM navigates; no JS required. */
	public static function month( array $a ): string {
		$first = self::month_first( $a );
		$next  = $first->modify( '+1 month' );
		$prev  = $first->modify( '-1 month' );
		$rows  = self::month_rows( $a, $first );
		$by    = array();
		foreach ( $rows as $r ) {
			$by[ self::local( $r['start_utc'], (int) $r['event_id'] )->format( 'Y-m-d' ) ][] = $r; }
		$start_dow  = (int) get_option( 'start_of_week', 0 );
		$grid_start = $first->modify( '-' . ( ( (int) $first->format( 'w' ) - $start_dow + 7 ) % 7 ) . ' days' );
		$nav        = fn( $d ) => esc_url( add_query_arg( 'beacon_month', $d->format( 'Y-m' ) ) );
		$out        = '<div class="beacon-month"><header class="beacon-month-nav"><a class="beacon-prev" rel="nofollow" href="' . $nav( $prev ) . '" aria-label="' . esc_attr__( 'Previous month', 'beacon-events' ) . '">‹</a><h3 class="beacon-month-title">' . esc_html( wp_date( 'F Y', $first->getTimestamp() ) ) . '</h3><a class="beacon-next" rel="nofollow" href="' . $nav( $next ) . '" aria-label="' . esc_attr__( 'Next month', 'beacon-events' ) . '">›</a></header>';
		$out       .= '<table class="beacon-grid"><thead><tr>';
		for ( $i = 0; $i < 7; $i++ ) {
			$out .= '<th scope="col">' . esc_html( wp_date( 'D', $grid_start->modify( "+$i days" )->getTimestamp() ) ) . '</th>'; }
		$out  .= '</tr></thead><tbody>';
		$d     = $grid_start;
		$today = ( new \DateTimeImmutable( 'today', self::tz() ) )->format( 'Y-m-d' );
		for ( $w = 0; $w < 6; $w++ ) {
			if ( $w >= 4 && $d >= $next ) {
				break; }
			$out .= '<tr>';
			for ( $i = 0; $i < 7; $i++ ) {
				$ymd  = $d->format( 'Y-m-d' );
				$cls  = 'beacon-cell' . ( $d < $first || $d >= $next ? ' beacon-other-month' : '' ) . ( $ymd === $today ? ' beacon-today' : '' );
				// the full date as the link's name: a screen reader otherwise hears "31, link" with no month
				$out .= '<td class="' . $cls . '"><a class="beacon-daynum" rel="nofollow" href="' . esc_url( add_query_arg( 'beacon_day', $ymd ) ) . '" aria-label="' . esc_attr( wp_date( (string) get_option( 'date_format' ), $d->getTimestamp(), $d->getTimezone() ) ) . '"' . ( $ymd === $today ? ' aria-current="date"' : '' ) . '>' . esc_html( $d->format( 'j' ) ) . '</a>';
				foreach ( $by[ $ymd ] ?? array() as $r ) {
					$time  = (int) $r['all_day'] ? '' : '<span class="beacon-cell-time">' . self::when_start( $r ) . '</span>';
					$title = '<span class="beacon-cell-title">' . self::title( $r ) . '</span>';
					// on paper the time leads, inline, so a wrapped title uses the cell's full width
					$out .= '<a class="beacon-cell-event' . self::row_class( $r ) . '"' . self::color_attr( (int) $r['event_id'] ) . ' href="' . esc_url( get_permalink( (int) $r['event_id'] ) ) . '">' . ( empty( $a['printing'] ) ? $title . $time : ( $time ? $time . ' ' : '' ) . $title ) . self::badge( $r ) . '</a>'; }
				$out .= '</td>';
				$d    = $d->modify( '+1 day' );
			}
			$out .= '</tr>';
		}
		$out .= '</tbody></table>';
		if ( self::print_link_on( $a ) ) {
			// the print page rebuilds this view from the URL alone, so the block's own filters travel with the link
			$args = array_filter(
				array(
					'beacon_print' => 'month',
					'beacon_month' => $first->format( 'Y-m' ),
					'bp_cat'       => (string) ( $a['category'] ?? '' ),
					'bp_venue'     => (string) ( $a['venue'] ?? '' ),
					'bp_org'       => (string) ( $a['organizer'] ?? '' ),
					'bp_q'         => (string) ( $a['q'] ?? '' ),
				),
				'strlen'
			);
			$out .= '<p class="beacon-month-tools"><a class="beacon-print-link" rel="nofollow" href="' . esc_url( add_query_arg( array_map( 'rawurlencode', $args ), remove_query_arg( array( 'beacon_day', 'beacon_week' ) ) ) ) . '">' . esc_html__( 'Print this month', 'beacon-events' ) . '</a></p>';
		}
		return $out . '</div>';
	}

	const PRINT_ARGS = array( 'beacon_print', 'bp_cat', 'bp_venue', 'bp_org', 'bp_q' );

	/**
	 * ?beacon_print=month: the month grid as its own page, without the theme's header, menus or sidebars, laid out for
	 * one landscape sheet with the category colours kept and a key to them. Read-only, published events only.
	 */
	public static function print_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page of public events; nothing changes state
		$g = fn( string $k ) => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		// phpcs:enable
		if ( 'month' !== $g( 'beacon_print' ) || is_404() ) {
			return;
		}
		$slugs = fn( string $csv ) => implode( ',', array_filter( array_map( 'sanitize_title', explode( ',', $csv ) ) ) );
		$a     = array(
			'view'      => 'month',
			'category'  => $slugs( $g( 'bp_cat' ) ),
			'venue'     => $slugs( $g( 'bp_venue' ) ),
			'organizer' => $slugs( $g( 'bp_org' ) ),
			'q'         => $g( 'bp_q' ),
			'print'     => 'no',
			'printing'  => true,
		);
		$first = self::month_first( $a );
		$site  = self::plain( get_bloginfo( 'name' ) );
		$month = wp_date( 'F Y', $first->getTimestamp() );
		$back  = remove_query_arg( self::PRINT_ARGS );
		$obj   = get_queried_object(); // the calendar's own address for the foot of the sheet
		$page  = $obj instanceof \WP_Post ? get_permalink( $obj ) : ( $obj instanceof \WP_Term ? get_term_link( $obj ) : home_url( '/' ) );
		$page  = is_string( $page ) ? $page : home_url( '/' );
		// what the sheet is filtered to, by name: "Yoga · Main Hall"
		$names = array();
		foreach ( self::TAX_ATTR as $tax => $attr ) {
			foreach ( array_filter( explode( ',', $a[ $attr ] ) ) as $slug ) {
				$t = get_term_by( 'slug', $slug, $tax );
				if ( $t ) {
					$names[] = self::plain( $t->name ); }
			}
		}
		if ( '' !== $a['q'] ) {
			/* translators: %s: the search words the calendar was filtered by */
			$names[] = sprintf( __( 'matching "%s"', 'beacon-events' ), $a['q'] );
		}
		// a key to the colours on this sheet: each event's first coloured category, as the grid colours it
		$key = array();
		foreach ( self::month_rows( $a, $first ) as $r ) {
			$terms = get_the_terms( (int) $r['event_id'], 'beacon_category' );
			foreach ( ( $terms && ! is_wp_error( $terms ) ) ? $terms : array() as $t ) {
				$c = sanitize_hex_color( (string) get_term_meta( $t->term_id, 'beacon_color', true ) );
				if ( $c ) {
					$key[ $t->term_id ] = array( self::plain( $t->name ), $c );
					break;
				}
			}
		}
		self::register_assets();
		wp_add_inline_style( 'beacon-events', self::PRINT_CSS );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo '<!doctype html><html ';
		language_attributes();
		echo '><head><meta charset="' . esc_attr( get_option( 'blog_charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . esc_html( $month . ' – ' . $site ) . '</title>';
		wp_print_styles( 'beacon-events' );
		echo '</head><body class="beacon-print-page"><p class="beacon-print-bar"><button type="button" onclick="window.print()">' . esc_html__( 'Print', 'beacon-events' ) . '</button> <a href="' . esc_url( $back ) . '">' . esc_html__( '← Back to the calendar', 'beacon-events' ) . '</a></p>';
		echo '<p class="beacon-print-site">' . esc_html( $site ) . ( $names ? ' · ' . esc_html( implode( ' · ', $names ) ) : '' ) . '</p>';
		echo self::month( $a ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- month() escapes every value it puts in its markup, as for the block.
		if ( count( $key ) > 1 ) {
			echo '<ul class="beacon-print-key">';
			foreach ( $key as $k ) {
				echo '<li style="--beacon-color:' . esc_attr( $k[1] ) . '">' . esc_html( $k[0] ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p class="beacon-print-foot">' . esc_html( preg_replace( '#^https?://#', '', $page ) ) . '</p></body></html>';
		exit;
	}

	/** Styles for the print page only (added inline there, so beacon.css stays as small as it is). */
	const PRINT_CSS = 'body.beacon-print-page{margin:0;padding:16px 20px;background:#fff;color:#111;font:13px/1.35 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
		. '.beacon-print-bar{display:flex;gap:14px;align-items:center;margin:0 0 14px;font-size:14px}.beacon-print-bar button{font:inherit;padding:6px 16px;cursor:pointer}'
		. '.beacon-print-site{margin:0 0 2px;color:#444}.beacon-print-page .beacon-month-title{font-size:22px}.beacon-print-page a{color:inherit;text-decoration:none}'
		. '.beacon-print-page .beacon-grid th{font-size:12px;padding:3px 4px;border-bottom:1px solid #888}'
		. '.beacon-print-page .beacon-cell{height:6.3em;padding:2px 4px;border:1px solid #aaa;font-size:11px}.beacon-print-page .beacon-daynum{font-weight:700;pointer-events:none}'
		. '.beacon-print-page .beacon-cell-event{display:block;margin:1px 0 2px;font-size:11px;line-height:1.2}'
		. '.beacon-print-page .beacon-cell-event:not([style*="--beacon-color"]){border-left:3px solid transparent;padding-left:.3em}'
		. '.beacon-print-page .beacon-cell-title{display:inline;overflow:visible}.beacon-print-page .beacon-cell-time{display:inline;font-size:inherit;font-weight:600;opacity:1;white-space:nowrap}'
		. '.beacon-print-page .beacon-badge{font-size:9px;padding:0 3px;margin-left:3px;border-radius:3px;vertical-align:baseline}'
		. '.beacon-print-key{display:flex;flex-wrap:wrap;gap:4px 16px;margin:8px 0 0;padding:0;list-style:none;font-size:12px}'
		. '.beacon-print-key li::before{content:"";display:inline-block;width:10px;height:10px;margin-right:5px;vertical-align:-1px;background:var(--beacon-color)}'
		. '.beacon-print-foot{margin:6px 0 0;font-size:11px;color:#555}'
		. '@page{size:landscape;margin:8mm}'
		. '@media print{body.beacon-print-page{padding:0}.beacon-print-bar,.beacon-print-page .beacon-prev,.beacon-print-page .beacon-next{display:none}.beacon-print-page .beacon-month-title{font-size:18px}.beacon-print-page .beacon-cell{height:4.6em}.beacon-print-page *{-webkit-print-color-adjust:exact;print-color-adjust:exact}.beacon-print-page .beacon-grid tr{break-inside:avoid}}';

	/** On a single event page, prepend when/where — theme-agnostic, no template override needed. */
	public static function single_details( string $content ): string {
		if ( ! self::claim( 'details' ) ) {
			return $content; }
		return self::details_block( get_the_ID() ) . $content;
	}

	/**
	 * Should the event page's own content get a Beacon part ($what: 'details', or Pro's 'rsvp') here? Classic and block
	 * themes print it inside the main loop. Theme builders (Greyd, Elementor or Divi theme templates…) print it OUTSIDE the
	 * loop, which used to drop the When/Where box and Pro's booking form without a word (first Pro trial site, 2026-10-18).
	 * Outside the loop it is allowed for the event the page is about, after <head>, once per page; never in excerpts.
	 * A shortcode that already printed the part claims it first, so the filter doesn't add a second copy.
	 */
	public static function claim( string $what, int $id = 0, bool $force = false ): bool {
		static $done = array();
		if ( ! $force && ( ! is_singular( Events::CPT ) || is_admin() || doing_action( 'wp_head' ) || doing_filter( 'get_the_excerpt' ) ) ) {
			return false; }
		$id = $id ? $id : (int) get_the_ID();
		if ( ! $id || ! empty( $done[ $what ][ $id ] ) ) {
			return false; }
		if ( ! $force && ! ( in_the_loop() && is_main_query() ) && ( (int) get_queried_object_id() !== $id || ! did_action( 'wp_head' ) ) ) {
			return false; }
		$done[ $what ][ $id ] = true;
		return true;
	}

	/** [beacon_event_details id=""]: the When / Repeats / Where box, for theme templates that skip the page content. */
	public static function details_shortcode( $atts ): string {
		$a  = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'beacon_event_details' );
		$id = (int) $a['id'] ? (int) $a['id'] : ( is_singular( Events::CPT ) ? (int) get_queried_object_id() : (int) get_the_ID() );
		if ( ! $id || Events::CPT !== get_post_type( $id ) ) {
			return ''; }
		self::claim( 'details', $id, true );
		return self::details_block( $id );
	}

	/** The When / Repeats / Where block, or '' if the event has no start. */
	public static function details_block( int $id ): string {
		$e = Events::get( $id );
		if ( ! $e['start'] ) {
			return ''; }
		try {
			$tz = new \DateTimeZone( $e['tz'] );
		} catch ( \Exception $x ) {
			$tz = self::tz(); }
		$s  = new \DateTimeImmutable( $e['start'], $tz );
		$en = $e['end'] ? new \DateTimeImmutable( $e['end'], $tz ) : null;
		if ( $e['rrule'] ) {
			// A series shows its next date (someone looking in October wants the next class, not September's first one);
			// the Repeats row says how it goes on. A finished series keeps its first date.
			try {
				$otz = new \DateTimeZone( $e['tz'] );
			} catch ( \Exception $x ) {
				$otz = new \DateTimeZone( 'UTC' ); }
			foreach ( Events::occurrences_for_edit( $id, 20 ) as $o ) {
				if ( $o['status'] !== 'cancelled' ) {
					$s  = ( new \DateTimeImmutable( $o['start'], $otz ) )->setTimezone( $tz );
					$en = $en ? ( new \DateTimeImmutable( $o['end'], $otz ) )->setTimezone( $tz ) : null;
					break; }
			}
		}
		$when = $e['all_day'] ? wp_date( get_option( 'date_format' ), $s->getTimestamp(), $tz ) . ( $en && $en->format( 'Y-m-d' ) !== $s->format( 'Y-m-d' ) ? ' – ' . wp_date( get_option( 'date_format' ), $en->getTimestamp(), $tz ) : '' ) : wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $s->getTimestamp(), $tz ) . ( $en ? ' – ' . wp_date( $en->format( 'Y-m-d' ) === $s->format( 'Y-m-d' ) ? get_option( 'time_format' ) : get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $en->getTimestamp(), $tz ) : '' ) . self::zone_label( $s );
		$rows = '<div class="beacon-detail"><span class="beacon-detail-label">' . esc_html__( 'When', 'beacon-events' ) . '</span> <span class="beacon-detail-value">' . esc_html( $when ) . '</span></div>';
		if ( $e['rrule'] ) {
			$rows .= '<div class="beacon-detail"><span class="beacon-detail-label">' . esc_html__( 'Repeats', 'beacon-events' ) . '</span> <span class="beacon-detail-value">' . esc_html( Recurrence::describe( $e['rrule'] ) ) . '</span></div>'; }
		$changes = $e['rrule'] ? self::changes_line( $id ) : '';
		if ( $changes ) {
			$rows .= '<div class="beacon-detail beacon-changes"><span class="beacon-detail-label">' . esc_html__( 'Changes', 'beacon-events' ) . '</span> <span class="beacon-detail-value">' . $changes . '</span></div>'; }
		$where = self::where( $id );
		if ( $where ) {
			$rows .= '<div class="beacon-detail"><span class="beacon-detail-label">' . esc_html__( 'Where', 'beacon-events' ) . '</span> <span class="beacon-detail-value">' . $where . '</span></div>'; }
		if ( Events::has_date_links( $id ) ) {
			// Dates with a registration page each (Blackbaud, Altru, Eventbrite sessions): one button can't serve them all,
			// so the next dates are listed, each with its own link.
			$items = array();
			foreach ( Events::occurrences_for_edit( $id, 8 ) as $o ) {
				if ( $o['status'] === 'cancelled' ) {
					continue; }
				$url     = Events::link_for( $id, $o['orig'] );
				$items[] = '<li><span class="beacon-date">' . esc_html( $o['label'] ) . '</span>' . ( $url ? ' <a class="beacon-row-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( self::link_label( $e ) ) . '</a>' : '' ) . '</li>';
			}
			if ( $items ) {
				$rows .= '<div class="beacon-detail beacon-dates" id="beacon-dates"><span class="beacon-detail-label">' . esc_html__( 'Dates', 'beacon-events' ) . '</span> <ul class="beacon-detail-value">' . implode( '', $items ) . '</ul></div>'; }
		} elseif ( $e['link'] ) {
			$rows .= '<div class="beacon-detail beacon-link-row"><a class="beacon-link wp-element-button" href="' . esc_url( $e['link'] ) . '" target="_blank" rel="noopener">' . esc_html( self::link_label( $e ) ) . '</a></div>'; }
		$rows .= '<div class="beacon-detail beacon-add"><a class="beacon-ical" href="' . esc_url( add_query_arg( array( 'beacon_ical' => $id ), home_url( '/' ) ) ) . '">' . esc_html__( 'Add to calendar (.ics)', 'beacon-events' ) . '</a> <a class="beacon-google" href="' . esc_url( self::google_url( $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Add to Google Calendar', 'beacon-events' ) . '</a></div>';
		wp_enqueue_style( 'beacon-events' );
		return '<div class="beacon-single">' . $rows . '</div>';
	}

	/** Header of a venue / organizer / category page: address + map, description, subscribe link. Empty elsewhere. */
	public static function term_details(): string {
		$t = get_queried_object();
		if ( ! $t instanceof \WP_Term || ! isset( self::TAX_ATTR[ $t->taxonomy ] ) ) {
			return ''; }
		wp_enqueue_style( 'beacon-events' );
		$out = '';
		if ( $t->taxonomy === 'beacon_venue' ) {
			$addr = (string) get_term_meta( $t->term_id, 'beacon_address', true );
			if ( $addr ) {
				$out .= '<p class="beacon-term-address">' . esc_html( $addr ) . ' <a class="beacon-map" href="' . esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $t->name . ', ' . $addr ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Map', 'beacon-events' ) . '</a></p>'; }
		}
		if ( $t->description ) {
			$out .= '<div class="beacon-term-description">' . wp_kses_post( wpautop( $t->description ) ) . '</div>'; }
		$out .= '<p class="beacon-term-subscribe"><a class="beacon-ical" href="' . esc_url( self::ical_url( $t ) ) . '">' . esc_html__( 'Subscribe to these events (.ics)', 'beacon-events' ) . '</a></p>';
		return '<div class="beacon-term-details">' . $out . '</div>';
	}

	/** Calendar-app feed for everything, or for one venue / organizer / category. */
	public static function ical_url( ?\WP_Term $t = null ): string {
		$args = array( 'beacon_ical' => 'all' );
		if ( $t && isset( self::TAX_ATTR[ $t->taxonomy ] ) ) {
			$args[ 'beacon_' . self::TAX_ATTR[ $t->taxonomy ] ] = $t->slug; }
		return add_query_arg( $args, home_url( '/' ) );
	}

	/** Classic themes print the term description on archives; give venues their address there too. */
	public static function archive_description( $description ) {
		$t = get_queried_object();
		if ( wp_is_block_theme() || ! $t instanceof \WP_Term || ! isset( self::TAX_ATTR[ $t->taxonomy ] ) ) {
			return $description; }
		$extra = '';
		if ( $t->taxonomy === 'beacon_venue' ) {
			$addr = (string) get_term_meta( $t->term_id, 'beacon_address', true );
			if ( $addr ) {
				$extra .= '<p class="beacon-term-address">' . esc_html( $addr ) . ' <a class="beacon-map" href="' . esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $t->name . ', ' . $addr ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Map', 'beacon-events' ) . '</a></p>'; }
		}
		$extra .= '<p class="beacon-term-subscribe"><a class="beacon-ical" href="' . esc_url( self::ical_url( $t ) ) . '">' . esc_html__( 'Subscribe to these events (.ics)', 'beacon-events' ) . '</a></p>';
		return $description . $extra;
	}

	/**
	 * schema.org Event markup on the event's own page, one object per upcoming occurrence (Google wants each date as
	 * its own Event). Filter `beacon_schema` to change it or return an empty array to turn it off.
	 */
	public static function schema(): void {
		if ( ! is_singular( Events::CPT ) ) {
			return; }
		$id = get_queried_object_id();
		$e  = Events::get( $id );
		if ( ! $e['start'] ) {
			return; }
		try {
			$tz = new \DateTimeZone( $e['tz'] );
		} catch ( \Exception $x ) {
			$tz = self::tz(); }
		$occ = Events::occurrences_for_edit( $id, 10 );
		if ( ! $occ ) {   // nothing upcoming: describe the (past or unpublished) base date once
			$occ = array(
				array(
					'orig'   => substr( $e['start'], 0, 10 ),
					'start'  => $e['start'],
					'end'    => $e['end'],
					'status' => 'scheduled',
				),
			); }
		$post   = get_post( $id );
		$desc   = self::plain( $post->post_excerpt ?: strip_shortcodes( $post->post_content ) );
		$desc   = trim( preg_replace( '/\s+/', ' ', $desc ) );
		$desc   = mb_substr( $desc, 0, 300 );
		$venue  = get_the_terms( $id, 'beacon_venue' );
		$venue  = $venue && ! is_wp_error( $venue ) ? $venue[0] : null;
		$org    = get_the_terms( $id, 'beacon_organizer' );
		$org    = $org && ! is_wp_error( $org ) ? $org[0] : null;
		$image  = get_the_post_thumbnail_url( $id, 'large' );
		$mode   = $e['online'] ? ( $venue ? 'https://schema.org/MixedEventAttendanceMode' : 'https://schema.org/OnlineEventAttendanceMode' ) : 'https://schema.org/OfflineEventAttendanceMode';
		$places = array();
		if ( $e['online'] ) {
			$places[] = array_filter(
				array(
					'@type' => 'VirtualLocation',
					'url'   => Events::public_join_url( $id ) ?: get_permalink( $id ),
				)
			); }
		if ( $venue ) {
			$places[] = array_filter(
				array(
					'@type'   => 'Place',
					'name'    => self::plain( $venue->name ),
					'address' => self::plain( (string) get_term_meta( $venue->term_id, 'beacon_address', true ) ),
				)
			); }
		$items = array();
		foreach ( $occ as $o ) {
			$s    = new \DateTimeImmutable( $o['start'], $tz );
			$f    = $o['end'] ? new \DateTimeImmutable( $o['end'], $tz ) : $s->modify( '+1 hour' );
			$item = array(
				'@context'            => 'https://schema.org',
				'@type'               => 'Event',
				'name'                => self::plain( get_the_title( $id ) ),
				'startDate'           => $e['all_day'] ? $s->format( 'Y-m-d' ) : $s->format( 'c' ),
				'endDate'             => $e['all_day'] ? $f->format( 'Y-m-d' ) : $f->format( 'c' ),
				'eventStatus'         => $o['status'] === 'cancelled' ? 'https://schema.org/EventCancelled' : ( $o['status'] === 'moved' ? 'https://schema.org/EventRescheduled' : 'https://schema.org/EventScheduled' ),
				'eventAttendanceMode' => $mode,
				'url'                 => get_permalink( $id ),
				'description'         => $desc,
				'image'               => $image ?: null,
				'location'            => count( $places ) === 1 ? $places[0] : ( $places ?: null ),
				'organizer'           => $org ? array(
					'@type' => 'Organization',
					'name'  => self::plain( $org->name ),
				) : null,
			);
			if ( defined( 'BEACON_PRO_VERSION' ) && get_post_meta( $id, '_beacon_rsvp', true ) === '1' ) {
				$raw           = (string) get_post_meta( $id, '_beacon_price', true );
				$pro           = is_callable( array( 'BeaconPro\\Rsvp', 'parse_price' ) );
				$price         = $pro ? \BeaconPro\Rsvp::parse_price( $raw ) : (float) str_replace( ',', '.', $raw );
				$item['offers'] = array(
					'@type'         => 'Offer',
					'url'           => get_permalink( $id ),
					'price'         => number_format( $price, 2, '.', '' ),
					'priceCurrency' => strtoupper( $pro ? \BeaconPro\Rsvp::currency() : (string) get_option( 'beacon_pro_currency', 'usd' ) ),
					'availability'  => 'https://schema.org/InStock',
				); }
			if ( $o['status'] === 'moved' ) {
				$item['previousStartDate'] = $o['orig']; }
			$items[] = array_filter( $item, fn( $v ) => $v !== null && $v !== '' );
		}
		$items = apply_filters( 'beacon_schema', $items, $id );
		if ( ! $items ) {
			return; }
		echo '<script type="application/ld+json">' . wp_json_encode( count( $items ) === 1 ? $items[0] : $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON built from escaped scalars by wp_json_encode
	}
}
