<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;

/**
 * Sugar Calendar and Sugar Calendar Lite (data model read from Sugar Calendar Lite 3.14.0, with events made in its own
 * editor). Each event is an `sc_event` post plus one row in the {prefix}sc_events table (object_id = the post): start /
 * end are the local wall-clock time, start_tz the event's zone ("" = floating, shown in Sugar Calendar's own time zone
 * setting, option sc_timezone), all_day 1 with the end at 23:59:59. Repeats (Pro): recurrence daily|weekly|monthly|
 * yearly, recurrence_interval, recurrence_count, recurrence_end ("0000-00-00 00:00:00" = none) and, with Pro's
 * advanced patterns, recurrence_byday / _bypos / _bymonthday / _bymonth (the parts its own .ics export writes). Event
 * meta lives in {prefix}sc_eventmeta: location (free text), online_provider (custom|zoom), custom_link_url or a
 * created meeting's join_url, online_visibility (everyone|attendees). Calendars are the sc_event_category taxonomy
 * with a `color`. A link Sugar Calendar showed to attendees only is never made public here. Works with it deactivated.
 */
final class Source_SC {
	const KEY = 'sugarcalendar';
	const CPT = 'sc_event';

	public static function label(): string {
		return __( 'Sugar Calendar', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Covers Sugar Calendar Lite and Pro. Repeating events come over as repeating events, calendars become categories with their colours, and once Sugar Calendar is deactivated its /events/… links are redirected.', 'beacon-events' ); }

	private static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'sc_' . $name;
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

	public static function detect(): array {
		global $wpdb;
		$events = 0;
		$places = 0;
		if ( self::has_table( 'events' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows; it may be deactivated
			$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e JOIN %i p ON p.ID = e.object_id WHERE e.object_type = 'post' AND p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit')", self::table( 'events' ), $wpdb->posts, self::CPT ) );
			if ( self::has_table( 'eventmeta' ) ) {
				$places = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT meta_value) FROM %i WHERE meta_key = 'location' AND meta_value <> ''", self::table( 'eventmeta' ) ) ); }
			// phpcs:enable
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the taxonomy may be unregistered
		$cals = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE taxonomy = %s', $wpdb->term_taxonomy, 'sc_event_category' ) );
		return array(
			'found'      => $events > 0,
			'total'      => $events,
			'events'     => $events,
			'venues'     => $places,
			'organizers' => 0,
			'categories' => $cals,
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => function_exists( 'sugar_calendar' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		if ( ! self::has_table( 'events' ) ) {
			return array(); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's events; one-off migration
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i e JOIN %i p ON p.ID = e.object_id WHERE e.object_type = 'post' AND p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit') ORDER BY p.ID ASC LIMIT %d OFFSET %d", self::table( 'events' ), $wpdb->posts, self::CPT, $limit, $offset ) );
		return array_map( 'intval', (array) $ids );
	}

	public static function map( $post_id, array &$state ): ?array {
		global $wpdb;
		$p = get_post( (int) $post_id );
		if ( ! $p ) {
			return null; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading Sugar Calendar's own table
		$e     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE object_id = %d AND object_type = 'post' ORDER BY id ASC LIMIT 1", self::table( 'events' ), (int) $p->ID ), ARRAY_A );
		$title = $p->post_title !== '' ? $p->post_title : __( '(Untitled event)', 'beacon-events' );
		if ( ! $e || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string) $e['start'] ) || 0 === strpos( (string) $e['start'], '0000' ) ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $p->ID );
			return null;
		}
		$meta = array();
		if ( self::has_table( 'eventmeta' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading Sugar Calendar's own meta table
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE sc_event_id = %d', self::table( 'eventmeta' ), (int) $e['id'] ), ARRAY_A ) as $r ) {
				$meta[ $r['meta_key'] ] = (string) $r['meta_value']; }
		}
		$m       = fn( string $k ) => $meta[ $k ] ?? '';
		$all_day = '1' === (string) $e['all_day'];
		$start   = substr( (string) $e['start'], 0, 16 );
		$end     = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string) $e['end'] ) && 0 !== strpos( (string) $e['end'], '0000' ) ? substr( (string) $e['end'], 0, 16 ) : $start;
		if ( $end < $start ) {
			$end = $start; }
		if ( $all_day ) {
			$start = substr( $start, 0, 10 ) . ' 00:00';
			$end   = substr( $end, 0, 10 ) . ' 23:59';
		}
		$zone = (string) $e['start_tz'];
		if ( '' === $zone || ! in_array( $zone, timezone_identifiers_list(), true ) ) {
			$own  = (string) get_option( 'sc_timezone', '' );  // floating times are shown in Sugar Calendar's own zone
			$zone = in_array( $own, timezone_identifiers_list(), true ) ? $own : '';
		}
		[ $rrule, $note ] = self::recurrence( $e, $m );

		$venue = null;
		$loc   = trim( (string) $m( 'location' ) );
		if ( (int) $e['venue_id'] > 0 && ( $vp = get_post( (int) $e['venue_id'] ) ) && '' !== $vp->post_title ) {
			$venue = array(
				'name'    => $vp->post_title,
				'address' => $loc,
			);
		} elseif ( '' !== $loc ) {
			$venue = Import::place_from_text( $loc );
		}
		$cats = array();
		foreach ( Import::source_terms( (int) $p->ID, 'sc_event_category' ) as $tid ) {
			$c = Import::term_chain( $tid, 'sc_event_category' );
			if ( $c ) {
				$color = (string) get_term_meta( $tid, 'color', true );
				if ( preg_match( '/^#[0-9a-f]{6}$/i', $color ) ) {
					$c['color'] = $color; }
				$cats[] = $c;
			}
		}
		$provider = (string) $m( 'online_provider' );
		$url      = '' === $provider ? '' : esc_url_raw( 'custom' === $provider ? (string) $m( 'custom_link_url' ) : (string) ( $m( 'join_url' ) ?: $m( 'custom_link_url' ) ) );
		$online   = '' !== $provider;
		if ( $url && 'attendees' === (string) $m( 'online_visibility' ) ) {
			// shown to attendees only in Sugar Calendar: never made public by the import
			$why  = sprintf( /* translators: %s: the joining link */ __( 'its joining link (%s) went only to attendees in Sugar Calendar, so it was left off the public event. To keep it private, take RSVPs with Beacon Events Pro and tick "Send the joining link only to people who sign up"; to show it to everyone, paste it under Online event.', 'beacon-events' ), $url );
			$note = trim( ( $note ? $note . ' ' : '' ) . $why );
			$url  = '';
		}
		return array(
			'uid'          => self::KEY . '-' . (int) $p->ID,
			'src_id'       => (int) $p->ID,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?sc_events?[a-z_]*[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => in_array( $p->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $p->post_status : 'draft',
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => $all_day,
			'tz'           => $zone,
			'rrule'        => $rrule,
			'exdates'      => array(),
			'online'       => $online,
			'online_url'   => $url,
			'venue'        => $venue,
			'organizers'   => array(),
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'extra'        => array_filter( array( 'link' => esc_url_raw( (string) $m( 'url' ) ) ), 'strlen' ),
			'note'         => $note,
		);
	}

	/**
	 * The rule Sugar Calendar's own .ics export writes for this event, kept to what Beacon expands.
	 *
	 * @return array{0:string,1:?string} rrule, note
	 */
	private static function recurrence( array $e, callable $m ): array {
		$freq = strtoupper( (string) $e['recurrence'] );
		if ( '' === $freq ) {
			return array( '', null ); }
		if ( ! in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
			return array( '', sprintf( /* translators: %s: repeat type */ __( 'repeats "%s", which is not supported; imported as a single event.', 'beacon-events' ), (string) $e['recurrence'] ) ); }
		$rule  = 'FREQ=' . $freq . ';INTERVAL=' . max( 1, (int) $e['recurrence_interval'] );
		$extra = array();
		$byday = strtoupper( preg_replace( '/\s+/', '', (string) $m( 'recurrence_byday' ) ) );
		$pos   = (int) $m( 'recurrence_bypos' );
		$mday  = preg_replace( '/\s+/', '', (string) $m( 'recurrence_bymonthday' ) );
		if ( '' !== $byday ) {
			if ( $pos && 'MONTHLY' === $freq && preg_match( '/^(SU|MO|TU|WE|TH|FR|SA)$/', $byday ) ) {
				$byday = $pos . $byday;  // "fourth Tuesday": BYSETPOS=4;BYDAY=TU is BYDAY=4TU
			} elseif ( $pos ) {
				$extra[] = 'BYSETPOS'; }
			$rule .= ';BYDAY=' . $byday;
		} elseif ( '' !== $mday ) {
			$rule .= ';BYMONTHDAY=' . (int) $mday;
			if ( false !== strpos( $mday, ',' ) ) {
				$extra[] = 'BYMONTHDAY=' . $mday; }
		}
		if ( '' !== (string) $m( 'recurrence_bymonth' ) ) {
			$extra[] = 'BYMONTH'; }
		if ( (int) $e['recurrence_count'] > 0 ) {
			$rule .= ';COUNT=' . (int) $e['recurrence_count'];
		} elseif ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', (string) $e['recurrence_end'], $u ) && '0000' !== $u[1] ) {
			$rule .= ';UNTIL=' . $u[1] . $u[2] . $u[3];
		}
		$note = null;
		$rule = Import::normalize_rrule( $rule, $note );
		if ( $extra ) {
			$note = sprintf( /* translators: %s: rule parts */ __( 'recurrence rule was simplified (%s not supported); check its dates.', 'beacon-events' ), implode( ', ', $extra ) ); }
		return array( $rule, $note );
	}
}
