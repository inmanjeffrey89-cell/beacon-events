<?php
/**
 * Recurrence engine — a deliberately small RRULE subset that covers what event sites actually use:
 *   FREQ=DAILY|WEEKLY|MONTHLY|YEARLY ; INTERVAL=n ; BYDAY=MO,TU,... (weekly) or 2TU / -1FR (monthly by position) ;
 *   BYMONTHDAY=n ; COUNT=n | UNTIL=YYYYMMDD ; EXDATE=YYYYMMDD,...
 * Pure PHP, no WordPress dependency, so it can be unit-tested on its own. Expands to occurrence start dates.
 * Rules are stored on the parent event; occurrences are materialised into a table for fast querying.
 */
namespace Beacon;

final class Recurrence {
	const MAX_OCCURRENCES = 1100;         // hard cap on dates returned: three years of daily events (one back, two ahead)
	private static $days  = array(
		'SU' => 0,
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
	);

	/** Parse "FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;COUNT=10" into an array. Unknown keys are ignored, never fatal. */
	public static function parse( string $rrule ): array {
		$r = array(
			'FREQ'       => null,
			'INTERVAL'   => 1,
			'BYDAY'      => array(),
			'BYMONTHDAY' => null,
			'COUNT'      => null,
			'UNTIL'      => null,
			'EXDATE'     => array(),
		);
		foreach ( explode( ';', strtoupper( trim( $rrule ) ) ) as $part ) {
			if ( strpos( $part, '=' ) === false ) {
				continue;
			}
			[$k, $v] = explode( '=', $part, 2 );
			switch ( $k ) {
				case 'FREQ':
					$r['FREQ'] = in_array( $v, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ? $v : null;
					break;
				case 'INTERVAL':
					$r['INTERVAL'] = max( 1, (int) $v );
					break;
				case 'BYDAY':
					$r['BYDAY'] = array_values( array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
					break;
				case 'BYMONTHDAY':
					$r['BYMONTHDAY'] = (int) $v ?: null;
					break;
				case 'COUNT':
					$r['COUNT'] = max( 1, (int) $v );
					break;
				case 'UNTIL':
					$r['UNTIL'] = self::ymd( substr( $v, 0, 8 ) );
					break;
				case 'EXDATE':
					$r['EXDATE'] = array_values( array_filter( array_map( fn( $d ) => self::ymd( substr( trim( $d ), 0, 8 ) ), explode( ',', $v ) ) ) );
					break;
			}
		}
		return $r;
	}

	private static function ymd( string $s ): ?string {
		return preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $s, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "$m[1]-$m[2]-$m[3]" : null;
	}

	/**
	 * Expand a rule from $start (Y-m-d) into occurrence dates (Y-m-d), inclusive of $start when it matches.
	 * $windowEnd bounds the expansion regardless of COUNT/UNTIL so an open-ended rule can't run away.
	 * $windowStart (optional) keeps only dates on or after it. COUNT still counts from the series' first date, and
	 * a rule without COUNT jumps straight to the window, so a weekly event that began years ago gets its upcoming
	 * dates without walking every week since.
	 */
	public static function expand( string $rrule, string $start, string $windowEnd, ?\DateTimeZone $tz = null, ?string $windowStart = null ): array {
		$r = self::parse( $rrule );
		if ( ! $r['FREQ'] ) {
			return array( $start );
		}
		$tz  = $tz ?: new \DateTimeZone( 'UTC' );
		$cur = new \DateTimeImmutable( $start, $tz );
		$end = new \DateTimeImmutable( $windowEnd, $tz );
		if ( $r['UNTIL'] ) {
			$until = new \DateTimeImmutable( $r['UNTIL'], $tz );
			if ( $until < $end ) {
				$end = $until;
			}
		}
		$from = $windowStart ? new \DateTimeImmutable( $windowStart, $tz ) : $cur;
		if ( $from < $cur ) {
			$from = $cur;
		}
		$jump  = ! $r['COUNT'] && $from > $cur;   // without COUNT nothing before the window matters
		$ex    = array_flip( $r['EXDATE'] );
		$out   = array();
		$count = 0;   // every date the rule produces from $start on: what COUNT limits
		$guard = 0;

		if ( $r['FREQ'] === 'WEEKLY' && $r['BYDAY'] ) {
			$wanted = array_values( array_filter( array_map( fn( $d ) => self::$days[ $d ] ?? null, $r['BYDAY'] ), fn( $v ) => $v !== null ) );
			sort( $wanted );
			$weekStart = $cur->modify( 'sunday last week' )->modify( '+0 days' ); // Sunday of start's week
			if ( (int) $cur->format( 'w' ) === 0 ) {
				$weekStart = $cur;
			}
			if ( $jump ) {
				$weeks = intdiv( intdiv( (int) $weekStart->diff( $from )->days, 7 ), $r['INTERVAL'] ) * $r['INTERVAL'];
				$weekStart = $weekStart->modify( '+' . ( 7 * $weeks ) . ' days' );
			}
			while ( $guard++ < 5000 ) {
				foreach ( $wanted as $dow ) {
					$d = $weekStart->modify( "+$dow days" );
					if ( $d < $cur ) {
						continue;
					}
					if ( $d > $end ) {
						break 2;
					}
					$ymd = $d->format( 'Y-m-d' );
					++$count;
					if ( $d >= $from && ! isset( $ex[ $ymd ] ) ) {
						$out[] = $ymd;
					}
					if ( count( $out ) >= self::MAX_OCCURRENCES || ( $r['COUNT'] && $count >= $r['COUNT'] ) ) {
						break 2;
					}
				}
				$weekStart = $weekStart->modify( '+' . ( 7 * $r['INTERVAL'] ) . ' days' );
			}
			return $out;
		}

		if ( $r['FREQ'] === 'MONTHLY' && $r['BYDAY'] && preg_match( '/^-?\d[A-Z]{2}$/', $r['BYDAY'][0] ) ) {
			// By position in the month: 2TU = second Tuesday, -1FR = last Friday; several at once ("2TU,4TU" = second
			// and fourth Tuesday) all count. A month without a fifth Tuesday has no 5TU date (PHP's "fifth tuesday of
			// this month" would roll into the next month, so it is checked).
			$names = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
			$ord   = array( 'first', 'second', 'third', 'fourth', 'fifth' );
			$specs = array();
			foreach ( $r['BYDAY'] as $b ) {
				if ( preg_match( '/^(-?\d)([A-Z]{2})$/', $b, $m ) && isset( self::$days[ $m[2] ] ) && ( (int) $m[1] === -1 || ( (int) $m[1] >= 1 && (int) $m[1] <= 5 ) ) ) {
					$specs[] = array( (int) $m[1], self::$days[ $m[2] ] ); }
			}
			if ( ! $specs ) {
				return array( $start );
			}
			$month = $cur->modify( 'first day of this month' );
			if ( $jump ) {
				$gap   = $month->diff( $from->modify( 'first day of this month' ) );
				$month = $month->modify( '+' . ( intdiv( $gap->y * 12 + $gap->m, $r['INTERVAL'] ) * $r['INTERVAL'] ) . ' months' );
			}
			while ( $guard++ < 1200 && $month <= $end ) {
				$in = array();
				foreach ( $specs as list( $pos, $dow ) ) {
					$d = $pos > 0 ? $month->modify( $ord[ $pos - 1 ] . ' ' . $names[ $dow ] . ' of this month' )
									: $month->modify( 'last ' . $names[ $dow ] . ' of this month' );
					if ( $d->format( 'Y-m' ) === $month->format( 'Y-m' ) ) {
						$in[ $d->format( 'Y-m-d' ) ] = $d; }
				}
				ksort( $in );
				foreach ( $in as $ymd => $d ) {
					if ( $d < $cur || $d > $end ) {
						continue; }
					++$count;
					if ( $d >= $from && ! isset( $ex[ $ymd ] ) ) {
						$out[] = $ymd;
					}
					if ( count( $out ) >= self::MAX_OCCURRENCES || ( $r['COUNT'] && $count >= $r['COUNT'] ) ) {
						return $out;
					}
				}
				$month = $month->modify( '+' . $r['INTERVAL'] . ' months' );
			}
			return $out;
		}

		// DAILY, plain WEEKLY, MONTHLY by month-day, YEARLY
		$step  = array(
			'DAILY'   => 'days',
			'WEEKLY'  => 'weeks',
			'MONTHLY' => 'months',
			'YEARLY'  => 'years',
		)[ $r['FREQ'] ];
		$mday  = $r['BYMONTHDAY'] ?: (int) $cur->format( 'j' );
		$first = $cur->modify( 'first day of this month' );
		$i     = 0;
		if ( $jump ) {
			$gap = $cur->diff( $from );
			$i   = intdiv(
				array(
					'DAILY'   => (int) $gap->days,
					'WEEKLY'  => intdiv( (int) $gap->days, 7 ),
					'MONTHLY' => $gap->y * 12 + $gap->m,
					'YEARLY'  => $gap->y,
				)[ $r['FREQ'] ],
				$r['INTERVAL']
			);
		}
		while ( $guard++ < 5000 ) {
			if ( $r['FREQ'] === 'MONTHLY' ) {
				// step from the 1st so "+N months" can never overflow; keep the intended day; skip months without it
				$d = $first->modify( '+' . ( $i * $r['INTERVAL'] ) . ' months' );
				if ( $mday > (int) $d->format( 't' ) ) {
					++$i;
					if ( $d > $end ) {
						break;
					} continue; }
				$d = $d->setDate( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), $mday );
			} else {
				$d = $cur->modify( '+' . ( $i * $r['INTERVAL'] ) . " $step" );
			}
			if ( $d > $end ) {
				break;
			}
			$ymd = $d->format( 'Y-m-d' );
			++$count;
			if ( $d >= $from && ! isset( $ex[ $ymd ] ) ) {
				$out[] = $ymd;
			}
			if ( count( $out ) >= self::MAX_OCCURRENCES || ( $r['COUNT'] && $count >= $r['COUNT'] ) ) {
				break;
			}
			++$i;
		}
		return $out;
	}

	/** Human sentence for the admin UI: "Every 2 weeks on Tuesday and Thursday, 10 times". */
	public static function describe( string $rrule ): string {
		$r = self::parse( $rrule );
		if ( ! $r['FREQ'] ) {
			return __( 'Does not repeat', 'beacon-events' );
		}
		// Whole phrases per unit (German: "Jeden Tag", "Jede Woche", "Jeden Monat", "Jedes Jahr"), never "Every %s".
		$n = $r['INTERVAL'];
		switch ( $r['FREQ'] ) {
			case 'DAILY':
				/* translators: %d: number of days, 2 or more */
				$s = 1 === $n ? __( 'Every day', 'beacon-events' ) : sprintf( _n( 'Every %d day', 'Every %d days', $n, 'beacon-events' ), $n );
				break;
			case 'WEEKLY':
				/* translators: %d: number of weeks, 2 or more */
				$s = 1 === $n ? __( 'Every week', 'beacon-events' ) : sprintf( _n( 'Every %d week', 'Every %d weeks', $n, 'beacon-events' ), $n );
				break;
			case 'MONTHLY':
				/* translators: %d: number of months, 2 or more */
				$s = 1 === $n ? __( 'Every month', 'beacon-events' ) : sprintf( _n( 'Every %d month', 'Every %d months', $n, 'beacon-events' ), $n );
				break;
			default:
				/* translators: %d: number of years, 2 or more */
				$s = 1 === $n ? __( 'Every year', 'beacon-events' ) : sprintf( _n( 'Every %d year', 'Every %d years', $n, 'beacon-events' ), $n );
		}
		$names = self::weekday_names();
		$ord   = self::ordinals();
		$and   = function ( array $l ): string {
			if ( count( $l ) < 2 ) {
				return (string) reset( $l ); }
			$last = array_pop( $l );
			/* translators: a list: 1: "Monday, Tuesday", 2: "Friday" → "Monday, Tuesday and Friday" */
			return sprintf( __( '%1$s and %2$s', 'beacon-events' ), implode( ', ', $l ), $last );
		};
		$on    = function ( string $what, string $days ): string {
			/* translators: 1: "Every week", "Every month"…, 2: the days ("Monday and Thursday", "the second Tuesday") */
			return sprintf( __( '%1$s on %2$s', 'beacon-events' ), $what, $days );
		};
		if ( $r['FREQ'] === 'MONTHLY' && $r['BYDAY'] && preg_match( '/^-?\d[A-Z]{2}$/', $r['BYDAY'][0] ) ) {
			// "the second and fourth Tuesday", "the first Monday and the third Wednesday"
			$by = array();
			foreach ( $r['BYDAY'] as $d ) {
				if ( preg_match( '/^(-?\d)([A-Z]{2})$/', $d, $m ) ) {
					$by[ $names[ $m[2] ] ?? $m[2] ][] = $ord[ $m[1] ] ?? $m[1]; }
			}
			$bits = array();
			foreach ( $by as $day => $ords ) {
				/* translators: 1: ordinals ("second and fourth"), 2: weekday → "the second and fourth Tuesday" */
				$bits[] = sprintf( __( 'the %1$s %2$s', 'beacon-events' ), $and( $ords ), $day ); }
			$s = $on( $s, $and( $bits ) );
		} elseif ( $r['BYDAY'] ) {
			$parts = array();
			$byday = $r['BYDAY'];
			if ( ! preg_grep( '/\d/', $byday ) ) {
				// plain weekdays in the site's week order: "Saturday and Sunday", not RRULE's "Sunday and Saturday"
				$first = function_exists( 'get_option' ) ? (int) get_option( 'start_of_week', 1 ) : 1;
				$pos   = array_flip( array_keys( $names ) );
				usort( $byday, fn( $a, $b ) => ( ( ( $pos[ $a ] ?? 0 ) - $first + 7 ) % 7 ) <=> ( ( ( $pos[ $b ] ?? 0 ) - $first + 7 ) % 7 ) );
			}
			foreach ( $byday as $d ) {
				if ( preg_match( '/^(-?\d)([A-Z]{2})$/', $d, $m ) ) {
					/* translators: 1: ordinal ("first"), 2: weekday → "first Monday" */
					$parts[] = sprintf( _x( '%1$s %2$s', 'ordinal weekday', 'beacon-events' ), $ord[ $m[1] ] ?? $m[1], $names[ $m[2] ] ?? $m[2] );
				} else {
					$parts[] = $names[ $d ] ?? $d;
				}
			}
			$s = $on( $s, $and( $parts ) );
		} elseif ( $r['BYMONTHDAY'] ) {
			/* translators: 1: "Every month", 2: day of the month → "Every month on day 15" */
			$s = sprintf( __( '%1$s on day %2$d', 'beacon-events' ), $s, (int) $r['BYMONTHDAY'] );
		}
		if ( $r['COUNT'] ) {
			/* translators: %d: how many dates the series has */
			$s .= ', ' . sprintf( _n( '%d time', '%d times', (int) $r['COUNT'], 'beacon-events' ), (int) $r['COUNT'] );
		}
		if ( $r['UNTIL'] ) {
			// visitors read this on the event page: the site's date format, not "2026-10-25"
			$until = function_exists( 'wp_date' ) ? wp_date( (string) get_option( 'date_format', 'F j, Y' ), (int) strtotime( $r['UNTIL'] . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) ) : $r['UNTIL'];
			/* translators: %s: last date of the series */
			$s .= ', ' . sprintf( __( 'until %s', 'beacon-events' ), $until );
		}
		return $s;
	}

	/** Weekday names by RRULE code, in the site's language when WordPress has loaded its locale. */
	private static function weekday_names(): array {
		$codes = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );
		$en    = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		$out   = array();
		foreach ( $codes as $i => $c ) {
			$out[ $c ] = ( isset( $GLOBALS['wp_locale'] ) && is_object( $GLOBALS['wp_locale'] ) ) ? (string) $GLOBALS['wp_locale']->get_weekday( $i ) : $en[ $i ]; }
		return $out;
	}

	/** "first" … "last" as in "the first Monday of the month". */
	private static function ordinals(): array {
		return array(
			'1'  => _x( 'first', 'the first Monday of the month', 'beacon-events' ),
			'2'  => _x( 'second', 'the second Monday of the month', 'beacon-events' ),
			'3'  => _x( 'third', 'the third Monday of the month', 'beacon-events' ),
			'4'  => _x( 'fourth', 'the fourth Monday of the month', 'beacon-events' ),
			'5'  => _x( 'fifth', 'the fifth Monday of the month', 'beacon-events' ),
			'-1' => _x( 'last', 'the last Monday of the month', 'beacon-events' ),
		);
	}
}
