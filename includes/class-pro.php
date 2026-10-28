<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * One "Tickets & RSVP" page under Events describing the paid add-on, plus a collapsed "Tickets & RSVP" panel in the
 * event editor that links to it (where Pro's own ticket settings appear once installed). No banners on other screens,
 * no dashboard notices, no counters — the wordpress.org rule and, more to the point, the thing every one-star review
 * in this category complains about. The page hides itself entirely once Pro is installed.
 */
final class Pro {
	const CHECKOUT = 'https://checkout.freemius.com/plugin/39970/plan/68746/';
	const TRIAL    = 'https://checkout.freemius.com/plugin/39970/plan/68746/?trial=free'; // 14 days, no card

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu(): void {
		if ( defined( 'BEACON_PRO_VERSION' ) ) {
			return; } // Pro is installed; it adds its own screens
		add_submenu_page( 'edit.php?post_type=' . Events::CPT, __( 'Tickets & RSVP', 'beacon-events' ), __( 'Tickets & RSVP', 'beacon-events' ), 'manage_options', 'beacon-pro', array( __CLASS__, 'page' ) );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$features = array(
			__( 'RSVP — visitors reserve a spot, get a confirmation email with a calendar file, and can cancel themselves. Set a capacity per date and the form shows "3 spots left".', 'beacon-events' ),
			__( 'Paid tickets through your own Stripe account (Stripe hosts the checkout, card details never touch your site) or through your WooCommerce checkout with every gateway the shop already has. No per-ticket fee on top: you pay your payment provider\'s card fee and nothing else, unlike ticketing sites that add a service fee to every ticket.', 'beacon-events' ),
			__( 'Several ticket types per event, such as early bird, general and VIP, each with its own price and capacity.', 'beacon-events' ),
			__( 'Waitlist when a date is full: a cancellation hands the spot to the first person on it. Reminder emails to everyone confirmed the day before.', 'beacon-events' ),
			__( 'Attendee list per event: filter, cancel, mark someone paid, export CSV. Every ticket has a QR code: scan it at the door with your phone camera to check people in, or print a door list for each date.', 'beacon-events' ),
			__( 'Moving from The Events Calendar or WP Events Manager (ThimPress)? Pro imports your tickets and attendees along with the events: ticket price and capacity, every RSVP, every paid attendee (Event Tickets Plus orders paid through WooCommerce included) and every completed booking.', 'beacon-events' ),
			__( 'Let visitors submit events from a page on your site. They arrive as drafts for you to approve.', 'beacon-events' ),
			__( 'Email support, answered by the person who wrote the plugin.', 'beacon-events' ),
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Tickets & RSVP', 'beacon-events' ) . '</h1>';
		echo '<p style="font-size:14px;max-width:46em">' . esc_html__( 'Everything in Beacon Events stays free — recurring events, calendar sync, every view. If your events need people to sign up or buy a ticket, that lives in the paid add-on.', 'beacon-events' ) . '</p>';
		echo '<ul style="max-width:46em;list-style:disc;margin-left:1.4em">';
		foreach ( $features as $f ) {
			echo '<li style="margin:.5em 0">' . esc_html( $f ) . '</li>'; }
		echo '</ul>';
		echo '<table class="widefat" style="max-width:46em;margin:1.5em 0"><thead><tr><th>' . esc_html__( 'Licence', 'beacon-events' ) . '</th><th>' . esc_html__( 'Price', 'beacon-events' ) . '</th></tr></thead><tbody>';
		foreach ( array(
			__( '1 site', 'beacon-events' )          => '$7/mo, $49/yr or $149 once',
			__( 'Up to 5 sites', 'beacon-events' )   => '$99/yr or $299 once',
			__( 'Unlimited sites', 'beacon-events' ) => '$149/yr',
		) as $label => $price ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $price ) . '</td></tr>'; }
		echo '</tbody></table>';
		echo '<p><a class="button button-primary button-hero" href="' . esc_url( self::TRIAL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Start a 14-day free trial', 'beacon-events' ) . '</a> <a style="margin-left:1em;vertical-align:middle" href="' . esc_url( self::CHECKOUT ) . '" target="_blank" rel="noopener">' . esc_html__( 'or buy now', 'beacon-events' ) . '</a></p><p class="description" style="max-width:46em">' . esc_html__( 'The trial needs only your email, no card. When it ends, Pro stops unless you buy a licence; your events and the free plugin keep working.', 'beacon-events' ) . '</p>';
		echo '<p class="description" style="max-width:46em">' . esc_html__( 'See what setting it up involves before you start:', 'beacon-events' ) . ' <a href="' . esc_url( 'https://beacon-events.duckdns.org/demo/pro-guide/' ) . '" target="_blank" rel="noopener">' . esc_html__( 'the 10-minute setup guide', 'beacon-events' ) . '</a>. ' . esc_html__( 'Or try the booking form on the', 'beacon-events' ) . ' <a href="' . esc_url( 'https://beacon-events.duckdns.org/demo/event/pottery-for-beginners/' ) . '" target="_blank" rel="noopener">' . esc_html__( 'demo site', 'beacon-events' ) . '</a>.</p>';
		echo '<p class="description" style="max-width:46em">' . esc_html__( '14-day money-back guarantee. If your licence lapses, your events and tickets keep working — you just stop getting updates and support.', 'beacon-events' ) . '</p>';
		echo '</div>';
	}
}
