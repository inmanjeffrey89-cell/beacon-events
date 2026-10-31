<?php
/**
 * Stand-ins for The Events Calendar's Elementor widgets after a move. Elementor builds a widget from the type name saved
 * in the page; with The Events Calendar gone that type is unknown and the section renders empty. These register the same
 * names and draw Beacon's view from whatever the saved settings say (view, category, count, title), else sensible defaults.
 * Hidden from the Elementor panel: they exist for saved pages, not new ones.
 */
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return; }

abstract class Elementor_Compat_Widget extends \Elementor\Widget_Base {
	public function get_icon() {
		return 'eicon-calendar'; }
	public function get_categories() {
		return array( 'general' ); }
	public function show_in_panel() {
		return false; }

	/** The page's saved settings for this widget, as the old plugin wrote them. */
	protected function saved(): array {
		$s = $this->get_data( 'settings' );
		return is_array( $s ) ? $s : array();
	}

	protected static function first( array $s, array $keys ): string {
		foreach ( $keys as $k ) {
			$v = $s[ $k ] ?? '';
			if ( is_array( $v ) ) {
				$v = implode( ',', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) ); }
			if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				return trim( (string) $v ); }
		}
		return '';
	}

	/** [beacon_events] attributes for these saved settings. */
	abstract public function atts( array $s ): array;

	protected function render() {
		$s     = $this->saved();
		$title = static::first( $s, array( 'title', 'widget_title', 'header_title' ) );
		if ( '' !== $title && $this instanceof Elementor_TEC_Events_List ) {
			echo '<h3 class="beacon-widget-title">' . esc_html( $title ) . '</h3>'; }
		echo Views::shortcode( $this->atts( $s ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Views escapes everything it prints
	}
}

/** The Events Calendar Pro's "Events View" widget: a whole calendar view on a page. */
final class Elementor_TEC_Events_View extends Elementor_Compat_Widget {
	public function get_name() {
		return 'tec_elementor_widget_events_view'; }
	public function get_title() {
		return __( 'Events view (old The Events Calendar widget)', 'beacon-events' ); }

	public function atts( array $s ): array {
		$v    = strtolower( static::first( $s, array( 'view', 'events_view', 'tribe_view', 'default_view' ) ) );
		$view = in_array( $v, array( 'month', 'week', 'day' ), true ) ? $v : ( '' === $v ? 'month' : 'list' );
		return array(
			'view'     => $view,
			'days'     => 365,
			'category' => Compat::cats( 'tec', static::first( $s, array( 'category', 'categories', 'tribe_events_cat', 'filter_category' ) ) ),
			'filters'  => in_array( strtolower( static::first( $s, array( 'tribe_bar', 'tribe-bar', 'show_search_bar', 'events_bar' ) ) ), array( 'no', 'false', '0', 'off' ), true ) ? '' : '1',
		);
	}
}

/** The Events Calendar Pro's "Events List" widget: a short upcoming list, like its sidebar widget. */
final class Elementor_TEC_Events_List extends Elementor_Compat_Widget {
	public function get_name() {
		return 'tec_elementor_widget_events_list_widget'; }
	public function get_title() {
		return __( 'Events list (old The Events Calendar widget)', 'beacon-events' ); }

	public function atts( array $s ): array {
		$limit = (int) static::first( $s, array( 'limit', 'number', 'events_per_page', 'count' ) );
		return array(
			'view'     => 'list',
			'layout'   => 'compact',
			'days'     => 365,
			'limit'    => $limit > 0 ? min( $limit, 50 ) : 5,
			'category' => Compat::cats( 'tec', static::first( $s, array( 'category', 'categories', 'tribe_events_cat', 'filter_category' ) ) ),
		);
	}
}
