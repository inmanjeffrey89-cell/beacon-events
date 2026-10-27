<?php
/** Runs only when the user deletes the plugin from the Plugins screen. Off by default: nothing is removed unless they opted in. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; }
/**
 * Removes one site's Beacon data, if that site opted in; returns whether it did. On a multisite the uninstall runs once,
 * in the main site's context, so it is called for every site (each site keeps its own data and its own opt-in).
 */
function beacon_events_uninstall_site(): bool {
	wp_clear_scheduled_hook( 'beacon_sync_feeds' );
	wp_clear_scheduled_hook( 'beacon_refresh_occurrences' );
	if ( get_option( 'beacon_delete_on_uninstall' ) !== '1' ) {
		return false; }
	global $wpdb;
	// The plugin isn't loaded while it is uninstalled, so its taxonomies aren't registered and get_terms() would refuse them.
	foreach ( array( 'beacon_category', 'beacon_venue', 'beacon_organizer' ) as $beacon_tax ) {
		if ( ! taxonomy_exists( $beacon_tax ) ) {
			register_taxonomy( $beacon_tax, 'beacon_event' ); }
	}
	// Every status, trash included ('any' leaves out trashed events, such as the copies Combine moved to the trash).
	foreach ( get_posts(
		array(
			'post_type'      => 'beacon_event',
			'post_status'    => array_keys( get_post_stati() ),
			'fields'         => 'ids',
			'posts_per_page' => -1,
		)
	) as $beacon_post_id ) {
		wp_delete_post( $beacon_post_id, true ); }
	// Uploaded CSV files of imports that never finished.
	foreach ( array_keys( (array) get_option( 'beacon_import_files', array() ) ) as $beacon_file ) {
		if ( is_string( $beacon_file ) && file_exists( $beacon_file ) ) {
			wp_delete_file( $beacon_file ); }
	}
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
	foreach ( array( 'beacon_feeds', 'beacon_sync_log', 'beacon_import_log', 'beacon_import_report', 'beacon_ai1ec_links', 'beacon_mec_links', 'beacon_delete_on_uninstall', 'beacon_schema_version', 'beacon_flush_rewrites', 'beacon_calendar_page', 'beacon_sample_terms', 'beacon_combined', 'beacon_import_files', 'beacon_tec_series' ) as $beacon_option ) {
		delete_option( $beacon_option ); }
	// Importer progress is kept per user as beacon_import_<user id> transients.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_beacon_import_' ) . '%', $wpdb->esc_like( '_transient_timeout_beacon_import_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}beacon_occurrences" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return true;
}

if ( is_multisite() ) {
	$beacon_any = false;
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $beacon_site_id ) {
		switch_to_blog( (int) $beacon_site_id );
		$beacon_any = beacon_events_uninstall_site() || $beacon_any;
		restore_current_blog();
	}
} else {
	$beacon_any = beacon_events_uninstall_site();
}
if ( $beacon_any ) {
	delete_metadata( 'user', 0, 'beacon_hide_calendar_hint', '', true ); // "No thanks" to the calendar page line
}
