<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** One row per occurrence so "what's on this week" is a cheap indexed query, not RRULE math at request time. */
final class Schema {
	const VERSION = '3'; // 3 = 0.29.4's clean-up of imported links that point back at the event itself
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'beacon_occurrences'; }

	public static function activate(): void {
		self::install();
		// The CPT and its rewrite rules are registered on `init`, which has not run yet on the
		// activation request. Flushing here writes rules that do not contain ours, and every event
		// and archive URL 404s until somebody re-saves permalinks. Defer it to the next init.
		update_option( 'beacon_flush_rewrites', '1' );
	}

	public static function maybe_upgrade(): void {
		$was = get_option( 'beacon_schema_version' );
		if ( self::VERSION !== $was ) {
			self::install();
			// The activation hook only runs on the site that activated the plugin. When it is network-activated on a
			// multisite, every other site meets Beacon here first, with rewrite rules saved before Beacon existed, so
			// its event pages 404. Ask for the same deferred flush the activation hook asks for.
			update_option( 'beacon_flush_rewrites', '1' );
			if ( false !== $was && (int) $was < 3 ) {
				Import::drop_self_links(); }
		}
	}

	private static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		$c = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			start_utc datetime NOT NULL,
			end_utc datetime NOT NULL,
			all_day tinyint(1) NOT NULL DEFAULT 0,
			orig_ymd char(10) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'scheduled',
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY start_utc (start_utc)
		) $c;"
		);
		update_option( 'beacon_schema_version', self::VERSION );
	}
}
