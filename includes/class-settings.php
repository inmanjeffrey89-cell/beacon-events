<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Events → Settings: feeds, sync log, and the "delete everything on uninstall" switch. One page, no tabs. */
final class Settings {
	const OPT_DELETE = 'beacon_delete_on_uninstall';

	public static function init(): void {
		add_action( 'admin_menu', fn() => add_submenu_page( 'edit.php?post_type=' . Events::CPT, __( 'Beacon Settings', 'beacon-events' ), __( 'Settings', 'beacon-events' ), 'manage_options', 'beacon-settings', array( __CLASS__, 'page' ) ) );
		add_action( 'admin_post_beacon_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_beacon_sync_now', array( __CLASS__, 'sync_now' ) );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$feeds = Ics::feeds();
		$log   = get_option( Ics::OPT_LOG, array() );
		echo '<div class="wrap"><h1>' . esc_html__( 'Beacon Events', 'beacon-events' ) . '</h1>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags only
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( isset( $_GET['synced'] ) ? __( 'Saved, and the feeds were synced: the result is in the log below.', 'beacon-events' ) : __( 'Saved.', 'beacon-events' ) ) . '</p></div>'; }
		if ( isset( $_GET['synced_now'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Feeds synced: the result is in the log below.', 'beacon-events' ) . '</p></div>'; }
		// phpcs:enable
		$bad = get_transient( 'beacon_feeds_skipped_' . get_current_user_id() );
		if ( $bad ) {
			delete_transient( 'beacon_feeds_skipped_' . get_current_user_id() );
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: %s: the lines that were not web addresses */ __( 'These lines were not calendar addresses and were left out: %s', 'beacon-events' ), implode( ', ', (array) $bad ) ) ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'beacon_settings' );
		echo '<input type="hidden" name="action" value="beacon_save_settings">';
		echo '<h2>' . esc_html__( 'Calendar feeds', 'beacon-events' ) . '</h2><p class="description">' . esc_html__( 'One per line: an ICS URL, optionally followed by a space and a category slug. Google Calendar: Settings → your calendar → "Public address in iCal format". Synced hourly; every sync is logged below.', 'beacon-events' ) . '</p>';
		echo '<textarea name="feeds" rows="5" class="large-text code">' . esc_textarea( implode( "\n", array_map( fn( $f ) => trim( ( $f['url'] ?? '' ) . ' ' . ( $f['category'] ?? '' ) ), $feeds ) ) ) . '</textarea>';
		echo '<h2>' . esc_html__( 'Uninstall', 'beacon-events' ) . '</h2><label><input type="checkbox" name="delete_on_uninstall" value="1" ' . checked( get_option( self::OPT_DELETE ), '1', false ) . '> ' . esc_html__( 'Delete all events, venues, categories, settings and the occurrence table when the plugin is uninstalled. Off by default so an accidental uninstall loses nothing.', 'beacon-events' ) . '</label>';
		echo '<h2>' . esc_html__( 'Export', 'beacon-events' ) . '</h2><p><code>' . esc_html( add_query_arg( 'beacon_ical', 'all', home_url( '/' ) ) ) . '</code> — ' . esc_html__( 'subscribe to this in any calendar app.', 'beacon-events' ) . ' ' . esc_html__( 'Add &beacon_category=slug, &beacon_venue=slug or &beacon_organizer=slug for one category, venue or organizer; every venue, organizer and category page also links to its own feed.', 'beacon-events' ) . '</p>';
		submit_button();
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:-10px">';
		wp_nonce_field( 'beacon_sync' );
		echo '<input type="hidden" name="action" value="beacon_sync_now">';
		submit_button( __( 'Sync feeds now', 'beacon-events' ), 'secondary' );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Sync log', 'beacon-events' ) . '</h2>';
		if ( ! $log ) {
			echo '<p class="description">' . esc_html__( 'No syncs yet.', 'beacon-events' ) . '</p>'; } else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'beacon-events' ) . '</th><th>' . esc_html__( 'Feed', 'beacon-events' ) . '</th><th>' . esc_html__( 'Created', 'beacon-events' ) . '</th><th>' . esc_html__( 'Updated', 'beacon-events' ) . '</th><th>' . esc_html__( 'Removed → draft', 'beacon-events' ) . '</th><th>' . esc_html__( 'Errors', 'beacon-events' ) . '</th></tr></thead><tbody>';
			foreach ( $log as $e ) {
				echo '<tr><td>' . esc_html( $e['time'] ) . '</td><td>' . esc_html( $e['url'] ) . '</td><td>' . (int) $e['created'] . '</td><td>' . (int) $e['updated'] . '</td><td>' . (int) $e['removed'] . '</td><td>' . esc_html( implode( '; ', $e['errors'] ?? array() ) ) . '</td></tr>'; }
			echo '</tbody></table>'; }
			echo '</div>';
	}

	public static function save(): void {
		check_admin_referer( 'beacon_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(); }
		$feeds   = array();
		$skipped = array();
		// Not sanitize_textarea_field(): it strips percent-encoded characters, and every Google Calendar address has
		// them ("…%40group.calendar.google.com"), so the pasted address broke (0.18.4 and earlier). Each line is
		// cleaned on its own below: the address by feed_url() / esc_url_raw(), the category by sanitize_title().
		$raw = isset( $_POST['feeds'] ) ? (string) wp_unslash( $_POST['feeds'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per line below
		foreach ( explode( "\n", str_replace( "\r", '', $raw ) ) as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( $line === '' ) {
				continue; }
			$parts = preg_split( '/\s+/', $line, 2 );
			$url   = self::feed_url( $parts[0] );
			if ( $url ) {
				$feeds[] = array(
					'url'      => $url,
					'category' => sanitize_title( $parts[1] ?? '' ), // "Kids Classes" → kids-classes, the whole name
				);
			} else {
				$skipped[] = $parts[0]; }
		}
		$old = Ics::feeds();
		update_option( Ics::OPT_FEEDS, $feeds, false );
		update_option( self::OPT_DELETE, empty( $_POST['delete_on_uninstall'] ) ? '0' : '1', false );
		if ( $skipped ) {
			set_transient( 'beacon_feeds_skipped_' . get_current_user_id(), $skipped, 10 * MINUTE_IN_SECONDS ); }
		// A feed just added should show its events now, not at the next hourly sync.
		$new = $feeds && wp_list_pluck( $feeds, 'url' ) !== wp_list_pluck( $old, 'url' );
		if ( $new ) {
			Ics::sync_all(); }
		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'post_type' => Events::CPT,
						'page'      => 'beacon-settings',
						'saved'     => 1,
						'synced'    => $new ? 1 : 0,
					)
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * A calendar address as people paste it: webcal:// subscribe links become https://, and a Google Calendar share or
	 * embed link (calendar.google.com/calendar/embed?src=…) becomes that calendar's public iCal address. '' when it
	 * isn't a web address at all.
	 */
	public static function feed_url( string $raw ): string {
		$u = trim( $raw );
		$u = (string) preg_replace( '#^webcals?://#i', 'https://', $u );
		if ( preg_match( '#^https?://calendar\.google\.com/calendar/(?:u/\d+/)?(?:embed|r)?/?\?(?:[^\#]*&)?src=([^&\#]+)#i', $u, $m ) ) {
			$u = 'https://calendar.google.com/calendar/ical/' . rawurlencode( rawurldecode( $m[1] ) ) . '/public/basic.ics'; }
		if ( ! preg_match( '#^https?://#i', $u ) ) {
			// esc_url_raw() would turn any word into http://word: only something shaped like an address gets https://
			if ( ! preg_match( '#^[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$#i', $u ) ) {
				return ''; }
			$u = 'https://' . $u;
		}
		$u = esc_url_raw( $u );
		return preg_match( '#^https?://#', $u ) ? $u : '';
	}

	public static function sync_now(): void {
		check_admin_referer( 'beacon_sync' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(); }
		Ics::sync_all();
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'  => Events::CPT,
					'page'       => 'beacon-settings',
					'synced_now' => 1,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
