<?php
/**
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

	/** Drops Rank Math's cached sitemaps once (on update), so its index is rebuilt with the events in it. */
	public static function refresh_sitemaps(): void {
		if ( is_callable( array( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
	}
}
