<?php
/**
 * SEO plugins and Beacon's events. Moving events in: each event's own SEO settings come along (copy_from).
 * Beacon's events in SEO plugins' sitemaps. Rank Math stores its "include in sitemap" switch per post type once,
 * when it is set up. A post type that appears later (Beacon installed after Rank Math, the usual order) has no stored
 * switch, so Rank Math's sitemap leaves every event out while its own settings screen shows the switch as on. Until
 * the site owner saves that screen, a missing switch reads as on; a switch they turned off stays off.
 */
namespace Beacon;

defined( 'ABSPATH' ) || exit;

final class Seo {

	const RANK_MATH_SITEMAP = 'rank-math-options-sitemap';

	/** Called when the plugin file loads: Rank Math may read its settings before plugins_loaded. */
	public static function init(): void {
		add_filter( 'option_' . self::RANK_MATH_SITEMAP, array( __CLASS__, 'rank_math_sitemap' ) );
	}

	/**
	 * Rank Math's sitemap settings, with a missing switch for events read as on.
	 *
	 * @param mixed $value The stored option.
	 * @return mixed
	 */
	public static function rank_math_sitemap( $value ) {
		$key = 'pt_' . Events::CPT . '_sitemap';
		if ( is_array( $value ) && ! isset( $value[ $key ] ) ) {
			$value[ $key ] = 'on';
		}
		return $value;
	}

	/** The SEO Framework keeps its per-post settings under these names (from its own post data class). */
	const TSF_KEYS = array( '_genesis_title', '_genesis_description', '_genesis_canonical_uri', '_genesis_noindex', '_genesis_nofollow', '_genesis_noarchive', '_open_graph_title', '_open_graph_description', '_twitter_title', '_twitter_description', '_social_image_url', '_social_image_id', '_tsf_title_no_blogname', '_tsf_twitter_card_type', 'exclude_local_search', 'exclude_from_archive', 'redirect' );

	/** Settings a person typed (title, description, focus keyword, robots, canonical, social); SEO plugins also store scores and caches on every post, which don't count as "this event had SEO settings". */
	const SET_BY_HAND = '/^(_yoast_wpseo_(title|metadesc|focuskw|meta-robots-noindex|meta-robots-nofollow|canonical|opengraph-(title|description|image)|twitter-(title|description|image))|rank_math_(title|description|focus_keyword|robots|canonical_url|facebook_(title|description|image)|twitter_(title|description))|_seopress_(titles_title|titles_desc|analysis_target_kw|robots_index|robots_follow|robots_canonical|social_(fb|twitter)_(title|desc|img))|_aioseo_(title|description|keywords|og_title|og_description|twitter_title|twitter_description)|_genesis_(title|description|canonical_uri|noindex|nofollow)|_open_graph_(title|description)|_twitter_(title|description)|_social_image_url)$/';

	/**
	 * Per-post SEO settings of an event's old post (Yoast SEO, Rank Math, SEOPress, The SEO Framework, All in One SEO): the
	 * custom title, meta description, focus keyword, robots, canonical and social fields, so an event keeps them when it
	 * moves into Beacon. Primary-category ids and per-post schema are left behind: they point at the old plugin's
	 * taxonomies and its event schema, and Beacon writes its own Event markup. Read-only when $to is 0 (dry runs).
	 *
	 * @param int $from The old event's post ID.
	 * @param int $to   The Beacon event, or 0 to only look.
	 * @return string[] Names of the SEO plugins whose settings the old post had.
	 */
	public static function copy_from( int $from, int $to = 0 ): array {
		if ( $from <= 0 || $from === $to ) {
			return array(); }
		global $wpdb;
		$like = array();
		foreach ( array( '_yoast_wpseo_', 'rank_math_', '_seopress_', '_aioseo_', '_genesis_', '_open_graph_', '_twitter_', '_social_image_', '_tsf_' ) as $prefix ) {
			$like[] = $wpdb->esc_like( $prefix ) . '%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one read per imported event, in a one-off migration; the keys are SEO plugins' own
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key IN ( 'exclude_local_search', 'exclude_from_archive', 'redirect' ) )", $wpdb->postmeta, $from, $like[0], $like[1], $like[2], $like[3], $like[4], $like[5], $like[6], $like[7], $like[8] ) );
		$found = array();
		foreach ( (array) $rows as $r ) {
			$k = (string) $r->meta_key;
			if ( preg_match( '/^(_yoast_wpseo_primary_|rank_math_primary_|rank_math_schema_|rank_math_internal_|rank_math_analytic_)|^_seopress_robots_primary_cat$/', $k ) ) {
				continue; } // ids of the old plugin's terms, its schema, and Rank Math's own bookkeeping
			if ( ! preg_match( '/^(_yoast_wpseo_|rank_math_|_seopress_|_aioseo_)/', $k ) && ! in_array( $k, self::TSF_KEYS, true ) ) {
				continue; }
			if ( $to ) {
				update_post_meta( $to, $k, wp_slash( maybe_unserialize( $r->meta_value ) ) ); }
			if ( '' !== trim( (string) $r->meta_value ) && preg_match( self::SET_BY_HAND, $k ) ) {
				$found[ self::plugin_of( $k ) ] = true; }
		}
		if ( self::aioseo_row( $from, $to ) ) {
			$found['All in One SEO'] = true; }
		return array_keys( $found );
	}

	private static function plugin_of( string $key ): string {
		if ( 0 === strpos( $key, '_yoast_wpseo_' ) ) {
			return 'Yoast SEO'; }
		if ( 0 === strpos( $key, 'rank_math_' ) ) {
			return 'Rank Math'; }
		if ( 0 === strpos( $key, '_seopress_' ) ) {
			return 'SEOPress'; }
		if ( 0 === strpos( $key, '_aioseo_' ) ) {
			return 'All in One SEO'; }
		return 'The SEO Framework';
	}

	/**
	 * All in One SEO keeps its per-post settings in its own table (aioseo_posts), one row per post. Copies the old post's
	 * row onto the Beacon event (insert or update), without the row id, the primary term and the schema settings.
	 * True when the old row had a title, description, keyphrase or canonical set by hand.
	 */
	private static function aioseo_row( int $from, int $to ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- All in One SEO's own table, read once per imported event
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $table, $from ), ARRAY_A );
		if ( ! $row ) {
			return false; }
		$set = false;
		foreach ( array( 'title', 'description', 'keyphrases', 'canonical_url', 'og_title', 'og_description' ) as $c ) {
			$v = trim( (string) ( $row[ $c ] ?? '' ) );
			if ( '' !== $v && ! in_array( $v, array( '{"focus":{"keyphrase":""},"additional":[]}', '[]', '{}' ), true ) ) {
				$set = true; }
		}
		if ( $to ) {
			unset( $row['id'], $row['primary_term'], $row['schema_type'], $row['schema_type_options'] );
			$row['post_id'] = $to;
			$row['created'] = current_time( 'mysql', true );
			$row['updated'] = current_time( 'mysql', true );
			$have = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE post_id = %d', $table, $to ) );
			if ( $have ) {
				unset( $row['created'] );
				$wpdb->update( $table, $row, array( 'id' => $have ) );
			} else {
				$wpdb->insert( $table, $row ); }
		}
		// phpcs:enable
		return $set;
	}

	/** Drops Rank Math's cached sitemaps once (on update), so its index is rebuilt with the events in it. */
	public static function refresh_sitemaps(): void {
		if ( is_callable( array( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
	}
}
