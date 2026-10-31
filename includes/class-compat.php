<?php
/**
 * Old calendar shortcodes after a move. Pages keep the previous plugin's shortcodes ([add_eventon_list …],
 * [tribe_events view="month"], [my_calendar format="list"] …) and once that plugin is gone they print as raw text.
 * For every plugin Beacon imported events from, its list and calendar shortcodes render the closest Beacon view,
 * as long as nothing else has registered the tag (with the old plugin still active, its own shortcode wins).
 */
namespace Beacon;

defined( 'ABSPATH' ) || exit;

final class Compat {

	const OPT = 'beacon_compat_sources';

	/** Import source key => the shortcode tags it brings back. Order matters where two sources share a tag. */
	const TAGS = array(
		'tec'            => array( 'tribe_events', 'tribe_events_list', 'tribe_mini_calendar', 'tribe_this_week' ),
		'eventon'        => array( 'add_eventon', 'add_ajde_evcal', 'add_eventon_list', 'add_eventon_sv' ),
		'em'             => array( 'events_list', 'events_list_grouped', 'events_calendar' ),
		'eventorganiser' => array( 'eo_events', 'eo_fullcalendar', 'eo_calendar' ),
		'mycalendar'     => array( 'my_calendar', 'my_calendar_upcoming', 'my_calendar_today' ),
		'eventprime'     => array( 'em_events' ),
		'sugarcalendar'  => array( 'sugarcalendar_events_calendar', 'sugarcalendar_events_list', 'sc_events_calendar', 'sc_events_list' ),
		'eventin'        => array( 'events', 'events_calendar', 'etn_monthly_calendar' ),
		'wpem'           => array( 'events', 'upcoming_events' ),
		'wpems'          => array( 'wp_event_list_event' ),
		'ai1ec'          => array( 'ai1ec' ),
		'mec'            => array( 'MEC' ),
	);

	/** Each source's own category taxonomy (term IDs in its shortcodes are looked up there). */
	const CAT_TAX = array(
		'tec'            => 'tribe_events_cat',
		'eventon'        => 'event_type',
		'em'             => 'event-categories',
		'eventorganiser' => 'event-category',
		'eventprime'     => 'em_event_type',
		'sugarcalendar'  => 'sc_event_category',
		'eventin'        => 'etn_category',
		'wpem'           => 'event_listing_category',
		'ai1ec'          => 'events_categories',
		'mec'            => 'mec_category',
	);

	/** @var array<string,string> tag => source, filled by register() */
	private static $owner = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 99 ); // after every plugin registered its own
		add_filter( 'render_block', array( __CLASS__, 'eventon_block' ), 10, 2 );
	}

	/** Sources Beacon holds imported events from. Worked out once, then kept; Import::finish() resets it. */
	public static function sources(): array {
		$s = get_option( self::OPT, false );
		if ( is_array( $s ) ) {
			return $s; }
		global $wpdb;
		$found = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT meta_value FROM %i WHERE meta_key = %s', $wpdb->postmeta, '_beacon_import' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once, then stored
		$s     = array_values( array_intersect( array_keys( self::TAGS ), array_map( 'strval', (array) $found ) ) );
		update_option( self::OPT, $s, true );
		return $s;
	}

	public static function register(): void {
		$on = self::sources();
		foreach ( self::TAGS as $source => $tags ) {
			if ( ! in_array( $source, $on, true ) ) {
				continue; }
			foreach ( $tags as $tag ) {
				if ( isset( self::$owner[ $tag ] ) || shortcode_exists( $tag ) ) {
					continue; }
				self::$owner[ $tag ] = $source;
				add_shortcode( $tag, array( __CLASS__, 'render' ) );
			}
		}
	}

	/** The shortcode callback for every tag above. */
	public static function render( $atts, $content = '', $tag = '' ): string {
		$a      = array_change_key_case( is_array( $atts ) ? $atts : array(), CASE_LOWER );
		$source = self::$owner[ $tag ] ?? '';
		$b      = $source ? self::map( $source, (string) $tag, $a ) : null;
		if ( null === $b ) {
			return ''; }
		$b = array_merge( array( 'days' => 365 ), array_filter( $b, fn( $v ) => '' !== $v && null !== $v ) );
		return Views::shortcode( $b );
	}

	/** Old shortcode + attributes → [beacon_events] attributes. */
	public static function map( string $source, string $tag, array $a ): ?array {
		$view  = 'list';
		$limit = 0;
		$cat   = '';
		$venue = '';
		$org   = '';
		$extra = array();
		$get   = function ( string ...$keys ) use ( $a ) {
			foreach ( $keys as $k ) {
				if ( isset( $a[ $k ] ) && '' !== trim( (string) $a[ $k ] ) ) {
					return trim( (string) $a[ $k ] ); }
			}
			return '';
		};
		switch ( $source ) {
			case 'tec':
				if ( 'tribe_events' === $tag ) {
					$view = self::view( $get( 'view' ) ?: 'month', array( 'month' => 'month', 'week' => 'week', 'day' => 'day' ) );
					// the full calendar comes with its search bar unless the page turned it off
					$extra['filters'] = in_array( strtolower( $get( 'tribe-bar' ) ), array( 'false', '0', 'no', 'off' ), true ) ? '' : '1';
				} elseif ( 'tribe_mini_calendar' === $tag ) {
					$view = 'month';
				} elseif ( 'tribe_this_week' === $tag ) {
					$view = 'week';
				}
				$limit = (int) $get( 'limit', 'events_per_page' );
				$cat   = self::cats( $source, $get( 'category', 'cat', 'categories' ) );
				$venue = self::by_source_id( $source, 'beacon_venue', $get( 'venue' ) );
				$org   = self::by_source_id( $source, 'beacon_organizer', $get( 'organizer' ) );
				break;
			case 'eventon':
				$view  = in_array( $tag, array( 'add_eventon', 'add_ajde_evcal' ), true ) ? 'month' : 'list';
				$limit = (int) $get( 'event_count', 'show_limit' );
				$cat   = self::join( self::cats( $source, $get( 'event_type' ) ), self::cats( $source, $get( 'event_type_2' ), 'event_type_2' ) );
				$extra['once'] = 'yes' === strtolower( $get( 'hide_mult_occur' ) ) ? '1' : ''; // EventON's "hide multiple occurrences"
				$m     = (int) $get( 'number_of_months' );
				if ( 'list' === $view && $m > 0 ) {
					$extra['days'] = min( 31 * $m, 3660 ); }
				break;
			case 'em':
				$view  = 'events_calendar' === $tag ? 'month' : 'list';
				$limit = (int) $get( 'limit' );
				$cat   = self::cats( $source, $get( 'category' ) );
				$venue = self::by_source_id( $source, 'beacon_venue', $get( 'location' ) );
				break;
			case 'eventorganiser':
				if ( 'eo_events' === $tag ) {
					$extra['layout'] = 'compact';
					$limit           = (int) $get( 'numberposts', 'posts_per_page' );
					$extra['once']   = 'series' === strtolower( $get( 'group_events_by' ) ) ? '1' : '';
				} else {
					$v    = strtolower( $get( 'defaultview' ) );
					$view = str_contains( $v, 'day' ) ? 'day' : ( str_contains( $v, 'week' ) && ! str_starts_with( $v, 'list' ) ? 'week' : ( str_starts_with( $v, 'list' ) ? 'list' : 'month' ) );
				}
				$cat   = self::cats( $source, $get( 'event_category', 'event-category', 'category' ) );
				$venue = self::venue_by_slug( $source, 'event-venue', $get( 'event_venue', 'event-venue', 'venue' ) );
				break;
			case 'mycalendar':
				if ( 'my_calendar' === $tag ) {
					$time = strtolower( $get( 'time' ) );
					$view = 'list' === strtolower( $get( 'format' ) ) ? 'list' : ( 'week' === $time ? 'week' : ( 'day' === $time ? 'day' : 'month' ) );
				} elseif ( 'my_calendar_today' === $tag ) {
					$view = 'day';
				} else {
					$extra['layout'] = 'compact';
					$after           = $get( 'after' );
					$limit           = 'days' !== strtolower( $get( 'type' ) ) && ctype_digit( $after ) ? (int) $after : 0;
					$extra['once']   = 'no' === strtolower( $get( 'show_recurring' ) ) ? '1' : '';
				}
				$cat = self::mc_cats( $get( 'category' ) );
				break;
			case 'eventprime':
				$view  = self::view( $get( 'view' ), array( 'month' => 'month', 'week' => 'week', 'day' => 'day' ) );
				$limit = (int) $get( 'limit', 'show' );
				break;
			case 'sugarcalendar':
				if ( str_ends_with( $tag, '_calendar' ) ) {
					$view = self::view( $get( 'display' ) ?: 'month', array( 'month' => 'month', 'week' => 'week', 'day' => 'day' ) );
				}
				$limit = (int) $get( 'maximum_events_to_show', 'number' );
				$cat   = self::cats( $source, $get( 'calendars', 'category' ) );
				break;
			case 'eventin':
				$view  = 'events' === $tag ? 'list' : 'month';
				$limit = (int) $get( 'limit' );
				$cat   = self::cats( $source, $get( 'event_cat_ids' ) );
				break;
			case 'wpem':
				$limit = (int) $get( 'per_page' );
				$cat   = self::cats( $source, $get( 'categories', 'category' ) );
				if ( 'events' === $tag ) {
					$extra['filters'] = in_array( strtolower( $get( 'show_filters' ) ), array( 'false', '0', 'no' ), true ) ? '' : '1'; }
				break;
			case 'wpems':
				$limit = (int) $get( 'limit' );
				break;
			case 'ai1ec':
				$view = self::view( preg_replace( '/ly$/', '', strtolower( $get( 'view' ) ) ), array( 'month' => 'month', 'week' => 'week', 'dai' => 'day', 'day' => 'day' ) );
				$cat  = self::join( self::cats( $source, $get( 'cat_id' ) ), self::cats( $source, $get( 'cat_name' ) ) );
				break;
			case 'mec':
				$id   = (int) $get( 'id' );
				$skin = $get( 'skin' ) ?: ( $id ? (string) get_post_meta( $id, 'skin', true ) : '' );
				$view = in_array( $skin, array( 'monthly_view', 'full_calendar', 'general_calendar', 'yearly_view' ), true ) ? 'month'
					: ( in_array( $skin, array( 'weekly_view', 'timetable' ), true ) ? 'week' : ( 'daily_view' === $skin ? 'day' : 'list' ) );
				$c    = $id ? get_post_meta( $id, 'category', true ) : '';
				$cat  = self::cats( $source, is_array( $c ) ? implode( ',', $c ) : (string) $c );
				break;
			default:
				return null;
		}
		return array_merge(
			array(
				'view'      => $view,
				'limit'     => $limit > 0 ? min( $limit, 100 ) : '',
				'category'  => $cat,
				'venue'     => $venue,
				'organizer' => $org,
			),
			$extra
		);
	}

	private static function view( string $v, array $map ): string {
		return $map[ strtolower( trim( $v ) ) ] ?? 'list';
	}

	private static function join( string ...$parts ): string {
		return implode( ',', array_filter( $parts ) );
	}

	/** Comma list of the source's category IDs, slugs or names → Beacon category slugs. */
	public static function cats( string $source, string $list, string $tax = '' ): string {
		$tax = $tax ?: ( self::CAT_TAX[ $source ] ?? '' );
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $list ) ), 'strlen' ) as $t ) {
			if ( in_array( strtolower( $t ), array( 'all', 'default', 'any' ), true ) || str_starts_with( $t, '-' ) ) {
				continue; }
			$slug = ctype_digit( $t ) ? ( $tax ? self::source_slug( (int) $t, $tax ) : '' ) : sanitize_title( $t );
			$hit  = $slug ? self::beacon_cat( $source, $slug ) : '';
			if ( ! $hit && ! ctype_digit( $t ) ) {
				$term = get_term_by( 'name', $t, 'beacon_category' );
				$hit  = $term ? $term->slug : '';
			}
			$hit = $hit ?: $slug;
			if ( $hit ) {
				$out[] = $hit; }
		}
		return implode( ',', array_unique( $out ) );
	}

	/** A term's slug in a taxonomy that may no longer be registered (the old plugin is gone, its terms stay). */
	private static function source_slug( int $id, string $tax ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT t.slug FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = %s', $wpdb->terms, $wpdb->term_taxonomy, $id, $tax ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup per old shortcode attribute
	}

	/** The Beacon category the importer made from the source's category with this slug. */
	private static function beacon_cat( string $source, string $slug ): string {
		$by_meta = get_terms(
			array(
				'taxonomy'   => 'beacon_category',
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'slugs',
				'meta_key'   => '_beacon_' . $source . '_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- term meta, small table
				'meta_value' => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( is_array( $by_meta ) && $by_meta ) {
			return (string) $by_meta[0]; }
		$term = get_term_by( 'slug', $slug, 'beacon_category' );
		return $term ? $term->slug : '';
	}

	/** Venue / organizer by the source's own ID, which the importer keeps as term meta. */
	private static function by_source_id( string $source, string $taxonomy, string $id ): string {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $id ) ), 'ctype_digit' ) as $one ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 1,
					'fields'     => 'slugs',
					'meta_key'   => '_beacon_' . $source . '_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- term meta, small table
					'meta_value' => (int) $one, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( is_array( $found ) && $found ) {
				$out[] = (string) $found[0]; }
		}
		return implode( ',', $out );
	}

	/** Event Organiser's venue slugs → its venue term IDs → the Beacon venues made from them. */
	private static function venue_by_slug( string $source, string $tax, string $slugs ): string {
		global $wpdb;
		$ids = array();
		foreach ( array_filter( array_map( 'sanitize_title', explode( ',', $slugs ) ) ) as $slug ) {
			$ids[] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT t.term_id FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s', $wpdb->terms, $wpdb->term_taxonomy, $slug, $tax ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup per old shortcode attribute
		}
		return self::by_source_id( $source, 'beacon_venue', implode( ',', array_filter( $ids ) ) );
	}

	/** My Calendar's category IDs (its own table) or names → Beacon category slugs. */
	private static function mc_cats( string $list ): string {
		global $wpdb;
		$names = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $list ) ), 'strlen' ) as $t ) {
			if ( ctype_digit( $t ) ) {
				$prev = $wpdb->suppress_errors(); // the table is gone if My Calendar was uninstalled with its data
				$name = $wpdb->get_var( $wpdb->prepare( 'SELECT category_name FROM %i WHERE category_id = %d', $wpdb->prefix . 'my_calendar_categories', (int) $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup per old shortcode attribute
				$wpdb->suppress_errors( $prev );
				if ( $name ) {
					$names[] = (string) $name; }
			} elseif ( 'all' !== strtolower( $t ) ) {
				$names[] = $t;
			}
		}
		return self::cats( 'mycalendar', implode( ',', $names ), '-' );
	}

	/**
	 * EventON Lite's block saves its shortcode inside a styled paragraph. With EventON gone the block is unknown, so the
	 * paragraph stays; hand back the bare shortcode so Beacon's calendar isn't drawn inside a <p>.
	 */
	public static function eventon_block( $content, $block ) {
		if ( ( $block['blockName'] ?? '' ) !== 'eventon-blocks/evo-eventon-main' || ( ! isset( self::$owner['add_eventon_list'] ) && ! isset( self::$owner['add_eventon'] ) ) ) {
			return $content; }
		$text = str_replace( array( '&#8221;', '&#8243;', '&#8220;', '”', '″', '“' ), '"', (string) $content );
		if ( preg_match( '/\[(add_eventon(?:_list|_sv)?|add_ajde_evcal)\b[^\]]*\]/', $text, $m ) && isset( self::$owner[ $m[1] ] ) ) {
			return $m[0]; }
		return $content;
	}
}
