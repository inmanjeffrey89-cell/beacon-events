<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Import\Source_AI1EC;
use Beacon\Import\Source_CSV;
use Beacon\Import\Source_EM;
use Beacon\Import\Source_EVENTON;
use Beacon\Import\Source_MEC;
use Beacon\Import\Source_SC;
use Beacon\Import\Source_ETN;
use Beacon\Import\Source_EO;
use Beacon\Import\Source_EP;
use Beacon\Import\Source_MC;
use Beacon\Import\Source_TEC;
use Beacon\Import\Source_WPEM;
use Beacon\Import\Source_WPEMS;

/**
 * Events → Import: move a site off another events plugin, or bring a spreadsheet in, in one click.
 *
 * Each source (The Events Calendar, Events Manager, All-in-One Event Calendar, CSV) reads its own data and hands back one normalized event;
 * this class turns that into Beacon events, venues, organizers and categories, carries images, timezones and
 * recurrence rules across, and never deletes anything of the other plugin's. Re-running updates what it created
 * before (matched on `_beacon_uid`) instead of duplicating. Batches keep big calendars under PHP time limits.
 */
final class Import {
	const PAGE       = 'beacon-import';
	const OPT_LOG    = 'beacon_import_log';
	const OPT_REPORT = 'beacon_import_report'; // the latest run, one row per event (not autoloaded)
	const REPORT_CAP = 10000;

	public static function init(): void {
		add_action( 'admin_menu', fn() => add_submenu_page( 'edit.php?post_type=' . Events::CPT, __( 'Import / Export', 'beacon-events' ), __( 'Import / Export', 'beacon-events' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) ) );
		add_action( 'admin_post_beacon_import', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_beacon_import_tec', array( __CLASS__, 'handle' ) ); // 0.3.0 form name
		add_action( 'admin_post_beacon_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_beacon_import_report', array( __CLASS__, 'download_report' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( Events::REFRESH, array( __CLASS__, 'sweep_files' ) ); // daily
		// Before redirect_canonical (10): its "closest match" guess would send /events/week/ to any post whose slug starts with "week".
		add_action( 'template_redirect', array( __CLASS__, 'legacy_redirects' ), 9 );
	}

	/** key → source class. Each has label(), detect(), ids(), map(). */
	public static function sources(): array {
		return array(
			Source_TEC::KEY   => Source_TEC::class,
			Source_EM::KEY    => Source_EM::class,
			Source_AI1EC::KEY => Source_AI1EC::class,
			Source_MEC::KEY   => Source_MEC::class,
			Source_WPEM::KEY  => Source_WPEM::class,
			Source_EVENTON::KEY => Source_EVENTON::class,
			Source_WPEMS::KEY => Source_WPEMS::class,
			Source_SC::KEY    => Source_SC::class,
			Source_ETN::KEY   => Source_ETN::class,
			Source_EO::KEY    => Source_EO::class,
			Source_EP::KEY    => Source_EP::class,
			Source_MC::KEY    => Source_MC::class,
			Source_CSV::KEY   => Source_CSV::class,
		);
	}

	public static function batch_size(): int {
		return max( 1, (int) apply_filters( 'beacon_import_batch', 40 ) );
	}

	/** Is there already a Beacon event carrying this uid (any source)? */
	public static function uid_exists( string $uid ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed lookup per CSV row
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_beacon_uid' AND meta_value = %s LIMIT 1", $uid ) );
	}

	/** How many Beacon events came from one source (by uid prefix). */
	public static function imported_count( string $key ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one COUNT for the admin page
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_beacon_uid' AND meta_value LIKE %s", $wpdb->esc_like( $key . '-' ) . '%' ) );
	}

	/* ---------------- admin page ---------------- */

	public static function assets( string $hook ): void {
		if ( $hook !== Events::CPT . '_page_' . self::PAGE ) {
			return; }
		wp_register_script( 'beacon-events-import', false, array(), BEACON_VERSION, true );
		wp_enqueue_script( 'beacon-events-import' );
		// The batch form re-submits itself; the visible "Continue" button is the no-JavaScript fallback.
		// prototype call: a form's own submit() is shadowed when it contains a control named "submit"
		wp_add_inline_script( 'beacon-events-import', 'setTimeout(function(){var f=document.getElementById("beacon-import-continue");if(f){HTMLFormElement.prototype.submit.call(f);}},400);' );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		echo '<div class="wrap"><h1>' . esc_html__( 'Import / Export', 'beacon-events' ) . '</h1>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags only; the work happens in handle() behind a nonce
		$offset = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : null;
		$done   = isset( $_GET['done'] );
		$error  = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable
		$state = get_transient( self::state_key() );
		if ( $offset !== null && is_array( $state ) && $state['next'] !== null ) {
			$total = max( 1, (int) $state['total'] );
			$pct   = min( 100, (int) round( 100 * $state['processed'] / $total ) );
			echo '<h2>' . esc_html( sprintf( empty( $state['dry'] ) ? /* translators: %s: source name */ __( 'Importing from %s…', 'beacon-events' ) : /* translators: %s: source name */ __( 'Dry run: checking %s… (nothing is written)', 'beacon-events' ), self::label( $state['source'] ) ) ) . '</h2>';
			echo '<p>' . esc_html( sprintf( /* translators: 1: events done, 2: total events */ __( '%1$d of %2$d', 'beacon-events' ), $state['processed'], $total ) ) . '</p>';
			echo '<div style="max-width:40em;background:#dcdcde;height:14px;border-radius:7px"><div style="width:' . (int) $pct . '%;background:#2271b1;height:14px;border-radius:7px"></div></div>';
			echo '<form id="beacon-import-continue" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'beacon_import' );
			echo '<input type="hidden" name="action" value="beacon_import"><input type="hidden" name="source" value="' . esc_attr( $state['source'] ) . '"><input type="hidden" name="offset" value="' . (int) $state['next'] . '">';
			submit_button( __( 'Continue', 'beacon-events' ), 'secondary', 'beacon_continue' );
			echo '</form></div>';
			return;
		}
		if ( $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>'; }
		$log = get_option( self::OPT_LOG, array() );
		if ( $done && $log ) {
			self::report( $log[0] ); }
		$intro = esc_html__( 'Nothing in the other plugin is changed or deleted. Events, venues, organizers, categories, featured images, timezones and recurring rules are copied over; anything imported earlier is updated, not duplicated. Both plugins can stay active while you check the result.', 'beacon-events' );
		foreach ( self::sources() as $key => $cls ) {
			if ( $key === Source_CSV::KEY ) {
				continue; }
			$d = $cls::detect();
			if ( empty( $d['found'] ) ) {
				continue; }
			echo '<h2>' . esc_html( sprintf( /* translators: %s: plugin name */ __( 'From %s', 'beacon-events' ), $cls::label() ) ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:40em"><tbody>';
			foreach ( array(
				'events'     => __( 'Events', 'beacon-events' ),
				'venues'     => __( 'Venues / locations', 'beacon-events' ),
				'organizers' => __( 'Organizers', 'beacon-events' ),
				'categories' => __( 'Categories', 'beacon-events' ),
				'tickets'    => __( 'Tickets and RSVPs', 'beacon-events' ),
				'attendees'  => __( 'Confirmed attendees', 'beacon-events' ),
				'imported'   => __( 'Already imported', 'beacon-events' ),
			) as $k => $label ) {
				if ( isset( $d[ $k ] ) && ( ! in_array( $k, array( 'imported', 'tickets', 'attendees' ), true ) || $d[ $k ] ) ) {
					echo '<tr><td>' . esc_html( $label ) . '</td><td>' . (int) $d[ $k ] . '</td></tr>'; }
			}
			echo '</tbody></table><p class="description" style="max-width:46em">' . $intro . ' ' . esc_html( $cls::hint() ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
			if ( ! empty( $d['tickets'] ) ) {
				if ( has_filter( 'beacon_import_after_event' ) ) {
					echo '<p class="description">' . esc_html__( 'Ticket prices, capacity and the confirmed attendee lists are imported too.', 'beacon-events' ) . '</p>';
				} else {
					echo '<p class="description">' . esc_html__( 'Your tickets and attendee lists stay where they are. Beacon Events Pro (from $7 a month) imports them too: ticket price and capacity, every RSVP and every paid attendee.', 'beacon-events' ) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Events::CPT . '&page=beacon-pro' ) ) . '">' . esc_html__( 'About Pro', 'beacon-events' ) . '</a></p>';
				}
			}
			if ( ! empty( $d['children'] ) ) {
				echo '<p class="description">' . esc_html( sprintf( /* translators: %d: number of child posts */ __( '%d generated occurrence posts will be skipped; their parent events are imported with the rule.', 'beacon-events' ), $d['children'] ) ) . '</p>'; }
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'beacon_import' );
			echo '<input type="hidden" name="action" value="beacon_import"><input type="hidden" name="source" value="' . esc_attr( $key ) . '"><input type="hidden" name="offset" value="0">';
			echo '<p class="submit">';
			submit_button( ! empty( $d['imported'] ) ? __( 'Import again (update)', 'beacon-events' ) : sprintf( /* translators: %d: event count */ __( 'Import %d events', 'beacon-events' ), (int) $d['events'] ), 'primary', 'submit', false );
			echo ' ';
			submit_button( __( 'Dry run first', 'beacon-events' ), 'secondary', 'dry', false, array( 'title' => __( 'Shows what the import would create, update, skip or simplify, without writing anything.', 'beacon-events' ) ) );
			echo '</p></form>';
		}
		Combine::section();
		// CSV in
		echo '<h2>' . esc_html__( 'From a CSV file', 'beacon-events' ) . '</h2>';
		echo '<p class="description" style="max-width:46em">' . esc_html__( 'One event per row. Columns (header row, any order; only title and start are required):', 'beacon-events' ) . ' <code>' . esc_html( implode( ', ', Source_CSV::COLUMNS ) ) . '</code>. ' . esc_html__( 'Dates like 2026-10-06 18:00 (a bare date means all day). "repeat" is an iCalendar rule such as FREQ=WEEKLY;BYDAY=TU;COUNT=10. Separate several categories or organizers with |. Rows with a uid that was imported before are updated. Exporting first gives you a file in exactly this shape.', 'beacon-events' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'beacon_import' );
		echo '<input type="hidden" name="action" value="beacon_import"><input type="hidden" name="source" value="csv"><input type="hidden" name="offset" value="0">';
		echo '<p><input type="file" name="beacon_csv" accept=".csv,text/csv" required></p>';
		submit_button( __( 'Import CSV', 'beacon-events' ), 'primary' );
		echo '</form>';
		// CSV out
		echo '<h2>' . esc_html__( 'Export', 'beacon-events' ) . '</h2><p class="description">' . esc_html__( 'Every event as a CSV file in the same columns, ready for a spreadsheet or another site.', 'beacon-events' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'beacon_export' );
		echo '<input type="hidden" name="action" value="beacon_export_csv">';
		submit_button( __( 'Download events.csv', 'beacon-events' ), 'secondary' );
		echo '</form>';
		if ( ! $done && $log ) {
			echo '<h2>' . esc_html__( 'Last import', 'beacon-events' ) . '</h2>';
			self::report( $log[0] ); }
		echo '</div>';
	}

	private static function label( string $key ): string {
		$s = self::sources();
		return isset( $s[ $key ] ) ? $s[ $key ]::label() : $key;
	}

	/** Prints " Download the report (CSV)" when the saved per-event report belongs to this run. */
	private static function report_link( array $r ): void {
		$saved = self::last_report();
		if ( ! $saved || ( $saved['started'] ?? '' ) !== ( $r['started'] ?? null ) || ( $saved['source'] ?? '' ) !== ( $r['source'] ?? null ) || ! $saved['rows'] ) {
			return; }
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=beacon_import_report' ), 'beacon_import_report' );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Download the report, one row per event (CSV)', 'beacon-events' ) . '</a>';
	}

	private static function report( array $r ): void {
		if ( ! empty( $r['dry'] ) ) {
			echo '<div class="notice notice-info inline" style="margin:0 0 1em"><p><strong>' . esc_html__( 'Dry run, nothing was written.', 'beacon-events' ) . '</strong> ' . esc_html(
				sprintf(
					/* translators: 1: source, 2: would create, 3: would update, 4: skipped, 5: venues, 6: organizers, 7: categories, 8: time */
					__( '%1$s: the import would create %2$d events and update %3$d (%4$d skipped), and add %5$d venues, %6$d organizers and %7$d categories. Checked %8$s.', 'beacon-events' ),
					self::label( (string) ( $r['source'] ?? '' ) ),
					$r['created'],
					$r['updated'],
					$r['skipped'],
					$r['venues'],
					$r['organizers'],
					$r['categories'],
					$r['finished']
				)
			);
			self::report_link( $r );
			echo '</p></div>';
			$lines = array_merge( array_map( fn( $e ) => __( 'Error:', 'beacon-events' ) . ' ' . $e, $r['errors'] ), $r['notes'] );
			if ( ! empty( $r['images'] ) ) {
				$lines[] = sprintf( /* translators: %d: count */ __( '%d featured images would be downloaded.', 'beacon-events' ), $r['images'] ); }
			if ( ! empty( $r['links'] ) ) {
				/* translators: %d: number of events */
				$lines[] = sprintf( _n( '%d event has a website or ticket address; it would become the event\'s "Tickets & info" button.', '%d events have a website or ticket address; it would become each event\'s "Tickets & info" button.', (int) $r['links'], 'beacon-events' ), (int) $r['links'] ); }
			if ( ! empty( $r['extras'] ) ) {
				/* translators: 1: number of events, 2: field names, e.g. "cost" */
				$lines[] = sprintf( _n( '%1$d event has fields Beacon doesn\'t show (%2$s); they would be kept in hidden meta (_beacon_import_extra).', '%1$d events have fields Beacon doesn\'t show (%2$s); they would be kept in hidden meta (_beacon_import_extra).', (int) $r['extras'], 'beacon-events' ), (int) $r['extras'], self::extra_names( $r ) ); }
			if ( ! empty( $r['tz_fallbacks'] ) ) {
				$lines[] = sprintf( /* translators: %d: count */ __( '%d events have no usable named timezone; they would get the site timezone.', 'beacon-events' ), $r['tz_fallbacks'] ); }
			if ( $lines ) {
				echo '<ul style="list-style:disc;margin-left:1.4em;max-width:60em">';
				foreach ( array_slice( $lines, 0, 60 ) as $l ) {
					echo '<li>' . esc_html( $l ) . '</li>'; }
				echo '</ul>'; }
			return;
		}
		echo '<div class="notice notice-success inline" style="margin:0 0 1em"><p>' . esc_html(
			sprintf(
				/* translators: 1: source, 2: created, 3: updated, 4: skipped, 5: venues, 6: organizers, 7: categories, 8: time */
				__( '%1$s: imported %2$d new events and updated %3$d (%4$d skipped); %5$d venues, %6$d organizers and %7$d categories created. Finished %8$s.', 'beacon-events' ),
				self::label( (string) ( $r['source'] ?? '' ) ),
				$r['created'],
				$r['updated'],
				$r['skipped'],
				$r['venues'],
				$r['organizers'],
				$r['categories'],
				$r['finished']
			)
		) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Events::CPT ) ) . '">' . esc_html__( 'See the events', 'beacon-events' ) . '</a>';
		self::report_link( $r );
		echo '</p></div>';
		// The one moment a review ask fits: a move that just worked. One line on this screen, nothing elsewhere.
		if ( (int) $r['created'] > 0 && empty( $r['errors'] ) ) {
			$ask = Source_CSV::KEY === ( $r['source'] ?? '' )
				? __( 'Did the import go smoothly? A short review on wordpress.org helps other sites find Beacon Events.', 'beacon-events' )
				: sprintf(
					/* translators: %s: the plugin the events came from, e.g. "The Events Calendar" */
					__( 'Did the move go smoothly? A short review on wordpress.org helps other sites leaving %s find Beacon Events.', 'beacon-events' ),
					self::label( (string) ( $r['source'] ?? '' ) )
				);
			echo '<p class="beacon-import-review">' . esc_html( $ask ) . ' <a href="https://wordpress.org/support/plugin/beacon-events/reviews/#new-post" target="_blank" rel="noopener">' . esc_html__( 'Write a review', 'beacon-events' ) . '</a></p>';
		}
		$lines = array_merge( array_map( fn( $e ) => __( 'Error:', 'beacon-events' ) . ' ' . $e, $r['errors'] ), $r['notes'] );
		if ( ! empty( $r['tickets'] ) || ! empty( $r['attendees'] ) ) {
			$lines[] = sprintf( /* translators: 1: ticket count, 2: attendee count */ __( '%1$d events got their tickets and RSVPs; %2$d attendees were brought over.', 'beacon-events' ), (int) ( $r['tickets'] ?? 0 ), (int) ( $r['attendees'] ?? 0 ) ); }
		if ( ! empty( $r['links'] ) ) {
			/* translators: %d: number of events */
			$lines[] = sprintf( _n( '%d event got a "Tickets & info" button from its website or ticket address.', '%d events got a "Tickets & info" button from their website or ticket address.', (int) $r['links'], 'beacon-events' ), (int) $r['links'] ); }
		if ( ! empty( $r['extras'] ) ) {
			/* translators: 1: number of events, 2: field names, e.g. "cost" */
			$lines[] = sprintf( _n( '%1$d event had fields Beacon doesn\'t show (%2$s); they were kept in hidden meta (_beacon_import_extra).', '%1$d events had fields Beacon doesn\'t show (%2$s); they were kept in hidden meta (_beacon_import_extra).', (int) $r['extras'], 'beacon-events' ), (int) $r['extras'], self::extra_names( $r ) ); }
		if ( ! empty( $r['tz_fallbacks'] ) ) {
			$lines[] = sprintf( /* translators: %d: count */ __( '%d events had no usable named timezone; they were set to the site timezone.', 'beacon-events' ), $r['tz_fallbacks'] ); }
		if ( $lines ) {
			echo '<ul style="list-style:disc;margin-left:1.4em;max-width:60em">';
			foreach ( array_slice( $lines, 0, 60 ) as $l ) {
				echo '<li>' . esc_html( $l ) . '</li>'; }
			echo '</ul>'; }
	}

	/* ---------------- batch runner ---------------- */

	private static function state_key(): string {
		return 'beacon_import_' . get_current_user_id();
	}

	public static function fresh_state( string $source ): array {
		return array(
			'source'       => $source,
			'created'      => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'venues'       => 0,
			'organizers'   => 0,
			'categories'   => 0,
			'extras'       => 0,
			'extra_keys'   => array(),
			'links'        => 0,
			'tz_fallbacks' => 0,
			'tickets'      => 0,
			'attendees'    => 0,
			'images'       => 0,
			'dry'          => false,
			'seen'         => array(),
			'processed'    => 0,
			'total'        => null,
			'next'         => 0,
			'file'         => '',
			'errors'       => array(),
			'notes'        => array(),
			'rows'         => array(),
			'last'         => null,
			'started'      => current_time( 'mysql' ),
		);
	}

	public static function handle(): void {
		if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'beacon_import_tec' ) ) {
			$_POST['source'] = 'tec'; // 0.3.0 form
		} else {
			check_admin_referer( 'beacon_import' ); }
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(); }
		$source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'tec';
		$sources = self::sources();
		if ( ! isset( $sources[ $source ] ) ) {
			wp_die( esc_html__( 'Unknown import source.', 'beacon-events' ) ); }
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$state  = $offset ? get_transient( self::state_key() ) : false;
		if ( ! is_array( $state ) || ( $state['source'] ?? '' ) !== $source ) {
			$state  = self::fresh_state( $source );
			$offset = 0;
		}
		if ( 0 === $offset ) {
			$state['dry'] = ! empty( $_POST['dry'] ) && Source_CSV::KEY !== $source; // a dry run reads the other plugin only; CSV uploads always import
		}
		$args = array(
			'post_type' => Events::CPT,
			'page'      => self::PAGE,
		);
		if ( $source === Source_CSV::KEY && $offset === 0 ) {
			$err = Source_CSV::receive_upload( $state );
			if ( $err ) {
				$args['error'] = $err;
				wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
				exit;
			}
		}
		$state = self::run_batch( $state, $offset, self::batch_size() );
		if ( $state['next'] === null ) {
			self::finish( $state );
			delete_transient( self::state_key() );
			$args['done'] = 1;
		} else {
			set_transient( self::state_key(), $state, HOUR_IN_SECONDS );
			$args['offset'] = $state['next'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function export_csv(): void {
		check_admin_referer( 'beacon_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(); }
		Source_CSV::export();
	}

	/** Import up to $limit source items starting at $offset. Sets next=null when finished. */
	public static function run_batch( array $state, int $offset, int $limit ): array {
		$cls = self::sources()[ $state['source'] ];
		if ( $state['total'] === null ) {
			$state['total'] = (int) ( $cls::detect()['total'] ?? 0 ); }
		$ids = $cls::ids( $state, $offset, $limit );
		wp_defer_term_counting( true );
		foreach ( $ids as $id ) {
			$before        = array( (int) $state['skipped'], count( $state['notes'] ), count( $state['errors'] ) );
			$state['last'] = null;
			$n             = null;
			try {
				$n = $cls::map( $id, $state );
				if ( $n && ! empty( $state['dry'] ) ) {
					self::preview_event( $n, $state );
				} elseif ( $n ) {
					self::import_event( $n, $state ); }
			} catch ( \Throwable $t ) {
				$state['errors'][] = '#' . $id . ': ' . $t->getMessage();
			}
			self::report_row( $state, $id, $n, $before );
			++$state['processed'];
		}
		wp_defer_term_counting( false );
		$state['next'] = count( $ids ) < $limit ? null : $offset + $limit;
		return $state;
	}

	public static function finish( array $state ): void {
		$state['finished'] = current_time( 'mysql' );
		update_option(
			self::OPT_REPORT,
			array(
				'source'  => $state['source'],
				'dry'     => ! empty( $state['dry'] ),
				'started' => $state['started'],
				'capped'  => ! empty( $state['rows_capped'] ),
				'rows'    => (array) ( $state['rows'] ?? array() ),
			),
			false
		);
		$state['report_rows'] = count( (array) ( $state['rows'] ?? array() ) );
		unset( $state['seen'], $state['rows'], $state['last'], $state['rows_capped'] );
		$state['errors']   = array_slice( $state['errors'], 0, 50 );
		$state['notes']    = array_slice( $state['notes'], 0, 50 );
		if ( ! empty( $state['file'] ) ) {
			self::untrack_file( $state['file'] );
			$state['file'] = ''; }
		$log = get_option( self::OPT_LOG, array() );
		array_unshift( $log, $state );
		update_option( self::OPT_LOG, array_slice( is_array( $log ) ? $log : array(), 0, 5 ), false );
	}

	/* ---------------- uploaded CSV files ---------------- */

	const OPT_FILES = 'beacon_import_files'; // path => upload time, for CSV imports still running (not autoloaded)

	public static function track_file( string $path ): void {
		$files          = (array) get_option( self::OPT_FILES, array() );
		$files[ $path ] = time();
		update_option( self::OPT_FILES, $files, false );
	}

	/** Deletes the file and forgets it. */
	public static function untrack_file( string $path ): void {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path ); }
		$files = (array) get_option( self::OPT_FILES, array() );
		unset( $files[ $path ] );
		update_option( self::OPT_FILES, $files, false );
	}

	/** An import left unfinished (tab closed mid-batch) never reaches finish(): its CSV goes after a day. */
	public static function sweep_files(): void {
		$files = (array) get_option( self::OPT_FILES, array() );
		if ( ! $files ) {
			return; }
		foreach ( $files as $path => $at ) {
			if ( ! is_string( $path ) || (int) $at > time() - DAY_IN_SECONDS ) {
				continue; }
			if ( file_exists( $path ) ) {
				wp_delete_file( $path ); }
			unset( $files[ $path ] );
		}
		update_option( self::OPT_FILES, $files, false );
	}

	/* ---------------- per-event report (CSV) ---------------- */

	/** The decision for an event that was combined into a repeating one earlier: skipped, pointing at that series. */
	private static function combined_row( string $uid, array $existing ): array {
		return array(
			'result' => 'skip',
			'id'     => $existing ? (int) $existing[0] : Combine::series_for_uid( $uid ),
			'reason' => __( 'Combined into a repeating event earlier, left as it is.', 'beacon-events' ),
		);
	}

	/**
	 * One report row per source event that got a decision: created / updated / skipped (with the reason) / error. Items a
	 * source drops silently (one date of a series that comes over as one repeating event, a deleted post, a plugin's own
	 * sample events) are not events of their own and get no row.
	 */
	private static function report_row( array &$state, $id, ?array $n, array $before ): void {
		[ $skipped, $notes, $errors ] = $before;
		$last = $state['last'] ?? null;
		if ( $last ) {
			$result = (string) $last['result'];
		} elseif ( count( $state['errors'] ) > $errors ) {
			$result = 'error';
		} elseif ( (int) $state['skipped'] > $skipped ) {
			$result = 'skip';
		} else {
			return;
		}
		if ( count( $state['rows'] ?? array() ) >= self::REPORT_CAP ) {
			$state['rows_capped'] = true;
			return;
		}
		$said = array_merge( array_slice( $state['errors'], $errors ), isset( $last['reason'] ) ? array( $last['reason'] ) : array(), array_slice( $state['notes'], $notes ) );
		if ( 'skip' === $result && ! empty( $state['combined_note'] ) ) { // the run-wide "combined" note is said once, the row has its own reason
			$said = array_values( array_diff( $said, array( __( 'Events you combined into repeating events were left as they are.', 'beacon-events' ), __( 'Events you combined into repeating events would be left as they are.', 'beacon-events' ) ) ) );
		}
		$state['rows'][] = array(
			$result,
			(string) ( $n['src_id'] ?? $id ),
			(string) ( $n['title'] ?? '' ),
			(int) ( $last['id'] ?? 0 ),
			(string) ( $n['start'] ?? '' ),
			(string) ( $n['end'] ?? '' ),
			(string) ( $n['rrule'] ?? '' ),
			implode( ' | ', $said ),
		);
	}

	/** The saved report of the latest run, or null. */
	public static function last_report(): ?array {
		$r = get_option( self::OPT_REPORT );
		return is_array( $r ) && isset( $r['rows'] ) ? $r : null;
	}

	/** Writes the report as CSV to an open stream (the download, or a file from `wp beacon import --report`). */
	public static function write_report( array $r, $out ): void {
		$dry    = ! empty( $r['dry'] );
		$labels = array(
			'create' => $dry ? __( 'would create', 'beacon-events' ) : __( 'created', 'beacon-events' ),
			'update' => $dry ? __( 'would update', 'beacon-events' ) : __( 'updated', 'beacon-events' ),
			'skip'   => $dry ? __( 'would skip', 'beacon-events' ) : __( 'skipped', 'beacon-events' ),
			'error'  => __( 'error', 'beacon-events' ),
		);
		fputcsv( $out, array( __( 'Result', 'beacon-events' ), __( 'Source', 'beacon-events' ), __( 'Source ID', 'beacon-events' ), __( 'Title', 'beacon-events' ), __( 'Beacon event ID', 'beacon-events' ), __( 'Start', 'beacon-events' ), __( 'End', 'beacon-events' ), __( 'Repeats (RRULE)', 'beacon-events' ), __( 'Notes', 'beacon-events' ) ) );
		$source = self::label( (string) ( $r['source'] ?? '' ) );
		foreach ( (array) $r['rows'] as $row ) {
			[ $result, $src_id, $title, $post, $start, $end, $rrule, $notes ] = $row;
			$line = array( $labels[ $result ] ?? $result, $source, $src_id, $title, $post ? (string) $post : '', $start, $end, $rrule, $notes );
			// spreadsheet formula injection guard: a leading =, +, -, @ becomes text
			fputcsv( $out, array_map( fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v, $line ) );
		}
		if ( ! empty( $r['capped'] ) ) {
			/* translators: %d: number of rows */
			fputcsv( $out, array( sprintf( __( 'The report lists the first %d events only.', 'beacon-events' ), self::REPORT_CAP ) ) );
		}
	}

	/** File name for the report: source, date of the run, and whether it was a dry run. */
	public static function report_filename( array $r ): string {
		$when = preg_replace( '/[^0-9]+/', '-', substr( (string) ( $r['started'] ?? '' ), 0, 16 ) );
		return 'beacon-import-' . sanitize_key( (string) ( $r['source'] ?? '' ) ) . ( ! empty( $r['dry'] ) ? '-dry-run' : '' ) . '-' . trim( (string) $when, '-' ) . '.csv';
	}

	public static function download_report(): void {
		check_admin_referer( 'beacon_import_report' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(); }
		$r = self::last_report();
		if ( ! $r ) {
			wp_die( esc_html__( 'There is no import report yet. Run an import or a dry run first.', 'beacon-events' ) ); }
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::report_filename( $r ) . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a download
		self::write_report( $r, $out );
		exit;
	}

	/* ---------------- one normalized event ---------------- */

	/**
	 * $n keys: uid (required), src_id, title, content, excerpt, status, author, date, date_gmt, slug, start (Y-m-d H:i),
	 * end, all_day, tz, rrule, exdates[], online, online_url, venue {name,address,src_id}, organizers [{name,src_id}],
	 * categories [{name,slug,description,color,parent{…}|null,src_id}], thumbnail_id, image_url, extra{}, note.
	 */
	public static function import_event( array $n, array &$state ): void {
		$n   = array_merge(
			array(
				'title'      => '',
				'content'    => '',
				'excerpt'    => '',
				'status'     => 'publish',
				'author'     => 0,
				'date'       => '',
				'date_gmt'   => '',
				'slug'       => '',
				'end'        => '',
				'all_day'    => false,
				'tz'         => '',
				'rrule'      => '',
				'exdates'    => array(),
				'online'     => false,
				'online_url' => '',
				'link'       => '',
				'link_label' => '',
				'venue'      => null,
				'organizers' => array(),
				'categories' => array(),
				'thumbnail_id' => 0,
				'image_url'  => '',
				'extra'      => array(),
				'note'       => null,
			),
			$n
		);
		$uid = (string) $n['uid'];
		// phpcs:disable WordPress.DB.SlowDBQuery -- one lookup per imported event, in a one-off migration
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
		// Copies that were combined into one repeating event stay combined: the removed copies are not brought back,
		// and the series keeps its rule instead of being reset to the single date it was imported with.
		if ( ( ! $existing && Combine::series_for_uid( $uid ) ) || ( $existing && get_post_meta( (int) $existing[0], Combine::META, true ) ) ) {
			++$state['skipped'];
			$state['last'] = self::combined_row( $uid, $existing );
			if ( empty( $state['combined_note'] ) ) {
				$state['combined_note'] = true;
				$state['notes'][]       = __( 'Events you combined into repeating events were left as they are.', 'beacon-events' );
			}
			return;
		}
		$data = array(
			'post_type'    => Events::CPT,
			'post_status'  => in_array( $n['status'], array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $n['status'] : 'draft',
			'post_title'   => (string) $n['title'],
			'post_content' => (string) $n['content'],
			'post_excerpt' => (string) $n['excerpt'],
		);
		if ( $n['author'] ) {
			$data['post_author'] = (int) $n['author']; }
		if ( $n['date'] ) {
			$data['post_date']     = $n['date'];
			$data['post_date_gmt'] = $n['date_gmt'] ?: get_gmt_from_date( $n['date'] ); }
		if ( $n['slug'] ) {
			$data['post_name'] = $n['slug']; }
		if ( $existing ) {
			$data['ID'] = (int) $existing[0];
			$id         = wp_update_post( wp_slash( $data ), true );
			++$state['updated'];
		} else {
			$id = wp_insert_post( wp_slash( $data ), true );
			++$state['created'];
		}
		if ( is_wp_error( $id ) ) {
			$state['errors'][] = '#' . $n['src_id'] . ' ' . $n['title'] . ': ' . $id->get_error_message();
			$state['last']     = array( 'result' => 'error', 'id' => 0 );
			return;
		}
		$state['last'] = array( 'result' => $existing ? 'update' : 'create', 'id' => (int) $id );
		update_post_meta( $id, '_beacon_uid', $uid );
		update_post_meta( $id, '_beacon_import', $state['source'] );
		if ( $state['source'] === Source_TEC::KEY ) {
			update_post_meta( $id, '_beacon_tec_id', (int) $n['src_id'] ); } // 0.3.0 redirects and counts
		update_post_meta( $id, Events::META['start'], $n['start'] );
		update_post_meta( $id, Events::META['end'], (string) $n['end'] );
		update_post_meta( $id, Events::META['tz'], self::timezone( (string) $n['tz'], $state ) );
		update_post_meta( $id, Events::META['all_day'], $n['all_day'] ? '1' : '0' );
		update_post_meta( $id, Events::META['rrule'], (string) $n['rrule'] );
		update_post_meta( $id, Events::META['exdates'], implode( ',', array_unique( (array) $n['exdates'] ) ) );
		update_post_meta( $id, Events::META['online'], $n['online'] ? '1' : '0' );
		update_post_meta( $id, Events::META['online_url'], (string) $n['online_url'] );
		[ $link, $label ] = self::link_from( $n );
		update_post_meta( $id, Events::META['link'], $link );
		update_post_meta( $id, Events::META['link_label'], $label );
		if ( $n['thumbnail_id'] ) {
			set_post_thumbnail( $id, (int) $n['thumbnail_id'] );
		} elseif ( $n['image_url'] && ! has_post_thumbnail( $id ) ) {
			self::sideload( (string) $n['image_url'], $id, $state ); }
		$venues = array();
		if ( is_array( $n['venue'] ) && ! empty( $n['venue']['name'] ) ) {
			$t = self::venue_term( $n['venue'], $state );
			if ( $t ) {
				$venues[] = $t; }
		}
		wp_set_object_terms( $id, $venues, 'beacon_venue' );
		$orgs = array();
		foreach ( (array) $n['organizers'] as $o ) {
			$t = self::organizer_term( (array) $o, $state );
			if ( $t ) {
				$orgs[] = $t; }
		}
		wp_set_object_terms( $id, $orgs, 'beacon_organizer' );
		$cats = array();
		foreach ( (array) $n['categories'] as $c ) {
			$t = self::category_term( (array) $c, $state );
			if ( $t ) {
				$cats[] = $t; }
		}
		wp_set_object_terms( $id, $cats, 'beacon_category' );
		if ( ! empty( $n['extra'] ) ) {
			update_post_meta( $id, '_beacon_import_extra', $n['extra'] ); }
		self::count_extras( $n, $state );
		if ( ! empty( $n['series'] ) ) {
			self::remember_series( (string) $n['series'], (int) $id ); }
		if ( $n['note'] ) {
			$state['notes'][] = sprintf( '"%s" (#%s): %s', $n['title'], $n['src_id'], $n['note'] ); }
		if ( $data['post_status'] === 'publish' ) {
			Events::materialize( $id ); }
		/**
		 * After one event is imported (Beacon Events Pro brings tickets and attendees over here).
		 *
		 * @param array $state     The running import state (counters, notes, errors); return it.
		 * @param int   $beacon_id The Beacon event just created or updated.
		 * @param array $n         The normalized source event, including `src_id`.
		 */
		$filtered = apply_filters( 'beacon_import_after_event', $state, (int) $id, $n );
		if ( is_array( $filtered ) ) {
			$state = $filtered; }
	}

	/**
	 * Dry run of one normalized event: everything import_event() would decide — create or update, combined copies left
	 * alone, new venues / organizers / categories, timezone fallbacks, images to download, notes about simplified rules —
	 * without writing anything to the database.
	 */
	public static function preview_event( array $n, array &$state ): void {
		$n   = array_merge(
			array(
				'title'        => '',
				'status'       => 'publish',
				'tz'           => '',
				'venue'        => null,
				'organizers'   => array(),
				'categories'   => array(),
				'thumbnail_id' => 0,
				'image_url'    => '',
				'extra'        => array(),
				'note'         => null,
			),
			$n
		);
		$uid = (string) $n['uid'];
		// phpcs:disable WordPress.DB.SlowDBQuery -- one lookup per event, the same one the real import makes
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
		if ( ( ! $existing && Combine::series_for_uid( $uid ) ) || ( $existing && get_post_meta( (int) $existing[0], Combine::META, true ) ) ) {
			++$state['skipped'];
			$state['last'] = self::combined_row( $uid, $existing );
			if ( empty( $state['combined_note'] ) ) {
				$state['combined_note'] = true;
				$state['notes'][]       = __( 'Events you combined into repeating events would be left as they are.', 'beacon-events' );
			}
			return;
		}
		$existing ? ++$state['updated'] : ++$state['created'];
		$state['last'] = array( 'result' => $existing ? 'update' : 'create', 'id' => $existing ? (int) $existing[0] : 0 );
		self::timezone( (string) $n['tz'], $state ); // counts the fallbacks, writes nothing
		$new = function ( string $kind, string $name, bool $exists ) use ( &$state ): void {
			$k = $kind . "\0" . strtolower( $name );
			if ( '' !== $name && ! $exists && empty( $state['seen'][ $k ] ) ) {
				$state['seen'][ $k ] = true;
				++$state[ $kind ];
			}
		};
		if ( is_array( $n['venue'] ) && ! empty( $n['venue']['name'] ) ) {
			$v = trim( (string) $n['venue']['name'] );
			$new( 'venues', $v, (bool) get_term_by( 'name', $v, 'beacon_venue' ) );
		}
		foreach ( (array) $n['organizers'] as $o ) {
			$o = trim( (string) ( $o['name'] ?? '' ) );
			$new( 'organizers', $o, (bool) get_term_by( 'name', $o, 'beacon_organizer' ) );
		}
		$cat = function ( array $c ) use ( &$cat, $new ): void {
			if ( ! empty( $c['parent'] ) && is_array( $c['parent'] ) ) {
				$cat( $c['parent'] ); }
			$name = trim( (string) ( $c['name'] ?? '' ) );
			$new( 'categories', $name, (bool) term_exists( $name, 'beacon_category' ) );
		};
		foreach ( (array) $n['categories'] as $c ) {
			$cat( (array) $c ); }
		if ( ! $n['thumbnail_id'] && $n['image_url'] && ! ( $existing && has_post_thumbnail( (int) $existing[0] ) ) ) {
			++$state['images']; }
		self::count_extras( $n, $state );
		if ( $n['note'] ) {
			$state['notes'][] = sprintf( '"%s" (#%s): %s', $n['title'], $n['src_id'], $n['note'] ); }
	}

	/* ---------------- shared helpers ---------------- */

	/**
	 * The event's outside link: the one the source gave explicitly, else the URL each source keeps for it (The Events
	 * Calendar's event website, All-in-One's ticket URL, MEC's "more info", EventON's external link, WP Event Manager's
	 * registration link). Visitors used these as the "buy tickets" button, so they must survive a migration.
	 */
	/**
	 * Counts what the summary says about a source event's extra fields: whether it got a "Tickets & info" button, and
	 * which fields Beacon keeps only in hidden meta (the address that became the button is not one of them).
	 */
	private static function count_extras( array $n, array &$state ): void {
		[ $link ] = self::link_from( $n );
		if ( $link ) {
			$state['links'] = (int) ( $state['links'] ?? 0 ) + 1; }
		$left = array();
		foreach ( (array) ( $n['extra'] ?? array() ) as $k => $v ) {
			if ( is_scalar( $v ) ? '' === trim( (string) $v ) : empty( $v ) ) {
				continue; }
			if ( $link && is_string( $v ) && esc_url_raw( trim( $v ) ) === $link ) {
				continue; }
			if ( is_string( $v ) && self::is_self_link( $v, (string) ( $n['slug'] ?? '' ), (int) ( $n['src_id'] ?? 0 ) ) ) {
				continue; } // the event's own old address: nothing was lost
			$left[] = str_replace( '_', ' ', (string) $k );
		}
		if ( $left ) {
			++$state['extras'];
			$state['extra_keys'] = array_slice( array_values( array_unique( array_merge( (array) ( $state['extra_keys'] ?? array() ), $left ) ) ), 0, 8 );
		}
	}

	const OPT_SERIES = 'beacon_tec_series'; // Events Calendar Pro series slug => the Beacon event it became (not autoloaded; read on /series/ 404s only)

	/** Remembers the first imported event of an Events Calendar Pro series, so the old /series/<slug>/ page can redirect to it. */
	private static function remember_series( string $slug, int $event_id ): void {
		$slug = sanitize_title( $slug );
		$map  = (array) get_option( self::OPT_SERIES, array() );
		if ( '' !== $slug && $event_id && empty( $map[ $slug ] ) ) {
			$map[ $slug ] = $event_id;
			update_option( self::OPT_SERIES, $map, false );
		}
	}

	/** The leftover field names for the summary; runs saved before 0.28.8 have none recorded. */
	private static function extra_names( array $r ): string {
		$k = (array) ( $r['extra_keys'] ?? array() );
		return $k ? implode( ', ', $k ) : __( 'cost and similar', 'beacon-events' );
	}

	private static function link_from( array $n ): array {
		$slug = (string) ( $n['slug'] ?? '' );
		$src  = (int) ( $n['src_id'] ?? 0 );
		$ok   = function ( $u ) use ( $slug, $src ) {
			if ( ! is_string( $u ) || ! preg_match( '#^https?://#i', trim( $u ) ) || self::is_self_link( $u, $slug, $src ) ) {
				return ''; }
			return esc_url_raw( trim( $u ) );
		};
		if ( $ok( $n['link'] ?? '' ) ) {
			return array( $ok( $n['link'] ), sanitize_text_field( (string) ( $n['link_label'] ?? '' ) ) ); }
		$x = (array) ( $n['extra'] ?? array() );
		foreach ( array(
			'ticket_url'   => __( 'Tickets', 'beacon-events' ),
			'url'          => '',
			'more_info'    => '',
			'link'         => '',
			'registration' => __( 'Register', 'beacon-events' ),
		) as $k => $label ) {
			if ( $ok( $x[ $k ] ?? '' ) ) {
				return array( $ok( $x[ $k ] ), $label ); }
		}
		return array( '', '' );
	}

	/**
	 * True when $url is this site's own address for the event: EventON's "Open Event Page" setting saves the event's
	 * permalink as its link, and other plugins' website fields sometimes hold it too. As a "Tickets & info" button it
	 * only sends visitors back to the page they are on (On Rotation, 2026-10-29: 307 of 366 imported events).
	 * Same host (www or not) and either the slug as the last path part (a date, number or "all" after it allowed, for
	 * per-date pages), ?p= / ?page_id= equal to the source id, or a query value equal to the slug (?ajde_events=slug).
	 */
	public static function is_self_link( string $url, string $slug, int $src_id = 0 ): bool {
		$u    = wp_parse_url( trim( $url ) );
		$home = wp_parse_url( home_url( '/' ) );
		$host = fn( $a ) => is_array( $a ) ? preg_replace( '/^www\./', '', strtolower( (string) ( $a['host'] ?? '' ) ) ) : '';
		if ( '' === $host( $u ) || $host( $u ) !== $host( $home ) ) {
			return false; }
		$slug  = strtolower( $slug );
		$parts = array_values( array_filter( explode( '/', (string) ( $u['path'] ?? '' ) ), 'strlen' ) );
		if ( count( $parts ) > 1 && preg_match( '/^(\d{4}-\d{2}-\d{2}|\d+|all)$/', (string) end( $parts ) ) ) {
			array_pop( $parts ); }
		if ( '' !== $slug && $parts && strtolower( rawurldecode( (string) end( $parts ) ) ) === $slug ) {
			return true; }
		parse_str( (string) ( $u['query'] ?? '' ), $q );
		foreach ( $q as $k => $v ) {
			if ( ! is_string( $v ) ) {
				continue; }
			if ( $src_id && in_array( $k, array( 'p', 'page_id', 'post' ), true ) && (int) $v === $src_id ) {
				return true; }
			if ( '' !== $slug && strtolower( $v ) === $slug ) {
				return true; }
		}
		return false;
	}

	/**
	 * One-time clean-up on the update to 0.29.4: imported events whose "Tickets & info" link is the event's own old
	 * address lose it (link and label). Only events an importer made (their uid starts with a source key), so a link
	 * someone typed in by hand is never touched. Returns how many were cleared.
	 */
	public static function drop_self_links(): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT p.ID, p.post_name, l.meta_value AS link, u.meta_value AS uid FROM %i p JOIN %i l ON l.post_id = p.ID AND l.meta_key = %s JOIN %i u ON u.post_id = p.ID AND u.meta_key = %s WHERE p.post_type = %s AND l.meta_value <> %s', $wpdb->posts, $wpdb->postmeta, Events::META['link'], $wpdb->postmeta, '_beacon_uid', Events::CPT, '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$keys = implode( '|', array_map( 'preg_quote', array_keys( self::sources() ) ) );
		$n    = 0;
		foreach ( (array) $rows as $r ) {
			if ( ! preg_match( '/^(' . $keys . ')-(.+)$/', (string) $r->uid, $m ) ) {
				continue; }
			$src = ctype_digit( $m[2] ) ? (int) $m[2] : 0;
			if ( self::is_self_link( (string) $r->link, (string) $r->post_name, $src ) ) {
				update_post_meta( (int) $r->ID, Events::META['link'], '' );
				update_post_meta( (int) $r->ID, Events::META['link_label'], '' );
				++$n;
			}
		}
		return $n;
	}

	/** "2026-10-06 18:00:00" → "2026-10-06 18:00"; anything else → "". */
	public static function minute( string $s ): string {
		return preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $s, $m ) ? "$m[1] $m[2]" : '';
	}

	public static function timezone( string $tz, array &$state ): string {
		if ( $tz && in_array( $tz, timezone_identifiers_list(), true ) ) {
			return $tz; }
		if ( $tz ) {
			++$state['tz_fallbacks']; } // "UTC+2"-style offsets: Beacon expands rules in a named zone so DST is right
		return wp_timezone_string();
	}

	/**
	 * A location kept as one free-text box (Sugar Calendar, Eventin). "Main Library, 12 Main St, Springfield" or a first line with the
	 * address below become name + address; text with no street number is a name alone.
	 */
	public static function place_from_text( string $loc ): array {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $loc ) ), 'strlen' ) );
		if ( count( $lines ) > 1 ) {
			return array(
				'name'    => $lines[0],
				'address' => implode( ', ', array_slice( $lines, 1 ) ),
			); }
		$parts = array_map( 'trim', explode( ',', $loc, 2 ) );
		if ( 2 === count( $parts ) && ! preg_match( '/\d/', $parts[0] ) && preg_match( '/\d/', $parts[1] ) ) {
			return array(
				'name'    => $parts[0],
				'address' => $parts[1],
			); }
		return array(
			'name'    => $loc,
			'address' => preg_match( '/^\d+\s+\S/', $loc ) ? $loc : '',
		);
	}

	/** Keep only what Beacon expands; say so when something was dropped. */
	public static function normalize_rrule( string $rrule, ?string &$note ): string {
		$r    = Recurrence::parse( $rrule );
		$keys = array();
		foreach ( explode( ';', strtoupper( $rrule ) ) as $p ) {
			$keys[] = strtok( $p, '=' ); }
		$extra = array_diff( array_filter( $keys ), array( 'FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'COUNT', 'UNTIL', 'WKST' ) );
		if ( ! $r['FREQ'] ) {
			$note = __( 'recurrence rule could not be read; imported as a single event.', 'beacon-events' );
			return '';
		}
		$parts = array( 'FREQ=' . $r['FREQ'], 'INTERVAL=' . $r['INTERVAL'] );
		if ( $r['FREQ'] === 'WEEKLY' && $r['BYDAY'] ) {
			$days = array_values( array_intersect( $r['BYDAY'], array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ) ) );
			if ( $days ) {
				$parts[] = 'BYDAY=' . implode( ',', $days ); }
		} elseif ( $r['FREQ'] === 'MONTHLY' && $r['BYDAY'] ) {
			// "2TU,4TU" (second and fourth Tuesday) is kept whole since 0.20.0; only entries Beacon can't expand go
			$pos = array_values( array_filter( $r['BYDAY'], fn( $d ) => (bool) preg_match( '/^(-1|[1-5])(SU|MO|TU|WE|TH|FR|SA)$/', $d ) ) );
			if ( $pos ) {
				$parts[] = 'BYDAY=' . implode( ',', $pos ); }
			if ( count( $pos ) !== count( $r['BYDAY'] ) ) {
				$extra[] = 'BYDAY'; }
		} elseif ( $r['BYMONTHDAY'] ) {
			$parts[] = 'BYMONTHDAY=' . $r['BYMONTHDAY']; }
		if ( $r['COUNT'] ) {
			$parts[] = 'COUNT=' . $r['COUNT']; } elseif ( $r['UNTIL'] ) {
			$parts[] = 'UNTIL=' . str_replace( '-', '', $r['UNTIL'] ); }
		if ( $extra ) {
			$note = sprintf( /* translators: %s: rule parts */ __( 'recurrence rule was simplified (%s not supported); check its dates.', 'beacon-events' ), implode( ', ', array_unique( $extra ) ) ); }
		return implode( ';', $parts );
	}

	/** Term ids of one taxonomy attached to a source post, read directly (the taxonomy may not be registered). */
	public static function source_terms( int $post_id, string $taxonomy ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the source taxonomy may be unregistered, so the API cannot read it
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s", $post_id, $taxonomy ) ) );
	}

	/** A source term with its parents, as nested {name,slug,description,parent,src_id}, read directly. */
	public static function term_chain( int $term_id, string $taxonomy, int $depth = 0 ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the source taxonomy may be unregistered
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.name, t.slug, tt.parent, tt.description FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.term_id = %d", $taxonomy, $term_id ), ARRAY_A );
		if ( ! $row ) {
			return null; }
		return array(
			'name'        => $row['name'],
			'slug'        => $row['slug'],
			'description' => (string) $row['description'],
			'parent'      => ( (int) $row['parent'] && $depth < 10 ) ? self::term_chain( (int) $row['parent'], $taxonomy, $depth + 1 ) : null,
			'src_id'      => $term_id,
		);
	}

	private static function venue_term( array $v, array &$state ): int {
		$name = trim( (string) ( $v['name'] ?? '' ) );
		if ( $name === '' ) {
			return 0; }
		$term = get_term_by( 'name', $name, 'beacon_venue' );
		if ( $term ) {
			$term_id = (int) $term->term_id;
		} else {
			$r = wp_insert_term( $name, 'beacon_venue' );
			if ( is_wp_error( $r ) ) {
				$state['errors'][] = 'venue "' . $name . '": ' . $r->get_error_message();
				return 0;
			}
			$term_id = (int) $r['term_id'];
			++$state['venues'];
		}
		$addr = trim( (string) ( $v['address'] ?? '' ) );
		if ( $addr && ! get_term_meta( $term_id, 'beacon_address', true ) ) {
			update_term_meta( $term_id, 'beacon_address', sanitize_text_field( $addr ) ); }
		if ( ! empty( $v['src_id'] ) ) {
			update_term_meta( $term_id, '_beacon_' . $state['source'] . '_id', (int) $v['src_id'] ); }
		return $term_id;
	}

	private static function organizer_term( array $o, array &$state ): int {
		$name = trim( (string) ( $o['name'] ?? '' ) );
		if ( $name === '' ) {
			return 0; }
		$term = get_term_by( 'name', $name, 'beacon_organizer' );
		if ( $term ) {
			$term_id = (int) $term->term_id;
		} else {
			$r = wp_insert_term( $name, 'beacon_organizer' );
			if ( is_wp_error( $r ) ) {
				$state['errors'][] = 'organizer "' . $name . '": ' . $r->get_error_message();
				return 0;
			}
			$term_id = (int) $r['term_id'];
			++$state['organizers'];
		}
		if ( ! empty( $o['src_id'] ) ) {
			update_term_meta( $term_id, '_beacon_' . $state['source'] . '_id', (int) $o['src_id'] ); }
		return $term_id;
	}

	/** Nested source category → beacon_category term, parents first. Same-name terms under the same parent are reused. */
	private static function category_term( array $c, array &$state ): int {
		$name = trim( (string) ( $c['name'] ?? '' ) );
		if ( $name === '' ) {
			return 0; }
		$parent   = ! empty( $c['parent'] ) && is_array( $c['parent'] ) ? self::category_term( $c['parent'], $state ) : 0;
		$existing = term_exists( $name, 'beacon_category', $parent ?: null );
		if ( $existing ) {
			$id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		} else {
			$r = wp_insert_term(
				$name,
				'beacon_category',
				array(
					'parent'      => $parent,
					'description' => (string) ( $c['description'] ?? '' ),
				)
			);
			if ( is_wp_error( $r ) ) {
				$state['errors'][] = 'category "' . $name . '": ' . $r->get_error_message();
				return 0;
			}
			$id = (int) $r['term_id'];
			++$state['categories'];
		}
		$hex = ! empty( $c['color'] ) ? sanitize_hex_color( (string) $c['color'] ) : '';
		if ( $hex && ! get_term_meta( $id, 'beacon_color', true ) ) {
			update_term_meta( $id, 'beacon_color', $hex ); }
		if ( ! empty( $c['slug'] ) ) {
			update_term_meta( $id, '_beacon_' . $state['source'] . '_slug', (string) $c['slug'] );
			if ( $state['source'] === Source_TEC::KEY ) {
				update_term_meta( $id, '_beacon_tec_slug', (string) $c['slug'] ); } // 0.3.0 redirects
		}
		return $id;
	}

	/** Fetch an image URL into the media library as the event's featured image (CSV rows). */
	private static function sideload( string $url, int $post_id, array &$state ): void {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php'; }
		$att = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $att ) ) {
			$state['notes'][] = sprintf( /* translators: 1: url, 2: error */ __( 'Image %1$s could not be fetched: %2$s', 'beacon-events' ), $url, $att->get_error_message() );
			return; }
		set_post_thumbnail( $post_id, (int) $att );
	}

	/* ---------------- old links ---------------- */

	/**
	 * Once the other plugin is deactivated its URLs 404; send the ones we can map to ours so bookmarks and search
	 * results survive. TEC: /event/, /venue/, /organizer/ already match; categories and views are redirected here.
	 * Events Manager: /events/<slug> → /event/<slug>, /locations/<slug> → /venue/<slug>, /events/categories/<slug>.
	 * All-in-One Event Calendar: /event/<slug> already matches; /events_categories/<slug>, calendar-view URLs
	 * (…/action~month/…) and events placed under the calendar page are redirected.
	 * Modern Events Calendar: /events/<slug> (its default single slug) → /event/<slug> via the Events Manager rule
	 * (guarded by EM being absent), /mec-category/<slug> → our category; custom slugs from MEC's settings are recorded at
	 * import (option beacon_mec_links) and used instead.
	 * WP Event Manager: /event/ and /event-category/ already match; /event-type/<slug> → the category it became.
	 * EventON: /events/<slug> via the EM rule; /event-location/, /event-organizer/ → venue / organizer; /event-type(_N)/.
	 * WP Events Manager (ThimPress): /events/<slug> via the EM rule; /tp-event-category/ and /tp-event-type/ → category;
	 * /tp-event-tag/ → the events archive (tags are not imported).
	 */
	public static function legacy_redirects(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return; }
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- path only, matched against fixed patterns below
		// WordPress in a subfolder (example.org/site/): match the part after the site's own path
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( $home !== '' && strpos( $path . '/', $home . '/' ) === 0 ) {
			$path = trim( (string) substr( $path, strlen( $home ) ), '/' ); }
		$to = '';
		// Not only on a 404: Events Manager leaves placeholder pages named "events" / "locations" behind, which make
		// WordPress answer these URLs with something other than a 404. A redirect happens only when the target exists.
		if ( preg_match( '#^events/categor(?:y|ies)/([^/]+)#', $path, $m ) && ! class_exists( 'Tribe__Events__Main' ) && ! class_exists( 'EM_Event' ) && ! defined( 'EVENT_ORGANISER_VER' ) ) {
			$to = self::term_link_by_slug( 'beacon_category', sanitize_title( $m[1] ) );
		} elseif ( ! function_exists( 'mc_get_version' ) && ( $to = self::mc_redirect( $path ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found,Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			// My Calendar: ?mc_id=<date> links on any page, and on a 404 its /mc-events/, /mc-locations/, /mc-event-category/ pages
		} elseif ( ! defined( 'EVENTPRIME_VERSION' ) && ( $to = self::ep_redirect( $path ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found,Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			// EventPrime: ?event= / ?venue= / ?organizer= / ?event_type= links on its calendar pages, and a further date's own
			// /event/<slug>/ (its SEO addresses otherwise already match Beacon's /event/, /venue/, /organizer/)
		} elseif ( ! defined( 'EVENT_ORGANISER_VER' ) && is_404() && is_array( $eo = get_option( Source_EO::OPT_LINKS ) ) && ( $to = self::eo_redirect( $path, $eo ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found,Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			// Event Organiser: /events/event/<slug>/, /events/venue/<slug>/, its categories, tags and date archives (its URL
			// bases as recorded at import); looked up only on a 404 after an Event Organiser import, so other sites pay nothing
		} elseif ( preg_match( '#^event/([^/]+)/?$#', $path, $m ) && is_404() && ( $series = Combine::series_for_slug( sanitize_title( $m[1] ) ) ) ) {
			// a single copy that was combined into a repeating event; links from emails keep their query (a cancel or
			// payment-return link carries its token there)
			$to = (string) get_permalink( $series );
			if ( $_GET ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- passed on unchanged to the same site
				$to = add_query_arg( urlencode_deep( map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ) ), $to ); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( preg_match( '#^event/([^/]+)/(?:\d{4}-\d{2}-\d{2}|all)/?$#', $path, $m ) && ! class_exists( 'Tribe__Events__Main' ) && is_404() ) {
			// Events Calendar Pro gives every date of a series its own URL (/event/<slug>/2026-10-06/) and an "all dates"
			// page (/event/<slug>/all/); Beacon has one page per event
			$p = get_page_by_path( sanitize_title( $m[1] ), OBJECT, Events::CPT );
			if ( $p && $p->post_status === 'publish' ) {
				$to = (string) get_permalink( $p ); }
		} elseif ( ( preg_match( '#^events/(month|list|today|day|week|photo|map|summary|upcoming|past|tag)(/|$)#', $path ) || preg_match( '#^events/\d{4}-\d{2}(-\d{2})?/?$#', $path ) ) && ! class_exists( 'Tribe__Events__Main' ) && is_404() ) {
			// The Events Calendar's views, its /events/2026-10-06/ day URLs and tag pages → the calendar
			$to = self::calendar_url();
		} elseif ( ! function_exists( 'sugar_calendar' ) && preg_match( '#^events/calendar/([^/]+)#', $path, $m ) ) {
			// Sugar Calendar's calendars were imported as categories
			$to = self::term_link_by_slug( 'beacon_category', sanitize_title( $m[1] ) );
		} elseif ( ! class_exists( 'Wpeventin' ) && preg_match( '#^etn/(?:[^/]+/)?([^/]+)/?$#', $path, $m ) ) {
			// Eventin: /etn/<slug>/, and one date of a series at /etn/<series>/<date-slug>/ → the series when it came
			// over as one repeating event, else that date's own event
			$slug = sanitize_title( $m[1] );
			$p    = get_page_by_path( $slug, OBJECT, Events::CPT );
			if ( ! $p ) {
				$src = get_posts( array( 'name' => $slug, 'post_type' => Source_ETN::CPT, 'post_status' => 'any', 'numberposts' => 1 ) );
				$par = $src && $src[0]->post_parent ? get_post( (int) $src[0]->post_parent ) : null;
				$p   = $par ? get_page_by_path( $par->post_name, OBJECT, Events::CPT ) : null;
			}
			if ( $p && $p->post_status === 'publish' ) {
				$to = (string) get_permalink( $p ); }
		} elseif ( ! class_exists( 'Wpeventin' ) && preg_match( '#^etn_(category|tags)/([^/]+)#', $path, $m ) ) {
			$to = 'category' === $m[1] ? self::term_link_by_slug( 'beacon_category', sanitize_title( $m[2] ) ) : self::calendar_url();
		} elseif ( ! class_exists( 'Wpeventin' ) && is_404() && preg_match( '#^etn/?$#', $path ) ) {
			$to = self::calendar_url();
		} elseif ( preg_match( '#^series/([^/]+)/?$#', $path, $m ) && ! class_exists( 'Tribe__Events__Pro__Main' ) && is_404() && ( $series_map = (array) get_option( self::OPT_SERIES, array() ) ) ) {
			// Events Calendar Pro's series pages: the event that series became, else the calendar
			$series_id = (int) ( $series_map[ sanitize_title( $m[1] ) ] ?? 0 );
			$to        = $series_id && 'publish' === get_post_status( $series_id ) ? get_permalink( $series_id ) : self::calendar_url();
		} elseif ( preg_match( '#^locations/([^/]+)/?$#', $path, $m ) && ! class_exists( 'EM_Location' ) ) {
			$to = self::term_link_by_slug( 'beacon_venue', sanitize_title( $m[1] ) );
		} elseif ( preg_match( '#^events/([^/]+)/?$#', $path, $m ) && ! in_array( $m[1], array( 'page', 'feed' ), true ) && ! class_exists( 'EM_Event' ) && ! is_singular( array( 'mec-events', 'ajde_events', 'tp_event', 'sc_event' ) ) ) {
			// (MEC, EventON, WP Events Manager and Sugar Calendar use /events/<slug> too: leave their pages alone while they are still active)
			$p = get_page_by_path( sanitize_title( $m[1] ), OBJECT, Events::CPT );
			if ( $p && $p->post_status === 'publish' ) {
				$to = (string) get_permalink( $p ); }
		} elseif ( ! class_exists( 'MEC' ) && preg_match( '#^mec-category/([^/]+)#', $path, $m ) ) {
			$to = self::term_link_by_slug( 'beacon_category', sanitize_title( $m[1] ) );
		} elseif ( ! class_exists( 'MEC' ) && is_404() && ( $mec = self::mec_links() ) && preg_match( '#^' . preg_quote( $mec['category'], '#' ) . '/([^/]+)#', $path, $m ) ) {
			// custom slugs from MEC's settings, recorded at import; looked up only on a 404 so normal pages pay nothing
			$to = self::term_link_by_slug( 'beacon_category', sanitize_title( $m[1] ) );
		} elseif ( ! class_exists( 'MEC' ) && is_404() && ( $mec = self::mec_links() ) && 'events' !== $mec['single'] && preg_match( '#^' . preg_quote( $mec['single'], '#' ) . '/([^/]+)/?$#', $path, $m ) ) {
			$p = get_page_by_path( sanitize_title( $m[1] ), OBJECT, Events::CPT );
			if ( $p && $p->post_status === 'publish' ) {
				$to = (string) get_permalink( $p ); }
		} elseif ( ! class_exists( 'WP_Event_Manager' ) && ! class_exists( 'EventON' ) && ! defined( 'EVENTPRIME_VERSION' ) && preg_match( '#^event-type(?:_\d+)?/([^/]+)#', $path, $m ) ) {
			// WP Event Manager / EventON / EventPrime event types were imported as categories
			$to = self::term_link_by_slug( 'beacon_category', sanitize_title( $m[1] ) );
		} elseif ( ! class_exists( 'EventON' ) && preg_match( '#^event-(location|organizer)/([^/]+)#', $path, $m ) ) {
			$to = self::term_link_by_slug( 'location' === $m[1] ? 'beacon_venue' : 'beacon_organizer', sanitize_title( $m[2] ) );
		} elseif ( ! defined( 'WPEMS_MAIN_FILE' ) && preg_match( '#^tp-event-(category|type)/([^/]+)#', $path, $m ) ) {
			// WP Events Manager (ThimPress): categories and event types were imported as categories
			$to = self::term_link_by_slug( 'beacon_category', sanitize_title( $m[2] ) );
		} elseif ( ! defined( 'WPEMS_MAIN_FILE' ) && is_404() && preg_match( '#^tp-event-tag/#', $path ) ) {
			$to = (string) get_post_type_archive_link( Events::CPT );
		} elseif ( ! defined( 'AI1EC_POST_TYPE' ) && preg_match( '#^events_categories/([^/]+)#', $path, $m ) ) {
			$to = self::term_link_by_slug( 'beacon_category', sanitize_title( $m[1] ) );
		} elseif ( ! defined( 'AI1EC_POST_TYPE' ) && is_404() && preg_match( '#^events_tags/#', $path ) ) {
			$to = (string) get_post_type_archive_link( Events::CPT );
		} elseif ( ! defined( 'AI1EC_POST_TYPE' ) && is_404() && preg_match( '#^(.*?)/?[a-z_]+~[^/]*#', $path, $m ) ) {
			// All-in-One Event Calendar view URLs: /calendar/action~month/exact_date~1-10-2026/ → the page itself
			$page = $m[1] !== '' ? get_page_by_path( $m[1] ) : null;
			$to   = $page ? (string) get_permalink( $page ) : (string) get_post_type_archive_link( Events::CPT );
		} elseif ( ! defined( 'AI1EC_POST_TYPE' ) && is_404() && get_option( Source_AI1EC::OPT_LINKS ) && preg_match( '#([^/]+)/?$#', $path, $m ) ) {
			// its single events can live under the calendar page (/calendar/<slug>/) instead of /event/<slug>/
			$p = get_page_by_path( sanitize_title( $m[1] ), OBJECT, Events::CPT );
			if ( $p && $p->post_status === 'publish' && get_post_meta( $p->ID, '_beacon_import', true ) === Source_AI1EC::KEY ) {
				$to = (string) get_permalink( $p ); }
		}
		if ( $to ) {
			wp_safe_redirect( $to, 301 );
			exit;
		}
	}

	/** Where an old My Calendar link goes after a My Calendar import; '' when it isn't one (or nothing matches). */
	private static function mc_redirect( string $path ): string {
		$raw = isset( $_GET['mc_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mc_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup of where an old link now lives
		$occ = '' !== $raw && ctype_digit( $raw ) ? (int) $raw : 0;
		if ( ! $occ && ! is_404() ) {
			return ''; }
		$bases = get_option( Source_MC::OPT_LINKS );
		if ( ! is_array( $bases ) ) {
			return ''; }
		if ( $occ ) {
			$src   = Source_MC::event_of_occurrence( $occ );
			$found = $src ? get_posts( array( 'post_type' => Events::CPT, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_beacon_uid', 'meta_value' => Source_MC::KEY . '-' . $src ) ) : array(); // phpcs:ignore WordPress.DB.SlowDBQuery -- only for an old My Calendar link
			return $found ? (string) get_permalink( (int) $found[0] ) : '';
		}
		$q = fn( string $k ) => preg_quote( trim( (string) ( $bases[ $k ] ?? '' ), '/' ), '#' );
		if ( '' !== $q( 'event' ) && preg_match( '#^' . $q( 'event' ) . '/([^/]+)/?$#', $path, $m ) ) {
			$p = get_page_by_path( sanitize_title( $m[1] ), OBJECT, Events::CPT );
			return $p && 'publish' === $p->post_status ? (string) get_permalink( $p ) : '';
		}
		if ( '' !== $q( 'location' ) && preg_match( '#^' . $q( 'location' ) . '/([^/]+)#', $path, $m ) ) {
			return self::term_link_by_slug( 'beacon_venue', sanitize_title( $m[1] ) ); }
		if ( preg_match( '#^mc-event-category/([^/]+)#', $path, $m ) ) {
			return self::term_link_by_slug( 'beacon_category', sanitize_title( $m[1] ) ); }
		return '';
	}

	/** Where an old EventPrime link goes after an EventPrime import; '' when it isn't one (or nothing matches). */
	private static function ep_redirect( string $path ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup of where an old link now lives
		$q     = array_intersect_key( wp_unslash( $_GET ), array_flip( array( 'event', 'venue', 'organizer', 'event_type' ) ) );
		$child = is_404() && preg_match( '#^event/([^/]+)/?$#', $path, $m ) ? sanitize_title( $m[1] ) : '';
		if ( ( ! $q && '' === $child ) || ! get_option( Source_EP::OPT_LINKS ) ) {
			return ''; }
		$event = function ( int $src ) {
			$found = get_posts( array( 'post_type' => Events::CPT, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_beacon_uid', 'meta_value' => Source_EP::KEY . '-' . Source_EP::parent_of( $src ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- only for an old EventPrime link
			return $found ? (string) get_permalink( (int) $found[0] ) : '';
		};
		$term  = function ( string $tax, int $src ) {
			$found = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 1, 'meta_key' => '_beacon_' . Source_EP::KEY . '_id', 'meta_value' => $src ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- only for an old EventPrime link
			$link  = $found && ! is_wp_error( $found ) ? get_term_link( $found[0] ) : '';
			return is_string( $link ) ? $link : '';
		};
		foreach ( $q as $k => $v ) {
			if ( ! is_string( $v ) || ! ctype_digit( $v ) ) {
				continue; }
			if ( 'event' === $k ) {
				$to = $event( (int) $v );
			} elseif ( 'venue' === $k ) {
				$to = $term( 'beacon_venue', (int) $v );
			} elseif ( 'organizer' === $k ) {
				$to = $term( 'beacon_organizer', (int) $v );
			} else {
				$c  = self::term_chain( (int) $v, 'em_event_type' );
				$to = $c ? self::term_link_by_slug( 'beacon_category', sanitize_title( $c['slug'] ) ) : '';
			}
			if ( $to ) {
				return $to; }
		}
		if ( '' !== $child ) {
			$src = get_posts( array( 'name' => $child, 'post_type' => Source_EP::CPT, 'post_status' => 'any', 'numberposts' => 1 ) );
			if ( $src && $src[0]->post_parent ) {
				return $event( (int) $src[0]->ID ); }
		}
		return '';
	}

	/** Where an old Event Organiser address goes, given its URL bases; '' when nothing matches. */
	private static function eo_redirect( string $path, array $bases ): string {
		$q = fn( string $k ) => preg_quote( trim( (string) ( $bases[ $k ] ?? '' ), '/' ), '#' );
		if ( '' !== $q( 'event' ) && preg_match( '#^' . $q( 'event' ) . '/([^/]+)/?$#', $path, $m ) && ! in_array( $m[1], array( 'on', 'page', 'feed' ), true ) ) {
			$p = get_page_by_path( sanitize_title( $m[1] ), OBJECT, Events::CPT );
			return $p && 'publish' === $p->post_status ? (string) get_permalink( $p ) : '';
		}
		if ( '' !== $q( 'venue' ) && preg_match( '#^' . $q( 'venue' ) . '/([^/]+)#', $path, $m ) ) {
			return self::term_link_by_slug( 'beacon_venue', sanitize_title( $m[1] ) ); }
		if ( '' !== $q( 'category' ) && preg_match( '#^' . $q( 'category' ) . '/([^/]+)#', $path, $m ) ) {
			return self::term_link_by_slug( 'beacon_category', sanitize_title( $m[1] ) ); }
		if ( ( '' !== $q( 'event' ) && preg_match( '#^' . $q( 'event' ) . '(?:/on/.*|/page/\d+)?/?$#', $path ) ) || ( '' !== $q( 'tag' ) && preg_match( '#^' . $q( 'tag' ) . '/#', $path ) ) ) {
			return self::calendar_url(); } // its events archive, day/month archives and tag pages
		return '';
	}

	/** The calendar page made from the Events screen, when there is one; else the events archive. */
	private static function calendar_url(): string {
		$page = (int) get_option( 'beacon_calendar_page', 0 );
		if ( $page && get_post_status( $page ) === 'publish' ) {
			return (string) get_permalink( $page ); }
		return (string) get_post_type_archive_link( Events::CPT );
	}

	/** MEC's URL slugs as recorded at import (null when nothing was imported from MEC). */
	private static function mec_links(): ?array {
		$l = get_option( Source_MEC::OPT_LINKS );
		return ( is_array( $l ) && ! empty( $l['single'] ) && ! empty( $l['category'] ) ) ? $l : null;
	}

	private static function term_link_by_slug( string $taxonomy, string $slug ): string {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			foreach ( array( '_beacon_tec_slug', '_beacon_em_slug', '_beacon_ai1ec_slug', '_beacon_mec_slug', '_beacon_wpem_slug', '_beacon_eventon_slug', '_beacon_wpems_slug', '_beacon_sugarcalendar_slug', '_beacon_eventin_slug', '_beacon_eventorganiser_slug', '_beacon_eventprime_slug', '_beacon_mycalendar_slug', '_beacon_csv_slug' ) as $key ) {
				$found = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only on a 404 of an old URL
						'meta_value' => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'number'     => 1,
					)
				);
				if ( is_array( $found ) && $found ) {
					$term = $found[0];
					break; }
			}
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return ''; }
		$link = get_term_link( $term );
		return is_wp_error( $link ) ? '' : (string) $link;
	}
}
