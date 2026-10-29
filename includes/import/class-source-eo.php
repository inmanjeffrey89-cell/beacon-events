<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;
use Beacon\Recurrence;

/**
 * Event Organiser by Stephen Harris (data model read from 3.12.11). Events are `event` posts; the first date is in post
 * meta _eventorganiser_schedule_start_start / _start_finish ("Y-m-d H:i:s" in the site's timezone: Event Organiser has
 * no per-event zone), the repeat settings in the serialized _eventorganiser_event_schedule (schedule once | daily |
 * weekly | monthly | yearly | custom, frequency, schedule_meta = weekdays for weekly or "BYMONTHDAY=15" / "BYDAY=2TU"
 * for monthly, all_day, include / exclude date lists), the start of the last date in _eventorganiser_schedule_until, and
 * — the source of truth — one row per date in its own table {prefix}eo_events. A rule is rebuilt from the settings and
 * kept only when, minus skipped dates, it gives exactly those rows; otherwise the first date is imported and the report
 * lists the rest. Venues are the event-venue taxonomy with their address in {prefix}eo_venuemeta; categories are
 * event-category with the colour in the option eo-event-category_<term id>. The post type name `event` is common, so
 * only posts with Event Organiser's schedule meta count. Works with Event Organiser active or deactivated.
 */
final class Source_EO {
	const KEY       = 'eventorganiser';
	const CPT       = 'event';
	const META      = '_eventorganiser_schedule_start_start';
	const OPT_LINKS = 'beacon_eo_links'; // its URL bases, saved at import so old links can be redirected later

	public static function label(): string {
		return __( 'Event Organiser', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Once you deactivate Event Organiser, its old event, venue and category links are redirected to the imported ones, using the addresses set in its settings.', 'beacon-events' ); }

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eo_events'; }

	public static function detect(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows; its post type and taxonomies may be unregistered
		$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit') AND EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s)", $wpdb->posts, self::CPT, $wpdb->postmeta, self::META ) );
		$tax    = fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE taxonomy = %s', $wpdb->term_taxonomy, $t ) );
		// phpcs:enable
		return array(
			'found'      => $events > 0,
			'total'      => $events,
			'events'     => $events,
			'venues'     => $events ? $tax( 'event-venue' ) : 0,
			'organizers' => 0,
			'categories' => $events ? $tax( 'event-category' ) : 0,
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => defined( 'EVENT_ORGANISER_VER' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's posts; one-off migration
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit') AND EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s) ORDER BY p.ID ASC LIMIT %d OFFSET %d", $wpdb->posts, self::CPT, $wpdb->postmeta, self::META, $limit, $offset ) );
		return array_map( 'intval', (array) $ids );
	}

	/** Event Organiser's URL bases (its settings, else its defaults). */
	public static function slugs(): array {
		$o   = (array) get_option( 'eventorganiser_options', array() );
		$get = fn( string $k, string $d ) => trim( (string) ( $o[ $k ] ?? '' ), '/' ) ?: $d;
		return array(
			'event'    => $get( 'url_event', 'events/event' ),
			'venue'    => $get( 'url_venue', 'events/venue' ),
			'category' => $get( 'url_cat', 'events/category' ),
			'tag'      => $get( 'url_tag', 'events/tag' ),
		);
	}

	public static function map( $post_id, array &$state ): ?array {
		static $saved = false;
		if ( ! $saved && empty( $state['dry'] ) ) { // a dry run writes nothing
			update_option( self::OPT_LINKS, self::slugs(), false );
			$saved = true;
		}
		$p = get_post( (int) $post_id );
		if ( ! $p ) {
			return null; }
		$m     = fn( string $k ) => get_post_meta( $p->ID, $k, true );
		$title = $p->post_title !== '' ? $p->post_title : __( '(Untitled event)', 'beacon-events' );
		$s     = Import::minute( (string) $m( self::META ) );
		if ( '' === $s ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $p->ID );
			return null;
		}
		$sched   = $m( '_eventorganiser_event_schedule' );
		$sched   = is_array( $sched ) ? $sched : array();
		$e       = Import::minute( (string) $m( '_eventorganiser_schedule_start_finish' ) );
		$e       = ( '' === $e || $e < $s ) ? $s : $e;
		$all_day = ! empty( $sched['all_day'] );
		if ( $all_day ) {
			$s = substr( $s, 0, 10 ) . ' 00:00';
			$e = substr( $e, 0, 10 ) . ' 23:59';
		}
		[ $rrule, $exdates, $note ] = self::recurrence( (int) $p->ID, $sched, substr( $s, 0, 10 ), (string) $m( '_eventorganiser_schedule_until' ) );

		$venue = null;
		$vids  = Import::source_terms( (int) $p->ID, 'event-venue' );
		if ( $vids ) {
			$c = Import::term_chain( $vids[0], 'event-venue' );
			if ( $c ) {
				$venue = array(
					'name'    => $c['name'],
					'address' => self::venue_address( $vids[0] ),
					'src_id'  => $vids[0],
				);
			}
		}
		$cats = array();
		foreach ( Import::source_terms( (int) $p->ID, 'event-category' ) as $tid ) {
			$c = Import::term_chain( $tid, 'event-category' );
			if ( $c ) {
				$col = get_option( 'eo-event-category_' . $tid );
				$hex = is_array( $col ) ? ltrim( (string) ( $col['colour'] ?? '' ), '#' ) : '';
				if ( preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
					$c['color'] = '#' . $hex; }
				$cats[] = $c;
			}
		}
		if ( Import::source_terms( (int) $p->ID, 'event-tag' ) && empty( $state['eo_tags_noted'] ) ) {
			$state['eo_tags_noted'] = true;
			$state['notes'][]       = __( 'Event Organiser tags were not imported (Beacon has categories, not tags).', 'beacon-events' );
		}
		return array(
			'uid'          => self::KEY . '-' . (int) $p->ID,
			'src_id'       => (int) $p->ID,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?(eo_[a-z_]+|event_search|events_list)[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => in_array( $p->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $p->post_status : 'draft',
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $s,
			'end'          => $e,
			'all_day'      => $all_day,
			'tz'           => wp_timezone_string(),
			'rrule'        => $rrule,
			'exdates'      => $exdates,
			'online'       => false,
			'online_url'   => '',
			'venue'        => $venue,
			'organizers'   => array(),
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'extra'        => array(),
			'note'         => $note,
		);
	}

	/** Address parts from {prefix}eo_venuemeta, joined the way a postal address reads. */
	private static function venue_address( int $venue_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Event Organiser's own meta table; the plugin may be deactivated
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM %i WHERE eo_venue_id = %d AND meta_key IN ('_address','_city','_state','_postcode','_country')", $wpdb->prefix . 'eo_venuemeta', $venue_id ), OBJECT_K );
		$v    = fn( string $k ) => trim( (string) ( $rows[ $k ]->meta_value ?? '' ) );
		$line = trim( $v( '_state' ) . ' ' . $v( '_postcode' ) );
		return implode( ', ', array_filter( array( $v( '_address' ), $v( '_city' ), $line, $v( '_country' ) ), 'strlen' ) );
	}

	/** Every date Event Organiser stored for the event, as Y-m-d, sorted. */
	private static function stored_dates( int $post_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Event Organiser's occurrence table; the plugin may be deactivated
		$d = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT StartDate FROM %i WHERE post_id = %d ORDER BY StartDate ASC', self::table(), $post_id ) );
		return array_values( array_filter( array_map( 'strval', $d ), fn( $x ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $x ) ) );
	}

	/**
	 * The rule from Event Organiser's settings, kept only if it (minus skipped dates) gives exactly the stored dates.
	 *
	 * @return array{0:string,1:array,2:?string} rrule, skip dates, note
	 */
	private static function recurrence( int $post_id, array $sched, string $first, string $until ): array {
		$type = (string) ( $sched['schedule'] ?? 'once' );
		$want = self::stored_dates( $post_id );
		if ( 'once' === $type || count( $want ) < 2 ) {
			return array( '', array(), null ); }
		$f    = max( 1, (int) ( $sched['frequency'] ?? 1 ) );
		$meta = $sched['schedule_meta'] ?? '';
		$day  = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' )[ (int) gmdate( 'w', strtotime( $first . ' 12:00 UTC' ) ) ];
		$rule = '';
		if ( 'daily' === $type ) {
			$rule = "FREQ=DAILY;INTERVAL=$f";
		} elseif ( 'weekly' === $type ) {
			$days = array_values( array_intersect( array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' ), array_map( 'strtoupper', (array) $meta ) ) );
			$rule = "FREQ=WEEKLY;INTERVAL=$f;BYDAY=" . ( $days ? implode( ',', $days ) : $day );
		} elseif ( 'monthly' === $type ) {
			$meta = strtoupper( trim( is_string( $meta ) ? $meta : '' ) );
			if ( preg_match( '/^BYMONTHDAY=(\d{1,2})$/', $meta, $mm ) ) {
				$rule = "FREQ=MONTHLY;INTERVAL=$f;BYMONTHDAY=" . (int) $mm[1];
			} elseif ( preg_match( '/^BYDAY=(-?[1-5])(SU|MO|TU|WE|TH|FR|SA)$/', $meta, $mm ) ) {
				$rule = "FREQ=MONTHLY;INTERVAL=$f;BYDAY={$mm[1]}{$mm[2]}";
			} else {
				$rule = "FREQ=MONTHLY;INTERVAL=$f;BYMONTHDAY=" . (int) substr( $first, 8, 2 );
			}
		} elseif ( 'yearly' === $type ) {
			$rule = "FREQ=YEARLY;INTERVAL=$f";
		}
		$last = preg_match( '/^\d{4}-\d{2}-\d{2}/', $until ) ? substr( $until, 0, 10 ) : end( $want );
		$last = max( $last, end( $want ) );
		if ( '' !== $rule ) {
			$rule .= ';UNTIL=' . str_replace( '-', '', $last );
			$got   = Recurrence::expand( $rule, $first, gmdate( 'Y-m-d', strtotime( $last . ' +1 day' ) ), wp_timezone() );
			$got   = array_values( array_unique( $got ) );
			sort( $got );
			if ( $got && ! array_diff( $want, $got ) && $got[0] === $want[0] ) {
				$skip = array_values( array_diff( $got, $want ) );
				$note = null;
				return array( Import::normalize_rrule( $rule, $note ), $skip, $note );
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
}
