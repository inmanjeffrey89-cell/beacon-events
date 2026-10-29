<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;
use Beacon\Recurrence;

/**
 * My Calendar by Joe Dolson (data model read from 3.8.11, events made through its own mc_insert_event). Events live in its
 * own tables: {prefix}my_calendar (one row per event: event_begin / event_end DATE, event_time / event_endtime TIME in the
 * site's time — no per-event zone; all day = 00:00:00 to 23:59:59; event_recur = a letter + interval, "W2"; event_repeats =
 * an end date or a count; event_approved 0 draft, 1 published, 2 trash, 3 cancelled, 4/5 private, 6 scheduled; event_flagged
 * 1 = spam; event_location → {prefix}my_calendar_locations; event_tickets, event_link; event_post = its public mc-events post,
 * which holds the slug, content and featured image) and {prefix}my_calendar_events (one row per date: the source of truth).
 * Categories: {prefix}my_calendar_category_relationships → {prefix}my_calendar_categories (category_color, "#" optional).
 * A rule is rebuilt from event_recur (D daily, E weekdays, W/B weekly, M monthly by date, U monthly by weekday of the month,
 * Y yearly) to the last stored date and kept when it gives those dates (dates it gives that My Calendar doesn't have —
 * deleted ones — become skip dates, at most half as many as the real ones); otherwise the first date comes over and the
 * report lists the rest. Works with My Calendar active or deactivated.
 */
final class Source_MC {
	const KEY       = 'mycalendar';
	const OPT_LINKS = 'beacon_mc_links'; // its URL bases, saved at import so old links can be redirected later

	private const DAYS = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	public static function label(): string {
		return __( 'My Calendar', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Once you deactivate My Calendar, its event, location and category links, and its ?mc_id= date links, are redirected to the imported ones.', 'beacon-events' ); }

	private static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $name; }

	private static function has_table(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- does another plugin's table exist
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::t( 'my_calendar' ) ) ) );
	}

	public static function detect(): array {
		global $wpdb;
		$found = self::has_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows in its own tables
		$events = $found ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_flagged <> 1 AND event_approved <> 2', self::t( 'my_calendar' ) ) ) : 0;
		$venues = $found ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::t( 'my_calendar_locations' ) ) ) : 0;
		$cats   = $found ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::t( 'my_calendar_categories' ) ) ) : 0;
		// phpcs:enable
		return array(
			'found'      => $events > 0,
			'total'      => $events,
			'events'     => $events,
			'venues'     => $venues,
			'organizers' => 0,
			'categories' => $cats,
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => function_exists( 'mc_get_version' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		if ( ! self::has_table() ) {
			return array(); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's table; one-off migration
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_flagged <> 1 AND event_approved <> 2 ORDER BY event_id ASC LIMIT %d OFFSET %d', self::t( 'my_calendar' ), $limit, $offset ) );
		return array_map( 'intval', (array) $ids );
	}

	/** My Calendar's URL bases (its settings, else its defaults). */
	public static function slugs(): array {
		$o = (array) get_option( 'my_calendar_options', array() );
		return array(
			'event'    => trim( (string) ( $o['cpt_base'] ?? '' ), '/' ) ?: 'mc-events',
			'location' => trim( (string) ( $o['location_cpt_base'] ?? '' ), '/' ) ?: 'mc-locations',
		);
	}

	public static function map( $event_id, array &$state ): ?array {
		global $wpdb;
		static $saved = false;
		if ( ! $saved && empty( $state['dry'] ) ) { // a dry run writes nothing
			update_option( self::OPT_LINKS, self::slugs(), false );
			$saved = true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's event row
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d', self::t( 'my_calendar' ), (int) $event_id ) );
		if ( ! $r ) {
			return null; }
		$post  = $r->event_post ? get_post( (int) $r->event_post ) : null;
		$title = '' !== (string) $r->event_title ? wp_specialchars_decode( (string) $r->event_title, ENT_QUOTES ) : __( '(Untitled event)', 'beacon-events' );
		$b     = (string) $r->event_begin;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $b ) || '0000-00-00' === $b ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $r->event_id );
			return null;
		}
		$e       = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r->event_end ) && '0000-00-00' !== $r->event_end ? (string) $r->event_end : $b;
		$t1      = substr( (string) $r->event_time, 0, 5 ) ?: '00:00';
		$t2      = substr( (string) $r->event_endtime, 0, 5 ) ?: $t1;
		$all_day = '00:00' === $t1 && '23:59' === $t2;
		$start   = $b . ' ' . $t1;
		$end     = max( $start, $e . ' ' . $t2 );
		$note    = null;
		[ $rrule, $exdates, $note ] = self::recurrence( $r, $b );
		$approved = (int) $r->event_approved;
		if ( 3 === $approved ) {
			$note = trim( ( $note ? $note . ' ' : '' ) . __( 'was marked cancelled in My Calendar; check whether it should still be published.', 'beacon-events' ) ); }
		$status = array( 0 => 'draft', 4 => 'private', 5 => 'private' )[ $approved ] ?? 'publish';

		$venue = null;
		if ( (int) $r->event_location ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's location row
			$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE location_id = %d', self::t( 'my_calendar_locations' ), (int) $r->event_location ) );
			if ( $l && '' !== trim( (string) $l->location_label ) ) {
				$venue = self::place( (string) $l->location_label, (string) $l->location_street, (string) $l->location_street2, (string) $l->location_city, (string) $l->location_state, (string) $l->location_postcode, (string) $l->location_country, (int) $l->location_id );
			}
		} elseif ( '' !== trim( (string) ( $r->event_label ?? '' ) ) ) { // events saved before locations had their own table
			$venue = self::place( (string) $r->event_label, (string) $r->event_street, (string) $r->event_street2, (string) $r->event_city, (string) $r->event_state, (string) $r->event_postcode, (string) $r->event_country, 0 );
		}
		$cats = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's category tables
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.category_id, c.category_name, c.category_color, c.category_term FROM %i c WHERE c.category_id = %d OR c.category_id IN (SELECT r.category_id FROM %i r WHERE r.event_id = %d) ORDER BY ( c.category_id = %d ) DESC, c.category_id ASC', self::t( 'my_calendar_categories' ), (int) $r->event_category, self::t( 'my_calendar_category_relationships' ), (int) $r->event_id, (int) $r->event_category ) );
		foreach ( (array) $rows as $c ) {
			$term = (int) $c->category_term ? Import::term_chain( (int) $c->category_term, 'mc-event-category' ) : null;
			$hex  = ltrim( trim( (string) $c->category_color ), '#' );
			$cats[] = array(
				'name'        => wp_specialchars_decode( (string) $c->category_name, ENT_QUOTES ),
				'slug'        => $term ? $term['slug'] : sanitize_title( (string) $c->category_name ),
				'description' => '',
				'parent'      => null,
				'src_id'      => (int) $c->category_id,
				'color'       => preg_match( '/^[0-9a-f]{6}$/i', $hex ) ? '#' . $hex : '',
			);
		}
		$thumb = $post ? (int) get_post_thumbnail_id( $post->ID ) : 0;
		$extra = array_filter(
			array(
				'ticket_url'   => esc_url_raw( (string) $r->event_tickets ),
				'url'          => esc_url_raw( (string) $r->event_link ),
				'registration' => trim( wp_strip_all_tags( (string) $r->event_registration ) ),
			),
			'strlen'
		);
		$desc = $post && '' !== (string) $post->post_content ? (string) $post->post_content : (string) $r->event_desc;
		return array(
			'uid'          => self::KEY . '-' . (int) $r->event_id,
			'src_id'       => (int) $r->event_id,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?my_calendar[a-z_]*[^\]]*\]/i', '', $desc ) ),
			'excerpt'      => (string) $r->event_short,
			'status'       => $status,
			'author'       => (int) $r->event_author,
			'date'         => $post ? $post->post_date : '',
			'date_gmt'     => $post ? $post->post_date_gmt : '',
			'slug'         => $post && '' !== $post->post_name ? $post->post_name : sanitize_title( $title ),
			'start'        => $all_day ? $b . ' 00:00' : $start,
			'end'          => $all_day ? $e . ' 23:59' : $end,
			'all_day'      => $all_day,
			'tz'           => wp_timezone_string(),
			'rrule'        => $rrule,
			'exdates'      => $exdates,
			'online'       => false,
			'online_url'   => '',
			'venue'        => $venue,
			'organizers'   => array(),
			'categories'   => $cats,
			'thumbnail_id' => $thumb,
			'image_url'    => $thumb ? '' : esc_url_raw( (string) $r->event_image ),
			'extra'        => $extra,
			'note'         => $note,
		);
	}

	private static function place( string $label, string $street, string $street2, string $city, string $st, string $zip, string $country, int $src ): array {
		$line = trim( trim( $st ) . ' ' . trim( $zip ) );
		return array(
			'name'    => wp_specialchars_decode( trim( $label ), ENT_QUOTES ),
			'address' => implode( ', ', array_filter( array_map( 'trim', array( $street, $street2, $city, $line, $country ) ), 'strlen' ) ),
			'src_id'  => $src,
		);
	}

	/**
	 * The rule from event_recur, kept when (minus skipped dates) it gives exactly the stored dates.
	 *
	 * @return array{0:string,1:array,2:?string} rrule, skip dates, note
	 */
	private static function recurrence( object $r, string $first ): array {
		global $wpdb;
		$code  = strtoupper( trim( (string) $r->event_recur ) );
		$type  = substr( $code, 0, 1 );
		$every = max( 1, (int) substr( $code, 1 ) );
		if ( '' === $type || 'S' === $type ) {
			return array( '', array(), null ); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's occurrence table
		$want = array_values( array_unique( array_map( fn( $d ) => substr( (string) $d, 0, 10 ), (array) $wpdb->get_col( $wpdb->prepare( 'SELECT occur_begin FROM %i WHERE occur_event_id = %d ORDER BY occur_begin ASC', self::t( 'my_calendar_events' ), (int) $r->event_id ) ) ) ) );
		if ( count( $want ) < 2 ) {
			return array( '', array(), null ); }
		$d0   = (int) gmdate( 'w', (int) strtotime( $first . ' 12:00 UTC' ) );
		$day  = self::DAYS[ $d0 ];
		$rule = '';
		if ( 'D' === $type ) {
			$rule = "FREQ=DAILY;INTERVAL=$every";
		} elseif ( 'E' === $type && 1 === $every ) {
			$rule = 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,TU,WE,TH,FR';
		} elseif ( 'W' === $type ) {
			$rule = "FREQ=WEEKLY;INTERVAL=$every;BYDAY=$day";
		} elseif ( 'B' === $type ) {
			$rule = "FREQ=WEEKLY;INTERVAL=2;BYDAY=$day";
		} elseif ( 'M' === $type ) {
			$rule = "FREQ=MONTHLY;INTERVAL=$every;BYMONTHDAY=" . (int) substr( $first, 8, 2 );
		} elseif ( 'U' === $type ) {
			$nth  = intdiv( (int) substr( $first, 8, 2 ) - 1, 7 ) + 1;
			$rule = 'FREQ=MONTHLY;INTERVAL=1;BYDAY=' . ( 5 === $nth && (int) $r->event_fifth_week ? -1 : $nth ) . $day;
		} elseif ( 'Y' === $type ) {
			$rule = "FREQ=YEARLY;INTERVAL=$every";
		}
		if ( '' !== $rule ) {
			$rule .= ';UNTIL=' . str_replace( '-', '', end( $want ) );
			$got   = array_values( array_unique( Recurrence::expand( $rule, $want[0], gmdate( 'Y-m-d', (int) strtotime( end( $want ) . ' +1 day UTC' ) ) ) ) );
			sort( $got );
			$gap = array_values( array_diff( $got, $want ) );
			if ( $got && $got[0] === $want[0] && ! array_diff( $want, $got ) && count( $gap ) <= intdiv( count( $want ), 2 ) ) {
				$note = null;
				return array( Import::normalize_rrule( $rule, $note ), $gap, $note );
			}
		}
		$rest = array_slice( $want, 1 );
		$show = implode( ', ', array_slice( $rest, 0, 10 ) ) . ( count( $rest ) > 10 ? ' …' : '' );
		$note = 1 === count( $rest )
			/* translators: %s: the other date (Y-m-d) */
			? sprintf( __( 'repeats on dates Beacon can\'t express as a rule; only the first date was imported. The other date is %s.', 'beacon-events' ), $show )
			/* translators: 1: number of dates, 2: the dates (Y-m-d, comma separated) */
			: sprintf( __( 'repeats on dates Beacon can\'t express as a rule; only the first date was imported. The other %1$d dates are %2$s.', 'beacon-events' ), count( $rest ), $show );
		return array( '', array(), $note );
	}

	/** The My Calendar event a ?mc_id=<occurrence id> link points at (0 when none). */
	public static function event_of_occurrence( int $occur_id ): int {
		global $wpdb;
		if ( ! self::has_table() ) {
			return 0; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only for an old My Calendar link
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occur_event_id FROM %i WHERE occur_id = %d', self::t( 'my_calendar_events' ), $occur_id ) );
	}
}
