<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;

/**
 * WP Events Manager by ThimPress (data model read from 2.2.5; the plugin that ships with ThimPress themes such as
 * Eduma). Events are `tp_event` posts with tp_event_date_start / tp_event_time_start / tp_event_date_end /
 * tp_event_time_end in site time (the edit screen posts Y-m-d and H:i; older saves are read with a lenient parser),
 * a free-text address in tp_event_location, tp_event_qty seats and tp_event_price. tp_event_status (upcoming /
 * happening / expired) is kept in meta, not in the post status. Categories are tp_event_category; event types
 * (tp_event_type) become categories too; tags (tp_event_tag) are not imported. Bookings are `event_auth_book` posts
 * (status ea-completed when paid or free) — Beacon Events Pro brings those over. No per-event timezone and no
 * repeats exist in this plugin. Works with the plugin active or already deactivated.
 */
final class Source_WPEMS {
	const KEY  = 'wpems';
	const CPT  = 'tp_event';
	const BOOK = 'event_auth_book';

	public static function label(): string {
		return __( 'WP Events Manager (ThimPress)', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Once WP Events Manager is deactivated, its /events/…, /tp-event-category/… and /tp-event-type/… links are redirected to the imported events and categories.', 'beacon-events' ); }

	public static function detect(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows; its post types may be unregistered
		$events  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $wpdb->posts, self::CPT ) );
		$cats    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE taxonomy IN ('tp_event_category','tp_event_type')", $wpdb->term_taxonomy ) );
		$tickets = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = 'tp_event_qty' WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit') AND CAST(m.meta_value AS UNSIGNED) > 0", $wpdb->posts, $wpdb->postmeta, self::CPT ) );
		$booked  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status = 'ea-completed'", $wpdb->posts, self::BOOK ) );
		$venues  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.meta_value) FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = 'tp_event_location' WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit') AND m.meta_value <> ''", $wpdb->posts, $wpdb->postmeta, self::CPT ) );
		// phpcs:enable
		return array(
			'found'      => $events > 0,
			'total'      => $events,
			'events'     => $events,
			'venues'     => $venues,
			'categories' => $cats,
			'tickets'    => $tickets,
			'attendees'  => $booked,
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => defined( 'WPEMS_MAIN_FILE' ),
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
		$m      = fn( string $k ) => trim( (string) get_post_meta( $p->ID, 'tp_event_' . $k, true ) );
		$title  = $p->post_title !== '' ? $p->post_title : __( '(Untitled event)', 'beacon-events' );
		$sdate  = self::date( $m( 'date_start' ) );
		$edate  = self::date( $m( 'date_end' ) );
		$stime  = self::time( $m( 'time_start' ) );
		$etime  = self::time( $m( 'time_end' ) );
		if ( ! $sdate ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): its start date is missing or could not be read.', 'beacon-events' ), $title, (int) $p->ID );
			return null;
		}
		$edate   = $edate && $edate >= $sdate ? $edate : $sdate;
		$all_day = '' === $stime && '' === $etime;
		if ( $all_day ) {
			$start = $sdate . ' 00:00';
			$end   = $edate . ' 23:59';
		} else {
			$start = $sdate . ' ' . ( $stime ?: '00:00' );
			$end   = $edate . ' ' . ( $etime ?: ( $stime ?: '00:00' ) );
			if ( $end < $start ) {
				$end = $start; }
		}

		$loc   = $m( 'location' );
		$venue = $loc !== '' ? array(
			'name'    => $loc,
			'address' => $loc,
		) : null;

		$cats = array();
		foreach ( array( 'tp_event_category', 'tp_event_type' ) as $tax ) {
			foreach ( Import::source_terms( (int) $p->ID, $tax ) as $tid ) {
				$c = Import::term_chain( $tid, $tax );
				if ( $c ) {
					$cats[] = $c; }
			}
		}
		if ( empty( $state['wpems_tags'] ) && Import::source_terms( (int) $p->ID, 'tp_event_tag' ) ) {
			$state['wpems_tags'] = 1;
			$state['notes'][]    = __( 'Event tags were not imported: Beacon groups events by category only.', 'beacon-events' );
		}
		$extra = array_filter(
			array(
				'seats'            => $m( 'qty' ),
				'price'            => $m( 'price' ),
				'registration_end' => trim( self::date( $m( 'registration_end_date' ) ) . ' ' . self::time( $m( 'registration_end_time' ) ) ),
			),
			'strlen'
		);
		$status = in_array( $p->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $p->post_status : 'draft';
		return array(
			'uid'          => self::KEY . '-' . (int) $p->ID,
			'src_id'       => (int) $p->ID,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?(wp_event_[a-z_]+|tp_event[a-z_]*)[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => $status,
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => $all_day,
			'tz'           => '',   // the plugin has no per-event zone; its times are site time
			'rrule'        => '',
			'exdates'      => array(),
			'online'       => false,
			'online_url'   => '',
			'venue'        => $venue,
			'organizers'   => array(),
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'image_url'    => '',
			'extra'        => $extra,
			'note'         => null,
		);
	}

	/** "2026-10-10" as the edit screen posts it; older saves may hold another format strtotime() understood. */
	private static function date( string $s ): string {
		if ( $s === '' ) {
			return ''; }
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $s ) ) {
			return substr( $s, 0, 10 ); }
		$d = date_create( $s );
		return $d ? $d->format( 'Y-m-d' ) : '';
	}

	/** "18:30" as posted; "6:30 pm" and similar from older saves. '' when empty or unreadable. */
	private static function time( string $s ): string {
		if ( $s === '' ) {
			return ''; }
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $s, $x ) && (int) $x[1] < 24 ) {
			return sprintf( '%02d:%s', (int) $x[1], $x[2] ); }
		$d = date_create( '2000-01-01 ' . $s );
		return $d ? $d->format( 'H:i' ) : '';
	}
}
