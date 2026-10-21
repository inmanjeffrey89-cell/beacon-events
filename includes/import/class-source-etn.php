<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;
use Beacon\Recurrence;

/**
 * Eventin (wp-event-solution; data model read from 4.1.26, with events made through its own REST API, the path its
 * admin uses). Events are `etn` posts: etn_start_date / etn_end_date "Y-m-d", etn_start_time / etn_end_time "9:00 AM",
 * event_timezone, event_type offline|online|hybrid, etn_event_location an array (address, and for online events
 * integration + custom_url) or, before 4.0, plain text, meeting_link. A repeating event is a parent whose dates are the
 * RANGE, its rule in etn_event_recurrence (recurrence_freq day|week|month|month-advanced|year|custom, with
 * recurrence_daily_interval, recurrence_weekly_day ["0".."6", 0 = Sunday], recurrence_monthly_date,
 * recurrence_monthly_advanced_interval / _week_no / _weekday_no, recurrence_yearly_month / _date) and one CHILD post
 * per date (post_parent = the parent) — the children are the source of truth. A rule is rebuilt and kept only when it
 * gives exactly the children's dates; otherwise every date comes over as its own event. Eventin keeps the meeting link
 * out of public pages (emails and tickets only), so it is never made public here. Works with Eventin deactivated.
 */
final class Source_ETN {
	const KEY = 'eventin';
	const CPT = 'etn';

	private const DAYS = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	public static function label(): string {
		return __( 'Eventin', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Repeating events come over as one repeating event when their dates follow a rule Beacon can repeat, otherwise as one event per date. Meeting links stay off the public pages, as in Eventin. Once Eventin is deactivated, its /etn/… links are redirected.', 'beacon-events' ); }

	public static function detect(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's posts; it may be deactivated
		// leaves out Eventin's own samples: the event it publishes for previews (_etn_preview_placeholder, hidden from
		// its queries) and the two "(Demo) …" drafts it makes on install
		$all  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') AND NOT EXISTS (SELECT 1 FROM %i pm WHERE pm.post_id = ID AND pm.meta_key = '_etn_preview_placeholder') AND NOT ( post_status = 'draft' AND post_title LIKE %s )", $wpdb->posts, self::CPT, $wpdb->postmeta, $wpdb->esc_like( '(Demo)' ) . '%' ) );
		$top  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_parent = 0 AND post_status NOT IN ('trash','auto-draft','inherit') AND NOT EXISTS (SELECT 1 FROM %i pm WHERE pm.post_id = ID AND pm.meta_key = '_etn_preview_placeholder') AND NOT ( post_status = 'draft' AND post_title LIKE %s )", $wpdb->posts, self::CPT, $wpdb->postmeta, $wpdb->esc_like( '(Demo)' ) . '%' ) );
		$cats = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE taxonomy = %s', $wpdb->term_taxonomy, 'etn_category' ) );
		// phpcs:enable
		return array(
			'found'      => $top > 0,
			'total'      => $all,     // parents and their dates: what the batches walk through
			'events'     => $top,
			'categories' => $cats,
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => class_exists( 'Wpeventin' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's posts; one-off migration
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') AND NOT EXISTS (SELECT 1 FROM %i pm WHERE pm.post_id = ID AND pm.meta_key = '_etn_preview_placeholder') AND NOT ( post_status = 'draft' AND post_title LIKE %s ) ORDER BY ID ASC LIMIT %d OFFSET %d", $wpdb->posts, self::CPT, $wpdb->postmeta, $wpdb->esc_like( '(Demo)' ) . '%', $limit, $offset ) );
		return array_map( 'intval', (array) $ids );
	}

	public static function map( $post_id, array &$state ): ?array {
		$p = get_post( (int) $post_id );
		if ( ! $p ) {
			return null; }
		$title = $p->post_title !== '' ? $p->post_title : __( '(Untitled event)', 'beacon-events' );
		if ( 'draft' === $p->post_status && 0 === strpos( $title, '(Demo)' ) ) {
			return null; }   // the two sample events Eventin makes for itself on install
		$m      = fn( string $k ) => get_post_meta( $p->ID, $k, true );
		$parent = $p->post_parent ? get_post( (int) $p->post_parent ) : null;
		$series = $parent && self::CPT === $parent->post_type && self::repeats( (int) $parent->ID );
		if ( $series && null !== self::series( (int) $parent->ID )['rule'] ) {
			return null; }   // one date of a series that came over as one repeating event

		$s = self::at( (string) $m( 'etn_start_date' ), (string) $m( 'etn_start_time' ) );
		if ( ! $s ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $p->ID );
			return null;
		}
		$e       = self::at( (string) $m( 'etn_end_date' ) ?: (string) $m( 'etn_start_date' ), (string) $m( 'etn_end_time' ) ) ?: $s;
		$all_day = '' === trim( (string) $m( 'etn_start_time' ) );
		$rrule   = '';
		$note    = null;
		if ( ! $series && self::repeats( (int) $p->ID ) ) {
			$set = self::series( (int) $p->ID );
			if ( null === $set['rule'] ) {
				++$state['skipped'];
				$state['notes'][] = sprintf( /* translators: 1: title, 2: number of dates */ __( '"%1$s" repeats in a way Beacon cannot write as one rule, so its %2$d dates came over as separate events; Events → Import / Export → Combine can join them where they fit a weekly pattern.', 'beacon-events' ), $title, count( $set['dates'] ) );
				return null;
			}
			$rrule = $set['rule'];
			$first = $set['first'];   // the series starts on its first real date, not on the range's first day
			$s     = self::at( (string) get_post_meta( $first, 'etn_start_date', true ), (string) get_post_meta( $first, 'etn_start_time', true ) ) ?: $s;
			$e     = self::at( (string) get_post_meta( $first, 'etn_end_date', true ), (string) get_post_meta( $first, 'etn_end_time', true ) ) ?: $s;
		}
		if ( $e < $s ) {
			$e = $s; }
		$start = $all_day ? substr( $s, 0, 10 ) . ' 00:00' : $s;
		$end   = $all_day ? substr( $e, 0, 10 ) . ' 23:59' : $e;

		$loc   = $m( 'etn_event_location' ) ?: $m( 'location' );
		$addr  = is_array( $loc ) ? trim( (string) ( $loc['address'] ?? '' ) ) : trim( (string) $loc );
		$venue = '' !== $addr ? Import::place_from_text( $addr ) : null;
		$type  = (string) $m( 'event_type' );
		$link  = esc_url_raw( (string) ( $m( 'meeting_link' ) ?: ( is_array( $loc ) ? ( $loc['custom_url'] ?? '' ) : '' ) ) );
		if ( in_array( $type, array( 'online', 'hybrid' ), true ) && $link ) {
			$why  = sprintf( /* translators: %s: the meeting link */ __( 'its meeting link (%s) was only in Eventin\'s emails and tickets, so it was left off the public event. To send it only to people who sign up, take RSVPs with Beacon Events Pro and tick "Send the joining link only to people who sign up"; to show it to everyone, paste it under Online event.', 'beacon-events' ), $link );
			$note = trim( ( $note ? $note . ' ' : '' ) . $why );
		}
		$cats = array();
		$from = $series ? (int) $parent->ID : (int) $p->ID;   // a lone date of a series carries the series' categories
		foreach ( Import::source_terms( $from, 'etn_category' ) as $tid ) {
			$c = Import::term_chain( $tid, 'etn_category' );
			if ( $c ) {
				$cats[] = $c; }
		}
		$thumb = (int) get_post_thumbnail_id( $p->ID ) ?: (int) $m( 'event_banner_id' );
		return array(
			'uid'          => self::KEY . '-' . (int) $p->ID,
			'src_id'       => (int) $p->ID,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?(etn|events)[a-z_]*[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => in_array( $p->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $p->post_status : 'draft',
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => $all_day,
			'tz'           => (string) $m( 'event_timezone' ),
			'rrule'        => $rrule,
			'exdates'      => array(),
			'online'       => in_array( $type, array( 'online', 'hybrid' ), true ),
			'online_url'   => '',
			'venue'        => 'online' === $type ? null : $venue,
			'organizers'   => array(),
			'categories'   => $cats,
			'thumbnail_id' => $thumb > 0 && wp_attachment_is_image( $thumb ) ? $thumb : 0,
			'extra'        => array_filter( array( 'url' => esc_url_raw( (string) $m( 'external_link' ) ) ), 'strlen' ),
			'note'         => $note,
		);
	}

	/** "2026-11-12" + "6:30 PM" → "2026-11-12 18:30" ('' when the date isn't one). */
	private static function at( string $date, string $time ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $date ), $d ) || ! checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) ) {
			return ''; }
		$t = trim( $time );
		if ( '' === $t ) {
			return $d[0] . ' 00:00'; }
		$ts = strtotime( '2000-01-01 ' . $t . ' UTC' );
		return $d[0] . ' ' . ( false === $ts ? '00:00' : gmdate( 'H:i', $ts ) );
	}

	private static function repeats( int $id ): bool {
		$on = get_post_meta( $id, 'recurring_enabled', true );
		return '' !== (string) $on && 'no' !== (string) $on && is_array( get_post_meta( $id, 'etn_event_recurrence', true ) );
	}

	/**
	 * The series' real dates (its child posts) and the rule that gives exactly those dates, or rule null.
	 *
	 * @return array{dates:string[],first:int,rule:?string}
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
		$first = 0;
		foreach ( $kids as $k ) {
			$d = (string) get_post_meta( $k, 'etn_start_date', true );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				$dates[ $k ] = $d; }
		}
		asort( $dates );
		$first = (int) array_key_first( $dates );
		$want  = array_values( array_unique( $dates ) );
		$rule  = null;
		$r     = (array) get_post_meta( $id, 'etn_event_recurrence', true );
		$cands = array();
		$day0  = $want ? self::DAYS[ (int) gmdate( 'w', (int) strtotime( $want[0] . ' UTC' ) ) ] : 'MO';
		switch ( (string) ( $r['recurrence_freq'] ?? '' ) ) {
			case 'day':
				$cands[] = 'FREQ=DAILY;INTERVAL=' . max( 1, (int) ( $r['recurrence_daily_interval'] ?? 1 ) );
				break;
			case 'week':
				$days = array();
				foreach ( (array) ( $r['recurrence_weekly_day'] ?? array() ) as $w ) {
					if ( isset( self::DAYS[ (int) $w ] ) && '' !== (string) $w ) {
						$days[] = self::DAYS[ (int) $w ]; }
				}
				$cands[] = 'FREQ=WEEKLY;INTERVAL=1;BYDAY=' . ( $days ? implode( ',', array_unique( $days ) ) : $day0 );
				break;
			case 'month':
				$cands[] = 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=' . max( 1, (int) ( $r['recurrence_monthly_date'] ?? 1 ) );
				break;
			case 'month-advanced':
				$wk      = (int) ( $r['recurrence_monthly_advanced_week_no'] ?? 1 );
				$wd      = (int) ( $r['recurrence_monthly_advanced_weekday_no'] ?? 0 );
				$cands[] = 'FREQ=MONTHLY;INTERVAL=' . max( 1, (int) ( $r['recurrence_monthly_advanced_interval'] ?? 1 ) ) . ';BYDAY=' . ( $wk > 0 ? $wk : -1 ) . ( self::DAYS[ $wd ] ?? $day0 );
				break;
			case 'year':
				$cands[] = 'FREQ=YEARLY;INTERVAL=1';
				break;
		}
		if ( $want ) {
			foreach ( $cands as $c ) {
				$try = $c . ';COUNT=' . count( $want );
				$got = Recurrence::expand( $try, $want[0], gmdate( 'Y-m-d', (int) strtotime( end( $want ) . ' +1 day UTC' ) ) );
				sort( $got );
				if ( $got === $want ) {
					$note = null;
					$rule = Import::normalize_rrule( $try, $note );
					break;
				}
			}
		}
		$cache[ $id ] = array(
			'dates' => array_values( $dates ),
			'first' => $first,
			'rule'  => $rule,
		);
		return $cache[ $id ];
	}
}
