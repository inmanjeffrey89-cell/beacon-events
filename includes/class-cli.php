<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Imports events from other calendar plugins, from the command line.
 *
 * Same importer as Events → Import / Export, for agencies moving several sites at once: nothing in the other plugin is
 * changed, re-running updates instead of duplicating, and Beacon Events Pro's ticket and attendee import runs too when
 * it is active.
 *
 *     wp beacon sources
 *     wp beacon import tec
 */
final class CLI {

	public static function register(): void {
		\WP_CLI::add_command( 'beacon', __CLASS__ );
	}

	/**
	 * Lists the events plugins Beacon can import from and what it finds of each on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beacon sources
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 */
	public function sources( $args, $assoc ) {
		$rows = array();
		foreach ( Import::sources() as $key => $cls ) {
			if ( Import\Source_CSV::KEY === $key ) {
				continue; }
			$d      = $cls::detect();
			$rows[] = array(
				'source'    => $key,
				'plugin'    => $cls::label(),
				'events'    => (int) ( $d['events'] ?? 0 ),
				'tickets'   => (int) ( $d['tickets'] ?? 0 ),
				'attendees' => (int) ( $d['attendees'] ?? 0 ),
				'imported'  => (int) ( $d['imported'] ?? 0 ),
				'active'    => empty( $d['active'] ) ? 'no' : 'yes',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'source', 'plugin', 'events', 'tickets', 'attendees', 'imported', 'active' ) );
	}

	/**
	 * Finds repeating events that were entered one date at a time and combines each group into one repeating event.
	 *
	 * Same as the "Repeating events entered one date at a time" section on Events → Import / Export: the earliest
	 * copy becomes the series, the others go to the trash and their old links redirect.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only list what would be combined.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beacon combine --dry-run
	 *     wp beacon combine
	 *
	 * @param array $args  Unused.
	 * @param array $assoc [ dry-run ].
	 */
	public function combine( $args, $assoc ) {
		$groups = Combine::groups();
		if ( ! $groups ) {
			\WP_CLI::success( 'Nothing to combine.' );
			return;
		}
		$rows = array();
		foreach ( $groups as $g ) {
			$rows[] = array(
				'event'   => $g['title'],
				'pattern' => $g['text'],
				'dates'   => count( $g['dates'] ) . ' (' . $g['dates'][0] . ' to ' . end( $g['dates'] ) . ')',
				'skipped' => count( $g['exdates'] ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'event', 'pattern', 'dates', 'skipped' ) );
		if ( ! empty( $assoc['dry-run'] ) ) {
			return; }
		$n = 0;
		foreach ( $groups as $g ) {
			if ( Combine::combine( $g['ids'] ) ) {
				++$n; }
		}
		\WP_CLI::success( sprintf( 'Combined %d of %d groups into repeating events.', $n, count( $groups ) ) );
	}

	/**
	 * Imports events from another events plugin.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Where from: tec, em, ai1ec, mec, wpem, eventon, wpems, sugarcalendar, eventin, eventorganiser or eventprime (see `wp beacon sources`).
	 *
	 * [--batch=<number>]
	 * : Events per batch.
	 * ---
	 * default: 40
	 * ---
	 *
	 * [--dry-run]
	 * : Show what the import would create, update, skip and simplify, without writing anything.
	 *
	 * [--report=<file>]
	 * : Also write a CSV with one row per event: what happened to it (or would, on a dry run), its new Beacon ID, dates, rule and notes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beacon import tec --dry-run --report=tec-dry-run.csv
	 *     wp beacon import tec
	 *     wp beacon import eventon --batch=100
	 *
	 * @param array $args  [ source ].
	 * @param array $assoc [ batch, dry-run, report ].
	 */
	public function import( $args, $assoc ) {
		$key     = (string) ( $args[0] ?? '' );
		$sources = Import::sources();
		if ( ! isset( $sources[ $key ] ) || Import\Source_CSV::KEY === $key ) {
			\WP_CLI::error( sprintf( 'Unknown source "%s". Run `wp beacon sources` to see the choices.', $key ) ); }
		$d = $sources[ $key ]::detect();
		if ( empty( $d['found'] ) ) {
			\WP_CLI::error( sprintf( 'No %s events found on this site.', $sources[ $key ]::label() ) ); }
		$batch  = max( 1, (int) ( $assoc['batch'] ?? 40 ) );
		$report = isset( $assoc['report'] ) ? (string) $assoc['report'] : '';
		if ( '' !== $report && ! is_writable( is_file( $report ) ? $report : ( dirname( $report ) ?: '.' ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- CLI, a local path the user typed
			\WP_CLI::error( sprintf( 'Cannot write the report to %s.', $report ) ); }
		$state        = Import::fresh_state( $key );
		$state['dry'] = ! empty( $assoc['dry-run'] );
		$off          = 0;
		$bar          = \WP_CLI\Utils\make_progress_bar( sprintf( $state['dry'] ? 'Dry run, checking %s' : 'Importing from %s', $sources[ $key ]::label() ), (int) ( $d['total'] ?? $d['events'] ?? 0 ) );
		do {
			$before = (int) $state['processed'];
			$state  = Import::run_batch( $state, $off, $batch );
			$off    = $state['next'];
			$bar->tick( max( 0, (int) $state['processed'] - $before ) );
		} while ( null !== $state['next'] );
		$bar->finish();
		Import::finish( $state );
		if ( '' !== $report ) {
			$saved = (array) Import::last_report();
			$out   = fopen( $report, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI, a local path the user typed
			Import::write_report( $saved, $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			\WP_CLI::log( sprintf( 'Report: %s (%d events).', $report, count( (array) ( $saved['rows'] ?? array() ) ) ) );
		}
		\WP_CLI::log( sprintf( $state['dry'] ? 'Would create %d, update %d, skip %d. New venues %d, organizers %d, categories %d.' : 'Created %d, updated %d, skipped %d. Venues %d, organizers %d, categories %d.', $state['created'], $state['updated'], $state['skipped'], $state['venues'], $state['organizers'], $state['categories'] ) );
		if ( $state['dry'] && $state['images'] ) {
			\WP_CLI::log( sprintf( 'Would download %d featured images.', $state['images'] ) ); }
		if ( $state['tickets'] || $state['attendees'] ) {
			\WP_CLI::log( sprintf( 'Tickets set up on %d events; %d attendees brought over.', $state['tickets'], $state['attendees'] ) ); }
		foreach ( $state['notes'] as $note ) {
			\WP_CLI::log( 'Note: ' . $note ); }
		foreach ( $state['errors'] as $err ) {
			\WP_CLI::warning( $err ); }
		if ( $state['errors'] ) {
			\WP_CLI::error( sprintf( '%d events could not be imported (see above).', count( $state['errors'] ) ) ); }
		\WP_CLI::success( $state['dry'] ? 'Dry run finished. Nothing was written; run it without --dry-run to import.' : 'Import finished. Nothing in the other plugin was changed; run it again any time to pick up changes.' );
	}
}
