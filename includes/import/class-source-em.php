<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;

/**
 * Events Manager (5–7): events and locations live in its own tables next to their posts. Generated occurrence
 * posts (event_type "recurrence" / recurrence_id set) are skipped; the repeating template becomes one Beacon event
 * with the rule. Works whether Events Manager is active or already deactivated.
 */
final class Source_EM {
	const KEY = 'em';

	public static function label(): string {
		return __( 'Events Manager', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Once you deactivate Events Manager, its /events/… and /locations/… links are redirected to the imported events and venues.', 'beacon-events' ); }

	private static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'em_' . $name;
	}

	private static function has_table( string $name ): bool {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $name ] ) ) {
			$t = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema probe, once per request
			$cache[ $name ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
		}
		return $cache[ $name ];
	}

	private static function has_column( string $table, string $col ): bool {
		global $wpdb;
		static $cache = array();
		$k = "$table.$col";
		if ( ! isset( $cache[ $k ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema probe, once per request
			$cache[ $k ] = self::has_table( $table ) && (bool) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::table( $table ), $col ) );
		}
		return $cache[ $k ];
	}

	public static function detect(): array {
		global $wpdb;
		if ( ! self::has_table( 'events' ) ) {
			return array( 'found' => false ); }
		$t = self::table( 'events' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows
		// Two literal statements: rows that are events in their own right (not generated occurrences, not trashed).
		if ( self::has_column( 'events', 'event_type' ) ) {
			$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_status NOT IN ('trash','auto-draft') AND (e.recurrence_id IS NULL OR e.recurrence_id = 0) AND (e.event_type IS NULL OR e.event_type NOT IN ('recurrence','timeslot'))", $t, $wpdb->posts ) );
		} else {
			$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_status NOT IN ('trash','auto-draft') AND (e.recurrence_id IS NULL OR e.recurrence_id = 0)", $t, $wpdb->posts ) );
		}
		$children = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_status <> 'trash' AND e.recurrence_id > 0", $t, $wpdb->posts ) );
		$venues   = self::has_table( 'locations' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table( 'locations' ) ) ) : 0;
		$cats     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'event-categories'" );
		// phpcs:enable
		return array(
			'found'      => $events + $venues > 0,
			'total'      => $events,
			'events'     => $events,
			'children'   => $children,
			'venues'     => $venues,
			'categories' => $cats,
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => class_exists( 'EM_Event' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		$t = self::table( 'events' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- paging another plugin's rows; one-off migration
		if ( self::has_column( 'events', 'event_type' ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT e.event_id FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_status NOT IN ('trash','auto-draft') AND (e.recurrence_id IS NULL OR e.recurrence_id = 0) AND (e.event_type IS NULL OR e.event_type NOT IN ('recurrence','timeslot')) ORDER BY e.event_id ASC LIMIT %d OFFSET %d", $t, $wpdb->posts, $limit, $offset ) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT e.event_id FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_status NOT IN ('trash','auto-draft') AND (e.recurrence_id IS NULL OR e.recurrence_id = 0) ORDER BY e.event_id ASC LIMIT %d OFFSET %d", $t, $wpdb->posts, $limit, $offset ) );
		}
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	public static function map( $event_id, array &$state ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading Events Manager's own table
		$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d', self::table( 'events' ), (int) $event_id ), ARRAY_A );
		$p = $e ? get_post( (int) $e['post_id'] ) : null;
		if ( ! $e || ! $p ) {
			return null; }
		$title = $p->post_title !== '' ? $p->post_title : (string) $e['event_name'];
		$note  = null;
		$rrule = '';
		$ex    = array();
		$start = Import::minute( (string) $e['event_start_date'] . ' ' . ( $e['event_start_time'] ?: '00:00:00' ) );
		$end   = Import::minute( (string) $e['event_end_date'] . ' ' . ( $e['event_end_time'] ?: '00:00:00' ) );
		$type  = (string) ( $e['event_type'] ?? '' );
		if ( in_array( $type, array( 'repeating', 'recurring' ), true ) || ( ! empty( $e['recurrence'] ) && (int) $e['recurrence'] === 1 ) ) {
			[$rrule, $ex, $note, $rs, $re] = self::recurrence( $e );
			if ( $rs ) {
				$start = $rs;
				$end   = $re; }
		}
		if ( ! $start ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $event_id );
			return null;
		}
		$venue = null;
		if ( ! empty( $e['location_id'] ) && self::has_table( 'locations' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading Events Manager's own table
			$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE location_id = %d', self::table( 'locations' ), (int) $e['location_id'] ), ARRAY_A );
			if ( $l && trim( (string) $l['location_name'] ) !== '' ) {
				$g     = fn( string $k ) => trim( (string) ( $l[ $k ] ?? '' ) );
				$venue = array(
					'name'    => $g( 'location_name' ),
					'address' => implode( ', ', array_filter( array( $g( 'location_address' ), $g( 'location_town' ), trim( $g( 'location_state' ) . ' ' . $g( 'location_postcode' ) ), $g( 'location_country' ) ) ) ),
					'src_id'  => (int) $l['location_id'],
				);
			}
		}
		$cats = array();
		foreach ( Import::source_terms( (int) $p->ID, 'event-categories' ) as $tid ) {
			$c = Import::term_chain( $tid, 'event-categories' );
			if ( $c ) {
				$cats[] = $c; }
		}
		$extra = array();
		if ( ! empty( $e['event_rsvp'] ) ) {
			$extra['rsvp']   = 1;
			$extra['spaces'] = (int) ( $e['event_spaces'] ?? 0 ); }
		return array(
			'uid'          => self::KEY . '-' . (int) $event_id,
			'src_id'       => (int) $event_id,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?(event|events|locations|location)[_-]?[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => $p->post_status,
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => (int) ( $e['event_all_day'] ?? 0 ) === 1,
			'tz'           => (string) ( $e['event_timezone'] ?? '' ),
			'rrule'        => $rrule,
			'exdates'      => $ex,
			'venue'        => $venue,
			'organizers'   => array(),
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'extra'        => $extra,
			'note'         => $note,
		);
	}

	/**
	 * Returns [rrule, exdates[], note, start, end]. Events Manager 7 keeps rule sets in em_event_recurrences (one
	 * "include" set = the rule, "exclude" sets = skipped dates); 5 and 6 kept the rule on the event row itself.
	 * Day numbers are 0 = Sunday … 6 = Saturday.
	 */
	private static function recurrence( array $e ): array {
		global $wpdb;
		$days  = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
		$note  = null;
		$ex    = array();
		$rule  = null;
		$start = '';
		$end   = '';
		if ( self::has_table( 'event_recurrences' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading Events Manager's own table
			$sets = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d ORDER BY recurrence_order ASC, recurrence_set_id ASC', self::table( 'event_recurrences' ), (int) $e['event_id'] ), ARRAY_A );
			foreach ( (array) $sets as $s ) {
				if ( ( $s['recurrence_type'] ?? 'include' ) === 'exclude' ) {
					foreach ( preg_split( '/\s*,\s*/', (string) $s['recurrence_dates'] ) as $d ) {
						if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
							$ex[] = $d; }
					}
					continue;
				}
				if ( $rule ) {
					$note = __( 'had more than one recurrence rule; only the first was carried over.', 'beacon-events' );
					continue;
				}
				$rule  = $s;
				$start = Import::minute( (string) $s['recurrence_start_date'] . ' ' . ( $s['recurrence_start_time'] ?: '00:00:00' ) );
				$end   = Import::minute( (string) $s['recurrence_start_date'] . ' ' . ( $s['recurrence_end_time'] ?: $s['recurrence_start_time'] ?: '00:00:00' ) );
			}
		}
		if ( ! $rule && ! empty( $e['recurrence_freq'] ) ) {   // Events Manager 5 / 6
			$rule = array(
				'recurrence_freq'      => $e['recurrence_freq'],
				'recurrence_interval'  => $e['recurrence_interval'] ?? 1,
				'recurrence_byday'     => $e['recurrence_byday'] ?? '',
				'recurrence_byweekno'  => $e['recurrence_byweekno'] ?? 0,
				'recurrence_end_date'  => $e['event_end_date'],
				'recurrence_end_after' => 0,
			);
			$start = Import::minute( (string) $e['event_start_date'] . ' ' . ( $e['event_start_time'] ?: '00:00:00' ) );
			$end   = Import::minute( (string) $e['event_start_date'] . ' ' . ( $e['event_end_time'] ?: '00:00:00' ) );
		}
		if ( ! $rule ) {
			return array( '', $ex, __( 'is a repeating event whose rule could not be read; imported as a single event.', 'beacon-events' ), '', '' ); }
		$freq = strtoupper( (string) $rule['recurrence_freq'] );
		if ( $freq === 'ON' ) {   // specific dates: no rule to express them; keep the first date and say so
			return array( '', $ex, __( 'repeats on a list of specific dates, which Beacon cannot express as a rule; only the first date was imported.', 'beacon-events' ), $start, $end ); }
		if ( ! in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
			return array( '', $ex, sprintf( /* translators: %s: frequency */ __( 'repeats "%s", which is not supported; imported as a single event.', 'beacon-events' ), $rule['recurrence_freq'] ), $start, $end ); }
		$parts = array( "FREQ=$freq", 'INTERVAL=' . max( 1, (int) ( $rule['recurrence_interval'] ?? 1 ) ) );
		if ( $freq === 'WEEKLY' && (string) $rule['recurrence_byday'] !== '' ) {
			$list = array_filter( array_map( fn( $d ) => $days[ (int) trim( $d ) ] ?? '', explode( ',', (string) $rule['recurrence_byday'] ) ) );
			if ( $list ) {
				$parts[] = 'BYDAY=' . implode( ',', $list ); }
		}
		if ( $freq === 'MONTHLY' && (string) $rule['recurrence_byday'] !== '' && (int) ( $rule['recurrence_byweekno'] ?? 0 ) !== 0 ) {
			$pos = (int) $rule['recurrence_byweekno'];
			$pos = $pos < 0 ? -1 : min( 5, $pos );
			$parts[] = 'BYDAY=' . $pos . ( $days[ (int) $rule['recurrence_byday'] ] ?? 'MO' );
		}
		if ( ! empty( $rule['recurrence_end_after'] ) && (int) $rule['recurrence_end_after'] > 0 ) {
			$parts[] = 'COUNT=' . (int) $rule['recurrence_end_after'];
		} elseif ( ! empty( $rule['recurrence_end_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $rule['recurrence_end_date'] ) ) {
			$parts[] = 'UNTIL=' . str_replace( '-', '', substr( (string) $rule['recurrence_end_date'], 0, 10 ) ); }
		$rrule = Import::normalize_rrule( implode( ';', $parts ), $note );
		return array( $rrule, array_unique( $ex ), $note, $start, $end );
	}
}
