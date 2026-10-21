<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Elementor widget: the same server-rendered list / month / day view, with the block's options as widget controls.
 * Registered only when Elementor is loaded; the widget class is declared inside the hook so nothing here references
 * Elementor's classes on sites without it.
 */
final class Elementor {
	public static function init(): void {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register' ) );
	}

	public static function register( $manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return; }
		require_once BEACON_DIR . 'includes/elementor-widget.php';
		$manager->register( new \Beacon\Elementor_Widget() );
	}
}
