<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;

/**
 * WP Event Manager (data model read from 3.4.1). Events are `event_listing` posts with _event_start_date /
 * _event_end_date stored as local "Y-m-d H:i:s" (the raw form input is kept when their parser fails, so other
 * formats are tried too), _event_timezone (only honoured when option event_manager_timezone_setting = each_event), _event_online
 * ("yes"/"no"), the address ON THE EVENT (_event_location, _event_pincode, _event_country), and links to separate
 * `event_venue` / `event_organizer` posts (_event_venue_ids, _event_organizer_ids). Categories are
 * event_listing_category; event types (event_listing_type) become categories too. Past events sit in WP Event
 * Manager's own "expired" status. Works with the plugin active or already deactivated.
 */
final class Source_WPEM {
	const KEY = 'wpem';
	const CPT = 'event_listing';

	public static function label(): string {
		return __( 'WP Event Manager', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Its /event/… and /event-category/… links already match Beacon\'s; /event-type/… links are redirected to the matching category once WP Event Manager is deactivated.', 'beacon-events' ); }

	public static function detect(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows; its post types may be unregistered
		$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $wpdb->posts, self::CPT ) );
		$venues = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'event_venue' AND post_status NOT IN ('trash','auto-draft')", $wpdb->posts ) );
		$orgs   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'event_organizer' AND post_status NOT IN ('trash','auto-draft')", $wpdb->posts ) );
		$cats   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE taxonomy IN ('event_listing_category','event_listing_type')", $wpdb->term_taxonomy ) );
		// phpcs:enable
		return array(
			'found'      => $events > 0,
			'total'      => $events,
			'events'     => $events,
			'venues'     => $venues,
			'organizers' => $orgs,
			'categories' => $cats,
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => class_exists( 'WP_Event_Manager' ),
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
		$start = self::datetime( (string) $m( '_event_start_date' ), (string) $m( '_event_start_time' ) );
		if ( ! $start ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): its start date is missing or could not be read.', 'beacon-events' ), $title, (int) $p->ID );
			return null;
		}
		$end = self::datetime( (string) $m( '_event_end_date' ), (string) $m( '_event_end_time' ) );
		if ( ! $end || $end < $start ) {
			$end = $start; }

		$note = null;
		if ( in_array( (string) $m( '_event_cancelled' ), array( '1', 'yes', 'true' ), true ) ) {
			$note = __( 'was marked cancelled in WP Event Manager; check whether it should still be published.', 'beacon-events' ); }

		// Address lives on the event; the venue post only has a name.
		$addr  = implode( ', ', array_filter( array_map( 'trim', array( (string) $m( '_event_location' ), trim( (string) $m( '_event_pincode' ) ), (string) $m( '_event_country' ) ) ) ) );
		$venue = null;
		$vid   = self::first_id( $m( '_event_venue_ids' ) );
		$vpost = $vid ? get_post( $vid ) : null;
		if ( $vpost && 'event_venue' === $vpost->post_type && trim( $vpost->post_title ) !== '' ) {
			$venue = array(
				'name'    => trim( $vpost->post_title ),
				'address' => $addr,
				'src_id'  => $vid,
			);
		} elseif ( trim( (string) $m( '_event_location' ) ) !== '' ) {
			$venue = array(
				'name'    => trim( (string) $m( '_event_location' ) ),
				'address' => $addr,
			);
		}
		$orgs = array();
		foreach ( self::ids_list( $m( '_event_organizer_ids' ) ) as $oid ) {
			$o = get_post( $oid );
			if ( $o && 'event_organizer' === $o->post_type && trim( $o->post_title ) !== '' ) {
				$orgs[] = array(
					'name'   => trim( $o->post_title ),
					'src_id' => $oid,
				);
			}
		}
		$cats = array();
		foreach ( array( 'event_listing_category', 'event_listing_type' ) as $tax ) {
			foreach ( Import::source_terms( (int) $p->ID, $tax ) as $tid ) {
				$c = Import::term_chain( $tid, $tax );
				if ( $c ) {
					$cats[] = $c; }
			}
		}
		$image  = '';
		$banner = $m( '_event_banner' );
		if ( ! get_post_thumbnail_id( $p->ID ) ) {
			$first = is_array( $banner ) ? reset( $banner ) : $banner;
			$image = is_string( $first ) && preg_match( '#^https?://#', $first ) ? $first : '';
		}
		$extra = array_filter(
			array(
				'ticket'       => (string) $m( '_event_ticket_options' ),
				'price'        => (string) $m( '_event_ticket_price' ),
				'registration' => is_string( $m( '_registration' ) ) ? (string) $m( '_registration' ) : '',
			),
			'strlen'
		);
		$status = $p->post_status;
		if ( 'expired' === $status ) {
			$status = 'publish';   // WP Event Manager's own status for past events; Beacon shows past events as past
		} elseif ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			$status = 'draft'; }
		return array(
			'uid'          => self::KEY . '-' . (int) $p->ID,
			'src_id'       => (int) $p->ID,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?(events|event_[a-z_]+|past_events|upcoming_events|submit_event_form|event_dashboard)[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => $status,
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => false,
			// WP Event Manager only honours the per-event zone in its "each event" mode; otherwise times are site time
			'tz'           => 'each_event' === get_option( 'event_manager_timezone_setting', 'site_timezone' ) ? (string) $m( '_event_timezone' ) : '',
			'rrule'        => '',
			'exdates'      => array(),
			'online'       => 'yes' === strtolower( (string) $m( '_event_online' ) ),
			'online_url'   => '',
			'venue'        => $venue,
			'organizers'   => $orgs,
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'image_url'    => $image,
			'extra'        => $extra,
			'note'         => $note,
		);
	}

	/** "2026-10-10 18:30:00" (their DB format), or whatever raw input was kept when their parser failed. */
	private static function datetime( string $date, string $time ): string {
		$date = trim( $date );
		if ( $date === '' ) {
			return ''; }
		$m = Import::minute( $date );
		if ( $m ) {
			return $m; }
		$d = date_create( $date . ( preg_match( '/\d:\d/', $date ) ? '' : ' ' . trim( $time ) ) );
		return $d ? $d->format( 'Y-m-d H:i' ) : '';
	}

	private static function first_id( $v ): int {
		$l = self::ids_list( $v );
		return $l ? $l[0] : 0;
	}

	/** Their id fields are an array, a single id, or a comma list depending on version. */
	private static function ids_list( $v ): array {
		$v = is_array( $v ) ? $v : preg_split( '/\s*,\s*/', (string) $v );
		return array_values( array_filter( array_map( 'intval', (array) $v ) ) );
	}
}
