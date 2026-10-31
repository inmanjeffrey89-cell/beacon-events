<?php
/**
 * Stands in for another calendar plugin's sidebar widget after a move (see Compat::WIDGETS): the saved widget keeps its
 * place and title and shows Beacon's upcoming events as a short list.
 */
namespace Beacon;

defined( 'ABSPATH' ) || exit;

final class Compat_Widget extends \WP_Widget {

	/** @var string import source key */
	private $source;
	/** @var array instance keys to read (title, limit, cat, hide, once, mec), plus once_when and days */
	private $map;
	/** @var string the old plugin's name, for the admin note */
	private $plugin;

	public function __construct( string $id_base, string $source, string $plugin, array $map ) {
		$this->source = $source;
		$this->map    = $map;
		$this->plugin = $plugin;
		parent::__construct(
			$id_base,
			/* translators: %s: the calendar plugin the widget came from, e.g. "The Events Calendar" */
			sprintf( __( 'Upcoming events (old %s widget)', 'beacon-events' ), $plugin ),
			array(
				'description'           => __( 'Kept from your previous calendar plugin; Beacon Events fills it with your upcoming events.', 'beacon-events' ),
				'show_instance_in_rest' => false,
			)
		);
	}

	private function pick( array $instance, string $what ): string {
		foreach ( (array) ( $this->map[ $what ] ?? array() ) as $k ) {
			$v = $instance[ $k ] ?? '';
			if ( is_array( $v ) ) {
				$v = implode( ',', array_map( 'strval', $v ) ); }
			if ( '' !== trim( (string) $v ) ) {
				return trim( (string) $v ); }
		}
		return '';
	}

	/** The [beacon_events] attributes this saved instance stands for. */
	public function atts( array $instance ): array {
		$limit = (int) $this->pick( $instance, 'limit' );
		$cat   = $this->pick( $instance, 'cat' );
		$mec   = (int) $this->pick( $instance, 'mec' );
		if ( 'mycalendar' === $this->source ) {
			$cat = Compat::map( 'mycalendar', 'my_calendar_upcoming', array( 'category' => $cat ) )['category'] ?? '';
		} elseif ( $mec ) {
			$cat = Compat::map( 'mec', 'MEC', array( 'id' => (string) $mec ) )['category'] ?? '';
		} else {
			$cat = Compat::cats( $this->source, $cat );
		}
		$once = strtolower( $this->pick( $instance, 'once' ) );
		return array(
			'view'     => 'list',
			'layout'   => 'compact',
			'limit'    => $limit > 0 ? min( $limit, 50 ) : 5,
			'days'     => (int) ( $this->map['days'] ?? 365 ),
			'category' => $cat,
			'once'     => isset( $this->map['once_when'] ) && '' !== $once && $once === $this->map['once_when'] ? '1' : '',
		);
	}

	public function widget( $args, $instance ) {
		$instance = is_array( $instance ) ? $instance : array();
		$html     = Views::shortcode( $this->atts( $instance ) );
		$hide     = $this->pick( $instance, 'hide' );
		if ( $hide && ! in_array( strtolower( $hide ), array( '0', 'false', 'no', 'off' ), true ) && false !== strpos( $html, 'beacon-empty' ) ) {
			return; } // the old widget was set to disappear when there's nothing coming up
		$title = apply_filters( 'widget_title', $this->pick( $instance, 'title' ), $instance, $this->id_base );
		echo $args['before_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the theme's own widget markup
		if ( '' !== (string) $title ) {
			echo ( $args['before_title'] ?? '' ) . esc_html( (string) $title ) . ( $args['after_title'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup around an escaped title
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Views escapes everything it prints
		echo $args['after_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the theme's own widget markup
	}

	public function form( $instance ) {
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the calendar plugin the widget came from */
					__( 'This widget came from %s. Beacon Events now shows your upcoming events in it, with its title and number of events. To change it, replace it with the Events block.', 'beacon-events' ),
					$this->plugin
				)
			)
		);
		return 'noform';
	}

	public function update( $new_instance, $old_instance ) {
		return is_array( $old_instance ) ? $old_instance : array(); // kept as the old plugin saved it
	}
}
