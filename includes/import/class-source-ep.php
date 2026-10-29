<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;
use Beacon\Recurrence;

/**
 * EventPrime (eventprime-event-calendar-management; data model read from 4.3.6.1, events made through its own admin save
 * path). Events are `em_event` posts. em_start_date / em_end_date are unix timestamps whose DATE (read in the site's
 * timezone) is the event's day — the time of day in them is the moment of saving, not the event's — and em_start_time /
 * em_end_time hold the time as text ("09:00 AM" or "09:00"). em_all_day = 1. The zone is the site's (em_timezone exists
 * only for events made through its REST API). Venue, event type and organizers are the em_venue / em_event_type /
 * em_event_organizer taxonomies (venue address in term meta em_address, else em_locality / em_state / em_postal_code /
 * em_country; type colour em_color). A repeating event is a parent that is itself the FIRST date, plus one child post per
 * further date (post_parent = the parent) — the dates are the source of truth. A rule is rebuilt from em_recurrence_*
 * and kept when it gives those dates (any of its dates EventPrime lacks become skip dates, at most half as many as the
 * real ones); otherwise every date comes over as its own event (Combine can join them later). An outside booking link (em_enable_booking = external_bookings, em_custom_link) becomes the "Tickets &
 * info" button. Works with EventPrime active or deactivated.
 */
final class Source_EP {
	const KEY       = 'eventprime';
	const CPT       = 'em_event';
	const OPT_LINKS = 'beacon_ep_links'; // set by a real import: its ?event= / ?venue= … page links are redirected from then on

	private const DAYS = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	public static function label(): string {
		return __( 'EventPrime', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Once you deactivate EventPrime, its event, venue, organizer and event type links (on its calendar pages, or its SEO addresses) are redirected to the imported ones.', 'beacon-events' ); }

	public static function detect(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's posts and terms; it may be deactivated
		$all  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $wpdb->posts, self::CPT ) );
		$top  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_parent = 0 AND post_status NOT IN ('trash','auto-draft','inherit')", $wpdb->posts, self::CPT ) );
		$tax  = fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE taxonomy = %s', $wpdb->term_taxonomy, $t ) );
		// phpcs:enable
		return array(
			'found'      => $top > 0,
			'total'      => $all,     // parents and their further dates: what the batches walk through
			'events'     => $top,
			'venues'     => $tax( 'em_venue' ),
			'organizers' => $tax( 'em_event_organizer' ),
			'categories' => $tax( 'em_event_type' ),
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => defined( 'EVENTPRIME_VERSION' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's posts; one-off migration
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT %d OFFSET %d", $wpdb->posts, self::CPT, $limit, $offset ) );
		return array_map( 'intval', (array) $ids );
	}

	public static function map( $post_id, array &$state ): ?array {
		static $saved = false;
		if ( ! $saved && empty( $state['dry'] ) ) { // a dry run writes nothing
			update_option( self::OPT_LINKS, '1', false );
			$saved = true;
		}
		$p = get_post( (int) $post_id );
		if ( ! $p ) {
			return null; }
		$title  = $p->post_title !== '' ? $p->post_title : __( '(Untitled event)', 'beacon-events' );
		$m      = fn( string $k ) => get_post_meta( $p->ID, $k, true );
		$parent = $p->post_parent ? get_post( (int) $p->post_parent ) : null;
		if ( $parent && self::CPT === $parent->post_type && null !== self::series( (int) $parent->ID )['rule'] ) {
			return null; }   // a further date of a series that came over as one repeating event

		$s = self::at( $m( 'em_start_date' ), (string) $m( 'em_start_time' ) );
		if ( ! $s ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $p->ID );
			return null;
		}
		$e       = self::at( $m( 'em_end_date' ) ?: $m( 'em_start_date' ), (string) $m( 'em_end_time' ) ) ?: $s;
		$all_day = 1 === (int) $m( 'em_all_day' );
		$rrule   = '';
		$exdates = array();
		if ( ! $parent && self::repeats( (int) $p->ID ) ) {
			$set = self::series( (int) $p->ID );
			if ( null === $set['rule'] && count( $set['dates'] ) > 1 ) {
				$state['notes'][] = sprintf( /* translators: 1: title, 2: number of dates */ __( '"%1$s" repeats in a way Beacon cannot write as one rule, so its %2$d dates came over as separate events; Events → Import / Export → Combine can join them where they fit a weekly pattern.', 'beacon-events' ), $title, count( $set['dates'] ) );
			} elseif ( null !== $set['rule'] ) {
				$rrule   = $set['rule'];
				$exdates = $set['skip'];
			}
		}
		if ( $e < $s ) {
			$e = $s; }
		$start = $all_day ? substr( $s, 0, 10 ) . ' 00:00' : $s;
		$end   = $all_day ? substr( $e, 0, 10 ) . ' 23:59' : $e;

		$venue = null;
		$vids  = Import::source_terms( (int) $p->ID, 'em_venue' );
		if ( $vids ) {
			$c = Import::term_chain( $vids[0], 'em_venue' );
			if ( $c ) {
				$tm    = fn( string $k ) => trim( (string) get_term_meta( $vids[0], $k, true ) );
				$addr  = $tm( 'em_address' );
				$addr  = '' !== $addr ? $addr : implode( ', ', array_filter( array( $tm( 'em_locality' ), trim( $tm( 'em_state' ) . ' ' . $tm( 'em_postal_code' ) ), $tm( 'em_country' ) ), 'strlen' ) );
				$venue = array(
					'name'    => $c['name'],
					'address' => $addr,
					'src_id'  => $vids[0],
				);
			}
		}
		$orgs = array();
		foreach ( Import::source_terms( (int) $p->ID, 'em_event_organizer' ) as $oid ) {
			$c = Import::term_chain( $oid, 'em_event_organizer' );
			if ( $c ) {
				$orgs[] = array(
					'name'   => $c['name'],
					'src_id' => $oid,
				);
			}
		}
		$cats = array();
		foreach ( Import::source_terms( (int) $p->ID, 'em_event_type' ) as $tid ) {
			$c = Import::term_chain( $tid, 'em_event_type' );
			if ( $c ) {
				$hex = ltrim( (string) get_term_meta( $tid, 'em_color', true ), '#' );
				if ( preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
					$c['color'] = '#' . $hex; }
				$cats[] = $c;
			}
		}
		$extra = array();
		if ( 'external_bookings' === (string) $m( 'em_enable_booking' ) ) {
			$extra['ticket_url'] = esc_url_raw( (string) $m( 'em_custom_link' ) ); }
		$perf = array_filter( array_map( fn( $id ) => get_the_title( (int) $id ), (array) $m( 'em_performer' ) ) );
		if ( $perf ) {
			$extra['performers'] = implode( ', ', $perf ); }
		$tz = (string) $m( 'em_timezone' );
		return array(
			'uid'          => self::KEY . '-' . (int) $p->ID,
			'src_id'       => (int) $p->ID,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?(em_[a-z_]+|ep_[a-z_]+|eventprime[a-z_]*)[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => in_array( $p->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $p->post_status : 'draft',
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => $all_day,
			'tz'           => in_array( $tz, timezone_identifiers_list(), true ) ? $tz : wp_timezone_string(),
			'rrule'        => $rrule,
			'exdates'      => $exdates,
			'online'       => false,
			'online_url'   => '',
			'venue'        => $venue,
			'organizers'   => $orgs,
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'extra'        => array_filter( $extra, 'strlen' ),
			'note'         => null,
		);
	}

	/** A timestamp (its date, in the site's timezone) + "09:00 AM" / "09:00" → "Y-m-d H:i"; '' without a date. */
	private static function at( $stamp, string $time ): string {
		if ( ! is_numeric( $stamp ) || (int) $stamp <= 0 ) {
			return ''; }
		$d  = wp_date( 'Y-m-d', (int) $stamp );
		$t  = trim( $time );
		$ts = '' === $t ? false : strtotime( '2000-01-01 ' . $t . ' UTC' );
		return $d . ' ' . ( false === $ts ? '00:00' : gmdate( 'H:i', $ts ) );
	}

	private static function repeats( int $id ): bool {
		return 1 === (int) get_post_meta( $id, 'em_enable_recurrence', true ) && '' !== (string) get_post_meta( $id, 'em_recurrence_interval', true );
	}

	/** The source event (or, for a further date, its series' first event) that an old EventPrime id now lives in. */
	public static function parent_of( int $id ): int {
		$p = get_post( $id );
		return $p && self::CPT === $p->post_type && $p->post_parent && null !== self::series( (int) $p->post_parent )['rule'] ? (int) $p->post_parent : $id;
	}

	/**
	 * The series' real dates (the parent and its child posts) and the rule that gives exactly those dates, or rule null.
	 *
	 * @return array{dates:string[],rule:?string,skip:string[]}
	 */
	private static function series( int $id ): array {
		static $cache = array();
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ]; }
		$kids  = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_parent' => $id,
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		$dates = array();
		foreach ( array_merge( array( $id ), $kids ) as $k ) {
			$ts = get_post_meta( $k, 'em_start_date', true );
			if ( is_numeric( $ts ) && (int) $ts > 0 ) {
				$dates[] = wp_date( 'Y-m-d', (int) $ts ); }
		}
		$want = array_values( array_unique( $dates ) );
		sort( $want );
		$m     = fn( string $k ) => get_post_meta( $id, $k, true );
		$step  = max( 1, (int) $m( 'em_recurrence_step' ) );
		$day0  = $want ? self::DAYS[ (int) gmdate( 'w', (int) strtotime( $want[0] . ' UTC' ) ) ] : 'MO';
		$cands = array();
		switch ( (string) $m( 'em_recurrence_interval' ) ) {
			case 'daily':
				$cands[] = "FREQ=DAILY;INTERVAL=$step";
				break;
			case 'weekly':
				$days = array();
				foreach ( (array) $m( 'em_selected_weekly_day' ) as $w ) {
					if ( '' !== (string) $w && isset( self::DAYS[ (int) $w ] ) ) {
						$days[] = self::DAYS[ (int) $w ]; }
				}
				$cands[] = "FREQ=WEEKLY;INTERVAL=$step;BYDAY=" . ( $days ? implode( ',', array_unique( $days ) ) : $day0 );
				break;
			case 'monthly':
				if ( 'day' === (string) $m( 'em_recurrence_monthly_day' ) ) {
					$wk      = (int) $m( 'em_recurrence_monthly_weekno' );
					$wd      = (int) $m( 'em_recurrence_monthly_fullweekday' );
					$cands[] = "FREQ=MONTHLY;INTERVAL=$step;BYDAY=" . ( $wk >= 1 && $wk <= 4 ? $wk : -1 ) . ( self::DAYS[ $wd ] ?? $day0 );
				} else {
					$cands[] = "FREQ=MONTHLY;INTERVAL=$step;BYMONTHDAY=" . ( $want ? (int) substr( $want[0], 8, 2 ) : 1 );
				}
				break;
			case 'yearly':
				$cands[] = "FREQ=YEARLY;INTERVAL=$step";
				break;
		}
		// The rule runs to the last date; dates it gives that EventPrime doesn't have become skip dates (EventPrime starts
		// a weekly series on the week AFTER the first date's, and a deleted date leaves a gap). A rule that needs more skips
		// than half the dates doesn't describe the event, so every date comes over on its own instead.
		$rule = null;
		$skip = array();
		if ( count( $want ) > 1 ) {
			foreach ( $cands as $c ) {
				$try = $c . ';UNTIL=' . str_replace( '-', '', end( $want ) );
				$got = array_values( array_unique( Recurrence::expand( $try, $want[0], gmdate( 'Y-m-d', (int) strtotime( end( $want ) . ' +1 day UTC' ) ) ) ) );
				sort( $got );
				$gap = array_values( array_diff( $got, $want ) );
				if ( $got && $got[0] === $want[0] && ! array_diff( $want, $got ) && count( $gap ) <= intdiv( count( $want ), 2 ) ) {
					$note = null;
					$rule = Import::normalize_rrule( $try, $note );
					$skip = $gap;
					break;
				}
			}
		}
		$cache[ $id ] = array(
			'dates' => $want,
			'rule'  => $rule,
			'skip'  => $skip,
		);
		return $cache[ $id ];
	}
}
