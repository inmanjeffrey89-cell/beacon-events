<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;
use Beacon\Recurrence;

/**
 * EventON and EventON Lite (data model read from EventON Lite 2.5.9). Events are `ajde_events` posts. Start/end are
 * "virtual" unix timestamps in evcal_srow / evcal_erow: the event's wall-clock time encoded as if it were UTC, so
 * gmdate() gives the local time back; _evo_tz is the event's zone. evcal_allday = "yes". Repeats: evcal_repeat = "yes",
 * evcal_rep_freq (hourly|daily|weekly|monthly|yearly|custom), evcal_rep_gap, weekly by chosen days (evp_repeat_rb_wk
 * "dow", evo_rep_WKwk 0 = Sunday … 6), monthly by weekday of the month (evp_repeat_rb "dow", evo_rep_WK, evo_repeat_wom),
 * and — the source of truth — `repeat_intervals`, every occurrence EventON generated as [start, end] pairs. A rule is
 * rebuilt from the settings and used only when it reproduces exactly those dates; otherwise the first date is imported
 * with a note. Locations/organizers are the event_location / event_organizer taxonomies with their details in the
 * option evo_tax_meta; event_type, event_type_2 … become categories. Works with EventON active or deactivated.
 */
final class Source_EVENTON {
	const KEY = 'eventon';
	const CPT = 'ajde_events';

	private const DAYS = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	public static function label(): string {
		return __( 'EventON', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Covers EventON and EventON Lite. Once EventON is deactivated, its /events/… links are redirected to the imported events.', 'beacon-events' ); }

	private static function type_taxonomies(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- which event_type* taxonomies exist; EventON may be deactivated
		return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT taxonomy FROM %i WHERE taxonomy LIKE %s', $wpdb->term_taxonomy, $wpdb->esc_like( 'event_type' ) . '%' ) );
	}

	public static function detect(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows; its post type and taxonomies may be unregistered
		$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $wpdb->posts, self::CPT ) );
		$tax    = fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE taxonomy = %s', $wpdb->term_taxonomy, $t ) );
		// phpcs:enable
		$types = 0;
		foreach ( self::type_taxonomies() as $t ) {
			$types += $tax( $t ); }
		return array(
			'found'      => $events > 0,
			'total'      => $events,
			'events'     => $events,
			'venues'     => $tax( 'event_location' ),
			'organizers' => $tax( 'event_organizer' ),
			'categories' => $types,
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => class_exists( 'EventON' ) || class_exists( 'EVO_Lite' ) || function_exists( 'EVO' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's posts; one-off migration
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT %d OFFSET %d", $wpdb->posts, self::CPT, $limit, $offset ) );
		return array_map( 'intval', (array) $ids );
	}

	public static function map( $post_id, array &$state ): ?array {
		$p = get_post( (int) $post_id );
		if ( ! $p ) {
			return null; }
		$m     = fn( string $k ) => get_post_meta( $p->ID, $k, true );
		$title = $p->post_title !== '' ? $p->post_title : __( '(Untitled event)', 'beacon-events' );
		$s     = (int) $m( 'evcal_srow' );
		if ( $s <= 0 ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $p->ID );
			return null;
		}
		$e       = max( $s, (int) $m( 'evcal_erow' ) );
		$all_day = 'yes' === (string) $m( 'evcal_allday' );
		$start   = gmdate( 'Y-m-d H:i', $s );   // virtual timestamps: wall-clock time as if UTC
		$end     = gmdate( 'Y-m-d H:i', $e );
		if ( $all_day ) {
			$start = gmdate( 'Y-m-d', $s ) . ' 00:00';
			$end   = gmdate( 'Y-m-d', $e ) . ' 23:59';
		}
		$tz               = (string) $m( '_evo_tz' );
		[$rrule, $note]   = self::recurrence( $m, $s, $tz );
		$status           = (string) $m( '_status' );
		if ( 'cancelled' === $status ) {
			$note = trim( ( $note ? $note . ' ' : '' ) . __( 'was marked cancelled in EventON; check whether it should still be published.', 'beacon-events' ) ); }

		$venue = null;
		$meta  = (array) get_option( 'evo_tax_meta', array() );
		$tm    = function ( string $tax, int $tid ) use ( $meta ) {
			$v = $meta[ $tax ][ $tid ] ?? get_option( 'taxonomy_' . $tid );
			return is_array( $v ) ? $v : array();
		};
		$locs = Import::source_terms( (int) $p->ID, 'event_location' );
		if ( $locs ) {
			$c = Import::term_chain( $locs[0], 'event_location' );
			if ( $c ) {
				$venue = array(
					'name'    => $c['name'],
					'address' => trim( (string) ( $tm( 'event_location', $locs[0] )['location_address'] ?? '' ) ),
					'src_id'  => $locs[0],
				);
			}
		}
		$orgs = array();
		foreach ( Import::source_terms( (int) $p->ID, 'event_organizer' ) as $oid ) {
			$c = Import::term_chain( $oid, 'event_organizer' );
			if ( $c ) {
				$orgs[] = array(
					'name'   => $c['name'],
					'src_id' => $oid,
				);
			}
		}
		$cats  = array();
		$color = ltrim( (string) $m( 'evcal_event_color' ), '#' );
		foreach ( self::type_taxonomies() as $tax ) {
			foreach ( Import::source_terms( (int) $p->ID, $tax ) as $tid ) {
				$c = Import::term_chain( $tid, $tax );
				if ( $c ) {
					if ( preg_match( '/^[0-9a-f]{6}$/i', $color ) && ! $cats ) {
						$c['color'] = '#' . $color; }   // the event's own colour, on its first category
					$cats[] = $c;
				}
			}
		}
		$online = 'movedonline' === $status || 'yes' === (string) $m( '_virtual' );
		// evcal_exlink is an outside address only when the event's click setting is "External Link" (2), or unset on old
		// events. "Open Event Page" (4) stores the event's own permalink there, which would make a button back to itself.
		$exlink = in_array( (string) $m( '_evcal_exlink_option' ), array( '2', '' ), true ) ? (string) $m( 'evcal_exlink' ) : '';
		$extra  = array_filter(
			array(
				'subtitle'  => (string) $m( 'evcal_subtitle' ),
				'url'       => $exlink, // where clicking the event goes, so it wins over Learn More for the button
				'more_info' => (string) $m( 'evcal_lmlink' ), // the event card's "Learn More" link
			),
			'strlen'
		);
		return array(
			'uid'          => self::KEY . '-' . (int) $p->ID,
			'src_id'       => (int) $p->ID,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?(add_eventon|eventon)[a-z_]*[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => in_array( $p->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $p->post_status : 'draft',
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => $all_day,
			'tz'           => $tz,
			'rrule'        => $rrule,
			'exdates'      => array(),
			'online'       => $online,
			'online_url'   => $online ? esc_url_raw( (string) $m( '_vir_url' ) ) : '',
			'venue'        => $venue,
			'organizers'   => $orgs,
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'extra'        => $extra,
			'note'         => $note,
		);
	}

	/**
	 * Rebuild a rule from EventON's settings and keep it only if it yields exactly the dates EventON stored.
	 *
	 * @return array{0:string,1:?string} rrule, note
	 */
	private static function recurrence( callable $m, int $s, string $tz ): array {
		if ( 'yes' !== (string) $m( 'evcal_repeat' ) ) {
			return array( '', null ); }
		$want = array();
		foreach ( (array) $m( 'repeat_intervals' ) as $iv ) {
			if ( is_array( $iv ) && isset( $iv[0] ) && (int) $iv[0] > 0 ) {
				$want[] = gmdate( 'Y-m-d', (int) $iv[0] ); }
		}
		$want = array_values( array_unique( $want ) );
		sort( $want );
		$freq = (string) $m( 'evcal_rep_freq' );
		$gap  = max( 1, (int) $m( 'evcal_rep_gap' ) );
		$day  = self::DAYS[ (int) gmdate( 'w', $s ) ];
		$list = function ( $v ) {
			$v   = is_array( $v ) ? $v : explode( ',', (string) $v );
			$out = array();
			foreach ( $v as $raw ) {
				$raw = trim( str_replace( '_', '', (string) $raw ) );
				if ( '' !== $raw && ctype_digit( $raw ) && isset( self::DAYS[ (int) $raw ] ) ) {
					$out[] = self::DAYS[ (int) $raw ]; }
			}
			return array_values( array_unique( $out ) );
		};
		$cands = array();
		switch ( $freq ) {
			case 'daily':
				$cands[] = "FREQ=DAILY;INTERVAL=$gap";
				break;
			case 'weekly':
				$days    = 'dow' === (string) $m( 'evp_repeat_rb_wk' ) ? $list( $m( 'evo_rep_WKwk' ) ) : array();
				$cands[] = "FREQ=WEEKLY;INTERVAL=$gap;BYDAY=" . ( $days ? implode( ',', $days ) : $day );
				break;
			case 'monthly':
				if ( 'dow' === (string) $m( 'evp_repeat_rb' ) ) {
					$days = $list( $m( 'evo_rep_WK' ) );
					$woms = array_filter( array_map( 'intval', is_array( $m( 'evo_repeat_wom' ) ) ? $m( 'evo_repeat_wom' ) : explode( ',', (string) $m( 'evo_repeat_wom' ) ) ) );
					$pos  = $woms ? reset( $woms ) : (int) ceil( (int) gmdate( 'j', $s ) / 7 );
					$cands[] = "FREQ=MONTHLY;INTERVAL=$gap;BYDAY=" . $pos . ( $days ? $days[0] : $day );
				} else {
					$cands[] = "FREQ=MONTHLY;INTERVAL=$gap";
				}
				break;
			case 'yearly':
				$cands[] = "FREQ=YEARLY;INTERVAL=$gap";
				break;
		}
		if ( ! $want || ! $cands ) {
			$why = 'custom' === $freq ? __( 'repeats on a list of specific dates, which Beacon cannot express as a rule; only the first date was imported.', 'beacon-events' )
				: sprintf( /* translators: %s: repeat type */ __( 'repeats "%s", which is not supported; imported as a single event.', 'beacon-events' ), $freq );
			return array( '', $why );
		}
		$zone  = ( $tz && in_array( $tz, timezone_identifiers_list(), true ) ) ? new \DateTimeZone( $tz ) : wp_timezone();
		$first = gmdate( 'Y-m-d', $s );
		foreach ( $cands as $c ) {
			$rule = $c . ';COUNT=' . count( $want );
			$got  = Recurrence::expand( $rule, $first, gmdate( 'Y-m-d', strtotime( end( $want ) . ' +1 day' ) ), $zone );
			sort( $got );
			if ( $got === $want ) {
				$note = null;
				return array( Import::normalize_rrule( $rule, $note ), $note );
			}
		}
		return array( '', __( 'repeats in a pattern that could not be matched exactly; only the first date was imported, so check its other dates.', 'beacon-events' ) );
	}
}
