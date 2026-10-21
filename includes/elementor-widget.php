<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return; }

/** Loaded by Elementor::register() only once Elementor's base class exists. */
class Elementor_Widget extends \Elementor\Widget_Base {
	public function get_name() {
		return 'beacon-events'; }
	public function get_title() {
		return __( 'Beacon Events', 'beacon-events' ); }
	public function get_icon() {
		return 'eicon-calendar'; }
	public function get_categories() {
		return array( 'general' ); }
	public function get_keywords() {
		return array( 'events', 'calendar', 'beacon' ); }

	protected function register_controls() {
		$this->start_controls_section( 'beacon_section', array( 'label' => __( 'Events', 'beacon-events' ) ) );
		$this->add_control(
			'view',
			array(
				'label'   => __( 'View', 'beacon-events' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'list',
				'options' => array(
					'list'  => __( 'List', 'beacon-events' ),
					'week'  => __( 'Week', 'beacon-events' ),
					'month' => __( 'Month', 'beacon-events' ),
					'day'   => __( 'Day', 'beacon-events' ),
				),
			)
		);
		$this->add_control(
			'layout',
			array(
				'label'     => __( 'Layout', 'beacon-events' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''        => __( 'Grouped by day', 'beacon-events' ),
					'compact' => __( 'Compact (one line per event)', 'beacon-events' ),
				),
				'condition' => array( 'view' => 'list' ),
			)
		);
		$this->add_control(
			'days',
			array(
				'label'     => __( 'Days ahead', 'beacon-events' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'default'   => 60,
				'min'       => 1,
				'max'       => 730,
				'condition' => array( 'view' => 'list' ),
			)
		);
		$this->add_control(
			'limit',
			array(
				'label'     => __( 'Max events', 'beacon-events' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'default'   => 20,
				'min'       => 1,
				'max'       => 200,
				'condition' => array( 'view' => 'list' ),
			)
		);
		foreach ( array(
			'category'  => __( 'Category slugs (comma-separated, optional)', 'beacon-events' ),
			'venue'     => __( 'Venue slugs (optional)', 'beacon-events' ),
			'organizer' => __( 'Organizer slugs (optional)', 'beacon-events' ),
		) as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'   => $label,
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => '',
				)
			);
		}
		$this->add_control(
			'filters',
			array(
				'label'        => __( 'Filter bar', 'beacon-events' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		$s    = $this->get_settings_for_display();
		$html = Views::render(
			array(
				'view'      => (string) ( $s['view'] ?? 'list' ),
				'layout'    => (string) ( $s['layout'] ?? '' ),
				'days'      => (int) ( $s['days'] ?? 60 ),
				'limit'     => (int) ( $s['limit'] ?? 20 ),
				'category'  => (string) ( $s['category'] ?? '' ),
				'venue'     => (string) ( $s['venue'] ?? '' ),
				'organizer' => (string) ( $s['organizer'] ?? '' ),
				'filters'   => ( $s['filters'] ?? '' ) === 'yes',
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Views::render() escapes everything it prints
	}
}
