<?php
/**
 * Uninstall: remove settings; optionally per-image records and stored originals
 * (only when the "remove its data" option was ticked). Watermarked images are never altered here.
 *
 * @package Hamrang
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$hamrang_settings = get_option( 'hamrang_settings', array() );
$hamrang_cleanup  = is_array( $hamrang_settings ) && ! empty( $hamrang_settings['uninstall_cleanup'] );

delete_option( 'hamrang_settings' );
delete_transient( 'hamrang_activated' );

if ( $hamrang_cleanup ) {
	global $wpdb;
	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

	// Stored originals.
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_hamrang_original' ) );
	foreach ( (array) $rows as $row ) {
		$data = maybe_unserialize( $row );
		if ( ! is_array( $data ) || empty( $data['file'] ) ) {
			continue;
		}
		$file = wp_normalize_path( $base . ltrim( $data['file'], '/\\' ) );
		if ( 0 === strpos( $file, $base ) && preg_match( '/-hamrang-orig\.[a-z0-9]+$/i', $file ) && file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", '_hamrang_wm', '_hamrang_original' ) );
}
