<?php
namespace Beacon\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use Beacon\Events;
use Beacon\Import;
use Beacon\Views;

/**
 * CSV in and out. The export writes one row per event in exactly the columns the import reads, so a file can go
 * out, be edited in a spreadsheet, and come back (rows match on `uid`; new rows are created). Any other plugin
 * whose data can be exported to a spreadsheet can be moved through this too.
 */
final class Source_CSV {
	const KEY     = 'csv';
	const COLUMNS = array( 'uid', 'title', 'start', 'end', 'all_day', 'timezone', 'repeat', 'skip_dates', 'venue', 'address', 'organizer', 'categories', 'online', 'online_url', 'status', 'excerpt', 'description', 'image_url', 'slug', 'link', 'link_label' );

	public static function label(): string {
		return __( 'CSV file', 'beacon-events' ); }

	public static function hint(): string {
		return ''; }

	public static function detect(): array {
		return array(
			'found'    => false,
			'imported' => Import::imported_count( self::KEY ),
		);
	}

	/** Upload handling: called once, at offset 0. Returns the stored file path or an error string. */
	public static function receive_upload( array &$state ): ?string {
		// The caller (Import::handle) has already verified the nonce and capability; wp_handle_upload validates the file.
		$upload = isset( $_FILES['beacon_csv'] ) && is_array( $_FILES['beacon_csv'] ) ? $_FILES['beacon_csv'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		if ( empty( $upload['tmp_name'] ) ) {
			return __( 'Choose a CSV file first.', 'beacon-events' ); }
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php'; }
		$file = wp_handle_upload(
			$upload,
			array(
				'test_form' => false,
				'mimes'     => array( 'csv' => 'text/csv' ),
			)
		);
		if ( ! empty( $file['error'] ) ) {
			return (string) $file['error']; }
		$state['file']  = $file['file'];
		$state['total'] = max( 0, self::row_count( $file['file'] ) );
		return null;
	}

	private static function row_count( string $path ): int {
		$n = 0;
		$h = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local CSV parsing needs a stream
		if ( ! $h ) {
			return 0; }
		fgetcsv( $h );
		while ( fgetcsv( $h ) !== false ) {
			++$n; }
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $n;
	}

	/** Rows are addressed by their 0-based index after the header; ids() just returns index ranges. */
	public static function ids( array $state, int $offset, int $limit ): array {
		$total = (int) ( $state['total'] ?? 0 );
		if ( $offset >= $total ) {
			return array(); }
		return range( $offset, min( $total, $offset + $limit ) - 1 );
	}

	/** Read one row (and cache the parsed header) — files are small enough that re-scanning per batch is fine. */
	private static function row( string $path, int $index ): ?array {
		static $cache = array();
		if ( ! isset( $cache[ $path ] ) ) {
			$rows = array();
			$h    = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $h ) {
				return null; }
			$head = fgetcsv( $h );
			if ( ! $head ) {
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return null; }
			$head = array_map( fn( $c ) => strtolower( trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $c ) ) ), $head );
			while ( ( $r = fgetcsv( $h ) ) !== false ) {
				$row = array();
				foreach ( $head as $i => $col ) {
					$row[ $col ] = isset( $r[ $i ] ) ? trim( (string) $r[ $i ] ) : ''; }
				$rows[] = $row;
			}
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$cache[ $path ] = $rows;
		}
		return $cache[ $path ][ $index ] ?? null;
	}

	/** Accepts "2026-10-06 18:00", "2026-10-06T18:00", "2026-10-06" (all day) and most spreadsheet date formats. */
	private static function when( string $v ): string {
		$v = trim( $v );
		if ( $v === '' ) {
			return ''; }
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			return $v . ' 00:00'; }
		$t = strtotime( $v );
		return $t ? gmdate( 'Y-m-d H:i', $t ) : '';
	}

	public static function map( $index, array &$state ): ?array {
		$r = self::row( (string) ( $state['file'] ?? '' ), (int) $index );
		if ( $r === null ) {
			return null; }
		$g     = fn( string $k ) => (string) ( $r[ $k ] ?? '' );
		$title = $g( 'title' ) !== '' ? $g( 'title' ) : $g( 'name' );
		$start = self::when( $g( 'start' ) !== '' ? $g( 'start' ) : $g( 'start_date' ) . ' ' . $g( 'start_time' ) );
		if ( $title === '' || $start === '' ) {
			++$state['skipped'];
			$state['notes'][] = sprintf( /* translators: %d: row number */ __( 'Row %d skipped: it needs at least a title and a start.', 'beacon-events' ), (int) $index + 2 );
			return null;
		}
		// A uid that already belongs to one of our events (an exported row coming back, whatever plugin it originally
		// came from) updates that event; anything else is a CSV-born event.
		$raw = $g( 'uid' ) !== '' ? $g( 'uid' ) : md5( $title . '|' . $start );
		$uid = Import::uid_exists( $raw ) ? $raw : self::KEY . '-' . $raw;
		$cats = array();
		foreach ( preg_split( '/[|;]/', $g( 'categories' ) !== '' ? $g( 'categories' ) : $g( 'category' ) ) as $c ) {
			$c = trim( $c );
			if ( $c !== '' ) {
				$cats[] = array(
					'name'   => $c,
					'slug'   => sanitize_title( $c ),
					'parent' => null,
				); }
		}
		$orgs = array();
		foreach ( preg_split( '/[|;]/', $g( 'organizer' ) !== '' ? $g( 'organizer' ) : $g( 'organizers' ) ) as $o ) {
			$o = trim( $o );
			if ( $o !== '' ) {
				$orgs[] = array( 'name' => $o ); }
		}
		$all_day = in_array( strtolower( $g( 'all_day' ) ), array( '1', 'yes', 'true', 'y' ), true ) || preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $g( 'start' ) ) );
		$status  = strtolower( $g( 'status' ) );
		$note    = null;
		$rrule   = $g( 'repeat' ) !== '' ? $g( 'repeat' ) : $g( 'rrule' );
		if ( $rrule ) {
			$rrule = Import::normalize_rrule( strtoupper( preg_replace( '/^RRULE:/i', '', $rrule ) ), $note ); }
		return array(
			'uid'        => $uid,
			'src_id'     => (int) $index + 2,
			'title'      => $title,
			'content'    => wp_kses_post( $g( 'description' ) !== '' ? $g( 'description' ) : $g( 'content' ) ),
			'excerpt'    => sanitize_textarea_field( $g( 'excerpt' ) ),
			'status'     => in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'publish',
			'slug'       => sanitize_title( $g( 'slug' ) ),
			'start'      => $start,
			'end'        => self::when( $g( 'end' ) !== '' ? $g( 'end' ) : $g( 'end_date' ) . ' ' . $g( 'end_time' ) ),
			'all_day'    => $all_day,
			'tz'         => $g( 'timezone' ),
			'rrule'      => $rrule,
			'exdates'    => array_values( array_filter( array_map( fn( $d ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $d ) ) ? trim( $d ) : '', preg_split( '/[|;,]/', $g( 'skip_dates' ) !== '' ? $g( 'skip_dates' ) : $g( 'exdates' ) ) ) ) ),
			'online'     => in_array( strtolower( $g( 'online' ) ), array( '1', 'yes', 'true', 'y' ), true ),
			'online_url' => Events::sanitize_url( $g( 'online_url' ) ),
			'link'       => Events::sanitize_url( $g( 'link' ) ),
			'link_label' => $g( 'link_label' ),
			'venue'      => $g( 'venue' ) !== '' ? array(
				'name'    => $g( 'venue' ),
				'address' => $g( 'address' ),
			) : null,
			'organizers' => $orgs,
			'categories' => $cats,
			'image_url'  => Events::sanitize_url( $g( 'image_url' ) !== '' ? $g( 'image_url' ) : $g( 'image' ) ),
			'extra'      => array(),
			'note'       => $note,
		);
	}

	/* ---------------- export ---------------- */

	/** Every event, one row each, in the import's own columns. */
	public static function export(): void {
		$ids = get_posts(
			array(
				'post_type'      => Events::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="beacon-events-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a download
		fputcsv( $out, self::COLUMNS );
		foreach ( $ids as $id ) {
			$e   = Events::get( $id );
			$p   = get_post( $id );
			$uid = (string) get_post_meta( $id, '_beacon_uid', true );
			if ( $uid === '' ) {   // give hand-made events a stable id so the row can come back as an update
				$uid = 'beacon-' . $id;
				update_post_meta( $id, '_beacon_uid', $uid ); }
			$venue = get_the_terms( $id, 'beacon_venue' );
			$venue = $venue && ! is_wp_error( $venue ) ? $venue[0] : null;
			$orgs  = get_the_terms( $id, 'beacon_organizer' );
			$cats  = get_the_terms( $id, 'beacon_category' );
			$row   = array(
				$uid,
				$p->post_title,
				$e['all_day'] ? substr( $e['start'], 0, 10 ) : $e['start'],
				$e['all_day'] ? substr( $e['end'], 0, 10 ) : $e['end'],
				$e['all_day'] ? '1' : '0',
				$e['tz'],
				$e['rrule'],
				str_replace( ',', '|', $e['exdates'] ),
				$venue ? $venue->name : '',
				$venue ? (string) get_term_meta( $venue->term_id, 'beacon_address', true ) : '',
				$orgs && ! is_wp_error( $orgs ) ? implode( '|', wp_list_pluck( $orgs, 'name' ) ) : '',
				$cats && ! is_wp_error( $cats ) ? implode( '|', wp_list_pluck( $cats, 'name' ) ) : '',
				$e['online'] ? '1' : '0',
				$e['online_url'],
				$p->post_status,
				$p->post_excerpt,
				$p->post_content,
				(string) get_the_post_thumbnail_url( $id, 'full' ),
				$p->post_name,
				$e['link'],
				$e['link_label'],
			);
			// spreadsheet formula injection guard: a leading =, +, -, @ becomes text
			fputcsv( $out, array_map( fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v, $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
