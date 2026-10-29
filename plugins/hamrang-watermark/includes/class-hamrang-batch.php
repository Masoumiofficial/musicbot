<?php
/**
 * Batch processing of existing media (AJAX, chunked).
 *
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hamrang_Batch {

	/** Attachments processed per AJAX request (the client keeps calling until the queue is empty). */
	const CHUNK = 4;

	/** Soft time budget per request, seconds. */
	const BUDGET = 20;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_hamrang_batch_query', array( __CLASS__, 'ajax_query' ) );
		add_action( 'wp_ajax_hamrang_batch_run', array( __CLASS__, 'ajax_run' ) );
	}

	/**
	 * Permission + nonce check shared by the AJAX endpoints.
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'hamrang-watermark' ) ), 403 );
		}
		check_ajax_referer( 'hamrang_batch', 'nonce' );
	}

	/**
	 * Candidate attachment IDs.
	 *
	 * @param string $scope  all | featured | stamped | unstamped.
	 * @return int[]
	 */
	public static function query( $scope ) {
		global $wpdb;

		$mimes = "'" . implode( "','", array_map( 'esc_sql', Hamrang_Media::SUPPORTED ) ) . "'";
		$sql   = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$mimes})";

		switch ( $scope ) {
			case 'featured':
				$sql .= " AND p.ID IN (SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m WHERE m.meta_key = '_thumbnail_id' AND m.meta_value <> '')";
				break;
			case 'stamped':
				$sql .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s)", HAMRANG_META );
				break;
			case 'unstamped':
				$sql .= $wpdb->prepare( " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s)", HAMRANG_META );
				break;
		}
		$sql .= ' ORDER BY p.ID DESC LIMIT 50000';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- assembled from prepared/escaped parts above.
		$ids = $wpdb->get_col( $sql );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * AJAX: list the IDs to process.
	 */
	public static function ajax_query() {
		self::guard();
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
		if ( ! in_array( $scope, array( 'all', 'featured', 'stamped', 'unstamped' ), true ) ) {
			$scope = 'all';
		}
		$ids = self::query( $scope );
		wp_send_json_success(
			array(
				'ids'   => $ids,
				'total' => count( $ids ),
			)
		);
	}

	/**
	 * AJAX: process a chunk of IDs.
	 */
	public static function ajax_run() {
		self::guard();
		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : 'apply';
		if ( ! in_array( $task, array( 'apply', 'force', 'restore' ), true ) ) {
			$task = 'apply';
		}
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_slice( array_filter( $ids ), 0, self::CHUNK );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$started = microtime( true );
		$items   = array();
		foreach ( $ids as $id ) {
			if ( ! empty( $items ) && ( microtime( true ) - $started ) > self::BUDGET ) {
				break; // the client re-sends what we did not get to
			}
			$items[] = self::process( $id, $task );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Process one attachment and describe the outcome for the UI.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $task apply | force | restore.
	 * @return array
	 */
	public static function process( $id, $task ) {
		if ( 'restore' === $task ) {
			$r = Hamrang_Media::restore( $id );
		} else {
			$r = Hamrang_Media::apply( $id, 'batch', 'force' === $task );
		}
		$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
		return array(
			'id'      => (int) $id,
			'status'  => $r['status'],
			'code'    => $r['code'],
			'message' => $r['message'],
			'title'   => get_the_title( $id ),
			'thumb'   => $thumb ? add_query_arg( 'hamrang', time(), $thumb[0] ) : '',
			'link'    => get_edit_post_link( $id, 'raw' ),
		);
	}
}
