<?php
namespace Beacon;

defined( 'ABSPATH' ) || exit;

/**
 * Who may manage events. Events have capabilities of their own (edit_beacon_events …), so a site can give people an
 * "Event organizer" role: they add and publish their own events, but can't write posts or pages. Every role that may do
 * something with posts gets the same right for events automatically, so Administrators, Editors, Authors, Contributors
 * and other plugins' roles keep exactly what they had. A role editor that switches an event capability off explicitly
 * is respected.
 */
final class Roles {
	const ROLE = 'beacon_event_organizer';

	/** event capability => the post capability that brings it along */
	const MAP = array(
		'edit_beacon_events'             => 'edit_posts',
		'edit_others_beacon_events'      => 'edit_others_posts',
		'edit_published_beacon_events'   => 'edit_published_posts',
		'edit_private_beacon_events'     => 'edit_private_posts',
		'publish_beacon_events'          => 'publish_posts',
		'read_private_beacon_events'     => 'read_private_posts',
		'delete_beacon_events'           => 'delete_posts',
		'delete_others_beacon_events'    => 'delete_others_posts',
		'delete_published_beacon_events' => 'delete_published_posts',
		'delete_private_beacon_events'   => 'delete_private_posts',
	);

	/**
	 * What an Event organizer may do: their own events, the media library for images, nothing else. view_admin_dashboard
	 * is WooCommerce's own capability for "may use wp-admin": without it WooCommerce sends anyone who can't write posts
	 * to My Account instead of the dashboard.
	 */
	const ORGANIZER_CAPS = array(
		'read',
		'view_admin_dashboard',
		'upload_files',
		'edit_beacon_events',
		'edit_published_beacon_events',
		'publish_beacon_events',
		'delete_beacon_events',
		'delete_published_beacon_events',
	);

	public static function init(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'fill' ), 10, 3 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	/** WordPress sends people who can't write posts to their Profile after logging in; an organizer wants their events. */
	public static function login_redirect( $to, $requested, $user ) {
		unset( $requested );
		if ( ! $user instanceof \WP_User || ( '' !== (string) $to && admin_url() !== $to && 'wp-admin/' !== $to ) ) {
			return $to; }
		return $user->has_cap( 'edit_beacon_events' ) && ! $user->has_cap( 'edit_posts' ) ? admin_url( 'edit.php?post_type=' . Events::CPT ) : $to;
	}

	/** An event capability nobody set follows its post capability. */
	public static function fill( array $allcaps, array $caps, array $args ): array {
		unset( $args );
		foreach ( $caps as $cap ) {
			if ( isset( self::MAP[ $cap ] ) && ! array_key_exists( $cap, $allcaps ) && ! empty( $allcaps[ self::MAP[ $cap ] ] ) ) {
				$allcaps[ $cap ] = true; }
		}
		return $allcaps;
	}

	/** Adds the Event organizer role once (activation, upgrades and each site of a multisite on its first request). */
	public static function add_role(): void {
		if ( ! did_action( 'init' ) ) {
			// upgrades run on plugins_loaded; the role's translated name has to wait for init (WordPress 6.7+ warns otherwise)
			add_action( 'init', array( __CLASS__, 'add_role' ), 1 );
			return; }
		if ( get_role( self::ROLE ) ) {
			return; }
		add_role( self::ROLE, _x( 'Event organizer', 'user role', 'beacon-events' ), array_fill_keys( self::ORGANIZER_CAPS, true ) );
	}
}
