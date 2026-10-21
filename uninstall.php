<?php
/** Runs only when the user deletes the plugin from the Plugins screen. Off by default: nothing is removed unless they opted in. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; }
wp_clear_scheduled_hook( 'beacon_sync_feeds' );
wp_clear_scheduled_hook( 'beacon_refresh_occurrences' );
if ( get_option( 'beacon_delete_on_uninstall' ) !== '1' ) {
	return; }
global $wpdb;
foreach ( get_posts(
	array(
		'post_type'      => 'beacon_event',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => -1,
	)
) as $beacon_post_id ) {
	wp_delete_post( $beacon_post_id, true ); }
foreach ( array( 'beacon_category', 'beacon_venue', 'beacon_organizer' ) as $beacon_tax ) {
	foreach ( get_terms(
		array(
			'taxonomy'   => $beacon_tax,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	) as $beacon_term_id ) {
		wp_delete_term( $beacon_term_id, $beacon_tax ); }
}
foreach ( array( 'beacon_feeds', 'beacon_sync_log', 'beacon_import_log', 'beacon_import_report', 'beacon_ai1ec_links', 'beacon_mec_links', 'beacon_delete_on_uninstall', 'beacon_schema_version', 'beacon_flush_rewrites', 'beacon_calendar_page', 'beacon_sample_terms', 'beacon_combined' ) as $beacon_option ) {
	delete_option( $beacon_option ); }
// Importer progress is kept per user as beacon_import_<user id> transients.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_beacon_import_' ) . '%', $wpdb->esc_like( '_transient_timeout_beacon_import_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}beacon_occurrences" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
