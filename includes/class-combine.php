<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Repeating events that were entered one date at a time (the only way in plugins that charge for repeats, so an
 * import brings them across as copies) → one repeating event. A group is three or more one-off events with the same
 * title, start time, length, timezone and venue whose dates fit a daily, weekly (every n weeks, one or more
 * weekdays) or monthly (nth weekday / day of month) rule; missing dates become skipped dates. The rule is proved by
 * expanding it and comparing with the real dates, never guessed.
 *
 * Combining keeps the earliest copy as the series, gives it the rule, fills its description, image, link and terms
 * from the copies where it had none, moves the other copies to the trash (restorable) and remembers their links and
 * import ids: old links redirect to the series, and importing again does not bring the copies back.
 */
final class Combine {

	const META   = '_beacon_combined';   // on a series: array( ids, dates, at )
	const OPT    = 'beacon_combined';    // array( slugs => [ slug => series id ], uids => [ import uid => series id ] )
	const ACTION = 'beacon_combine';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/** Candidate groups, largest first. Each: ids (date order), dates (Y-m-d), rule, exdates, text. */
	public static function groups( int $limit = 200 ): array {
		// phpcs:disable WordPress.DB.SlowDBQuery -- admin screen / CLI only
		$ids = get_posts(
			array(
				'post_type'      => Events::CPT,
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => Events::META['rrule'],
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => Events::META['rrule'],
						'value' => '',
					),
				),
			)
		);
		// phpcs:enable
		$by = array();
		foreach ( $ids as $id ) {
			$k = self::key( (int) $id );
			if ( $k !== '' ) {
				$by[ $k ][] = (int) $id; }
		}
		$out = array();
		foreach ( $by as $members ) {
			foreach ( self::by_venue( $members ) as $set ) {
				if ( count( $set ) < 3 ) {
					continue; }
				$g = self::analyze( $set );
				if ( $g ) {
					$out[] = $g;
					continue;
				}
				// A stray date on another weekday (one Saturday session in a run of Sundays) spoils the whole set: each
				// weekday's dates can still be a series of their own, and the stray one stays a one-off.
				$wd = array();
				foreach ( $set as $id ) {
					$d = date_create( (string) Events::get( (int) $id )['start'] );
					if ( $d ) {
						$wd[ $d->format( 'w' ) ][] = (int) $id; }
				}
				foreach ( count( $wd ) > 1 ? $wd : array() as $sub ) {
					$g = count( $sub ) >= 3 ? self::analyze( $sub ) : null;
					if ( $g ) {
						$out[] = $g; }
				}
			}
		}
		usort( $out, fn( $a, $b ) => count( $b['ids'] ) <=> count( $a['ids'] ) );
		return array_slice( $out, 0, $limit );
	}

	/** What makes two one-off events "the same event on another date"; '' = never combine this one. */
	private static function key( int $id ): string {
		if ( get_post_meta( $id, '_beacon_feed', true ) !== '' ) {
			return ''; } // feed events would be recreated by the next sync
		// Bookings hang off each copy: only an add-on that moves them onto the series (Beacon Events Pro does) may
		// combine copies with tickets, and then only copies whose ticket setup matches (it adds that to the key).
		if ( get_post_meta( $id, '_beacon_rsvp', true ) === '1' && ! apply_filters( 'beacon_combine_tickets', false ) ) {
			return ''; }
		$e = Events::get( $id );
		$s = date_create( (string) $e['start'] );
		$f = date_create( (string) ( $e['end'] ?: $e['start'] ) );
		if ( ! $s || ! $f ) {
			return ''; }
		// copies that carry their own date in the title ("… – October 1, 2026") match on the rest of it
		$title = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', self::base_title( Views::plain( get_the_title( $id ) ), $s ) ) ) );
		if ( $title === '' ) {
			return ''; }
		return md5( (string) wp_json_encode( array( $title, $s->format( 'H:i' ), (int) round( ( $f->getTimestamp() - $s->getTimestamp() ) / 60 ), (string) $e['tz'], (int) $e['all_day'], (int) ! empty( $e['online'] ), (array) apply_filters( 'beacon_combine_key_parts', array(), $id ) ) ) );
	}

	/**
	 * A title with the event's own date taken out, for copies that carry their date in the title: "Pick Your Own
	 * Project – October 1, 2026" → "Pick Your Own Project", "Take A Spin – Sunday, October 4th, 3pm-5pm" → "Take A
	 * Spin", "Chef's Table Class 10/2/26" → "Chef's Table Class". Only the date this event is on is removed (with a
	 * weekday, year or time next to it), so "Talk: October 1918" or a date that names something else stays.
	 */
	public static function base_title( string $title, \DateTimeInterface $on ): string {
		$ts    = $on->getTimestamp();
		$tz    = $on->getTimezone();
		$alt   = fn( array $words ) => implode( '|', array_map( fn( $w ) => preg_quote( $w, '/' ), array_unique( array_filter( $words ) ) ) );
		$month = $alt( array( $on->format( 'F' ), $on->format( 'M' ), wp_date( 'F', $ts, $tz ), wp_date( 'M', $ts, $tz ) ) );
		$wday  = $alt( array( $on->format( 'l' ), $on->format( 'D' ), wp_date( 'l', $ts, $tz ), wp_date( 'D', $ts, $tz ) ) );
		list( $j, $n, $y, $yy ) = explode( ' ', $on->format( 'j n Y y' ) );
		$day   = "0?$j(?:st|nd|rd|th)?(?!\\d)";
		$year  = "(?:,?\\s*(?:$y|'$yy)(?!\\d))?";
		$named = "(?:(?:$month)\\.?\\s+$day$year|$day\\s+(?:of\\s+)?(?:$month)\\.?$year)";
		$clock = '\\d{1,2}(?::\\d{2})?\\s*[ap]\\.?m\\.?';
		$time  = "(?:\\s*(?:,|@|\\bat\\b|-|–|—)?\\s*$clock(?:\\s*(?:-|–|—|\\bto\\b)\\s*$clock)?)?";
		// 10/2/26 anywhere; a bare 10/2 only at the end (a "1/2 OFF" special on January 2 is not a date)
		$num   = "(?<![\\d\\/])(?:0?$n\\/0?$j\\/(?:$y|$yy)|0?$n-0?$j-(?:$y|$yy)|$y-0?$n-0?$j|0?$n\\/0?$j(?=$time\\)?\\s*$))(?![\\d\\/])";
		$lead  = '(?:\\s*(?:[-–—|:,]|\\bon\\b|\\bstarting\\b|\\bbeginning\\b)\\s*)*';
		$out   = preg_replace( "/$lead\\(?(?:(?:$wday)\\.?,?\\s+)?(?:$named|$num)$time\\)?/iu", ' ', $title, 1, $found );
		if ( ! $found || null === $out ) {
			return $title; }
		$out = trim( (string) preg_replace( array( '/\\(\\s*\\)/u', '/\\s{2,}/u', '/^[\\s\\-–—|:,]+|[\\s\\-–—|:,(]+$/u' ), array( '', ' ', '' ), $out ) );
		return mb_strlen( $out ) >= 3 ? $out : $title;
	}

	/** The venue ids of an event as "3,7" ('' = no venue set). */
	private static function venue_key( int $id ): string {
		$v = wp_get_object_terms( $id, 'beacon_venue', array( 'fields' => 'ids' ) );
		$v = is_wp_error( $v ) ? array() : array_map( 'intval', $v );
		sort( $v );
		return implode( ',', $v );
	}

	/**
	 * Split one event's copies by venue. Copies entered without a venue belong with the rest when the others agree on
	 * one venue (people skip the field on some copies: "Knee-high Naturalists (at ODC)" says where it is); when the
	 * copies name different venues they are different series, and the ones without a venue are left alone.
	 *
	 * @return int[][]
	 */
	private static function by_venue( array $ids ): array {
		$sets = array();
		foreach ( $ids as $id ) {
			$sets[ self::venue_key( (int) $id ) ][] = (int) $id; }
		$bare = $sets[''] ?? array();
		unset( $sets[''] );
		if ( count( $sets ) <= 1 ) {
			return array( array_merge( $sets ? reset( $sets ) : array(), $bare ) ); }
		return array_values( $sets );
	}

	/** The best rule for these events' dates, or null when the dates are too irregular to call a series. */
	public static function analyze( array $ids ): ?array {
		$rows = array();
		foreach ( $ids as $id ) {
			$e = Events::get( (int) $id );
			$d = date_create( (string) $e['start'] );
			if ( $d ) {
				$rows[] = array( (int) $id, $d->format( 'Y-m-d' ), (string) $e['start'] ); }
		}
		usort( $rows, fn( $a, $b ) => array( $a[1], $a[0] ) <=> array( $b[1], $b[0] ) );
		// Same title, time and length twice on one day is the same event listed twice: it goes with the series (and to
		// the trash) instead of blocking it. The earliest copy of each date stays the one that counts.
		$seen  = array();
		$dupes = array();
		foreach ( $rows as $i => $r ) {
			if ( isset( $seen[ $r[1] ] ) ) {
				$dupes[] = $r[0];
				unset( $rows[ $i ] );
			} else {
				$seen[ $r[1] ] = true; }
		}
		$rows  = array_values( $rows );
		$dates = array_column( $rows, 1 );
		if ( count( $rows ) < 3 ) {
			return null; }
		$first = $dates[0];
		$last  = end( $dates );
		$until = str_replace( '-', '', $last );
		$days  = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
		$dows  = array();
		foreach ( $dates as $d ) {
			$dows[ (int) gmdate( 'w', (int) strtotime( $d . ' 12:00 UTC' ) ) ] = true; }
		ksort( $dows );
		$byday      = implode( ',', array_map( fn( $w ) => $days[ $w ], array_keys( $dows ) ) );
		$candidates = array( 'FREQ=DAILY;INTERVAL=1' );
		foreach ( array( 1, 2, 3, 4 ) as $n ) {
			$candidates[] = "FREQ=WEEKLY;INTERVAL=$n;BYDAY=$byday"; }
		if ( count( $dows ) === 1 ) {
			$dow  = $days[ (int) array_key_first( $dows ) ];
			$nth  = array_unique( array_map( fn( $d ) => (int) ceil( (int) substr( $d, 8, 2 ) / 7 ), $dates ) );
			$lastw = array_unique( array_map( fn( $d ) => (int) gmdate( 't', (int) strtotime( $d . ' 12:00 UTC' ) ) - (int) substr( $d, 8, 2 ) < 7, $dates ) );
			if ( count( $nth ) === 1 && reset( $nth ) <= 4 ) {
				$candidates[] = 'FREQ=MONTHLY;INTERVAL=1;BYDAY=' . reset( $nth ) . $dow; }
			if ( count( $nth ) === 2 && max( $nth ) <= 4 ) { // "second and fourth Tuesday", "first and third Thursday"; three or more weeks read as weekly with a gap
				sort( $nth );
				$candidates[] = 'FREQ=MONTHLY;INTERVAL=1;BYDAY=' . implode( ',', array_map( fn( $n ) => $n . $dow, $nth ) ); }
			if ( $lastw === array( true ) ) {
				$candidates[] = 'FREQ=MONTHLY;INTERVAL=1;BYDAY=-1' . $dow; }
		}
		$mday = array_unique( array_map( fn( $d ) => (int) substr( $d, 8, 2 ), $dates ) );
		if ( count( $mday ) === 1 && reset( $mday ) <= 28 ) {
			$candidates[] = 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=' . reset( $mday ); }
		$tz   = self::tz( (int) $rows[0][0] );
		$have = array_flip( $dates );
		$best = null;
		foreach ( $candidates as $rule ) {
			$expected = Recurrence::expand( "$rule;UNTIL=$until", $first, $last, $tz ); // dates, as materialize() passes them
			if ( array_diff( $dates, $expected ) ) {
				continue; } // the rule misses a real date
			$missing = array_values( array_filter( $expected, fn( $d ) => ! isset( $have[ $d ] ) ) );
			if ( ! $best || count( $missing ) < count( $best['exdates'] ) ) {
				$best = array(
					'rule'    => "$rule;UNTIL=$until",
					'exdates' => $missing,
				);
			}
		}
		// at most one skipped date per three real ones: past that it is a list of dates, not a series
		if ( ! $best || count( $best['exdates'] ) > max( 1, intdiv( count( $dates ), 3 ) ) ) {
			return null; }
		$text = Recurrence::describe( preg_replace( '/;UNTIL=\d+/', '', $best['rule'] ) );
		return array(
			'ids'     => array_merge( array_column( $rows, 0 ), $dupes ),
			'dupes'   => count( $dupes ),
			'dates'   => $dates,
			'rule'    => $best['rule'],
			'exdates' => $best['exdates'],
			'text'    => $text,
			'title'   => self::series_title( (int) $rows[0][0] ),
		);
	}

	/** The title a combined event takes: the first copy's, less the date it carried ("… – October 1, 2026"). Plain text. */
	private static function series_title( int $id ): string {
		$d = date_create( (string) Events::get( $id )['start'] );
		$t = Views::plain( (string) get_post_field( 'post_title', $id, 'raw' ) );
		return $d ? self::base_title( $t, $d ) : $t;
	}

	private static function tz( int $id ): \DateTimeZone {
		$name = (string) get_post_meta( $id, Events::META['tz'], true );
		try {
			return $name !== '' ? new \DateTimeZone( $name ) : wp_timezone();
		} catch ( \Exception $x ) {
			return wp_timezone(); }
	}

	/** Combine one group; returns the series id, or 0 when the events no longer form a series. */
	public static function combine( array $ids ): int {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( count( $ids ) < 3 ) {
			return 0; }
		$keys = array_unique( array_map( array( __CLASS__, 'key' ), $ids ) );
		$sets = self::by_venue( $ids );
		if ( count( $keys ) !== 1 || reset( $keys ) === '' || count( $sets ) !== 1 || count( $sets[0] ) !== count( $ids ) ) {
			return 0; } // changed since the page was shown
		foreach ( $ids as $id ) {
			if ( get_post_status( $id ) !== 'publish' || (string) get_post_meta( $id, Events::META['rrule'], true ) !== '' ) {
				return 0; }
		}
		$g = self::analyze( $ids );
		if ( ! $g ) {
			return 0; }
		$series = (int) $g['ids'][0];
		$copies = array_slice( $g['ids'], 1 );
		// copies titled with their own date: the repeating event takes the title without it (a new slug too; WordPress
		// keeps the old one redirecting)
		if ( $g['title'] !== Views::plain( (string) get_post_field( 'post_title', $series, 'raw' ) ) ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'         => $series,
						'post_title' => $g['title'],
						'post_name'  => sanitize_title( $g['title'] ),
					)
				)
			);
		}
		update_post_meta( $series, Events::META['rrule'], $g['rule'] );
		update_post_meta( $series, Events::META['exdates'], implode( ',', $g['exdates'] ) );
		// Each copy's ticket link, by its date (a copy listed twice lends its link when the kept one has none). When the
		// links differ — a registration page per date, the way Blackbaud, Altru or Eventbrite sessions work — every date
		// keeps its own link and the event itself gets none; one shared link stays the event's link, as before.
		$by_date = array();
		foreach ( $g['ids'] as $id ) {
			$l = (string) get_post_meta( (int) $id, Events::META['link'], true );
			$d = substr( (string) Events::get( (int) $id )['start'], 0, 10 );
			if ( $l !== '' && empty( $by_date[ $d ] ) ) {
				$by_date[ $d ] = $l; }
		}
		$per_date = count( array_unique( $by_date ) ) > 1;
		// The other way people keep a page per date: a "click here to register" link pasted into each copy's
		// description, pointing at that date's page on the registration system. Those links become the dates' own,
		// and in the kept description the link now jumps to the event page's list of dates.
		$in_text = $per_date ? array() : self::description_links( $g['ids'] );
		if ( $in_text ) {
			$per_date = true;
			$by_date  = $in_text['by_date'];
			$own      = $by_date[ substr( (string) Events::get( $series )['start'], 0, 10 ) ] ?? '';
			if ( $own !== '' ) {
				$content = preg_replace_callback(
					'/(href=)(["\'])(https?:\/\/[^"\']+)\2/i',
					fn( $m ) => html_entity_decode( $m[3] ) === $own ? $m[1] . $m[2] . '#beacon-dates' . $m[2] : $m[0],
					(string) get_post_field( 'post_content', $series, 'raw' )
				);
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $series,
							'post_content' => $content,
						)
					)
				);
			}
		}
		if ( $per_date ) {
			$ov = Events::get( $series )['overrides'];
			foreach ( $by_date as $d => $l ) {
				$ov[ $d ] = array_merge( $ov[ $d ] ?? array(), array( 'link' => Events::sanitize_url( $l ) ) ); }
			update_post_meta( $series, Events::META['overrides'], Events::sanitize_overrides( $ov ) );
			update_post_meta( $series, Events::META['link'], '' );
		}
		// the series keeps its own description, image and link; where it has none, the first copy that has one fills it
		foreach ( $copies as $c ) {
			$post = get_post( $series );
			if ( trim( (string) $post->post_content ) === '' && trim( (string) get_post_field( 'post_content', $c ) ) !== '' ) {
				wp_update_post(
					array(
						'ID'           => $series,
						'post_content' => get_post_field( 'post_content', $c, 'raw' ),
					)
				);
			}
			if ( ! has_post_thumbnail( $series ) && has_post_thumbnail( $c ) ) {
				set_post_thumbnail( $series, get_post_thumbnail_id( $c ) ); }
			if ( ! $per_date && (string) get_post_meta( $series, Events::META['link'], true ) === '' && (string) get_post_meta( $c, Events::META['link'], true ) !== '' ) {
				update_post_meta( $series, Events::META['link'], get_post_meta( $c, Events::META['link'], true ) );
				update_post_meta( $series, Events::META['link_label'], get_post_meta( $c, Events::META['link_label'], true ) );
			}
			if ( $per_date && (string) get_post_meta( $series, Events::META['link_label'], true ) === '' && (string) get_post_meta( $c, Events::META['link_label'], true ) !== '' ) {
				update_post_meta( $series, Events::META['link_label'], get_post_meta( $c, Events::META['link_label'], true ) ); }
			if ( self::venue_key( $series ) === '' && self::venue_key( $c ) !== '' ) {
				wp_set_object_terms( $series, array_map( 'intval', explode( ',', self::venue_key( $c ) ) ), 'beacon_venue' ); }
			foreach ( array( 'beacon_category', 'beacon_organizer' ) as $tax ) {
				$t = wp_get_object_terms( $c, $tax, array( 'fields' => 'ids' ) );
				if ( $t && ! is_wp_error( $t ) ) {
					wp_set_object_terms( $series, array_map( 'intval', $t ), $tax, true ); }
			}
		}
		$map = self::map();
		foreach ( $copies as $c ) {
			$slug = (string) get_post_field( 'post_name', $c );
			if ( $slug !== '' ) {
				$map['slugs'][ $slug ] = $series; }
			$uid = (string) get_post_meta( $c, '_beacon_uid', true );
			if ( $uid !== '' ) {
				$map['uids'][ $uid ] = $series; }
			wp_trash_post( $c );
		}
		update_option( self::OPT, $map, false );
		update_post_meta(
			$series,
			self::META,
			array(
				'ids'   => $copies,
				'dates' => $g['dates'],
				'at'    => time(),
			)
		);
		Events::materialize( $series );
		/** Fires after one-off copies were combined into a repeating event. */
		do_action( 'beacon_events_combined', $series, $copies );
		return $series;
	}

	/**
	 * A registration page per date pasted into the copies' descriptions: the one link each copy has to some host
	 * (Blackbaud Altru, Eventbrite…), different on every date. Returns ['host' => …, 'by_date' => [Y-m-d => url]] or
	 * [] when no host fits (a shared link, several links to one host in a copy, or fewer than three dates).
	 */
	private static function description_links( array $ids ): array {
		$per = array();
		foreach ( $ids as $id ) {
			$d = substr( (string) Events::get( (int) $id )['start'], 0, 10 );
			preg_match_all( '/href=(["\'])(https?:\/\/[^"\']+)\1/i', (string) get_post_field( 'post_content', (int) $id, 'raw' ), $m );
			$hosts = array();
			foreach ( array_unique( array_map( 'html_entity_decode', $m[2] ) ) as $u ) {
				$h = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
				if ( $h !== '' ) {
					$hosts[ $h ][] = $u; }
			}
			foreach ( $hosts as $h => $list ) {
				if ( count( $list ) > 1 ) {
					$per[ $h ]['many'] = true; // several links to that host in one copy: not "the" registration link
				} elseif ( empty( $per[ $h ]['by_date'][ $d ] ) ) {
					$per[ $h ]['by_date'][ $d ] = $list[0]; // a date listed twice: the first copy that has one
				}
			}
		}
		$best = array();
		foreach ( $per as $h => $p ) {
			$by = $p['by_date'] ?? array();
			if ( ! empty( $p['many'] ) || count( $by ) < 3 || count( array_unique( $by ) ) !== count( $by ) ) {
				continue; }
			if ( count( $by ) > count( $best['by_date'] ?? array() ) ) {
				$best = array(
					'host'    => $h,
					'by_date' => $by,
				);
			}
		}
		return $best;
	}

	/** @return array{slugs: array<string,int>, uids: array<string,int>} */
	public static function map(): array {
		$m = get_option( self::OPT, array() );
		return array(
			'slugs' => is_array( $m['slugs'] ?? null ) ? $m['slugs'] : array(),
			'uids'  => is_array( $m['uids'] ?? null ) ? $m['uids'] : array(),
		);
	}

	/** The series a removed copy's old slug now lives in (only while that series is published), or 0. */
	public static function series_for_slug( string $slug ): int {
		$m  = get_option( self::OPT );
		$id = is_array( $m ) ? (int) ( $m['slugs'][ $slug ] ?? 0 ) : 0;
		return $id && get_post_status( $id ) === 'publish' ? $id : 0;
	}

	/** The series an import id was combined into (while it still exists), or 0. */
	public static function series_for_uid( string $uid ): int {
		$m  = get_option( self::OPT );
		$id = is_array( $m ) ? (int) ( $m['uids'][ $uid ] ?? 0 ) : 0;
		return $id && get_post_status( $id ) && get_post_status( $id ) !== 'trash' ? $id : 0;
	}

	/** Section on Events → Import / Export; prints nothing when there is nothing to combine. */
	public static function section(): void {
		$groups = self::groups();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only
		$done = isset( $_GET['combined'] ) ? (int) $_GET['combined'] : -1;
		if ( $done >= 0 ) {
			echo '<div class="notice notice-success inline" style="margin:1em 0"><p>' . esc_html( sprintf( /* translators: %d: number of repeating events */ _n( 'Combined into %d repeating event. The single copies are in the trash, and their old links lead to it.', 'Combined into %d repeating events. The single copies are in the trash, and their old links lead to them.', $done, 'beacon-events' ), $done ) ) . '</p></div>';
		}
		if ( ! $groups ) {
			return; }
		echo '<h2>' . esc_html__( 'Repeating events entered one date at a time', 'beacon-events' ) . '</h2>';
		echo '<p class="description" style="max-width:46em">' . esc_html__( 'These events have the same title, time and length (and the same venue, where one is set) on a regular pattern, which is how a weekly class ends up when the old calendar charged for repeating events. A date in the title ("– October 1, 2026") is left out when comparing, and the repeating event is named without it. Combine them and each becomes one repeating event you edit once; a missing date becomes a skipped date. The earliest one is kept, the others go to the trash (you can restore them), and their old links redirect. Each date keeps its own ticket link when the copies had different ones.', 'beacon-events' ) . ' ' . ( apply_filters( 'beacon_combine_tickets', false ) ? esc_html__( 'Copies that sell tickets are combined too when their ticket setup matches, and their bookings move to the same dates of the repeating event.', 'beacon-events' ) : esc_html__( 'Events with tickets or RSVPs are left out.', 'beacon-events' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<table class="widefat striped" style="max-width:60em"><thead><tr><td class="check-column"></td><th>' . esc_html__( 'Event', 'beacon-events' ) . '</th><th>' . esc_html__( 'Pattern', 'beacon-events' ) . '</th><th>' . esc_html__( 'Dates', 'beacon-events' ) . '</th></tr></thead><tbody>';
		foreach ( $groups as $g ) {
			$span = wp_date( 'M j, Y', (int) strtotime( $g['dates'][0] . ' 12:00' ) ) . ' – ' . wp_date( 'M j, Y', (int) strtotime( end( $g['dates'] ) . ' 12:00' ) );
			$skip = $g['exdates'] ? ' · ' . sprintf( /* translators: %d: number of skipped dates */ _n( '%d date skipped', '%d dates skipped', count( $g['exdates'] ), 'beacon-events' ), count( $g['exdates'] ) ) : '';
			$skip .= ! empty( $g['dupes'] ) ? ' · ' . sprintf( /* translators: %d: number of duplicate listings */ _n( '%d listed twice', '%d listed twice', (int) $g['dupes'], 'beacon-events' ), (int) $g['dupes'] ) : '';
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="groups[]" value="' . esc_attr( implode( '-', $g['ids'] ) ) . '" checked></th>';
			echo '<td><a href="' . esc_url( (string) get_edit_post_link( $g['ids'][0] ) ) . '">' . esc_html( $g['title'] ) . '</a></td>';
			echo '<td>' . esc_html( $g['text'] ) . '</td>';
			echo '<td>' . esc_html( sprintf( /* translators: %d: number of dates */ _n( '%d date', '%d dates', count( $g['dates'] ), 'beacon-events' ), count( $g['dates'] ) ) . ' · ' . $span . $skip ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Combine the checked events', 'beacon-events' ), 'primary' );
		echo '</form>';
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'beacon-events' ) ); }
		check_admin_referer( self::ACTION );
		$n = 0;
		foreach ( (array) wp_unslash( $_POST['groups'] ?? array() ) as $g ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is reduced to integers below
			$ids = array_filter( array_map( 'absint', explode( '-', (string) $g ) ) );
			if ( self::combine( $ids ) ) {
				++$n; }
		}
		wp_safe_redirect( add_query_arg( 'combined', $n, admin_url( 'edit.php?post_type=' . Events::CPT . '&page=' . Import::PAGE ) ) );
		exit;
	}
}
