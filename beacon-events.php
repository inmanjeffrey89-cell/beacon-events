<?php
/**
 * Plugin Name:       Beacon Events – Events Calendar with Recurring Events & Google Calendar Sync
 * Plugin URI:        https://wordpress.org/plugins/beacon-events/
 * Description:       A light, honest events calendar. Recurring events, ICS and Google Calendar import that stays in sync, and calendar views that look like your site — all free. Uninstalls clean.
 * Version:           0.30.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Beacon Events
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beacon-events
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

define( 'BEACON_VERSION', '0.30.0' );
define( 'BEACON_FILE', __FILE__ );
define( 'BEACON_DIR', plugin_dir_path( __FILE__ ) );
define( 'BEACON_URL', plugin_dir_url( __FILE__ ) );

// Translations shipped in languages/ (German first, for the first German site running it, 2026-10-16). WordPress's own
// just-in-time loader reads them; a language pack from translate.wordpress.org, once one exists, is looked up first and wins.
if ( isset( $GLOBALS['wp_textdomain_registry'] ) && method_exists( $GLOBALS['wp_textdomain_registry'], 'set_custom_path' ) ) {
	$GLOBALS['wp_textdomain_registry']->set_custom_path( 'beacon-events', BEACON_DIR . 'languages' ); }

require_once BEACON_DIR . 'includes/class-recurrence.php';
require_once BEACON_DIR . 'includes/class-schema.php';
require_once BEACON_DIR . 'includes/class-events.php';
require_once BEACON_DIR . 'includes/class-admin.php';
require_once BEACON_DIR . 'includes/class-views.php';
require_once BEACON_DIR . 'includes/class-ics.php';
require_once BEACON_DIR . 'includes/class-settings.php';
require_once BEACON_DIR . 'includes/import/class-source-tec.php';
require_once BEACON_DIR . 'includes/import/class-source-em.php';
require_once BEACON_DIR . 'includes/import/class-source-ai1ec.php';
require_once BEACON_DIR . 'includes/import/class-source-mec.php';
require_once BEACON_DIR . 'includes/import/class-source-wpem.php';
require_once BEACON_DIR . 'includes/import/class-source-eventon.php';
require_once BEACON_DIR . 'includes/import/class-source-wpems.php';
require_once BEACON_DIR . 'includes/import/class-source-sc.php';
require_once BEACON_DIR . 'includes/import/class-source-etn.php';
require_once BEACON_DIR . 'includes/import/class-source-eo.php';
require_once BEACON_DIR . 'includes/import/class-source-csv.php';
require_once BEACON_DIR . 'includes/class-import.php';
require_once BEACON_DIR . 'includes/class-combine.php';
require_once BEACON_DIR . 'includes/class-elementor.php';
require_once BEACON_DIR . 'includes/class-pro.php';
require_once BEACON_DIR . 'includes/class-onboarding.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEACON_DIR . 'includes/class-cli.php';
	Beacon\CLI::register();
}

register_activation_hook( __FILE__, array( 'Beacon\Schema', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'beacon_sync_feeds' );
		wp_clear_scheduled_hook( 'beacon_refresh_occurrences' );
	}
);
add_action(
	'plugins_loaded',
	function () {
		Beacon\Schema::maybe_upgrade();
		Beacon\Events::init();
		// Deferred from activation: our rewrite rules only exist once Events::register() has run,
		// so flush at a late init priority rather than during the activation request.
		add_action(
			'init',
			function () {
				$flag = get_option( 'beacon_flush_rewrites' );
				if ( $flag ) {
					// Reset to '0' instead of deleting: without a persistent object cache WordPress only
					// remembers a missing option for one request, so a deleted flag costs a query per page.
					update_option( 'beacon_flush_rewrites', '0' );
					flush_rewrite_rules();
				} elseif ( false === $flag ) {
					add_option( 'beacon_flush_rewrites', '0' ); // 0.8.0 and earlier deleted it after the flush
				}
			},
			99
		);
		Beacon\Views::init();
		Beacon\Ics::init();
		Beacon\Import::init();
		Beacon\Combine::init();
		Beacon\Elementor::init();
		// everywhere, not only wp-admin: the block editor saves over REST. A sample the user edits becomes theirs.
		add_action( 'save_post_' . Beacon\Events::CPT, array( 'Beacon\Onboarding', 'claim_edited_sample' ), 20, 3 );
		if ( is_admin() ) {
			Beacon\Admin::init();
			Beacon\Settings::init();
			Beacon\Pro::init();
			Beacon\Onboarding::init(); }
	}
);
