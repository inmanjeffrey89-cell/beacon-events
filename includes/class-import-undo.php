<?php
/**
 * Undo an import: removes the events an import from another plugin created, and the venues, organizers and categories
 * it created that are left empty. The other plugin's own data was never changed by the import, so turning that plugin
 * back on brings the old calendar back as it was. Events with bookings made in Beacon are kept (bookings that came over
 * with the import are deleted with their event); events changed in Beacon after the import are kept unless asked.
 */
namespace Beacon;

defined( 'ABSPATH' ) || exit;

final class Import_Undo {

	const TERM_META = '_beacon_import_created'; // on a venue / organizer / category the import created: the source key
	const POST_META = '_beacon_imported_at';    // on an imported event: when the import last wrote it (Unix time)
	const BUDGET    = 15;                        // seconds of deleting per request; the page continues by itself

	public static function init(): void {
		add_action( 'admin_post_beacon_import_undo', array( __CLASS__, 'handle' ) );
	}

	/** Beacon events whose `_beacon_import` is this source, any status (trash included). */
	public static function event_ids( string $key ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin action, one indexed lookup
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_beacon_import' WHERE p.post_type = %s AND m.meta_value = %s ORDER BY p.ID", Events::CPT, $key ) ) );
	}

	/**
	 * Beacon Events Pro's bookings table: [table, has the `source` column]. '' when Pro was never installed. Rows Pro's
	 * importer brought over from the old plugin carry a `source` ("tec-rsvp:12" …); bookings made in Beacon have none.
	 */
	private static function attendees(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'beacon_attendees';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Pro's own table, probed once per undo step
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array( '', false ); }
		$source = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'source' ) );
		// phpcs:enable
		return array( $table, $source );
	}

	/**
	 * Event IDs with at least one booking or RSVP made in Beacon (empty when Pro was never installed). Bookings that
	 * only came over with the import don't count: they are still in the old plugin and go with their event.
	 */
	private static function booked_ids(): array {
		global $wpdb;
		list( $table, $source ) = self::attendees();
		if ( '' === $table ) {
			return array(); }
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Pro's own table, read once per undo
		if ( $source ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM %i WHERE source = ''", $table ) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT event_id FROM %i', $table ) );
		}
		// phpcs:enable
		return array_map( 'intval', $ids );
	}

	/** How many imported bookings and RSVPs belong to these events (they are deleted with them). */
	private static function imported_bookings( array $ids ): int {
		global $wpdb;
		list( $table, $source ) = self::attendees();
		if ( '' === $table || ! $source || ! $ids ) {
			return 0; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Pro's own table, read once for the confirm screen
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_id, COUNT(*) AS n FROM %i WHERE source <> '' GROUP BY event_id", $table ) );
		$want = array_flip( array_map( 'intval', $ids ) );
		$n    = 0;
		foreach ( $rows as $r ) {
			if ( isset( $want[ (int) $r->event_id ] ) ) {
				$n += (int) $r->n; }
		}
		return $n;
	}

	/** Changed in Beacon after the import last wrote it (only known for events imported with 0.38.0 or later). */
	private static function changed( int $id ): bool {
		$at = (int) get_post_meta( $id, self::POST_META, true );
		if ( ! $at ) {
			return false; }
		$p = get_post( $id );
		return $p && strtotime( $p->post_modified_gmt . ' UTC' ) > $at + 60;
	}

	/**
	 * What an undo would do.
	 *
	 * @param string $key            Source key.
	 * @param bool   $include_changed Also remove events changed after the import.
	 * @return array{remove:int[],changed:int[],booked:int[]}
	 */
	public static function plan( string $key, bool $include_changed = false ): array {
		$booked = array_flip( self::booked_ids() );
		$out    = array(
			'remove'  => array(),
			'changed' => array(),
			'booked'  => array(),
		);
		foreach ( self::event_ids( $key ) as $id ) {
			if ( isset( $booked[ $id ] ) ) {
				$out['booked'][] = $id;
				continue; }
			if ( self::changed( $id ) ) {
				$out['changed'][] = $id;
				if ( ! $include_changed ) {
					continue; }
			}
			$out['remove'][] = $id;
		}
		return $out;
	}

	/**
	 * Delete up to $seconds' worth of the planned events, then (when none are left) the empty terms the import created
	 * and the source's saved link maps. Returns how many events and terms were removed and how many events are left.
	 *
	 * @param string $key             Source key.
	 * @param bool   $include_changed Also remove events changed after the import.
	 * @param int    $seconds         Time budget; 0 = no limit (WP-CLI).
	 * @return array{events:int,terms:int,left:int}
	 */
	public static function run( string $key, bool $include_changed, int $seconds = self::BUDGET ): array {
		global $wpdb;
		$ids                    = self::plan( $key, $include_changed )['remove'];
		list( $table, $source ) = self::attendees();
		$start                  = microtime( true );
		$done                   = 0;
		foreach ( $ids as $id ) {
			if ( $seconds && microtime( true ) - $start > $seconds ) {
				break; }
			if ( wp_delete_post( $id, true ) ) {
				++$done;
				if ( $source ) {
					// Pro removes an event's bookings when it is deleted; with Pro switched off, the imported ones are removed here
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Pro's own table
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE event_id = %d AND source <> ''", $table, $id ) );
				}
			}
		}
		$left  = count( $ids ) - $done;
		$terms = 0;
		if ( ! $left ) {
			$terms = self::delete_terms( $key );
			self::forget( $key, $ids );
		}
		/**
		 * After an undo step removed events imported from a source.
		 *
		 * @param string $key  Source key.
		 * @param int[]  $ids  The events this step was asked to remove.
		 * @param int    $left Events still to remove.
		 */
		do_action( 'beacon_import_undone', $key, $ids, $left );
		return array(
			'events' => $done,
			'terms'  => $terms,
			'left'   => $left,
		);
	}

	/** Venues, organizers and categories this source's import created that no event uses any more (children first). */
	private static function delete_terms( string $key ): int {
		$n = 0;
		foreach ( array( 'beacon_venue', 'beacon_organizer', 'beacon_category' ) as $tax ) {
			do {
				$gone  = 0;
				$terms = get_terms(
					array(
						'taxonomy'   => $tax,
						'hide_empty' => false,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin action, a handful of terms
						'meta_query' => array(
							array(
								'key'   => self::TERM_META,
								'value' => $key,
							),
						),
					)
				);
				foreach ( is_array( $terms ) ? $terms : array() as $t ) {
					$count = (int) get_term( $t->term_id, $tax )->count; // fresh: deletions above changed it
					if ( $count || get_terms(
						array(
							'taxonomy'   => $tax,
							'parent'     => $t->term_id,
							'hide_empty' => false,
							'fields'     => 'ids',
							'number'     => 1,
						)
					) ) {
						continue; }
					if ( true === wp_delete_term( $t->term_id, $tax ) ) {
						++$gone; }
				}
				$n += $gone;
			} while ( $gone ); // a parent becomes deletable once its last child is gone
		}
		return $n;
	}

	/** Saved maps that pointed old links at the removed events. */
	private static function forget( string $key, array $ids ): void {
		$cls = Import::sources()[ $key ] ?? '';
		if ( $cls && defined( $cls . '::OPT_LINKS' ) ) {
			delete_option( constant( $cls . '::OPT_LINKS' ) ); }
		if ( Import\Source_TEC::KEY === $key ) {
			delete_option( Import::OPT_SERIES ); }
		$map = get_option( Combine::OPT );
		if ( is_array( $map ) && $ids ) {
			$gone = array_flip( $ids );
			foreach ( array( 'slugs', 'uids' ) as $k ) {
				if ( isset( $map[ $k ] ) && is_array( $map[ $k ] ) ) {
					$map[ $k ] = array_filter( $map[ $k ], fn( $series ) => ! isset( $gone[ (int) $series ] ) ); }
			}
			update_option( Combine::OPT, $map, false );
		}
		$report = get_option( Import::OPT_REPORT );
		if ( is_array( $report ) && ( $report['source'] ?? '' ) === $key ) {
			delete_option( Import::OPT_REPORT ); }
		delete_option( 'beacon_compat_sources' ); // worked out again from what is left
	}

	/* ---------------- admin ---------------- */

	/** Link under a source's import buttons. */
	public static function link( string $key ): void {
		$n = count( self::event_ids( $key ) );
		if ( ! $n ) {
			return; }
		$url = add_query_arg(
			array(
				'post_type' => Events::CPT,
				'page'      => Import::PAGE,
				'undo'      => $key,
			),
			admin_url( 'edit.php' )
		);
		/* translators: %d: number of imported events */
		echo '<p class="beacon-undo-link"><a href="' . esc_url( $url ) . '">' . esc_html( sprintf( _n( 'Undo this import (%d event)…', 'Undo this import (%d events)…', $n, 'beacon-events' ), $n ) ) . '</a></p>';
	}

	/** Titles of a few events, for the confirm screen. */
	private static function titles( array $ids ): string {
		$t = array();
		foreach ( array_slice( $ids, 0, 12 ) as $id ) {
			$t[] = Views::plain( get_the_title( $id ) ) ?: '#' . $id; }
		return implode( ', ', $t ) . ( count( $ids ) > 12 ? ', …' : '' );
	}

	/**
	 * The undo screens on Events → Import / Export: confirm, "removing…" (continues by itself), done.
	 *
	 * @param string $key   Source key.
	 * @param string $label Source plugin name.
	 * @param string $step  'confirm' | 'running' | 'done'.
	 * @param array  $r     Totals so far (events, terms).
	 */
	public static function screen( string $key, string $label, string $step, array $r = array() ): void {
		$back = add_query_arg(
			array(
				'post_type' => Events::CPT,
				'page'      => Import::PAGE,
			),
			admin_url( 'edit.php' )
		);
		if ( 'done' === $step ) {
			$removed = (int) ( $r['events'] ?? 0 ); // a variable: `wp i18n make-pot` skips _n() when the count is in parentheses
			echo '<div class="notice notice-success inline" style="margin:0 0 1em"><p>' . esc_html(
				sprintf(
					/* translators: 1: events removed, 2: venues/organizers/categories removed, 3: plugin name */
					_n( 'Removed %1$d imported event and %2$d venues, organizers and categories the import had created. Nothing in %3$s was changed: turn it back on and your old calendar is there as it was.', 'Removed %1$d imported events and %2$d venues, organizers and categories the import had created. Nothing in %3$s was changed: turn it back on and your old calendar is there as it was.', $removed, 'beacon-events' ),
					$removed,
					(int) ( $r['terms'] ?? 0 ),
					$label
				)
			) . '</p></div>';
			return;
		}
		$include = 'running' === $step && ! empty( $r['include'] );
		$plan    = self::plan( $key, $include );
		if ( 'running' === $step ) {
			echo '<h2>' . esc_html( sprintf( /* translators: %s: plugin name */ __( 'Undoing the import from %s…', 'beacon-events' ), $label ) ) . '</h2>';
			/* translators: %d: events left */
			echo '<p>' . esc_html( sprintf( _n( '%d event left to remove.', '%d events left to remove.', count( $plan['remove'] ), 'beacon-events' ), count( $plan['remove'] ) ) ) . '</p>';
			echo '<form id="beacon-import-continue" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'beacon_import_undo' );
			echo '<input type="hidden" name="action" value="beacon_import_undo"><input type="hidden" name="source" value="' . esc_attr( $key ) . '">';
			if ( $include ) {
				echo '<input type="hidden" name="include_changed" value="1">'; }
			foreach ( array( 'events', 'terms' ) as $k ) {
				echo '<input type="hidden" name="done_' . esc_attr( $k ) . '" value="' . (int) ( $r[ $k ] ?? 0 ) . '">'; }
			submit_button( __( 'Continue', 'beacon-events' ), 'secondary', 'beacon_continue' );
			echo '</form>';
			return;
		}
		echo '<h2>' . esc_html( sprintf( /* translators: %s: plugin name */ __( 'Undo the import from %s', 'beacon-events' ), $label ) ) . '</h2>';
		echo '<div style="max-width:46em">';
		$total = count( $plan['remove'] );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of events, 2: plugin name */
				_n( 'This permanently deletes the %1$d event that was imported from %2$s, and the venues, organizers and categories the import created once nothing uses them. Nothing in %2$s was changed by the import, so turning it back on brings your old calendar back as it was.', 'This permanently deletes the %1$d events that were imported from %2$s, and the venues, organizers and categories the import created once nothing uses them. Nothing in %2$s was changed by the import, so turning it back on brings your old calendar back as it was.', $total, 'beacon-events' ),
				$total,
				$label
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'beacon_import_undo' );
		echo '<input type="hidden" name="action" value="beacon_import_undo"><input type="hidden" name="source" value="' . esc_attr( $key ) . '">';
		if ( $plan['changed'] ) {
			echo '<p><label><input type="checkbox" name="include_changed" value="1"> ' . esc_html(
				sprintf(
					/* translators: 1: number of events, 2: their titles */
					_n( 'Also delete the %1$d event you changed after the import (%2$s). Left unticked, it stays.', 'Also delete the %1$d events you changed after the import (%2$s). Left unticked, they stay.', count( $plan['changed'] ), 'beacon-events' ),
					count( $plan['changed'] ),
					self::titles( $plan['changed'] )
				)
			) . '</label></p>';
		}
		$imported = self::imported_bookings( $plan['remove'] );
		if ( $imported ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: number of bookings, 2: plugin name */
					_n( 'The %1$d booking or RSVP that came over with these events is deleted with them. It is still in %2$s.', 'The %1$d bookings and RSVPs that came over with these events are deleted with them. They are still in %2$s.', $imported, 'beacon-events' ),
					$imported,
					$label
				)
			) . '</p>';
		}
		if ( $plan['booked'] ) {
			$kept = count( $plan['booked'] );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: number of events, 2: their titles */
					_n( '%1$d event has bookings or RSVPs made in Beacon, so it stays: %2$s.', '%1$d events have bookings or RSVPs made in Beacon, so they stay: %2$s.', $kept, 'beacon-events' ),
					$kept,
					self::titles( $plan['booked'] )
				)
			) . '</p>';
		}
		echo '<p class="submit">';
		submit_button( __( 'Delete the imported events', 'beacon-events' ), 'secondary', 'submit', false );
		echo ' <a class="button-link" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'beacon-events' ) . '</a></p>';
		echo '</form></div>';
	}

	public static function handle(): void {
		check_admin_referer( 'beacon_import_undo' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(); }
		$key = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		if ( ! isset( Import::sources()[ $key ] ) || Import\Source_CSV::KEY === $key ) {
			wp_die( esc_html__( 'Unknown import source.', 'beacon-events' ) ); }
		$include = ! empty( $_POST['include_changed'] );
		$r       = self::run( $key, $include );
		$args    = array(
			'post_type' => Events::CPT,
			'page'      => Import::PAGE,
			'undo'      => $key,
			'events'    => $r['events'] + ( isset( $_POST['done_events'] ) ? max( 0, (int) $_POST['done_events'] ) : 0 ),
			'terms'     => $r['terms'] + ( isset( $_POST['done_terms'] ) ? max( 0, (int) $_POST['done_terms'] ) : 0 ),
		);
		$args[ $r['left'] ? 'undoing' : 'undone' ] = 1;
		if ( $r['left'] && $include ) {
			$args['include'] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}
}
