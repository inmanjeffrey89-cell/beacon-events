<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;

/** The Events Calendar (free and Pro, versions 5 and 6): read straight from the database, TEC active or not. */
final class Source_TEC {
	const KEY   = 'tec';
	const SRC   = 'tribe_events';

	public static function label(): string {
		return __( 'The Events Calendar', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Deactivate The Events Calendar when you are happy: event, venue and organizer links keep the same addresses, and old category and calendar-view links are redirected.', 'beacon-events' ); }

	public static function detect(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows by post type
		$count = fn( string $type ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND post_parent = 0", $type ) );
		$out   = array(
			'events'     => $count( self::SRC ),
			'children'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tribe_events' AND post_parent > 0 AND post_status NOT IN ('trash','auto-draft')" ),
			'venues'     => $count( 'tribe_venue' ),
			'organizers' => $count( 'tribe_organizer' ),
			'categories' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'tribe_events_cat'" ),
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => class_exists( 'Tribe__Events__Main' ),
			// Event Tickets (RSVP + Tickets Commerce) and Event Tickets Plus (WooCommerce): what Pro would bring over — RSVP
			// "yes" answers and paid orders.
			'tickets'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('tribe_rsvp_tickets','tec_tc_ticket') AND post_status NOT IN ('trash','auto-draft')" )
				+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tribe_wooticket_for_event' WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')" ),
			'attendees'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} a JOIN {$wpdb->postmeta} m ON m.post_id = a.ID AND m.meta_key = '_tribe_rsvp_status' AND m.meta_value = 'yes' WHERE a.post_type = 'tribe_rsvp_attendees' AND a.post_status <> 'trash'" )
				+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} a JOIN {$wpdb->posts} o ON o.ID = a.post_parent WHERE a.post_type = 'tec_tc_attendee' AND a.post_status <> 'trash' AND o.post_status = 'tec-tc-completed'" )
				+ self::woo_paid_attendees(),
		);
		// phpcs:enable
		$out['found'] = $out['events'] + $out['venues'] + $out['organizers'] > 0;
		$out['total'] = $out['events'];
		return $out;
	}

	/** Event Tickets Plus attendees whose WooCommerce order is completed or processing (orders table or order posts). */
	private static function woo_paid_attendees(): int {
		global $wpdb;
		$t = $wpdb->prefix . 'wc_orders';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows
		if ( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes' && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} a JOIN {$wpdb->postmeta} m ON m.post_id = a.ID AND m.meta_key = '_tribe_wooticket_order' JOIN %i o ON o.id = m.meta_value AND o.status IN ('wc-completed','wc-processing') WHERE a.post_type = 'tribe_wooticket' AND a.post_status <> 'trash'", $t ) );
		} else {
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} a JOIN {$wpdb->postmeta} m ON m.post_id = a.ID AND m.meta_key = '_tribe_wooticket_order' JOIN {$wpdb->posts} o ON o.ID = m.meta_value AND o.post_status IN ('wc-completed','wc-processing') WHERE a.post_type = 'tribe_wooticket' AND a.post_status <> 'trash'" );
		}
		// phpcs:enable
		return $n;
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's posts; one-off migration
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND post_parent = 0 ORDER BY ID ASC LIMIT %d OFFSET %d", self::SRC, $limit, $offset ) ) );
	}

	/** One TEC event → the normalized shape Import::import_event() takes, or null to skip (with a note in $state). */
	public static function map( $tec_id, array &$state ): ?array {
		$tec_id = (int) $tec_id;
		$src    = get_post( $tec_id );
		if ( ! $src || $src->post_type !== self::SRC ) {
			return null; }
		$m     = fn( string $k ) => (string) get_post_meta( $tec_id, $k, true );
		$start = Import::minute( $m( '_EventStartDate' ) );
		if ( ! $start ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $src->post_title, $tec_id );
			return null;
		}
		[$rrule, $ex, $note] = self::recurrence( $tec_id );
		$venue = null;
		foreach ( array_filter( array_map( 'intval', (array) get_post_meta( $tec_id, '_EventVenueID', false ) ) ) as $vid ) {
			$v = get_post( $vid );
			if ( $v && $v->post_type === 'tribe_venue' && trim( $v->post_title ) !== '' ) {
				$g      = fn( string $k ) => trim( (string) get_post_meta( $vid, $k, true ) );
				$region = $g( '_VenueState' ) ?: $g( '_VenueProvince' );
				$venue  = array(
					'name'    => trim( $v->post_title ),
					'address' => implode( ', ', array_filter( array( $g( '_VenueAddress' ), $g( '_VenueCity' ), trim( $region . ' ' . $g( '_VenueZip' ) ), $g( '_VenueCountry' ) ) ) ),
					'src_id'  => $vid,
				);
				break;
			}
		}
		$orgs = array();
		foreach ( array_filter( array_map( 'intval', (array) get_post_meta( $tec_id, '_EventOrganizerID', false ) ) ) as $oid ) {
			$o = get_post( $oid );
			if ( $o && $o->post_type === 'tribe_organizer' && trim( $o->post_title ) !== '' ) {
				$orgs[] = array(
					'name'   => trim( $o->post_title ),
					'src_id' => $oid,
				); }
		}
		$cats = array();
		foreach ( Import::source_terms( $tec_id, 'tribe_events_cat' ) as $tid ) {
			$c = Import::term_chain( $tid, 'tribe_events_cat' );
			if ( $c ) {
				$cats[] = $c; }
		}
		$extra = array_filter(
			array(
				'cost' => $m( '_EventCost' ),
				'url'  => $m( '_EventURL' ),
			)
		);
		return array(
			'uid'          => self::KEY . '-' . $tec_id,
			'src_id'       => $tec_id,
			'title'        => $src->post_title,
			'content'      => self::clean_content( $src->post_content ),
			'excerpt'      => $src->post_excerpt,
			'status'       => $src->post_status,
			'author'       => (int) $src->post_author,
			'date'         => $src->post_date,
			'date_gmt'     => $src->post_date_gmt,
			'slug'         => $src->post_name,
			'start'        => $start,
			'end'          => Import::minute( $m( '_EventEndDate' ) ),
			'all_day'      => in_array( $m( '_EventAllDay' ), array( 'yes', '1' ), true ),
			'tz'           => $m( '_EventTimezone' ),
			'rrule'        => $rrule,
			'exdates'      => $ex,
			'venue'        => $venue,
			'organizers'   => $orgs,
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $tec_id ),
			'extra'        => $extra,
			'note'         => $note,
		);
	}

	/** TEC 6 Pro keeps an iCalendar rule set in {prefix}tec_events.rset (Free has the table without the column); TEC 5 kept a serialized array in meta. */
	private static function has_rset_column(): bool {
		static $has = null;
		if ( $has === null ) {
			global $wpdb;
			$table = $wpdb->prefix . 'tec_events';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- schema probe of another plugin's table, once per request
			$has = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
				&& (bool) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'rset' ) );
			// phpcs:enable
		}
		return $has;
	}

	/** Returns [rrule, exdates[], note|null]. */
	private static function recurrence( int $tec_id ): array {
		global $wpdb;
		$rrule = '';
		$ex    = array();
		$note  = null;
		$rset  = '';
		if ( self::has_rset_column() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading The Events Calendar's own table
			$rset = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT rset FROM %i WHERE post_id = %d', $wpdb->prefix . 'tec_events', $tec_id ) );
		}
		if ( $rset ) {
			foreach ( preg_split( '/\r?\n/', $rset ) as $line ) {
				$line = trim( $line );
				if ( stripos( $line, 'RRULE' ) === 0 ) {
					if ( $rrule ) {
						$note = __( 'had more than one recurrence rule; only the first was carried over.', 'beacon-events' );
					} else {
						$rrule = substr( $line, strpos( $line, ':' ) + 1 ); }
				} elseif ( stripos( $line, 'EXDATE' ) === 0 ) {
					foreach ( explode( ',', substr( $line, strpos( $line, ':' ) + 1 ) ) as $d ) {
						if ( preg_match( '/^(\d{4})(\d{2})(\d{2})/', trim( $d ), $m ) ) {
							$ex[] = "$m[1]-$m[2]-$m[3]"; }
					}
				} elseif ( stripos( $line, 'RDATE' ) === 0 || stripos( $line, 'EXRULE' ) === 0 ) {
					$note = __( 'had extra dates or exclusion rules; those are not supported and were not carried over.', 'beacon-events' );
				}
			}
		} else {
			$legacy = get_post_meta( $tec_id, '_EventRecurrence', true );
			if ( is_array( $legacy ) && ! empty( $legacy['rules'] ) && is_array( $legacy['rules'] ) ) {
				[$rrule, $ex, $note] = self::legacy_rule( $legacy ); }
		}
		if ( $rrule ) {
			$rrule = Import::normalize_rrule( $rrule, $note ); }
		return array( $rrule, array_unique( $ex ), $note );
	}

	/** TEC 5's serialized rules → RRULE. Returns [rrule, exdates[], note|null]. */
	private static function legacy_rule( array $legacy ): array {
		$rule   = $legacy['rules'][0];
		$custom = is_array( $rule['custom'] ?? null ) ? $rule['custom'] : array();
		$map    = array(
			'Every Day'   => 'DAILY',
			'Every Week'  => 'WEEKLY',
			'Every Month' => 'MONTHLY',
			'Every Year'  => 'YEARLY',
			'Daily'       => 'DAILY',
			'Weekly'      => 'WEEKLY',
			'Monthly'     => 'MONTHLY',
			'Yearly'      => 'YEARLY',
		);
		$freq   = $map[ $rule['type'] ?? '' ] ?? $map[ $custom['type'] ?? '' ] ?? null;
		if ( ! $freq ) {
			return array( '', array(), __( 'recurrence rule type is not supported; imported as a single event.', 'beacon-events' ) ); }
		$iso   = array(
			1 => 'MO',
			2 => 'TU',
			3 => 'WE',
			4 => 'TH',
			5 => 'FR',
			6 => 'SA',
			7 => 'SU',
		);
		$parts = array( "FREQ=$freq", 'INTERVAL=' . max( 1, (int) ( $custom['interval'] ?? 1 ) ) );
		if ( $freq === 'WEEKLY' && ! empty( $custom['week']['day'] ) ) {
			$days = array_filter( array_map( fn( $d ) => $iso[ (int) $d ] ?? '', (array) $custom['week']['day'] ) );
			if ( $days ) {
				$parts[] = 'BYDAY=' . implode( ',', $days ); }
		}
		if ( $freq === 'MONTHLY' && ! empty( $custom['month']['number'] ) ) {
			$pos = array(
				'First'  => 1,
				'Second' => 2,
				'Third'  => 3,
				'Fourth' => 4,
				'Fifth'  => 5,
				'Last'   => -1,
			);
			if ( isset( $pos[ $custom['month']['number'] ] ) && isset( $iso[ (int) ( $custom['month']['day'] ?? 0 ) ] ) ) {
				$parts[] = 'BYDAY=' . $pos[ $custom['month']['number'] ] . $iso[ (int) $custom['month']['day'] ];
			} elseif ( is_numeric( $custom['month']['number'] ) ) {
				$parts[] = 'BYMONTHDAY=' . (int) $custom['month']['number']; }
		}
		$end = $rule['end-type'] ?? 'Never';
		if ( $end === 'After' && ! empty( $rule['end-count'] ) ) {
			$parts[] = 'COUNT=' . max( 1, (int) $rule['end-count'] );
		} elseif ( $end === 'On' && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) ( $rule['end'] ?? '' ) ) ) {
			$parts[] = 'UNTIL=' . str_replace( '-', '', substr( $rule['end'], 0, 10 ) ); }
		$ex   = array();
		$note = null;
		foreach ( (array) ( $legacy['exclusions'] ?? array() ) as $x ) {
			$d = $x['custom']['date']['date'] ?? '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $d ) ) {
				$ex[] = substr( $d, 0, 10 );
			} else {
				$note = __( 'some exclusions were rules rather than dates; those were not carried over.', 'beacon-events' ); }
		}
		if ( count( $legacy['rules'] ) > 1 ) {
			$note = __( 'had more than one recurrence rule; only the first was carried over.', 'beacon-events' ); }
		return array( implode( ';', $parts ), $ex, $note );
	}

	/** Drop TEC's own blocks and shortcodes from the body; they render nothing once TEC is gone. */
	public static function clean_content( string $c ): string {
		if ( has_blocks( $c ) ) {
			$keep = array();
			foreach ( parse_blocks( $c ) as $b ) {
				if ( ! empty( $b['blockName'] ) && strpos( $b['blockName'], 'tribe/' ) === 0 ) {
					continue; }
				$keep[] = $b;
			}
			$c = serialize_blocks( $keep );
		}
		return trim( (string) preg_replace( '/\[\/?tribe[_-][^\]]*\]/i', '', $c ) );
	}
}
