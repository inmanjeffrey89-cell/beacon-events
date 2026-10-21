<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import;

/**
 * Modern Events Calendar (Lite and Pro; data model read from MEC Lite 6.5.6). Events are `mec-events` posts; the
 * schedule is in post meta: mec_start_date / mec_end_date (Y-m-d) + mec_start_day_seconds / mec_end_day_seconds,
 * mec_allday, mec_timezone ("global" = the site's zone), and the repeat rule in mec_repeat_* with these traps:
 * mec_repeat_interval is stored in DAYS for weekly repeats (every 2 weeks = 14), mec_repeat_end_at_occurrences is the
 * count MINUS ONE, weekday numbers are ISO (1 = Monday … 7 = Sunday), mec_not_in_days is a comma list of skipped
 * dates, custom_days keep their dates in mec_in_days, and "advanced" repeats are monthly tokens like "Sun.1" (first
 * Sunday) or "Fri.l" (last Friday). Venues/organizers/categories are the mec_location / mec_organizer / mec_category
 * taxonomies; term id 1 in the first two means "hidden". Works with MEC active or already deactivated.
 */
final class Source_MEC {
	const KEY       = 'mec';
	const CPT       = 'mec-events';
	const OPT_LINKS = 'beacon_mec_links'; // MEC's own URL slugs, saved at import so old links can be redirected later

	private const DAYS = array( 1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU' );

	public static function label(): string {
		return __( 'Modern Events Calendar', 'beacon-events' ); }

	public static function hint(): string {
		return __( 'Once you deactivate Modern Events Calendar, its /events/… and /mec-category/… links are redirected to the imported events and categories.', 'beacon-events' ); }

	public static function detect(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's rows; its post type and taxonomies may be unregistered
		$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')", $wpdb->posts, self::CPT ) );
		$tax    = fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE taxonomy = %s AND term_id <> 1', $wpdb->term_taxonomy, $t ) );
		// phpcs:enable
		$venues = $tax( 'mec_location' );
		$orgs   = $tax( 'mec_organizer' );
		return array(
			'found'      => $events > 0,
			'total'      => $events,
			'events'     => $events,
			'venues'     => $venues,
			'organizers' => $orgs,
			'categories' => $tax( 'mec_category' ),
			'imported'   => Import::imported_count( self::KEY ),
			'active'     => class_exists( 'MEC' ),
		);
	}

	public static function ids( array $state, int $offset, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paging another plugin's posts; one-off migration
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d OFFSET %d", $wpdb->posts, self::CPT, $limit, $offset ) );
		return array_map( 'intval', (array) $ids );
	}

	/** MEC's single-event and category slugs (both can be changed in its settings). */
	public static function slugs(): array {
		$set = (array) ( ( (array) get_option( 'mec_options', array() ) )['settings'] ?? array() );
		$one = trim( (string) ( $set['slug'] ?? '' ), '/ ' );
		$cat = trim( (string) ( $set['category_slug'] ?? '' ), '/ ' );
		return array(
			'single'   => $one !== '' ? $one : 'events',
			'category' => $cat !== '' ? $cat : 'mec-category',
		);
	}

	public static function map( $post_id, array &$state ): ?array {
		static $saved = false;
		if ( ! $saved ) {
			update_option( self::OPT_LINKS, self::slugs(), false );
			$saved = true;
		}
		$p = get_post( (int) $post_id );
		if ( ! $p ) {
			return null; }
		$m     = fn( string $k ) => get_post_meta( $p->ID, $k, true );
		$title = $p->post_title !== '' ? $p->post_title : __( '(Untitled event)', 'beacon-events' );
		$sd    = (string) $m( 'mec_start_date' );
		$ed    = (string) $m( 'mec_end_date' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sd ) ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: 1: title, 2: id */ __( 'Skipped "%1$s" (#%2$d): it has no start date.', 'beacon-events' ), $title, (int) $p->ID );
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ed ) || $ed < $sd ) {
			$ed = $sd; }
		$all_day = (int) $m( 'mec_allday' ) === 1;
		if ( $all_day ) {
			$start = "$sd 00:00";
			$end   = "$ed 23:59";
		} else {
			$start = $sd . ' ' . self::clock( (int) $m( 'mec_start_day_seconds' ) );
			$end   = $ed . ' ' . self::clock( (int) $m( 'mec_end_day_seconds' ) );
			if ( $end < $start ) {
				$end = $start; }
		}
		$note = null;
		[$rrule, $ex, $rnote] = self::recurrence( $m, $sd );
		if ( $rnote ) {
			$note = $rnote; }

		$status = (string) $m( 'mec_event_status' );
		$online = $status === 'EventMovedOnline';
		if ( $status === 'EventCancelled' ) {
			$note = trim( ( $note ? $note . ' ' : '' ) . __( 'was marked cancelled in Modern Events Calendar; check whether it should still be published.', 'beacon-events' ) ); }

		$venue = null;
		$lid   = (int) $m( 'mec_location_id' );
		if ( $lid > 1 ) {
			$t = self::term( $lid, 'mec_location' );
			if ( $t ) {
				$venue = array(
					'name'    => $t['name'],
					'address' => (string) get_term_meta( $lid, 'address', true ),
					'src_id'  => $lid,
				);
			}
		}
		$orgs = array();
		$oids = array_merge( array( (int) $m( 'mec_organizer_id' ) ), array_map( 'intval', (array) $m( 'mec_additional_organizer_ids' ) ) );
		foreach ( array_unique( $oids ) as $oid ) {
			$t = $oid > 1 ? self::term( $oid, 'mec_organizer' ) : null;
			if ( $t ) {
				$orgs[] = array(
					'name'   => $t['name'],
					'src_id' => $oid,
				);
			}
		}
		$cats = array();
		foreach ( Import::source_terms( (int) $p->ID, 'mec_category' ) as $tid ) {
			$c = Import::term_chain( $tid, 'mec_category' );
			if ( $c ) {
				$c['color'] = (string) get_term_meta( $tid, 'mec_cat_color', true );
				$cats[]     = $c;
			}
		}
		$extra = array_filter(
			array(
				'cost'      => (string) $m( 'mec_cost' ),
				'more_info' => (string) $m( 'mec_more_info' ),
			),
			'strlen'
		);
		return array(
			'uid'          => self::KEY . '-' . (int) $p->ID,
			'src_id'       => (int) $p->ID,
			'title'        => $title,
			'content'      => trim( (string) preg_replace( '/\[\/?MEC[^\]]*\]/i', '', (string) $p->post_content ) ),
			'excerpt'      => $p->post_excerpt,
			'status'       => $p->post_status,
			'author'       => (int) $p->post_author,
			'date'         => $p->post_date,
			'date_gmt'     => $p->post_date_gmt,
			'slug'         => $p->post_name,
			'start'        => $start,
			'end'          => $end,
			'all_day'      => $all_day,
			'tz'           => in_array( (string) $m( 'mec_timezone' ), array( '', 'global' ), true ) ? '' : (string) $m( 'mec_timezone' ),
			'rrule'        => $rrule,
			'exdates'      => $ex,
			'online'       => $online,
			'online_url'   => $online ? esc_url_raw( (string) $m( 'mec_moved_online_link' ) ) : '',
			'venue'        => $venue,
			'organizers'   => $orgs,
			'categories'   => $cats,
			'thumbnail_id' => (int) get_post_thumbnail_id( $p->ID ),
			'extra'        => $extra,
			'note'         => $note,
		);
	}

	/** Seconds after midnight → "HH:MM". */
	private static function clock( int $s ): string {
		$s = max( 0, min( 86399, $s ) );
		return sprintf( '%02d:%02d', intdiv( $s, 3600 ), intdiv( $s % 3600, 60 ) );
	}

	/** @return array{0:string,1:array,2:?string} rrule, skipped dates, note */
	private static function recurrence( callable $m, string $sd ): array {
		$ex = array();
		foreach ( preg_split( '/\s*,\s*/', (string) $m( 'mec_not_in_days' ) ) as $d ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				$ex[] = $d; }
		}
		if ( (int) $m( 'mec_repeat_status' ) !== 1 ) {
			return array( '', array(), null ); }
		$type     = (string) $m( 'mec_repeat_type' );
		$interval = max( 1, (int) $m( 'mec_repeat_interval' ) );
		$note     = null;
		$weekdays = function ( $nums ) {
			$out = array();
			foreach ( (array) $nums as $n ) {
				if ( isset( self::DAYS[ (int) $n ] ) ) {
					$out[] = self::DAYS[ (int) $n ]; }
			}
			return $out;
		};
		$settings = (array) ( ( (array) get_option( 'mec_options', array() ) )['settings'] ?? array() );
		switch ( $type ) {
			case 'daily':
				$parts = array( 'FREQ=DAILY', "INTERVAL=$interval" );
				break;
			case 'weekly':   // MEC multiplies the week interval by 7 before saving
				$parts = array( 'FREQ=WEEKLY', 'INTERVAL=' . max( 1, intdiv( $interval, 7 ) ), 'BYDAY=' . self::DAYS[ (int) gmdate( 'N', strtotime( $sd ) ) ] );
				break;
			case 'weekday':
			case 'weekend':
				$nums  = $type === 'weekday' ? ( $settings['weekdays'] ?? array( 1, 2, 3, 4, 5 ) ) : ( $settings['weekends'] ?? array( 6, 7 ) );
				$parts = array( 'FREQ=WEEKLY', 'INTERVAL=1', 'BYDAY=' . implode( ',', $weekdays( $nums ) ) );
				break;
			case 'certain_weekdays':
				$days  = $weekdays( $m( 'mec_certain_weekdays' ) );
				$parts = $days ? array( 'FREQ=WEEKLY', 'INTERVAL=1', 'BYDAY=' . implode( ',', $days ) ) : array();
				break;
			case 'monthly':
				$parts = array( 'FREQ=MONTHLY', "INTERVAL=$interval" );
				break;
			case 'yearly':
				$parts = array( 'FREQ=YEARLY', "INTERVAL=$interval" );
				break;
			case 'advanced':   // "Sun.1" = first Sunday of the month, "Fri.l" = last Friday
				$tokens = array_values( array_filter( (array) $m( 'mec_advanced_days' ) ) );
				$parts  = array();
				$map    = array(
					'mon' => 'MO',
					'tue' => 'TU',
					'wed' => 'WE',
					'thu' => 'TH',
					'fri' => 'FR',
					'sat' => 'SA',
					'sun' => 'SU',
				);
				// every token is kept since 0.20.0 ("Tue.2" + "Tue.4" = second and fourth Tuesday)
				$byday = array();
				foreach ( $tokens as $tok ) {
					if ( preg_match( '/^([A-Za-z]{3})\.([1-5]|l)$/', (string) $tok, $t ) && isset( $map[ strtolower( $t[1] ) ] ) ) {
						$byday[] = ( $t[2] === 'l' ? '-1' : $t[2] ) . $map[ strtolower( $t[1] ) ]; }
				}
				if ( $byday ) {
					$parts = array( 'FREQ=MONTHLY', 'INTERVAL=1', 'BYDAY=' . implode( ',', array_unique( $byday ) ) ); }
				if ( count( $byday ) !== count( $tokens ) ) {
					$note = __( 'some of its weekdays of the month could not be read and were left out, so check its dates.', 'beacon-events' ); }
				break;
			case 'custom_days':
				return array( '', $ex, __( 'repeats on a list of specific dates, which Beacon cannot express as a rule; only the first date was imported.', 'beacon-events' ) );
			default:
				$parts = array();
		}
		if ( ! $parts ) {
			return array( '', $ex, sprintf( /* translators: %s: repeat type */ __( 'repeats "%s", which is not supported; imported as a single event.', 'beacon-events' ), $type ) ); }
		$end = (string) $m( 'mec_repeat_end' );
		if ( $end === 'occurrences' && (string) $m( 'mec_repeat_end_at_occurrences' ) !== '' ) {
			$parts[] = 'COUNT=' . max( 1, (int) $m( 'mec_repeat_end_at_occurrences' ) + 1 );   // stored as count - 1
		} elseif ( $end === 'date' && preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', (string) $m( 'mec_repeat_end_at_date' ), $u ) ) {
			$parts[] = "UNTIL=$u[1]$u[2]$u[3]";
		}
		$rrule = Import::normalize_rrule( implode( ';', $parts ), $note );
		return array( $rrule, array_values( array_unique( $ex ) ), $note );
	}

	/** name/slug of a term read directly (MEC's taxonomies are unregistered once it is deactivated). */
	private static function term( int $term_id, string $taxonomy ): ?array {
		$c = Import::term_chain( $term_id, $taxonomy );
		return $c ? array(
			'name' => $c['name'],
			'slug' => $c['slug'],
		) : null;
	}
}
