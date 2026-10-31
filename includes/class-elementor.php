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
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_compat' ), 99 ); // after every plugin's own
	}

	/** After a move from The Events Calendar: its Elementor widgets on saved pages render Beacon's views instead of nothing. */
	public static function register_compat( $manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! in_array( 'tec', Compat::sources(), true ) || ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
			return; }
		require_once BEACON_DIR . 'includes/elementor-compat-widgets.php';
		foreach ( array( 'tec_elementor_widget_events_view' => Elementor_TEC_Events_View::class, 'tec_elementor_widget_events_list_widget' => Elementor_TEC_Events_List::class ) as $name => $cls ) {
			if ( ! $manager->get_widget_types( $name ) ) { // The Events Calendar Pro still active → its own widget
				$manager->register( new $cls() ); }
		}
	}

	public static function register( $manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return; }
		require_once BEACON_DIR . 'includes/elementor-widget.php';
		$manager->register( new \Beacon\Elementor_Widget() );
	}
}
