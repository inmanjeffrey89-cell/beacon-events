<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * ICS / Google Calendar import with scheduled sync and a visible drift log, plus iCal export.
 * The leader's most-repeated sync complaint is "it went out of sync silently" — so every sync writes what it did.
 * Google: paste the calendar's public ICS address (Settings → Integrate calendar → "Public address in iCal format").
 */
final class Ics {
	const OPT_FEEDS = 'beacon_feeds';       // array of ['url'=>..., 'category'=>slug]
	const OPT_LOG   = 'beacon_sync_log';    // last 30 sync entries
	const CRON      = 'beacon_sync_feeds';

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'sync_all' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 300, 'hourly', self::CRON );
				} }
		);
		// Before redirect_canonical (priority 10): a ?beacon_category=slug feed URL must not be bounced to the pretty term URL.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_export' ), 1 );
	}

	/* ---------------- parsing ---------------- */

	/** Unfold RFC 5545 line continuations and split into VEVENT arrays of [PROP => [params, value]]. */
	public static function parse( string $ics ): array {
		$ics    = preg_replace( "/\r\n[ \t]|\n[ \t]/", '', str_replace( "\r\n", "\n", $ics ) );
		$events = array();
		$cur    = null;
		$cal_tz = null;
		foreach ( explode( "\n", $ics ) as $line ) {
			$line = rtrim( $line );
			if ( $line === 'BEGIN:VEVENT' ) {
				$cur = array();
				continue; }
			if ( $line === 'END:VEVENT' ) {
				if ( $cur !== null ) {
					$events[] = $cur;
				} $cur = null;
				continue; }
			if ( strpos( $line, ':' ) === false ) {
				continue; }
			[ $left, $value ] = explode( ':', $line, 2 );
			$parts            = explode( ';', $left );
			$prop             = strtoupper( array_shift( $parts ) );
			$params           = array();
			foreach ( $parts as $p ) {
				if ( strpos( $p, '=' ) !== false ) {
					[ $k, $v ]                  = explode( '=', $p, 2 );
					$params[ strtoupper( $k ) ] = trim( $v, '"' ); }
			}
			if ( $cur === null ) {
				if ( $prop === 'X-WR-TIMEZONE' ) {
					$cal_tz = $value;
				} continue; }
			$key = $prop;   // a property can repeat (Google sends one EXDATE line per skipped date): keep them all
			for ( $n = 2; isset( $cur[ $key ] ); ++$n ) {
				$key = $prop . '#' . $n; }
			$cur[ $key ] = array(
				'params' => $params,
				'value'  => $value,
			);
		}
		return array(
			'tz'     => $cal_tz,
			'events' => $events,
		);
	}

	/** DTSTART/DTEND → [Y-m-d H:i local, tz name, all_day]. */
	private static function dt( array $p, ?string $cal_tz ): ?array {
		$v  = $p['value'];
		$tz = $p['params']['TZID'] ?? null;
		if ( ( $p['params']['VALUE'] ?? '' ) === 'DATE' || preg_match( '/^\d{8}$/', $v ) ) {
			return array( substr( $v, 0, 4 ) . '-' . substr( $v, 4, 2 ) . '-' . substr( $v, 6, 2 ) . ' 00:00', $tz ?: ( $cal_tz ?: wp_timezone_string() ), true ); }
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?(Z?)$/', $v, $m ) ) {
			return null; }
		$local = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]";
		if ( $m[7] === 'Z' ) {   // UTC → convert to the site's timezone for storage
			$d    = new \DateTimeImmutable( $local, new \DateTimeZone( 'UTC' ) );
			$site = wp_timezone_string();
			return array( $d->setTimezone( new \DateTimeZone( $site ) )->format( 'Y-m-d H:i' ), $site, false );
		}
		return array( $local, $tz ?: ( $cal_tz ?: wp_timezone_string() ), false );
	}

	/* ---------------- sync ---------------- */

	public static function feeds(): array {
		$f = get_option( self::OPT_FEEDS, array() );
		return is_array( $f ) ? $f : array(); }

	public static function sync_all(): void {
		foreach ( self::feeds() as $i => $f ) {
			self::sync_feed( $i, $f ); } }

	/** Upsert by UID; events that vanished from the feed are set to draft (never silently deleted) and logged. */
	public static function sync_feed( int $index, array $feed ): array {
		$url   = esc_url_raw( $feed['url'] ?? '' );
		$entry = array(
			'time'    => current_time( 'mysql' ),
			'url'     => $url,
			'created' => 0,
			'updated' => 0,
			'removed' => 0,
			'errors'  => array(),
		);
		if ( ! $url ) {
			$entry['errors'][] = 'empty url';
			return self::log( $entry ); }
		$res = wp_safe_remote_get( // refuses local and private addresses: the URL is typed by a person
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'Beacon Events/' . BEACON_VERSION,
			)
		);
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
			$entry['errors'][] = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
			return self::log( $entry ); }
		$body = (string) wp_remote_retrieve_body( $res );
		// Not a calendar (a login or error page, a calendar made private): stop before anything changes. Parsed as an
		// empty feed, every event from it would count as removed and go to drafts (0.18.4 and earlier).
		if ( false === stripos( $body, 'BEGIN:VCALENDAR' ) ) {
			$entry['errors'][] = __( 'this address returned a web page, not a calendar file; nothing was changed. Check that the calendar is public and that you copied its iCal address.', 'beacon-events' );
			return self::log( $entry ); }
		$parsed = self::parse( $body );
		// One post per series. A changed date of a repeating event (RECURRENCE-ID) shares the series' UID: it becomes a
		// per-date change on the series instead of overwriting it. A changed date whose series isn't in the feed stands alone.
		$series  = array();
		$changed = array();
		foreach ( $parsed['events'] as $ev ) {
			$uid = $ev['UID']['value'] ?? '';
			if ( ! $uid || empty( $ev['DTSTART'] ) ) {
				continue; }
			if ( empty( $ev['RECURRENCE-ID'] ) ) {
				$series[ $uid ] = $ev;
			} else {
				$changed[ $uid ][] = $ev; }
		}
		foreach ( $changed as $uid => $list ) {
			if ( ! isset( $series[ $uid ] ) ) {
				foreach ( $list as $ev ) {
					$series[ $uid . '/' . $ev['RECURRENCE-ID']['value'] ] = $ev; }
				unset( $changed[ $uid ] );
			}
		}
		$seen = array();
		foreach ( $series as $uid => $ev ) {
			$uid = (string) $uid;
			if ( strtoupper( $ev['STATUS']['value'] ?? '' ) === 'CANCELLED' ) {
				continue; }   // counts as gone: drafted below if we had it
			$start = self::dt( $ev['DTSTART'], $parsed['tz'] );
			if ( ! $start ) {
				continue; }
			$end = ! empty( $ev['DTEND'] ) ? self::dt( $ev['DTEND'], $parsed['tz'] ) : null;
			if ( $start[2] && $end ) {
				$end[0] = ( new \DateTimeImmutable( $end[0] ) )->modify( '-1 day' )->format( 'Y-m-d 23:59' ); }   // ICS all-day DTEND is exclusive
			$tz    = in_array( $start[1], timezone_identifiers_list(), true ) ? $start[1] : wp_timezone_string();
			$title = self::unescape( $ev['SUMMARY']['value'] ?? __( '(untitled)', 'beacon-events' ) );
			$desc  = self::unescape( $ev['DESCRIPTION']['value'] ?? '' );
			$rrule = self::rule( $ev['RRULE']['value'] ?? '', $start, $tz, self::utc_shift( $ev['DTSTART']['value'], $start[0] ) );
			$ex    = $rrule ? self::exdates( $ev, $tz ) : array();
			$ov    = $rrule ? self::changes( $changed[ $uid ] ?? array(), $start, $end, $tz, $parsed['tz'] ) : array();
			$seen[ $uid ] = true;
			// phpcs:disable WordPress.DB.SlowDBQuery -- UID lookup; feeds are small and this runs hourly
			$existing = get_posts(
				array(
					'post_type'      => Events::CPT,
					'post_status'    => 'any',
					'meta_key'       => '_beacon_uid',
					'meta_value'     => $uid,
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			// phpcs:enable
			$data = array(
				'post_type'    => Events::CPT,
				'post_status'  => 'publish',
				'post_title'   => wp_strip_all_tags( $title ),
				'post_content' => wp_kses_post( nl2br( $desc ) ),
			);
			if ( $existing ) {
				$data['ID'] = (int) $existing[0]; }
			// Feeds sync from cron, where WordPress runs kses over titles (no user) and stores "Q&A Night" as
			// "Q&amp;A Night", which the editor then shows. The title is plain text with its tags stripped: keep it as
			// written. (wp_insert_post unslashes, so slash it first or "AC\DC" loses its backslash.)
			$kses = has_filter( 'title_save_pre', 'wp_filter_kses' );
			if ( false !== $kses ) {
				remove_filter( 'title_save_pre', 'wp_filter_kses', $kses ); }
			$id = $existing ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
			if ( false !== $kses ) {
				add_filter( 'title_save_pre', 'wp_filter_kses', $kses ); }
			$existing ? ++$entry['updated'] : ++$entry['created'];
			if ( is_wp_error( $id ) ) {
				$entry['errors'][] = $id->get_error_message();
				continue; }
			update_post_meta( $id, '_beacon_uid', $uid );
			update_post_meta( $id, '_beacon_feed', $index );
			update_post_meta( $id, Events::META['start'], $start[0] );
			update_post_meta( $id, Events::META['end'], $end ? $end[0] : '' );
			update_post_meta( $id, Events::META['tz'], $tz );
			update_post_meta( $id, Events::META['all_day'], $start[2] ? '1' : '0' );
			update_post_meta( $id, Events::META['rrule'], $rrule );
			update_post_meta( $id, Events::META['exdates'], implode( ',', $ex ) );
			update_post_meta( $id, Events::META['overrides'], $ov ? wp_json_encode( $ov ) : '' );
			if ( ! empty( $ev['LOCATION']['value'] ) ) {
				// "Community Hall, 410 Oak St, Springfield" → venue "Community Hall" with the rest as its address.
				$parts = array_map( 'trim', explode( ',', self::unescape( $ev['LOCATION']['value'] ), 2 ) );
				$set   = wp_set_object_terms( $id, $parts[0], 'beacon_venue' );
				// wp_set_object_terms() returns term_taxonomy_ids; term meta is keyed by term_id
				$venue = ( is_array( $set ) && $set ) ? get_term_by( 'term_taxonomy_id', (int) $set[0], 'beacon_venue' ) : false;
				if ( ! empty( $parts[1] ) && $venue instanceof \WP_Term && ! get_term_meta( $venue->term_id, 'beacon_address', true ) ) {
					update_term_meta( $venue->term_id, 'beacon_address', sanitize_text_field( $parts[1] ) ); }
			}
			if ( ! empty( $feed['category'] ) ) {
				wp_set_object_terms( $id, sanitize_title( $feed['category'] ), 'beacon_category' ); }
			Events::materialize( $id );
		}
		// vanished from feed → draft + log (a silent delete is exactly the complaint we are here to fix)
		// phpcs:disable WordPress.DB.SlowDBQuery -- per-feed lookup; hourly
		$mine = get_posts(
			array(
				'post_type'      => Events::CPT,
				'post_status'    => 'publish',
				'meta_key'       => '_beacon_feed',
				'meta_value'     => $index,
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		// phpcs:enable
		foreach ( $mine as $pid ) {
			if ( ! isset( $seen[ get_post_meta( $pid, '_beacon_uid', true ) ] ) ) {
				wp_update_post(
					array(
						'ID'          => $pid,
						'post_status' => 'draft',
					)
				);
				++$entry['removed']; }
		}
		return self::log( $entry );
	}

	private static function unescape( string $s ): string {
		return str_replace( array( '\\n', '\\N', '\\,', '\\;', '\\\\' ), array( "\n", "\n", ',', ';', '\\' ), $s ); }

	/** A feed's RRULE reduced to what Beacon repeats: UNTIL as the event's last local date, BYSETPOS folded into BYDAY. */
	private static function rule( string $rrule, array $start, string $tz, int $shift = 0 ): string {
		if ( trim( $rrule ) === '' ) {
			return ''; }
		$kv = array();
		foreach ( explode( ';', strtoupper( trim( $rrule ) ) ) as $p ) {
			if ( strpos( $p, '=' ) !== false ) {
				[ $k, $v ]  = explode( '=', $p, 2 );
				$kv[ $k ] = $v; }
		}
		// "second Tuesday" written as BYDAY=TU;BYSETPOS=2 (Outlook) is Beacon's BYDAY=2TU
		if ( isset( $kv['BYSETPOS'], $kv['BYDAY'] ) && preg_match( '/^-?[1-5]$/', $kv['BYSETPOS'] ) && preg_match( '/^[A-Z]{2}$/', $kv['BYDAY'] ) ) {
			$kv['BYDAY'] = $kv['BYSETPOS'] . $kv['BYDAY'];
			unset( $kv['BYSETPOS'] );
		}
		// a UTC start that lands on another local day moves its weekdays with it (Wednesday 00:30Z is Tuesday evening in Chicago)
		if ( $shift && ( $kv['FREQ'] ?? '' ) === 'WEEKLY' && isset( $kv['BYDAY'] ) && preg_match( '/^[A-Z]{2}(,[A-Z]{2})*$/', $kv['BYDAY'] ) ) {
			$week        = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
			$kv['BYDAY'] = implode( ',', array_map( fn( $d ) => in_array( $d, $week, true ) ? $week[ ( array_search( $d, $week, true ) + $shift + 7 ) % 7 ] : $d, explode( ',', $kv['BYDAY'] ) ) );
		}
		// UNTIL=…T…Z is an instant; Beacon takes a date: the last day whose start time is still on or before it
		if ( isset( $kv['UNTIL'] ) && preg_match( '/^(\d{8})T(\d{6})Z$/', $kv['UNTIL'], $m ) ) {
			$u = \DateTimeImmutable::createFromFormat( 'Ymd His', $m[1] . ' ' . $m[2], new \DateTimeZone( 'UTC' ) );
			if ( $u ) {
				$u = $u->setTimezone( new \DateTimeZone( $tz ) );
				if ( $u->format( 'H:i' ) < substr( $start[0], 11, 5 ) ) {
					$u = $u->modify( '-1 day' ); }
				$kv['UNTIL'] = $u->format( 'Ymd' );
			}
		}
		$parts = array();
		foreach ( $kv as $k => $v ) {
			$parts[] = $k . '=' . $v; }
		$note = null;
		return Import::normalize_rrule( implode( ';', $parts ), $note );
	}

	/** Days between a UTC DTSTART's own date and the local date it was stored as (-1, 0 or 1); 0 for non-UTC values. */
	private static function utc_shift( string $v, string $local ): int {
		if ( ! preg_match( '/^(\d{8})T\d{4,6}Z$/', $v, $m ) ) {
			return 0; }
		$utc = \DateTimeImmutable::createFromFormat( '!Ymd', $m[1] );
		$loc = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $local, 0, 10 ) );
		return ( $utc && $loc ) ? (int) $utc->diff( $loc )->format( '%r%a' ) : 0;
	}

	/** Every EXDATE value (a feed may send several lines, each with several dates) as Y-m-d in the event's zone. */
	private static function exdates( array $ev, string $tz ): array {
		$out = array();
		foreach ( $ev as $k => $p ) {
			if ( strpos( (string) $k, 'EXDATE' ) !== 0 ) {
				continue; }
			foreach ( explode( ',', $p['value'] ) as $x ) {
				$d = self::local_date( trim( $x ), $p['params']['TZID'] ?? null, $tz );
				if ( $d !== '' ) {
					$out[] = $d; }
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Changed dates of a repeating event (VEVENTs with RECURRENCE-ID) as Beacon's per-date overrides, keyed by the date
	 * the rule gave: cancelled → {status:cancelled}; moved → {start,end} in the event's zone. A change that keeps the
	 * slot (only the title or text differ) has nothing to show per date and is left out.
	 */
	private static function changes( array $list, array $start, ?array $end, string $tz, ?string $cal_tz ): array {
		$out  = array();
		$zone = new \DateTimeZone( $tz );
		$s0   = new \DateTimeImmutable( $start[0], $zone );
		$len  = $end ? max( 0, ( new \DateTimeImmutable( $end[0], $zone ) )->getTimestamp() - $s0->getTimestamp() ) : 3600;
		foreach ( $list as $ev ) {
			$rid  = $ev['RECURRENCE-ID'];
			$orig = self::local_date( $rid['value'], $rid['params']['TZID'] ?? null, $tz );
			if ( $orig === '' ) {
				continue; }
			if ( strtoupper( $ev['STATUS']['value'] ?? '' ) === 'CANCELLED' ) {
				$out[ $orig ] = array( 'status' => 'cancelled' );
				continue; }
			$s = self::dt( $ev['DTSTART'], $cal_tz );
			if ( ! $s ) {
				continue; }
			$f  = ! empty( $ev['DTEND'] ) ? self::dt( $ev['DTEND'], $cal_tz ) : null;
			$ns = self::in_zone( $s, $tz );
			if ( $f && $s[2] ) {   // all-day DTEND is exclusive
				$nf = ( new \DateTimeImmutable( substr( $f[0], 0, 10 ) ) )->modify( '-1 day' )->format( 'Y-m-d' ) . ' 23:59';
			} else {
				$nf = $f ? self::in_zone( $f, $tz ) : ( new \DateTimeImmutable( $ns, $zone ) )->modify( "+$len seconds" )->format( 'Y-m-d H:i' ); }
			$was = new \DateTimeImmutable( $orig . ' ' . $s0->format( 'H:i' ), $zone );
			if ( $ns === $was->format( 'Y-m-d H:i' ) && $nf === $was->modify( "+$len seconds" )->format( 'Y-m-d H:i' ) ) {
				continue; }
			$out[ $orig ] = array(
				'start' => $ns,
				'end'   => $nf,
			);
		}
		ksort( $out );
		return $out;
	}

	/** dt()'s [local, zone, all_day] as "Y-m-d H:i" in $tz. */
	private static function in_zone( array $d, string $tz ): string {
		if ( $d[2] || $d[1] === $tz ) {
			return $d[0]; }
		try {
			return ( new \DateTimeImmutable( $d[0], new \DateTimeZone( $d[1] ) ) )->setTimezone( new \DateTimeZone( $tz ) )->format( 'Y-m-d H:i' );
		} catch ( \Exception $x ) {
			return $d[0]; }
	}

	/** An ICS DATE or DATE-TIME as the Y-m-d it falls on in $tz (UTC "Z" values and other zones are converted). */
	private static function local_date( string $v, ?string $from_tz, string $tz ): string {
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})\d{0,2}(Z?))?$/', $v, $m ) ) {
			return ''; }
		$ymd = "$m[1]-$m[2]-$m[3]";
		if ( ! isset( $m[4] ) ) {
			return $ymd; }
		$src = ( $m[6] ?? '' ) === 'Z' ? 'UTC' : ( ( $from_tz && in_array( $from_tz, timezone_identifiers_list(), true ) ) ? $from_tz : $tz );
		if ( $src === $tz ) {
			return $ymd; }
		try {
			return ( new \DateTimeImmutable( "$ymd $m[4]:$m[5]", new \DateTimeZone( $src ) ) )->setTimezone( new \DateTimeZone( $tz ) )->format( 'Y-m-d' );
		} catch ( \Exception $x ) {
			return $ymd; }
	}

	private static function log( array $entry ): array {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array(); }
		array_unshift( $log, $entry );
		update_option( self::OPT_LOG, array_slice( $log, 0, 30 ), false );
		return $entry;
	}

	/* ---------------- export ---------------- */

	/** ?beacon_ical=all → next 12 months of occurrences (up to 5,000); ?beacon_ical=<id> → that event's occurrences. */
	public static function maybe_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only export; no state changes.
		if ( ! isset( $_GET['beacon_ical'] ) ) {
			return; }
		$which = sanitize_text_field( wp_unslash( $_GET['beacon_ical'] ) );
		// phpcs:enable
		// One event, or ?beacon_category=slug (or beacon_venue / beacon_organizer) as linked from a term's page: pick those
		// events BEFORE the date limit. Filtering afterwards (0.20.4 and earlier) left an event page's .ics, or a small
		// category's feed, empty on a busy calendar whose first 1,000 dates belonged to other events.
		$only = 'all' === $which ? null : array_filter( array( (int) $which ) ); // "?beacon_ical=foo" → id 0 → an empty calendar
		foreach ( Views::TAX_ATTR as $tax => $attr ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only export
			$slug = isset( $_GET[ 'beacon_' . $attr ] ) ? sanitize_title( wp_unslash( $_GET[ 'beacon_' . $attr ] ) ) : '';
			// phpcs:enable
			if ( $slug ) {
				$term = get_term_by( 'slug', $slug, $tax );
				$in   = $term ? array_map( 'intval', (array) get_objects_in_term( (int) $term->term_id, $tax ) ) : array();
				$only = null === $only ? $in : array_values( array_intersect( $only, $in ) );
			}
		}
		// Up to 5,000 dates (a year of a studio running a dozen classes a day, about 1.5 MB), read in pages that double,
		// keeping those events' dates, until there are enough or the year runs out.
		$keep = null === $only ? null : array_flip( $only );
		$from = gmdate( 'Y-m-d H:i:s' );
		$to   = gmdate( 'Y-m-d H:i:s', strtotime( '+12 months' ) );
		$rows = array();
		$page = 2000;
		$off  = 0;
		while ( null === $keep || $keep ) {
			$chunk = Events::upcoming( $from, $to, $page, array(), $off );
			foreach ( $chunk as $r ) {
				if ( ( null === $keep || isset( $keep[ (int) $r['event_id'] ] ) ) && ( $r['status'] ?? 'scheduled' ) !== 'cancelled' ) { // a cancelled date should leave the subscriber's calendar
					$rows[] = $r; }
			}
			$off  += $page;
			$page *= 2;
			if ( count( $rows ) >= 5000 || count( $chunk ) < $page / 2 || $off >= 100000 ) {
				break; }
		}
		$rows = array_slice( $rows, 0, 5000 );
		// RFC 5545 §3.3.11: normalise every line break to \n and escape it. A bare CR would otherwise
		// end the content line, letting a site name or venue inject arbitrary ICS properties.
		$esc = static function ( $s ) {
			$s = preg_replace( '/\r\n|\r/', "\n", (string) $s );
			$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s );
			return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $s );
		};
		$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Beacon Events//EN\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:" . $esc( Views::plain( (string) get_bloginfo( 'name' ) ) ) . "\r\n";
		foreach ( $rows as $r ) {
			$id   = (int) $r['event_id'];
			$fmt  = fn( $u ) => gmdate( 'Ymd\THis\Z', strtotime( $u . ' UTC' ) );
			$out .= "BEGIN:VEVENT\r\nUID:beacon-$id-" . gmdate( 'Ymd', strtotime( $r['start_utc'] . ' UTC' ) ) . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) . "\r\nDTSTAMP:" . gmdate( 'Ymd\THis\Z' ) . "\r\n";
			if ( (int) $r['all_day'] ) {
				// All-day dates are the event's own local dates (its midnight is the previous day in UTC east of Greenwich);
				// DTEND is exclusive, so a one-day event ends the next day.
				$ymd  = fn( $u ) => ( new \DateTimeImmutable( $u, new \DateTimeZone( 'UTC' ) ) )->setTimezone( Events::tz_of( $id ) );
				$out .= 'DTSTART;VALUE=DATE:' . $ymd( $r['start_utc'] )->format( 'Ymd' ) . "\r\nDTEND;VALUE=DATE:" . $ymd( $r['end_utc'] ?: $r['start_utc'] )->modify( '+1 day' )->format( 'Ymd' ) . "\r\n";
			} else {
				$out .= 'DTSTART:' . $fmt( $r['start_utc'] ) . "\r\nDTEND:" . $fmt( $r['end_utc'] ) . "\r\n";
			}
			$loc  = Views::location( $id );
			$join = Events::public_join_url( $id ); // '' when the organizer keeps it for people who sign up
			$link = Events::link_for( $id, (string) ( $r['orig_ymd'] ?? '' ) ); // a date's own registration page, else the event's
			$desc = array_filter(
				array(
					$join ? __( 'Join online:', 'beacon-events' ) . ' ' . $join : '',
					$link ? Views::link_label( array( 'link_label' => get_post_meta( $id, Events::META['link_label'], true ) ) ) . ': ' . $link : '',
				)
			);
			$out .= 'SUMMARY:' . $esc( Views::plain( (string) $r['post_title'] ) ) . "\r\n" . ( $loc ? 'LOCATION:' . $esc( $loc ) . "\r\n" : '' ) . ( $desc ? 'DESCRIPTION:' . $esc( implode( "\n", $desc ) ) . "\r\n" : '' ) . 'URL:' . esc_url_raw( get_permalink( $id ) ) . "\r\nEND:VEVENT\r\n";
		}
		$out .= "END:VCALENDAR\r\n";
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="events.ics"' );
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- ICS payload, escaped above
		exit;
	}
}
