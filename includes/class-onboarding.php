<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * First run, without a single admin notice: a "Get started" link on the Plugins screen, and a short getting-started
 * panel on the Events screen while there are no events of the user's own. From it: add an event, import from another
 * events plugin when its data is found, add a few sample events (clearly titled "Sample:", removable in one click),
 * and create a calendar page. Everything here lives on Beacon's own screen and disappears once real events exist.
 */
final class Onboarding {
	const SOURCE   = 'sample';               // sample events go through the importer with this source key
	const OPT_PAGE = 'beacon_calendar_page'; // the page created by "Create a calendar page"
	const OPT_TERMS = 'beacon_sample_terms'; // venues/categories the samples created (never a term the user already had)

	public static function init(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( BEACON_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'views_edit-' . Events::CPT, array( __CLASS__, 'panel' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_beacon_sample_add', array( __CLASS__, 'add_samples' ) );
		add_action( 'admin_post_beacon_sample_remove', array( __CLASS__, 'remove_samples' ) );
		add_action( 'admin_post_beacon_calendar_page', array( __CLASS__, 'create_page' ) );
	}

	public static function action_links( $links ): array {
		$links = (array) $links;
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Events::CPT ) ) . '">' . esc_html__( 'Get started', 'beacon-events' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ): void {
		if ( 'edit.php' !== $hook || Events::CPT !== ( get_current_screen()->post_type ?? '' ) ) {
			return; }
		wp_register_style( 'beacon-onboarding', false, array(), BEACON_VERSION );
		wp_enqueue_style( 'beacon-onboarding' );
		wp_add_inline_style( 'beacon-onboarding', '.beacon-start{background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:4px 20px 16px;margin:12px 0 8px;max-width:60em}.beacon-start h2{margin:14px 0 6px}.beacon-start ol{margin:0 0 0 1.4em}.beacon-start li{margin:10px 0}.beacon-start li strong{display:block;margin-bottom:4px}.beacon-start form{display:inline}.beacon-start .button{margin:2px 6px 2px 0}.beacon-start-samples{margin:8px 0}' );
	}

	/** Events the user made or imported, not counting samples, trash or auto-drafts. */
	private static function own_events(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one count on the Events screen while it is empty
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_beacon_import' WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit') AND ( m.meta_value IS NULL OR m.meta_value <> %s )", $wpdb->posts, $wpdb->postmeta, Events::CPT, self::SOURCE ) );
	}

	private static function sample_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Events::CPT,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'meta_key'       => '_beacon_import', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin only
					'meta_value'     => self::SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	private static function calendar_page(): int {
		$id = (int) get_option( self::OPT_PAGE, 0 );
		return ( $id && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}

	private static function button( string $action, string $label, string $class = 'button' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Printed above the list on the Events screen (the views filter runs right there); returns the views untouched. */
	public static function panel( $views ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $views; }
		$samples = self::sample_ids();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after our own redirect
		$flag = isset( $_GET['beacon_start'] ) ? sanitize_key( wp_unslash( $_GET['beacon_start'] ) ) : '';
		if ( 'removed' === $flag ) {
			echo '<p class="beacon-start-samples">' . esc_html__( 'The sample events are gone.', 'beacon-events' ) . '</p>'; }
		if ( self::own_events() > 0 ) {
			if ( $samples ) {
				echo '<p class="beacon-start-samples">' . esc_html( sprintf( /* translators: %d: number of sample events */ _n( '%d sample event is still on your calendar.', '%d sample events are still on your calendar.', count( $samples ), 'beacon-events' ), count( $samples ) ) ) . ' ' . self::button( 'beacon_sample_remove', __( 'Remove sample events', 'beacon-events' ), 'button-link' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes
			}
			return $views;
		}
		$page    = self::calendar_page();
		$archive = (string) get_post_type_archive_link( Events::CPT );
		$import  = array();
		foreach ( Import::sources() as $key => $cls ) {
			if ( Import\Source_CSV::KEY === $key ) {
				continue; }
			$d = $cls::detect();
			if ( ! empty( $d['found'] ) && empty( $d['imported'] ) ) {
				$import[] = array( $cls::label(), (int) ( $d['events'] ?? 0 ) ); }
		}
		echo '<div class="beacon-start"><h2>' . esc_html__( 'Get your calendar up in a minute', 'beacon-events' ) . '</h2><ol>';

		echo '<li><strong>' . esc_html__( 'Add your events', 'beacon-events' ) . '</strong>';
		foreach ( $import as $i ) {
			echo '<p>' . esc_html( sprintf( /* translators: 1: number of events, 2: plugin name */ _n( 'Found %1$d event in %2$s.', 'Found %1$d events in %2$s.', $i[1], 'beacon-events' ), $i[1], $i[0] ) ) . ' <a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=' . Events::CPT . '&page=' . Import::PAGE ) ) . '">' . esc_html__( 'Import them', 'beacon-events' ) . '</a></p>';
		}
		echo '<a class="button' . ( $import ? '' : ' button-primary' ) . '" href="' . esc_url( admin_url( 'post-new.php?post_type=' . Events::CPT ) ) . '">' . esc_html__( 'Add an event', 'beacon-events' ) . '</a>';
		if ( ! $samples ) {
			echo self::button( 'beacon_sample_add', __( 'Add 5 sample events', 'beacon-events' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes
			echo '<br><span class="description">' . esc_html__( 'The samples are published so you can see the calendar working. Their titles start with "Sample:", and one click removes them all.', 'beacon-events' ) . '</span>';
		} else {
			echo self::button( 'beacon_sample_remove', __( 'Remove sample events', 'beacon-events' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes
		}
		if ( ! $import ) {
			echo '<br><span class="description">' . esc_html__( 'Coming from another calendar plugin or a spreadsheet?', 'beacon-events' ) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Events::CPT . '&page=' . Import::PAGE ) ) . '">' . esc_html__( 'Import / Export', 'beacon-events' ) . '</a></span>'; }
		echo '</li>';

		echo '<li><strong>' . esc_html__( 'Show them on your site', 'beacon-events' ) . '</strong>';
		if ( $archive ) {
			echo esc_html__( 'Your upcoming events are already live at', 'beacon-events' ) . ' <a href="' . esc_url( $archive ) . '" target="_blank" rel="noopener">' . esc_html( $archive ) . '</a>.<br>'; }
		if ( $page ) {
			echo esc_html__( 'Your calendar page:', 'beacon-events' ) . ' <a href="' . esc_url( (string) get_permalink( $page ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $page ) ) . '</a> (<a href="' . esc_url( (string) get_edit_post_link( $page ) ) . '">' . esc_html__( 'edit', 'beacon-events' ) . '</a>)';
		} elseif ( current_user_can( 'publish_pages' ) ) {
			echo self::button( 'beacon_calendar_page', __( 'Create a calendar page', 'beacon-events' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes
			echo '<span class="description">' . esc_html__( 'A page called "Calendar" with a month view, filters and the next five events.', 'beacon-events' ) . '</span>';
		}
		echo '<br><span class="description">' . esc_html__( 'Or add the "Beacon Events" block, or the [beacon_events] shortcode, to any page.', 'beacon-events' ) . '</span></li>';

		echo '<li><strong>' . esc_html__( 'Keep a Google Calendar in sync (optional)', 'beacon-events' ) . '</strong>' . esc_html__( 'Paste its public iCal address and Beacon checks it every hour.', 'beacon-events' ) . ' <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Events::CPT . '&page=beacon-settings' ) ) . '">' . esc_html__( 'Settings', 'beacon-events' ) . '</a></li>';
		echo '</ol></div>';
		return $views;
	}

	private static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'beacon-events' ), 403 ); }
	}

	private static function back( string $flag ): void {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Events::CPT . '&beacon_start=' . $flag ) );
		exit;
	}

	/** Five events around the coming weeks: a weekly class, a workshop, a monthly meetup, an online session, a two-day market. */
	public static function add_samples(): void {
		self::guard( 'beacon_sample_add' );
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'beacon-events' ), 403 ); }
		if ( ! self::sample_ids() ) {
			self::make_samples(); }
		self::back( 'added' );
	}

	public static function make_samples(): void {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$at    = fn( string $rel, string $time ) => $today->modify( $rel )->format( 'Y-m-d' ) . ' ' . $time;
		$day   = fn( string $rel ) => strtoupper( substr( $today->modify( $rel )->format( 'D' ), 0, 2 ) ); // MO, TU…
		$studio = array(
			'name'    => __( 'Main Studio', 'beacon-events' ),
			'address' => __( '123 Main Street', 'beacon-events' ),
		);
		$classes   = array(
			'name'  => __( 'Classes', 'beacon-events' ),
			'color' => '#2e7d32',
		);
		$workshops = array(
			'name'  => __( 'Workshops', 'beacon-events' ),
			'color' => '#c2185b',
		);
		$community = array(
			'name'  => __( 'Community', 'beacon-events' ),
			'color' => '#1565c0',
		);
		$about  = __( 'This is a sample event from Beacon Events. Remove all the samples in one click from Events in your dashboard, or edit this one and make it yours.', 'beacon-events' );
		$events = array(
			array( 'yoga', __( 'Sample: Morning Yoga', 'beacon-events' ), $at( 'tomorrow', '07:00' ), $at( 'tomorrow', '08:00' ), 'FREQ=WEEKLY;BYDAY=' . $day( 'tomorrow' ) . ',' . $day( '+3 days' ), false, false, $studio, $classes ),
			array( 'pottery', __( 'Sample: Beginner Pottery Workshop', 'beacon-events' ), $at( 'saturday next week', '10:00' ), $at( 'saturday next week', '12:30' ), '', false, false, $studio, $workshops ),
			array( 'potluck', __( 'Sample: Community Potluck', 'beacon-events' ), $at( 'first friday of next month', '18:00' ), $at( 'first friday of next month', '20:00' ), 'FREQ=MONTHLY;BYDAY=1FR', false, false, array( 'name' => __( 'Riverside Park Pavilion', 'beacon-events' ), 'address' => '' ), $community ),
			array( 'qa', __( 'Sample: Online Q&A', 'beacon-events' ), $at( '+10 days', '19:00' ), $at( '+10 days', '20:00' ), '', false, true, null, $community ),
			array( 'market', __( 'Sample: Holiday Market', 'beacon-events' ), $at( '+24 days', '00:00' ), $at( '+25 days', '23:59' ), '', true, false, array( 'name' => __( 'Riverside Park Pavilion', 'beacon-events' ), 'address' => '' ), $community ),
		);
		$names  = self::sample_term_names();
		$before = array();
		foreach ( $names as $tax => $list ) {
			foreach ( $list as $name ) {
				$t                        = get_term_by( 'name', $name, $tax );
				$before[ $tax ][ $name ] = $t ? (int) $t->term_id : 0;
			}
		}
		$state = Import::fresh_state( self::SOURCE );
		foreach ( $events as $e ) {
			Import::import_event(
				array(
					'uid'        => 'sample-' . $e[0],
					'src_id'     => $e[0],
					'title'      => $e[1],
					'content'    => $about,
					'status'     => 'publish',
					'author'     => get_current_user_id(),
					'start'      => $e[2],
					'end'        => $e[3],
					'rrule'      => $e[4],
					'all_day'    => $e[5],
					'online'     => $e[6],
					'online_url' => $e[6] ? 'https://meet.example.com/sample' : '',
					'venue'      => $e[7],
					'categories' => array( $e[8] ),
				),
				$state
			);
		}
		$created = array();
		foreach ( $names as $tax => $list ) {
			foreach ( $list as $name ) {
				$t = get_term_by( 'name', $name, $tax );
				if ( $t && ! $before[ $tax ][ $name ] ) {
					$created[ $tax ][] = (int) $t->term_id; }
			}
		}
		update_option( self::OPT_TERMS, $created, false );
	}

	private static function sample_term_names(): array {
		return array(
			'beacon_venue'    => array( __( 'Main Studio', 'beacon-events' ), __( 'Riverside Park Pavilion', 'beacon-events' ) ),
			'beacon_category' => array( __( 'Classes', 'beacon-events' ), __( 'Workshops', 'beacon-events' ), __( 'Community', 'beacon-events' ) ),
		);
	}

	/** Deletes the sample events, then any sample venue or category nothing else uses. */
	public static function remove_samples(): void {
		self::guard( 'beacon_sample_remove' );
		if ( ! current_user_can( 'delete_others_posts' ) ) { // samples were made by someone else, usually the admin
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'beacon-events' ), 403 ); }
		self::delete_samples();
		self::back( 'removed' );
	}

	public static function delete_samples(): void {
		foreach ( self::sample_ids() as $id ) {
			wp_delete_post( $id, true ); }
		foreach ( (array) get_option( self::OPT_TERMS, array() ) as $tax => $ids ) {
			foreach ( (array) $ids as $tid ) {
				$t = get_term( (int) $tid, (string) $tax );
				if ( $t instanceof \WP_Term && 0 === (int) $t->count ) {
					wp_delete_term( $t->term_id, (string) $tax ); }
			}
		}
		delete_option( self::OPT_TERMS );
	}

	/** A published "Calendar" page: month view with the filter bar, then the next five events; opens it on the site. */
	public static function create_page(): void {
		self::guard( 'beacon_calendar_page' );
		if ( ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'beacon-events' ), 403 ); }
		$id = self::calendar_page();
		if ( ! $id ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Calendar', 'beacon-events' ),
					'post_content' => '<!-- wp:beacon/events {"view":"month","filters":true} /-->' . "\n\n" . '<!-- wp:heading {"level":3} -->' . "\n" . '<h3 class="wp-block-heading">' . esc_html__( 'Coming up', 'beacon-events' ) . '</h3>' . "\n" . '<!-- /wp:heading -->' . "\n\n" . '<!-- wp:beacon/events {"view":"list","layout":"compact","limit":5} /-->',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				wp_die( esc_html( $id->get_error_message() ) ); }
			update_option( self::OPT_PAGE, (int) $id, false );
		}
		wp_safe_redirect( (string) get_permalink( $id ) );
		exit;
	}
}
