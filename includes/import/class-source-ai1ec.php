<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;

/**
 * All-in-One Event Calendar by Time.ly (2.x, and 3.x sites that still run the local calendar). Each event is an
 * ai1ec_event post plus one row in {prefix}ai1ec_events: UTC timestamps, the event's timezone, an iCalendar RRULE,
 * skipped dates, and venue / contact / cost fields. Category colours live in {prefix}ai1ec_event_category_meta.
 * The plugin was closed on wordpress.org in August 2025; its tables are read directly, so this works whether it is
 * still active or already deactivated.
 */
final class Source_AI1EC {
	const KEY = 'ai1ec';
	const CPT = 'ai1ec_event';

	/** Set once anything was imported from here; gates the catch-all old-link redirect. */
	const OPT_LINKS = 'beacon_ai1ec_links';

	public static function label(): string {
		return __( 'All-in-One Event Calendar (Time.ly)', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Events that came from calendar feeds keep their feed IDs, so adding the same feed under Events → Settings later updates them instead of duplicating them. Once you deactivate All-in-One Event Calendar, its event, category and calendar-view links are redirected.', 'beacon-events' ); }

	private static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'ai1ec_' . $name;
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
		if ( ! self::has_table( 'events' ) ) {
			return array( 'found' => false ); }
		$t = self::table( 'events' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows
		$events   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')", $t, $wpdb->posts, self::CPT ) );
		$venues   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT e.venue) FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND e.venue <> ''", $t, $wpdb->posts, self::CPT ) );
		$contacts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT e.contact_name) FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND e.contact_name <> ''", $t, $wpdb->posts, self::CPT ) );
		$cats     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'events_categories'" );
		$imported = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_beacon_import' AND meta_value = 'ai1ec'" );
		// phpcs:enable
		return array(
			'found'      => $events > 0,
			'total'      => $events,
			'events'     => $events,
			'venues'     => $venues,
			'organizers' => $contacts,
			'categories' => $cats,
			'imported'   => $imported,
			'active'     => defined( 'AI1EC_POST_TYPE' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's rows; one-off migration
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT e.post_id FROM %i e JOIN %i p ON p.ID = e.post_id WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') ORDER BY e.post_id ASC LIMIT %d OFFSET %d", self::table( 'events' ), $wpdb->posts, self::CPT, $limit, $offset ) );
		return array_map( 'intval', (array) $ids );
	}

	public static function map( $post_id, array &$state ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading All-in-One Event Calendar's own table
		$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', self::table( 'events' ), (int) $post_id ), ARRAY_A );
		$p = $e ? get_post( (int) $post_id ) : null;
		if ( ! $e || ! $p ) {
			return null; }
		self::once( $state );
		$g     = fn( string $k ) => trim( (string) ( $e[ $k ] ?? '' ) );
		$title = $p->post_title;
		if ( (int) $e['start'] <= 0 ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $post_id );
			return null;
		}
		// Times are UTC timestamps; the row names the zone they were entered in ("sys.default" = the site's).
		$tz    = $g( 'timezone_name' ) === 'sys.default' ? '' : $g( 'timezone_name' );
		$zone  = ( $tz !== '' && in_array( $tz, timezone_identifiers_list(), true ) ) ? new \DateTimeZone( $tz ) : wp_timezone();
		$at    = fn( int $ts ) => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $zone );
		$s     = $at( (int) $e['start'] );
		$start = $s->format( 'Y-m-d H:i' );
		$all   = (int) $e['allday'] === 1;
		$end   = $start; // an "instant" event has no end time; Beacon shows the start alone when both match
		if ( empty( $e['instant_event'] ) && (int) $e['end'] > (int) $e['start'] ) {
			$f = $at( (int) $e['end'] );
			if ( $all && $f->format( 'H:i' ) === '00:00' ) {
				$f = $f->modify( '-1 minute' ); } // it ends an all-day event at the following midnight; Beacon keeps the last day
			$end = $f->format( 'Y-m-d H:i' );
		}
		[$rrule, $ex, $notes] = self::recurrence( $e, $s );
		$cats = array();
		foreach ( Import::source_terms( (int) $p->ID, 'events_categories' ) as $tid ) {
			$c = Import::term_chain( $tid, 'events_categories' );
			if ( $c ) {
				$cats[] = self::with_colors( $c ); }
		}
		if ( empty( $state['ai1ec_tags'] ) && Import::source_terms( (int) $p->ID, 'events_tags' ) ) {
			$state['ai1ec_tags'] = 1;
			$state['notes'][]    = __( 'Event tags were not imported: Beacon groups events by category only.', 'beacon-events' );
		}
		$uid = self::KEY . '-' . (int) $post_id;
		if ( $g( 'ical_feed_url' ) !== '' && $g( 'ical_uid' ) !== '' && ! in_array( $g( 'ical_uid' ), self::shared_uids(), true ) ) {
			$uid = $g( 'ical_uid' ); } // the feed's own UID: re-adding that feed in Beacon adopts the event instead of copying it again
		if ( empty( $state['dry'] ) ) { // a dry run writes nothing
			update_option( self::OPT_LINKS, '1' ); }
		return array(
			'uid'          => $uid,
			'src_id'       => (int) $post_id,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( array( '/[ \t]*\[\/?ai1ec[^\]]*\][ \t]*/i', '/^[ \t]+$/m' ), array( ' ', '' ), (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => $p->post_status,
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => $all,
			'tz'           => $tz,
			'rrule'        => $rrule,
			'exdates'      => $ex,
			'venue'        => self::venue( $g ),
			'organizers'   => $g( 'contact_name' ) !== '' ? array( array( 'name' => $g( 'contact_name' ) ) ) : array(),
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'extra'        => self::extra( $g ),
			'note'         => $notes ? implode( ' ', $notes ) : null,
		);
	}

	/**
	 * Returns [rrule, exdates[], notes[]]. Its editor writes rules such as FREQ=WEEKLY;WKST=MO;BYday=TU,TH; or
	 * FREQ=MONTHLY;BYday=2TU;COUNT=10; (note the lower-case "day"); feed imports keep the feed's RRULE. Skipped dates
	 * are YYYYMMDD[THHMMSSZ] values read as dates in the event's zone.
	 */
	private static function recurrence( array $e, \DateTimeImmutable $start ): array {
		$notes = array();
		$rule  = strtoupper( trim( (string) $e['recurrence_rules'] ) );
		$rrule = '';
		if ( strpos( $rule, 'FREQ=' ) !== false ) {
			$parts = array_filter( array_map( 'trim', explode( ';', $rule ) ) );
			// Yearly rules name the month ("BYMONTH=11"); when that is just the start's own month it adds nothing.
			$parts = array_filter( $parts, fn( $p ) => $p !== 'BYMONTH=' . $start->format( 'n' ) && $p !== 'BYMONTH=' . $start->format( 'm' ) );
			foreach ( $parts as $i => $part ) {
				// UNTIL=…T…Z is an instant; Beacon takes a date: the last day whose start time is still on or before it
				if ( preg_match( '/^UNTIL=(\d{8})T(\d{6})Z$/', $part, $m ) ) {
					$u = \DateTimeImmutable::createFromFormat( 'Ymd His', $m[1] . ' ' . $m[2], new \DateTimeZone( 'UTC' ) );
					if ( $u ) {
						$u = $u->setTimezone( $start->getTimezone() );
						if ( $u->format( 'His' ) < $start->format( 'His' ) ) {
							$u = $u->modify( '-1 day' ); }
						$parts[ $i ] = 'UNTIL=' . $u->format( 'Ymd' );
					}
				}
			}
			$note  = null;
			$rrule = Import::normalize_rrule( implode( ';', $parts ), $note );
			if ( $note ) {
				$notes[] = $note; }
		} elseif ( trim( (string) $e['recurrence_dates'] ) !== '' || strpos( $rule, 'RDATE=' ) === 0 ) {
			$notes[] = __( 'repeats on a list of specific dates, which Beacon cannot express as a rule; only the first date was imported.', 'beacon-events' );
		}
		$ex = array();
		if ( $rrule !== '' ) {
			foreach ( explode( ',', (string) $e['exception_dates'] ) as $d ) {
				if ( preg_match( '/^(\d{4})(\d{2})(\d{2})/', trim( $d ), $m ) ) {
					$ex[] = "$m[1]-$m[2]-$m[3]"; }
			}
			if ( strpos( strtoupper( (string) $e['exception_rules'] ), 'FREQ=' ) !== false ) {
				$kept = self::minus_weekdays( $rrule, strtoupper( (string) $e['exception_rules'] ), $start );
				if ( $kept !== '' ) {
					$rrule = $kept;
				} else {
					$notes[] = __( 'had a rule that excludes dates, which Beacon cannot express; single skipped dates were carried over, check the rest.', 'beacon-events' ); }
			}
		}
		return array( $rrule, array_values( array_unique( $ex ) ), $notes );
	}

	/**
	 * "Every day except weekends" is stored as a DAILY rule plus an exclusion rule (FREQ=WEEKLY;BYday=SA,SU); the same
	 * dates are a WEEKLY rule on the remaining weekdays. Returns that rule, or '' when the exclusion is anything else.
	 */
	private static function minus_weekdays( string $rrule, string $exrule, \DateTimeImmutable $start ): string {
		$kv  = function ( string $rule ): array {
			$out = array();
			foreach ( array_filter( array_map( 'trim', explode( ';', $rule ) ) ) as $part ) {
				$pair = explode( '=', $part, 2 );
				if ( count( $pair ) === 2 ) {
					$out[ $pair[0] ] = $pair[1]; }
			}
			return $out;
		};
		$all = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
		$r   = $kv( $rrule );
		$x   = $kv( $exrule );
		unset( $x['WKST'], $x['UNTIL'] );
		$drop = isset( $x['BYDAY'] ) ? explode( ',', $x['BYDAY'] ) : array();
		if ( ( $x['FREQ'] ?? '' ) !== 'WEEKLY' || (int) ( $x['INTERVAL'] ?? 1 ) !== 1 || array_diff( array_keys( $x ), array( 'FREQ', 'INTERVAL', 'BYDAY' ) )
			|| ! $drop || array_diff( $drop, $all ) || isset( $r['COUNT'] ) || in_array( strtoupper( substr( $start->format( 'D' ), 0, 2 ) ), $drop, true ) ) {
			return ''; } // COUNT would count different dates; a start on an excluded day would still show
		if ( ( $r['FREQ'] ?? '' ) === 'DAILY' && (int) ( $r['INTERVAL'] ?? 1 ) === 1 && empty( $r['BYDAY'] ) ) {
			$from = $all;
		} elseif ( ( $r['FREQ'] ?? '' ) === 'WEEKLY' && ! empty( $r['BYDAY'] ) ) {
			$from = explode( ',', $r['BYDAY'] );
		} else {
			return ''; }
		$keep = array_values( array_intersect( $all, array_diff( $from, $drop ) ) );
		if ( ! $keep ) {
			return ''; }
		return 'FREQ=WEEKLY;INTERVAL=' . ( ( $r['FREQ'] ?? '' ) === 'WEEKLY' ? max( 1, (int) ( $r['INTERVAL'] ?? 1 ) ) : 1 ) . ';BYDAY=' . implode( ',', $keep ) . ( isset( $r['UNTIL'] ) ? ';UNTIL=' . $r['UNTIL'] : '' );
	}

	/** Venue name and one address line. Its address field often holds the full formatted address already. */
	private static function venue( callable $g ): ?array {
		$tail = array_filter( array( $g( 'city' ), trim( $g( 'province' ) . ' ' . $g( 'postal_code' ) ), $g( 'country' ) ) );
		$addr = $g( 'address' );
		if ( $addr === '' ) {
			$addr = implode( ', ', $tail );
		} elseif ( $g( 'city' ) !== '' && stripos( $addr, $g( 'city' ) ) === false ) {
			$addr = implode( ', ', array_merge( array( $addr ), $tail ) ); }
		$name = $g( 'venue' );
		if ( $name === '' && $addr !== '' ) {
			$name = trim( explode( ',', $addr )[0] ); }
		return $name === '' ? null : array(
			'name'    => $name,
			'address' => $addr,
			'src_id'  => 0,
		);
	}

	/** Cost, ticket link and contact details: kept in hidden meta, reported, not shown. */
	private static function extra( callable $g ): array {
		$extra = array();
		$cost  = $g( 'cost' );
		// Stored serialized: a:2:{s:4:"cost";s:N:"…";s:7:"is_free";b:0;} (plain text on very old versions).
		if ( preg_match( '/s:4:"cost";s:\d+:"(.*?)";s:7:"is_free";b:([01]);/s', $cost, $m ) ) {
			$cost = $m[1];
			if ( $m[2] === '1' ) {
				$extra['is_free'] = 1; }
		} elseif ( strpos( $cost, 'a:' ) === 0 ) {
			$cost = ''; }
		if ( trim( $cost ) !== '' ) {
			$extra['cost'] = trim( $cost ); }
		foreach ( array( 'ticket_url', 'contact_phone', 'contact_email', 'contact_url' ) as $k ) {
			if ( $g( $k ) !== '' ) {
				$extra[ $k ] = $g( $k ); }
		}
		return $extra;
	}

	/** Adds each category's (and its parents') colour from ai1ec_event_category_meta. */
	private static function with_colors( array $c ): array {
		static $colors = null;
		if ( $colors === null ) {
			$colors = array();
			if ( self::has_table( 'event_category_meta' ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading its colour table once per request
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT term_id, term_color FROM %i', self::table( 'event_category_meta' ) ), ARRAY_A ) as $r ) {
					$colors[ (int) $r['term_id'] ] = (string) $r['term_color']; }
			}
		}
		if ( ! empty( $colors[ (int) ( $c['src_id'] ?? 0 ) ] ) ) {
			$c['color'] = $colors[ (int) $c['src_id'] ]; }
		if ( ! empty( $c['parent'] ) && is_array( $c['parent'] ) ) {
			$c['parent'] = self::with_colors( $c['parent'] ); }
		return $c;
	}

	/** Feed UIDs used by more than one of its events (a feed's moved single dates): those keep a per-post uid. */
	private static function shared_uids(): array {
		static $dups = null;
		if ( $dups === null ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once per request
			$dups = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ical_uid FROM %i WHERE ical_uid <> '' AND ical_feed_url <> '' GROUP BY ical_uid HAVING COUNT(*) > 1", self::table( 'events' ) ) );
		}
		return $dups;
	}

	/** Notes that concern the whole calendar, added once per import: its feeds and its calendar page. */
	private static function once( array &$state ): void {
		if ( ! empty( $state['ai1ec_once'] ) ) {
			return; }
		$state['ai1ec_once'] = 1;
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once per import
		$feeds = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ical_feed_url FROM %i WHERE ical_feed_url <> '' ORDER BY ical_feed_url LIMIT 20", self::table( 'events' ) ) );
		if ( $feeds ) {
			$state['notes'][] = sprintf( /* translators: %s: list of feed URLs */ __( 'Some events came from calendar feeds. Add these under Events → Settings to keep them updating (the imported copies are matched, not duplicated): %s', 'beacon-events' ), implode( ', ', $feeds ) ); }
		$settings = get_option( 'ai1ec_settings' );
		$page_id  = 0;
		if ( is_array( $settings ) && isset( $settings['calendar_page_id'] ) ) {
			$v       = $settings['calendar_page_id'];
			$page_id = (int) ( is_array( $v ) ? ( $v['value'] ?? 0 ) : $v );
		}
		$page = $page_id ? get_post( $page_id ) : null;
		if ( $page && $page->post_type === 'page' && strpos( (string) $page->post_content, 'beacon' ) === false ) {
			$state['notes'][] = sprintf( /* translators: 1: page title, 2: page URL */ __( 'All-in-One Event Calendar drew its calendar into the page "%1$s" (%2$s). Once it is deactivated that page is empty: add the Beacon Events calendar block, or the shortcode [beacon_events view="month"], to it.', 'beacon-events' ), get_the_title( $page ), (string) get_permalink( $page ) );
		}
	}
}
