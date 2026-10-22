<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Event post type, taxonomies, meta, and occurrence materialisation. */
final class Events {
	const CPT  = 'beacon_event';
	const META = array(
		'start'   => '_beacon_start',
		'end'     => '_beacon_end',
		'all_day' => '_beacon_all_day',
		'tz'      => '_beacon_tz',
		'rrule'      => '_beacon_rrule',
		'exdates'    => '_beacon_exdates',
		'online'     => '_beacon_online',
		'online_url' => '_beacon_online_url',
		'overrides'  => '_beacon_overrides',
		'link'       => '_beacon_link',        // "Tickets & info" button: an outside ticket page (Eventbrite, Square…) or website
		'link_label' => '_beacon_link_label',
	);
	const HORIZON = '_beacon_horizon';              // last date a series was materialised to (Y-m-d)
	const REFRESH = 'beacon_refresh_occurrences';   // daily: keeps every open-ended series two years ahead

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'on_save' ), 20, 2 );
		// The block editor saves meta over REST AFTER save_post fires; rebuild occurrences once the meta is actually written.
		add_action( 'rest_after_insert_' . self::CPT, fn( $post ) => self::on_save( $post->ID, $post ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_filter( 'rest_prepare_' . self::CPT, array( __CLASS__, 'rest_hide_join' ), 10, 2 );
		add_action( self::REFRESH, array( __CLASS__, 'refresh_series' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::REFRESH ) ) {
					wp_schedule_event( time() + 600, 'daily', self::REFRESH );
				} }
		);
	}

	public static function register(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'        => array(
					'name'          => __( 'Events', 'beacon-events' ),
					'singular_name' => __( 'Event', 'beacon-events' ),
					'add_new_item'  => __( 'Add New Event', 'beacon-events' ),
					'edit_item'     => __( 'Edit Event', 'beacon-events' ),
					'menu_name'     => __( 'Events', 'beacon-events' ),
				),
				'public'        => true,
				'has_archive'   => 'events',
				'rewrite'       => array(
					'slug'       => 'event',
					'with_front' => false,
				),
				'menu_icon'     => 'dashicons-calendar-alt',
				'menu_position' => 20,
				'show_in_rest'  => true,
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
			)
		);
		register_taxonomy(
			'beacon_category',
			self::CPT,
			array(
				'label'        => __( 'Event Categories', 'beacon-events' ),
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'event-category' ),
			)
		);
		register_taxonomy(
			'beacon_venue',
			self::CPT,
			array(
				'label'        => __( 'Venues', 'beacon-events' ),
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'venue' ),
			)
		);
		register_taxonomy(
			'beacon_organizer',
			self::CPT,
			array(
				'label'        => __( 'Organizers', 'beacon-events' ),
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'organizer' ),
			)
		);
		register_term_meta(
			'beacon_venue',
			'beacon_address',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_term_meta(
			'beacon_category',
			'beacon_color',
			array(
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'sanitize_callback' => fn( $v ) => (string) sanitize_hex_color( (string) $v ),
			)
		);
		$sanitize = array(
			'online_url' => array( __CLASS__, 'sanitize_url' ),
			'link'       => array( __CLASS__, 'sanitize_url' ),
			'exdates'    => array( __CLASS__, 'sanitize_dates' ),
			'overrides'  => array( __CLASS__, 'sanitize_overrides' ),
		);
		foreach ( self::META as $name => $key ) {
			register_post_meta(
				self::CPT,
				$key,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => $sanitize[ $name ] ?? 'sanitize_text_field',
					'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
				)
			);
		}
	}

	/** The zone an event's times are entered in (its own, else the site's). */
	public static function tz_of( int $event_id ): \DateTimeZone {
		$name = (string) get_post_meta( $event_id, self::META['tz'], true );
		try {
			return $name !== '' ? new \DateTimeZone( $name ) : wp_timezone();
		} catch ( \Exception $x ) {
			return wp_timezone(); }
	}

	/** Read event meta with safe defaults. Times are stored as site-local "Y-m-d H:i" plus a timezone name. */
	public static function get( int $event_id ): array {
		$m = fn( $k ) => (string) get_post_meta( $event_id, self::META[ $k ], true );
		return array(
			'start'      => $m( 'start' ),
			'end'        => $m( 'end' ),
			'all_day'    => $m( 'all_day' ) === '1',
			'tz'         => $m( 'tz' ) ?: wp_timezone_string(),
			'rrule'      => $m( 'rrule' ),
			'exdates'    => $m( 'exdates' ),
			'online'     => $m( 'online' ) === '1',
			'online_url' => $m( 'online_url' ),
			'link'       => $m( 'link' ),
			'link_label' => $m( 'link_label' ),
			'overrides'  => self::decode_overrides( $m( 'overrides' ) ),
		);
	}

	/* ---------------- per-occurrence changes ---------------- */

	/**
	 * Overrides live in one JSON meta keyed by the occurrence's ORIGINAL date (the date the rule produced):
	 *   {"2026-10-20":{"status":"cancelled"},"2026-10-27":{"start":"2026-10-28 19:00","end":"2026-10-28 20:00"},
	 *    "2026-11-03":{"link":"https://…"}}
	 * A cancelled date stays visible as cancelled (unlike a skipped date, which disappears); a moved one is shown
	 * and exported at its new time; a date can carry its own ticket link (registration systems such as Blackbaud,
	 * Altru or Eventbrite sessions have a page per date), moved or not. Anything malformed is dropped rather than saved.
	 */
	public static function decode_overrides( string $json ): array {
		$raw = $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $raw ) ) {
			return array(); }
		$out = array();
		$dt  = fn( $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string) $v ) ? str_replace( 'T', ' ', substr( (string) $v, 0, 16 ) ) : '';
		foreach ( $raw as $ymd => $o ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ymd ) || ! is_array( $o ) ) {
				continue; }
			if ( ( $o['status'] ?? '' ) === 'cancelled' ) {
				$out[ $ymd ] = array( 'status' => 'cancelled' );
				continue;
			}
			$one = array();
			if ( $dt( $o['start'] ?? '' ) ) {
				$one['start'] = $dt( $o['start'] );
				$one['end']   = $dt( $o['end'] ?? '' );
			}
			$link = isset( $o['link'] ) && is_string( $o['link'] ) ? self::sanitize_url( $o['link'] ) : '';
			if ( $link !== '' ) {
				$one['link'] = $link; }
			if ( $one ) {
				$out[ $ymd ] = $one; }
		}
		ksort( $out );
		return $out;
	}

	/** A link as people type it: "zoom.us/j/123" becomes https://zoom.us/j/123 (esc_url_raw alone would add http://). */
	public static function sanitize_url( $value ): string {
		$v = trim( (string) $value );
		if ( $v !== '' && ! preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $v ) ) {
			$v = 'https://' . ltrim( $v, '/' ); }
		return esc_url_raw( $v, array( 'http', 'https' ) );
	}

	/**
	 * Skipped dates as people type them — 2026-12-25, 12/25/2026 (25/12/2026 where the site writes dates day first),
	 * 12/25/26, "Dec 25, 2026" — stored as "Y-m-d,Y-m-d". Anything unreadable is dropped rather than stored as noise
	 * that silently skips nothing (0.18.1 and earlier kept "12/25/2026" verbatim and ignored it).
	 */
	public static function sanitize_dates( $value ): string {
		$dmy    = (bool) preg_match( '/^[dj]/', (string) get_option( 'date_format' ) );
		$months = array_flip( array( '', 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec' ) );
		$out    = array();
		// "Dec 25, 2026" has its own comma: join it back before splitting the list on commas
		$value = preg_replace( '/([a-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?),\s*(\d{4})/i', '$1 $2', (string) $value );
		foreach ( preg_split( '/[,;\n]+/', (string) $value ) as $t ) {
			$t = trim( $t );
			$y = 0;
			$m = 0;
			$d = 0;
			if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $t, $x ) ) {
				list( , $y, $m, $d ) = array_map( 'intval', $x );
			} elseif ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $t, $x ) ) {
				$y = (int) $x[3] < 100 ? 2000 + (int) $x[3] : (int) $x[3];
				$m = $dmy ? (int) $x[2] : (int) $x[1];
				$d = $dmy ? (int) $x[1] : (int) $x[2];
			} elseif ( preg_match( '/^([a-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?\s+(\d{4})$/i', $t, $x ) && isset( $months[ strtolower( substr( $x[1], 0, 3 ) ) ] ) ) {
				// month names by pattern only: strtotime() reads loose words ("tuesday 2026") as some date
				$m = $months[ strtolower( substr( $x[1], 0, 3 ) ) ];
				$d = (int) $x[2];
				$y = (int) $x[3];
			} elseif ( preg_match( '/^(\d{1,2})(?:st|nd|rd|th)?\s+([a-z]{3,9})\.?\s+(\d{4})$/i', $t, $x ) && isset( $months[ strtolower( substr( $x[2], 0, 3 ) ) ] ) ) {
				$m = $months[ strtolower( substr( $x[2], 0, 3 ) ) ];
				$d = (int) $x[1];
				$y = (int) $x[3];
			}
			if ( $y && checkdate( $m, $d, $y ) ) {
				$out[] = sprintf( '%04d-%02d-%02d', $y, $m, $d ); }
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return implode( ',', $out );
	}

	/** Decoded overrides of one event, remembered for the request (views ask once per listed date). */
	private static function overrides_of( int $id ): array {
		static $memo = array();
		$json = (string) get_post_meta( $id, self::META['overrides'], true );
		$key  = $id . ':' . md5( $json );
		if ( ! isset( $memo[ $key ] ) ) {
			$memo[ $key ] = self::decode_overrides( $json ); }
		return $memo[ $key ];
	}

	/** The ticket / info link for one date (by the rule's original date): its own if it has one, else the event's. */
	public static function link_for( int $id, string $ymd ): string {
		$ov = self::overrides_of( $id );
		return (string) ( $ov[ $ymd ]['link'] ?? get_post_meta( $id, self::META['link'], true ) );
	}

	/** True when some date of this event has its own link (the single page then lists dates with their links). */
	public static function has_date_links( int $id ): bool {
		foreach ( self::overrides_of( $id ) as $o ) {
			if ( ! empty( $o['link'] ) ) {
				return true; }
		}
		return false;
	}

	/** Meta sanitizer: whatever comes in (REST or form), only well-formed overrides are stored, as canonical JSON. */
	public static function sanitize_overrides( $value ): string {
		$arr = self::decode_overrides( is_string( $value ) ? $value : (string) wp_json_encode( $value ) );
		return $arr ? (string) wp_json_encode( $arr ) : '';
	}

	/** Rows for the editor: the next $limit occurrences from today (cancelled and moved included), local times in the event's zone. */
	public static function occurrences_for_edit( int $event_id, int $limit = 8 ): array {
		global $wpdb;
		$e = self::get( $event_id );
		try {
			$tz = new \DateTimeZone( $e['tz'] );
		} catch ( \Exception $x ) {
			$tz = new \DateTimeZone( 'UTC' ); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own occurrence table
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT orig_ymd, start_utc, end_utc, status FROM %i WHERE event_id = %d AND end_utc >= %s ORDER BY start_utc ASC LIMIT %d', Schema::table(), $event_id, gmdate( 'Y-m-d H:i:s' ), $limit ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$s = ( new \DateTimeImmutable( $r['start_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$f = ( new \DateTimeImmutable( $r['end_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$out[] = array(
				'orig'   => $r['orig_ymd'],
				'start'  => $s->format( 'Y-m-d H:i' ),
				'end'    => $f->format( 'Y-m-d H:i' ),
				'status' => $r['status'],
				'link'   => (string) ( $e['overrides'][ $r['orig_ymd'] ]['link'] ?? '' ),
				'label'  => wp_date( get_option( 'date_format' ) . ( $e['all_day'] ? '' : ', ' . get_option( 'time_format' ) ), $s->getTimestamp(), $tz ),
			);
		}
		return $out;
	}

	/**
	 * Whether an online event's joining link is kept off public output (event page, .ics feeds, schema.org, REST) and
	 * handed only to people who sign up. Off by default; Beacon Events Pro turns it on per event.
	 */
	public static function join_private( int $id ): bool {
		return (bool) apply_filters( 'beacon_join_link_private', false, $id );
	}

	/** The joining link of an online event, or '' when there is none or it's private. */
	public static function public_join_url( int $id ): string {
		if ( get_post_meta( $id, self::META['online'], true ) !== '1' || self::join_private( $id ) ) {
			return '';
		}
		return (string) get_post_meta( $id, self::META['online_url'], true );
	}

	/** Registered meta is public in the REST API; a private joining link stays visible only to people who can edit. */
	public static function rest_hide_join( $response, $post ) {
		if ( $response instanceof \WP_REST_Response && self::join_private( (int) $post->ID ) && ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			$data = $response->get_data();
			if ( isset( $data['meta'][ self::META['online_url'] ] ) ) {
				$data['meta'][ self::META['online_url'] ] = '';
				$response->set_data( $data );
			}
		}
		return $response;
	}

	public static function rest(): void {
		register_rest_route(
			'beacon/v1',
			'/occurrences',
			array(
				'methods'             => 'GET',
				'permission_callback' => fn( $req ) => current_user_can( 'edit_post', (int) $req['post'] ),
				'args'                => array( 'post' => array( 'required' => true, 'type' => 'integer' ) ),
				'callback'            => fn( $req ) => rest_ensure_response( self::occurrences_for_edit( (int) $req['post'], 10 ) ),
			)
		);
	}

	/** Rebuild this event's occurrence rows from its rule. Bounded to a 2-year window from the start. */
	public static function materialize( int $event_id ): int {
		global $wpdb;
		$e = self::get( $event_id );
		$t = Schema::table();
		$wpdb->delete( $t, array( 'event_id' => $event_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table
		if ( ! $e['start'] ) {
			return 0; }
		try {
			$tz = new \DateTimeZone( $e['tz'] );
		} catch ( \Exception $x ) {
			$tz = new \DateTimeZone( 'UTC' ); }
		$start = new \DateTimeImmutable( $e['start'], $tz );
		$end   = $e['end'] ? new \DateTimeImmutable( $e['end'], $tz ) : $start->modify( '+1 hour' );
		if ( $end < $start ) {
			$end = $start; }
		$duration = $end->getTimestamp() - $start->getTimestamp();
		$rule     = $e['rrule'];
		if ( $e['exdates'] ) {
			$rule .= ( $rule ? ';' : 'FREQ=;' ) . 'EXDATE=' . str_replace( '-', '', $e['exdates'] ); }
		// A series keeps a year of history and always reaches two years past today (the daily refresh keeps it there),
		// so one that began years ago, imported or synced, still shows its upcoming dates.
		$from  = null;
		$until = $start->modify( '+2 years' );
		if ( $e['rrule'] ) {
			$today = new \DateTimeImmutable( 'today', $tz );
			$back  = $today->modify( '-1 year' );
			$from  = ( $start > $back ? $start : $back )->format( 'Y-m-d' );
			$until = ( $start > $today ? $start : $today )->modify( '+2 years' );
			update_post_meta( $event_id, self::HORIZON, $until->format( 'Y-m-d' ) );
		} else {
			delete_post_meta( $event_id, self::HORIZON );
		}
		$dates = Recurrence::expand( $rule, $start->format( 'Y-m-d' ), $until->format( 'Y-m-d' ), $tz, $from );
		$n     = 0;
		foreach ( $dates as $ymd ) {
			$s      = new \DateTimeImmutable( $ymd . ' ' . $start->format( 'H:i:s' ), $tz );   // same local wall-clock time each occurrence (DST-safe)
			$f      = $s->setTimestamp( $s->getTimestamp() + $duration );
			$status = 'scheduled';
			$ov     = $e['overrides'][ $ymd ] ?? null;
			if ( $ov && ( $ov['status'] ?? '' ) === 'cancelled' ) {
				$status = 'cancelled';
			} elseif ( $ov && ! empty( $ov['start'] ) ) {
				$status = 'moved';
				$s      = new \DateTimeImmutable( $ov['start'], $tz );
				$f      = ! empty( $ov['end'] ) ? new \DateTimeImmutable( $ov['end'], $tz ) : $s->setTimestamp( $s->getTimestamp() + $duration );
				if ( $f < $s ) {
					$f = $s; }
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- own occurrence table; rebuilt only on save
			$wpdb->insert(
				$t,
				array(
					'event_id'  => $event_id,
					'start_utc' => $s->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'end_utc'   => $f->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'all_day'   => $e['all_day'] ? 1 : 0,
					'orig_ymd'  => $ymd,
					'status'    => $status,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s' )
			);
			// phpcs:enable
			++$n;
		}
		return $n;
	}

	/**
	 * Daily: rebuild series whose dates run out within 18 months, so an open-ended event never quietly stops showing.
	 * A series with nothing left after its horizon is marked finished until the event is saved again.
	 */
	public static function refresh_series(): int {
		$soon = ( new \DateTimeImmutable( '+18 months', wp_timezone() ) )->format( 'Y-m-d' );
		// phpcs:disable WordPress.DB.SlowDBQuery -- daily cron over recurring events only, 200 at a time
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::META['rrule'],
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::HORIZON,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::HORIZON,
							'value'   => $soon,
							'compare' => '<',
						),
					),
				),
			)
		);
		// phpcs:enable
		$n = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$h  = (string) get_post_meta( $id, self::HORIZON, true );
			if ( $h !== '' && ! self::continues_after( self::get( $id ), $h ) ) {
				update_post_meta( $id, self::HORIZON, '9999-12-31' );
				continue; }
			self::materialize( $id );
			++$n;
		}
		return $n;
	}

	/** Does the event's rule produce any date after $ymd? */
	private static function continues_after( array $e, string $ymd ): bool {
		if ( ! $e['start'] || ! $e['rrule'] ) {
			return false; }
		try {
			$tz = new \DateTimeZone( $e['tz'] );
		} catch ( \Exception $x ) {
			$tz = new \DateTimeZone( 'UTC' ); }
		$after = ( new \DateTimeImmutable( $ymd, $tz ) )->modify( '+1 day' );
		return (bool) Recurrence::expand( $e['rrule'], substr( $e['start'], 0, 10 ), $after->modify( '+400 days' )->format( 'Y-m-d' ), $tz, $after->format( 'Y-m-d' ) );
	}

	public static function on_save( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return; }
		if ( $post->post_status === 'publish' ) {
			self::materialize( $post_id ); } else {
			global $wpdb;
			$wpdb->delete( Schema::table(), array( 'event_id' => $post_id ), array( '%d' ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table
	}

	public static function on_delete( int $post_id ): void {
		if ( get_post_type( $post_id ) === self::CPT ) {
			global $wpdb;
			$wpdb->delete( Schema::table(), array( 'event_id' => $post_id ), array( '%d' ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table
	}

	/** Occurrences of published events starting in [ $from_utc, $to_utc ), in time order; $offset pages through them. */
	public static function upcoming( string $from_utc, string $to_utc, int $limit = 200, array $tax = array(), int $offset = 0 ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- own occurrence table; results are per-request and cheap (indexed by start_utc).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.event_id, o.start_utc, o.end_utc, o.all_day, o.orig_ymd, o.status, p.post_title FROM %i o JOIN %i p ON p.ID = o.event_id WHERE p.post_status = %s AND o.start_utc >= %s AND o.start_utc < %s ORDER BY o.start_utc ASC, o.event_id ASC LIMIT %d OFFSET %d',
				Schema::table(),
				$wpdb->posts,
				'publish',
				$from_utc,
				$to_utc,
				$limit,
				max( 0, $offset )
			),
			ARRAY_A
		);
		// phpcs:enable
		return $rows ?: array();
	}
}
