<?php
namespace Beacon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** The one meta box: when, how often, where. Plain fields; the recurrence sentence updates live. */
final class Admin {
	public static function init(): void {
		// Classic editor gets the meta box; the block editor gets a proper sidebar panel instead (editor-panel.js).
		add_action( 'add_meta_boxes', fn() => add_meta_box( 'beacon_when', __( 'When', 'beacon-events' ), array( __CLASS__, 'box' ), Events::CPT, 'normal', 'high', array( '__back_compat_meta_box' => true ) ) );
		add_action(
			'enqueue_block_editor_assets',
			function () {
				if ( get_current_screen() && get_current_screen()->post_type === Events::CPT ) {
					wp_enqueue_script( 'beacon-events-panel', BEACON_URL . 'assets/editor-panel.js', array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-api-fetch' ), BEACON_VERSION, true );
					wp_set_script_translations( 'beacon-events-panel', 'beacon-events', BEACON_DIR . 'languages' );
					wp_add_inline_script(
						'beacon-events-panel',
						'window.beaconPanel = ' . wp_json_encode(
							array(
								'pro'    => defined( 'BEACON_PRO_VERSION' ),
								'proUrl' => admin_url( 'edit.php?post_type=' . Events::CPT . '&page=beacon-pro' ),
								// how "03/04/2026" typed into "Skip these dates" is read: day first when the site writes dates day first
								'dmy'    => (bool) preg_match( '/^[dj]/', (string) get_option( 'date_format' ) ),
								'siteTz' => wp_timezone_string(),
								'zones'  => timezone_identifiers_list(),
							)
						) . ';',
						'before'
					);
				}
			}
		);
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		// The plugin's only ask, and only on its own screens: the footer line (never an admin notice — the whole
		// category is hated for notices). New plugins live or die on early reviews.
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ) );
		add_action( 'save_post_' . Events::CPT, array( __CLASS__, 'save' ), 10, 1 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_beacon_duplicate', array( __CLASS__, 'duplicate' ) );
		// Venues carry a street address (Events → Venues). Shown under "Where" with a map link; exported as LOCATION.
		add_action( 'beacon_venue_add_form_fields', fn() => self::venue_field( '' ) );
		add_action( 'beacon_venue_edit_form_fields', fn( $term ) => self::venue_field( (string) get_term_meta( $term->term_id, 'beacon_address', true ), true ) );
		add_action( 'created_beacon_venue', array( __CLASS__, 'save_venue' ) );
		add_action( 'edited_beacon_venue', array( __CLASS__, 'save_venue' ) );
		// Categories can carry a colour: a dot in lists, a bar in the month grid. Empty = none (the default).
		add_action( 'beacon_category_add_form_fields', fn() => self::color_field( '' ) );
		add_action( 'beacon_category_edit_form_fields', fn( $term ) => self::color_field( (string) get_term_meta( $term->term_id, 'beacon_color', true ), true ) );
		add_action( 'created_beacon_category', array( __CLASS__, 'save_color' ) );
		add_action( 'edited_beacon_category', array( __CLASS__, 'save_color' ) );
		add_filter(
			'manage_' . Events::CPT . '_posts_columns',
			fn( $c ) => array_merge(
				array_slice( $c, 0, 2 ),
				array(
					'beacon_when'   => __( 'When', 'beacon-events' ),
					'beacon_repeat' => __( 'Repeats', 'beacon-events' ),
				),
				array_slice( $c, 2 )
			)
		);
		add_action(
			'manage_' . Events::CPT . '_posts_custom_column',
			function ( $col, $id ) {
				$e = Events::get( $id );
				if ( $col === 'beacon_when' ) {
					if ( $e['start'] ) {
						echo esc_html( $e['start'] . ( $e['end'] ? ' → ' . $e['end'] : '' ) );
					} else {
						echo '<span style="color:#b32d2e">' . esc_html__( 'No start date: not on the calendar', 'beacon-events' ) . '</span>'; }
				}
				if ( $col === 'beacon_repeat' ) {
					echo esc_html( Recurrence::describe( $e['rrule'] ) ); }
			},
			10,
			2
		);
	}

	/** Replace "Thank you for creating with WordPress" with a review ask on Beacon's own screens only. */
	public static function footer_text( $text ) {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== Events::CPT ) {
			return $text; }
		return sprintf(
			/* translators: %s: link to the wordpress.org review form */
			esc_html__( 'Enjoying Beacon Events? A quick %s helps a new plugin more than you would think.', 'beacon-events' ),
			'<a href="https://wordpress.org/support/plugin/beacon-events/reviews/#new-post" target="_blank" rel="noopener">' . esc_html__( 'review', 'beacon-events' ) . '</a>'
		);
	}

	/**
	 * The meta box's handful of rules. Enqueued rather than echoed as a <style> tag, and only on the
	 * event editor — the rest of the dashboard must not be restyled by this plugin.
	 */
	public static function admin_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return; }
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== Events::CPT ) {
			return; }
		wp_register_style( 'beacon-events-admin', false, array(), BEACON_VERSION );
		wp_enqueue_style( 'beacon-events-admin' );
		// Classic editor: a US date and time without AM/PM (or any half-filled date) is empty to the browser, so the form
		// would save nothing while the field still shows what was typed. Warn under the field and stop the save.
		wp_register_script( 'beacon-events-when', false, array(), BEACON_VERSION, true );
		wp_enqueue_script( 'beacon-events-when' );
		wp_add_inline_script(
			'beacon-events-when',
			'(function(){var box=document.getElementById("beacon_when"),form=document.getElementById("post");if(!box||!form)return;var msg=' . wp_json_encode( __( 'Not saved yet: fill in every part of the date and time, including AM or PM if it shows.', 'beacon-events' ) ) . ';function check(i){var bad=!!(i.validity&&i.validity.badInput),n=i.parentNode.querySelector(".beacon-date-warning");if(bad&&!n){n=document.createElement("p");n.className="beacon-date-warning";n.setAttribute("role","alert");n.style.cssText="color:#b32d2e;margin:4px 0 0";n.textContent=msg;i.parentNode.appendChild(n);}if(!bad&&n){n.parentNode.removeChild(n);}return bad;}var fields=box.querySelectorAll("input[type=datetime-local],input[type=date]");Array.prototype.forEach.call(fields,function(i){["input","keyup","blur"].forEach(function(t){i.addEventListener(t,function(){check(i);});});});var ad=box.querySelector("input[name=\"beacon[all_day]\"]"),st=box.querySelector("input[name=\"beacon[start]\"]"),en=box.querySelector("input[name=\"beacon[end]\"]");function mode(){if(!ad||!st||!en)return;[st,en].forEach(function(i){var v=i.value;if(ad.checked&&i.type!=="date"){i.type="date";i.value=v.slice(0,10);}else if(!ad.checked&&i.type!=="datetime-local"){i.type="datetime-local";i.value=v?v.slice(0,10)+(i===st?"T09:00":"T10:00"):"";}});}if(ad){ad.addEventListener("change",mode);mode();}form.addEventListener("submit",function(e){var first=null;Array.prototype.forEach.call(fields,function(i){if(check(i)&&!first){first=i;}});if(first){e.preventDefault();e.stopImmediatePropagation();first.focus();}},true);})();'
		);
		wp_add_inline_style( 'beacon-events-admin', '.beacon-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;max-width:760px}.beacon-grid label{display:block;font-weight:600;margin-bottom:3px}.beacon-grid input,.beacon-grid select{width:100%}.beacon-sentence{margin-top:10px;color:#50575e}.beacon-days label{font-weight:400;display:inline-block;margin-right:10px}.beacon-days>label:first-child{font-weight:600;display:block}.beacon-full{grid-column:1/-1}.beacon-online input[type=url]{margin-top:4px}.beacon-dates-title{margin:16px 0 4px}.beacon-dates{max-width:760px}.beacon-dates input{width:100%}' );
	}

	public static function box( \WP_Post $post ): void {
		$e = Events::get( $post->ID );
		wp_nonce_field( 'beacon_when', 'beacon_when_nonce' );
		$r = Recurrence::parse( $e['rrule'] );
		echo '<div class="beacon-grid">';
		echo '<div><label>' . esc_html__( 'Starts', 'beacon-events' ) . '</label><input type="datetime-local" name="beacon[start]" value="' . esc_attr( str_replace( ' ', 'T', $e['start'] ) ) . '"></div>';
		echo '<div><label>' . esc_html__( 'Ends', 'beacon-events' ) . '</label><input type="datetime-local" name="beacon[end]" value="' . esc_attr( str_replace( ' ', 'T', $e['end'] ) ) . '"></div>';
		echo '<div><label><input type="checkbox" name="beacon[all_day]" value="1" ' . checked( $e['all_day'], true, false ) . '> ' . esc_html__( 'All day', 'beacon-events' ) . '</label></div>';
		echo '<div><label>' . esc_html__( 'Timezone', 'beacon-events' ) . '</label><select name="beacon[tz]">' . wp_timezone_choice( $e['tz'] ) . '</select></div>';
		echo '<div><label>' . esc_html__( 'Repeats', 'beacon-events' ) . '</label><select name="beacon[freq]" id="beacon-freq">';
		foreach ( array(
			''        => __( 'Does not repeat', 'beacon-events' ),
			'DAILY'   => __( 'Daily', 'beacon-events' ),
			'WEEKLY'  => __( 'Weekly', 'beacon-events' ),
			'MONTHLY' => __( 'Monthly', 'beacon-events' ),
			'YEARLY'  => __( 'Yearly', 'beacon-events' ),
		) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r['FREQ'] ?? '', $k, false ) . '>' . esc_html( $l ) . '</option>'; }
		echo '</select></div>';
		echo '<div><label>' . esc_html__( 'Every', 'beacon-events' ) . '</label><input type="number" min="1" max="52" name="beacon[interval]" value="' . esc_attr( $r['INTERVAL'] ) . '"> <span class="description">' . esc_html__( 'day(s) / week(s) / month(s) / year(s)', 'beacon-events' ) . '</span></div>';
		echo '<div class="beacon-days"><label>' . esc_html__( 'On (weekly)', 'beacon-events' ) . '</label>';
		foreach ( array(
			'SU' => 'Sun',
			'MO' => 'Mon',
			'TU' => 'Tue',
			'WE' => 'Wed',
			'TH' => 'Thu',
			'FR' => 'Fri',
			'SA' => 'Sat',
		) as $k => $l ) {
			echo '<label><input type="checkbox" name="beacon[byday][]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, $r['BYDAY'], true ), true, false ) . '> ' . esc_html( $l ) . '</label>'; }
		echo '</div>';
		// Monthly: the choices people mean, worked out from the start date (as in the block editor), not "2TU" syntax
		// (0.19.0 and earlier had a text box that silently ignored "second Tuesday").
		$ord  = array(
			1 => __( 'first', 'beacon-events' ),
			2 => __( 'second', 'beacon-events' ),
			3 => __( 'third', 'beacon-events' ),
			4 => __( 'fourth', 'beacon-events' ),
		);
		$mo   = array( '' => __( 'Same day of the month as the start', 'beacon-events' ) );
		$sdt  = $e['start'] ? date_create( $e['start'] ) : false;
		if ( $sdt ) {
			$nth   = (int) ceil( (int) $sdt->format( 'j' ) / 7 );
			$code  = strtoupper( substr( $sdt->format( 'D' ), 0, 2 ) );
			$wname = date_i18n( 'l', strtotime( $sdt->format( 'Y-m-d' ) . ' 12:00' ) );
			/* translators: %d: day of the month */
			$mo[''] = sprintf( __( 'Monthly on day %d', 'beacon-events' ), (int) $sdt->format( 'j' ) );
			if ( $nth <= 4 ) {
				/* translators: 1: first/second/…, 2: weekday */
				$mo[ $nth . $code ] = sprintf( __( 'Monthly on the %1$s %2$s', 'beacon-events' ), $ord[ $nth ], $wname );
				$a                  = $nth % 2 ? 1 : 2;
				/* translators: 1: first/second, 2: third/fourth, 3: weekday */
				$mo[ $a . $code . ',' . ( $a + 2 ) . $code ] = sprintf( __( 'Monthly on the %1$s and %2$s %3$s', 'beacon-events' ), $ord[ $a ], $ord[ $a + 2 ], $wname );
			}
			if ( (int) $sdt->format( 'j' ) + 7 > (int) $sdt->format( 't' ) ) {
				/* translators: %s: weekday */
				$mo[ '-1' . $code ] = sprintf( __( 'Monthly on the last %s', 'beacon-events' ), $wname ); }
		}
		$cur = ( $r['BYDAY'] && preg_match( '/^-?\d[A-Z]{2}$/', $r['BYDAY'][0] ) ) ? implode( ',', $r['BYDAY'] ) : '';
		if ( $cur !== '' && ! isset( $mo[ $cur ] ) ) { // a rule from an import or an older version stays selectable
			$mo[ $cur ] = Recurrence::describe( 'FREQ=MONTHLY;BYDAY=' . $cur ); }
		echo '<div><label for="beacon-bypos">' . esc_html__( 'Monthly on', 'beacon-events' ) . '</label><select id="beacon-bypos" name="beacon[bypos]">';
		foreach ( $mo as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $cur, $k, false ) . '>' . esc_html( $l ) . '</option>'; }
		echo '</select></div>';
		echo '<div><label>' . esc_html__( 'Ends after (times)', 'beacon-events' ) . '</label><input type="number" min="1" name="beacon[count]" value="' . esc_attr( $r['COUNT'] ?? '' ) . '"></div>';
		echo '<div><label>' . esc_html__( 'Or ends on', 'beacon-events' ) . '</label><input type="date" name="beacon[until]" value="' . esc_attr( $r['UNTIL'] ?? '' ) . '"></div>';
		echo '<div class="beacon-full"><label>' . esc_html__( 'Skip these dates', 'beacon-events' ) . '</label><input type="text" name="beacon[exdates]" value="' . esc_attr( $e['exdates'] ) . '" placeholder="2026-12-25, 2027-01-01"></div>';
		echo '<div class="beacon-full beacon-online"><label><input type="checkbox" name="beacon[online]" value="1" ' . checked( $e['online'], true, false ) . '> ' . esc_html__( 'Online event', 'beacon-events' ) . '</label><input type="url" name="beacon[online_url]" value="' . esc_attr( $e['online_url'] ) . '" placeholder="' . esc_attr__( 'Meeting link, e.g. https://zoom.us/j/…', 'beacon-events' ) . '"></div>';
		echo '<div class="beacon-full"><label>' . esc_html__( 'Tickets & info link', 'beacon-events' ) . '</label><input type="url" name="beacon[link]" value="' . esc_attr( $e['link'] ) . '" placeholder="' . esc_attr__( 'e.g. your Eventbrite or Square page', 'beacon-events' ) . '"></div>';
		echo '<div><label>' . esc_html__( 'Button text', 'beacon-events' ) . '</label><input type="text" name="beacon[link_label]" value="' . esc_attr( $e['link_label'] ) . '" placeholder="' . esc_attr__( 'Tickets & info', 'beacon-events' ) . '"></div>';
		echo '</div><p class="beacon-sentence">' . esc_html( Recurrence::describe( $e['rrule'] ) ) . '</p>';
		// Per-date changes: cancel one date or move it, without touching the rule. Rows come from the last save.
		$occ = $e['rrule'] ? Events::occurrences_for_edit( $post->ID, 8 ) : array();
		if ( $occ ) {
			echo '<h4 class="beacon-dates-title">' . esc_html__( 'Upcoming dates', 'beacon-events' ) . '</h4><p class="description">' . esc_html__( 'Cancel a single date (it stays listed as cancelled), move it to another time, or give it its own ticket link (when each date has its own registration page). Dates reflect the last save.', 'beacon-events' ) . '</p>';
			echo '<table class="widefat striped beacon-dates"><thead><tr><th>' . esc_html__( 'Date', 'beacon-events' ) . '</th><th>' . esc_html__( 'Status', 'beacon-events' ) . '</th><th>' . esc_html__( 'Moved to (start)', 'beacon-events' ) . '</th><th>' . esc_html__( 'Moved to (end)', 'beacon-events' ) . '</th><th>' . esc_html__( 'Ticket link for this date', 'beacon-events' ) . '</th></tr></thead><tbody>';
			foreach ( $occ as $o ) {
				$ov  = $e['overrides'][ $o['orig'] ] ?? array();
				$n   = 'beacon[ov][' . esc_attr( $o['orig'] ) . ']';
				echo '<tr><td>' . esc_html( $o['orig'] === substr( $o['start'], 0, 10 ) ? $o['label'] : $o['orig'] ) . '</td><td><select name="' . $n . '[status]">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $n escaped above
				foreach ( array(
					'scheduled' => __( 'As scheduled', 'beacon-events' ),
					'cancelled' => __( 'Cancelled', 'beacon-events' ),
					'moved'     => __( 'Moved', 'beacon-events' ),
				) as $k => $l ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( $o['status'], $k, false ) . '>' . esc_html( $l ) . '</option>'; }
				echo '</select></td><td><input type="datetime-local" name="' . $n . '[start]" value="' . esc_attr( str_replace( ' ', 'T', $ov['start'] ?? $o['start'] ) ) . '"></td><td><input type="datetime-local" name="' . $n . '[end]" value="' . esc_attr( str_replace( ' ', 'T', $ov['end'] ?? $o['end'] ) ) . '"></td><td><input type="text" inputmode="url" class="widefat" name="' . $n . '[link]" value="' . esc_attr( $ov['link'] ?? '' ) . '" placeholder="' . esc_attr__( 'Same as the event', 'beacon-events' ) . '"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $n escaped above
			}
			echo '</tbody></table>';
		}
	}

	/** "Duplicate" on the events list: a draft copy with the same when/where/rule, terms and image, opened for editing. */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( $post->post_type === Events::CPT && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=beacon_duplicate&post=' . $post->ID ), 'beacon_duplicate_' . $post->ID );
			$actions['beacon_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'beacon-events' ) . '</a>'; }
		return $actions;
	}

	public static function duplicate(): void {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line
		check_admin_referer( 'beacon_duplicate_' . $id );
		$src = get_post( $id );
		if ( ! $src || $src->post_type !== Events::CPT || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You cannot duplicate this event.', 'beacon-events' ) ); }
		$new = wp_insert_post(
			array(
				'post_type'    => Events::CPT,
				'post_status'  => 'draft',
				'post_title'   => $src->post_title,
				'post_content' => $src->post_content,
				'post_excerpt' => $src->post_excerpt,
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) ); }
		$skip = array( '_edit_lock', '_edit_last', '_beacon_uid', '_beacon_feed', '_beacon_tec_id', '_thumbnail_id' );
		foreach ( (array) get_post_meta( $id ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue; }
			foreach ( $values as $v ) {
				add_post_meta( $new, $key, maybe_unserialize( $v ) ); }
		}
		foreach ( get_object_taxonomies( Events::CPT ) as $tax ) {
			$terms = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new, $terms, $tax ); }
		}
		$thumb = get_post_thumbnail_id( $id );
		if ( $thumb ) {
			set_post_thumbnail( $new, $thumb ); }
		wp_safe_redirect( admin_url( 'post.php?post=' . $new . '&action=edit' ) );
		exit;
	}

	public static function venue_field( string $value, bool $edit = false ): void {
		$label = esc_html__( 'Address', 'beacon-events' );
		$input = '<input type="text" name="beacon_address" id="beacon_address" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( '123 Main St, Springfield', 'beacon-events' ) . '">';
		$help  = '<p class="description">' . esc_html__( 'Shown on the event page with a map link, and sent to calendar apps.', 'beacon-events' ) . '</p>';
		if ( $edit ) {
			echo '<tr class="form-field"><th scope="row"><label for="beacon_address">' . $label . '</label></th><td>' . $input . $help . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above
		} else {
			echo '<div class="form-field"><label for="beacon_address">' . $label . '</label>' . $input . $help . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above
		}
	}

	public static function color_field( string $value, bool $edit = false ): void {
		$label = esc_html__( 'Colour', 'beacon-events' );
		$input = '<input type="color" name="beacon_color" id="beacon_color" value="' . esc_attr( $value ?: '#4f6bed' ) . '"> <label><input type="checkbox" name="beacon_color_none" value="1" ' . checked( $value === '', true, false ) . '> ' . esc_html__( 'No colour', 'beacon-events' ) . '</label>';
		$help  = '<p class="description">' . esc_html__( 'Shown as a dot next to events in lists and a bar in the month grid.', 'beacon-events' ) . '</p>';
		if ( $edit ) {
			echo '<tr class="form-field"><th scope="row"><label for="beacon_color">' . $label . '</label></th><td>' . $input . $help . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above
		} else {
			echo '<div class="form-field"><label for="beacon_color">' . $label . '</label>' . $input . $help . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above
		}
	}

	public static function save_color( int $term_id ): void {
		// WordPress verifies the term form nonce and capability before firing created_/edited_ hooks.
		if ( ! isset( $_POST['beacon_color'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return; }
		$none  = ! empty( $_POST['beacon_color_none'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$color = sanitize_hex_color( sanitize_text_field( wp_unslash( $_POST['beacon_color'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $none || ! $color ) {
			delete_term_meta( $term_id, 'beacon_color' );
		} else {
			update_term_meta( $term_id, 'beacon_color', $color ); }
	}

	public static function save_venue( int $term_id ): void {
		// WordPress verifies the term form nonce and capability before firing created_/edited_ hooks.
		if ( ! isset( $_POST['beacon_address'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return; }
		update_term_meta( $term_id, 'beacon_address', sanitize_text_field( wp_unslash( $_POST['beacon_address'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	public static function save( int $post_id ): void {
		if ( ! isset( $_POST['beacon_when_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['beacon_when_nonce'] ) ), 'beacon_when' ) ) {
			return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return; }
		$in = isset( $_POST['beacon'] ) && is_array( $_POST['beacon'] ) ? map_deep( wp_unslash( $_POST['beacon'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via map_deep
		$dt  = fn( $s ) => preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string) $s ) ? str_replace( 'T', ' ', substr( $s, 0, 16 ) ) : '';
		$day = fn( $s, $time ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $s ) ? $s . ' ' . $time : $dt( $s ); // all day: the box sends dates only
		update_post_meta( $post_id, Events::META['start'], $day( $in['start'] ?? '', '00:00' ) );
		update_post_meta( $post_id, Events::META['end'], $day( $in['end'] ?? '', '23:59' ) );
		update_post_meta( $post_id, Events::META['all_day'], empty( $in['all_day'] ) ? '0' : '1' );
		$tz = sanitize_text_field( $in['tz'] ?? '' );
		update_post_meta( $post_id, Events::META['tz'], in_array( $tz, timezone_identifiers_list(), true ) ? $tz : wp_timezone_string() );
		$freq = strtoupper( sanitize_text_field( $in['freq'] ?? '' ) );
		$rule = '';
		if ( in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
			$rule = "FREQ=$freq;INTERVAL=" . max( 1, (int) ( $in['interval'] ?? 1 ) );
			$days = array_values( array_intersect( array_map( 'strtoupper', (array) ( $in['byday'] ?? array() ) ), array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ) ) );
			$pos  = strtoupper( sanitize_text_field( $in['bypos'] ?? '' ) );
			if ( $freq === 'WEEKLY' && $days ) {
				$rule .= ';BYDAY=' . implode( ',', $days ); }
			$pos = implode( ',', array_filter( explode( ',', $pos ), fn( $p ) => (bool) preg_match( '/^(-1|[1-5])(SU|MO|TU|WE|TH|FR|SA)$/', $p ) ) );
			if ( $freq === 'MONTHLY' && $pos !== '' ) {
				$rule .= ';BYDAY=' . $pos; }
			if ( ! empty( $in['count'] ) ) {
				$rule .= ';COUNT=' . max( 1, (int) $in['count'] ); } elseif ( ! empty( $in['until'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $in['until'] ) ) {
				$rule .= ';UNTIL=' . str_replace( '-', '', $in['until'] ); }
		}
		update_post_meta( $post_id, Events::META['rrule'], $rule );
		update_post_meta( $post_id, Events::META['exdates'], Events::sanitize_dates( (string) ( $in['exdates'] ?? '' ) ) );
		update_post_meta( $post_id, Events::META['online'], empty( $in['online'] ) ? '0' : '1' );
		// links from the raw input: sanitize_text_field (map_deep above) strips "%20"-style characters out of addresses
		$raw_links = isset( $_POST['beacon'] ) && is_array( $_POST['beacon'] ) ? wp_unslash( $_POST['beacon'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the two URLs are sanitized by Events::sanitize_url (esc_url_raw)
		update_post_meta( $post_id, Events::META['online_url'], Events::sanitize_url( (string) ( $raw_links['online_url'] ?? '' ) ) );
		update_post_meta( $post_id, Events::META['link'], Events::sanitize_url( (string) ( $raw_links['link'] ?? '' ) ) );
		update_post_meta( $post_id, Events::META['link_label'], sanitize_text_field( (string) ( $in['link_label'] ?? '' ) ) );
		// Per-date changes: the box shows the next few dates; overrides for dates it did not show are kept as they were.
		$ov = Events::get( $post_id )['overrides'];
		foreach ( (array) ( $in['ov'] ?? array() ) as $ymd => $row ) {
			$ymd = (string) $ymd;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) || ! is_array( $row ) ) {
				continue; }
			$status = $row['status'] ?? 'scheduled';
			// read raw like the event's own links above: sanitize_text_field would strip %xx out of the address
			$link = Events::sanitize_url( (string) ( $raw_links['ov'][ $ymd ]['link'] ?? '' ) );
			if ( $status === 'cancelled' ) {
				$ov[ $ymd ] = array( 'status' => 'cancelled' );
			} elseif ( $status === 'moved' && $dt( $row['start'] ?? '' ) ) {
				$ov[ $ymd ] = array_filter(
					array(
						'start' => $dt( $row['start'] ),
						'end'   => $dt( $row['end'] ?? '' ),
						'link'  => $link,
					)
				);
			} elseif ( $link !== '' ) {
				$ov[ $ymd ] = array( 'link' => $link );
			} else {
				unset( $ov[ $ymd ] ); }
		}
		update_post_meta( $post_id, Events::META['overrides'], Events::sanitize_overrides( $ov ) );
	}
}
