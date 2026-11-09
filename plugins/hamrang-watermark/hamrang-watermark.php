<?php
/**
 * Plugin Name:       Hamrang – Smart Cover Watermark
 * Plugin URI:        https://ahangban.ir/hamrang
 * Description:       Puts your logo on featured images and uploads inside a frosted-glass plate that takes the dominant colour of each picture. Originals are kept and can be restored any time.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            AhangBan
 * Author URI:        https://ahangban.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hamrang-watermark
 * Domain Path:       /languages
 *
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HAMRANG_VERSION', '1.0.0' );
define( 'HAMRANG_FILE', __FILE__ );
define( 'HAMRANG_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAMRANG_URL', plugin_dir_url( __FILE__ ) );
define( 'HAMRANG_BASENAME', plugin_basename( __FILE__ ) );
define( 'HAMRANG_OPTION', 'hamrang_settings' );
define( 'HAMRANG_META', '_hamrang_wm' );
define( 'HAMRANG_META_ORIGINAL', '_hamrang_original' );
define( 'HAMRANG_MIN_PHP', '7.4' );

require_once HAMRANG_DIR . 'includes/engine.php';
require_once HAMRANG_DIR . 'includes/class-hamrang-settings.php';
require_once HAMRANG_DIR . 'includes/class-hamrang-media.php';
require_once HAMRANG_DIR . 'includes/class-hamrang-batch.php';
require_once HAMRANG_DIR . 'includes/class-hamrang-admin.php';

/**
 * Load translations and wire everything up.
 */
function hamrang_init() {
	load_plugin_textdomain( 'hamrang-watermark', false, dirname( HAMRANG_BASENAME ) . '/languages' );
	Hamrang_Media::init();
	Hamrang_Batch::init();
	if ( is_admin() ) {
		Hamrang_Admin::init();
	}
}
add_action( 'init', 'hamrang_init', 5 );

/**
 * Requirements check on activation: PHP version + GD.
 */
function hamrang_activate() {
	if ( version_compare( PHP_VERSION, HAMRANG_MIN_PHP, '<' ) ) {
		deactivate_plugins( HAMRANG_BASENAME );
		wp_die(
			/* translators: 1: required PHP version, 2: current PHP version */
			esc_html( sprintf( __( 'Hamrang needs PHP %1$s or newer; this server runs PHP %2$s.', 'hamrang-watermark' ), HAMRANG_MIN_PHP, PHP_VERSION ) ),
			esc_html__( 'Hamrang could not be activated', 'hamrang-watermark' ),
			array( 'back_link' => true )
		);
	}
	if ( ! hamrang_wm_available() ) {
		deactivate_plugins( HAMRANG_BASENAME );
		wp_die(
			esc_html__( 'Hamrang needs the PHP GD image library, which is not enabled on this server. Ask your host to enable the gd extension and try again.', 'hamrang-watermark' ),
			esc_html__( 'Hamrang could not be activated', 'hamrang-watermark' ),
			array( 'back_link' => true )
		);
	}
	// Store defaults once so the settings page shows real values from the start.
	if ( false === get_option( HAMRANG_OPTION, false ) ) {
		add_option( HAMRANG_OPTION, Hamrang_Settings::defaults(), '', 'no' );
	}
	set_transient( 'hamrang_activated', 1, 60 );
}
register_activation_hook( __FILE__, 'hamrang_activate' );

/**
 * Convenience accessor used by templates/other plugins: current settings array.
 *
 * @return array
 */
function hamrang_settings() {
	return Hamrang_Settings::get();
}

/**
 * Public API: watermark one attachment with the saved settings.
 *
 * @param int    $attachment_id Attachment ID.
 * @param bool   $force         Re-apply even if the image is already up to date.
 * @return array                { status: applied|skipped|error, message, code }
 */
function hamrang_apply( $attachment_id, $force = false ) {
	return Hamrang_Media::apply( (int) $attachment_id, 'api', (bool) $force );
}

/**
 * Public API: restore the untouched original of an attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return array             { status: restored|skipped|error, message, code }
 */
function hamrang_restore( $attachment_id ) {
	return Hamrang_Media::restore( (int) $attachment_id );
}
