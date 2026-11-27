<?php
/**
 * Settings: defaults, sanitising, and mapping onto the engine options.
 *
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hamrang_Settings {

	/** @var array|null */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'          => 1,
			'apply_mode'       => 'featured', // all | featured | manual
			'post_types'       => array(),    // featured mode: empty = every public post type
			'skip_transparent' => 1,          // leave transparent PNGs alone (logos, icons…) unless forced
			'keep_original'    => 1,
			'logo'             => 0,          // attachment ID (transparent PNG, preferably white)
			'logo_dark'        => 0,          // optional dark variant
			'position'         => 'bottom-right',
			'width'            => 15,
			'margin'           => 3.5,
			'opacity'          => 85,
			'shadow'           => 1,
			'shadow_opacity'   => 45,
			'min_cover'        => 240,
			'jpeg_quality'     => 90,
			'plate'            => 'auto',     // none | auto | square | rect
			'plate_style'      => 'glass',    // glass | solid
			'plate_color'      => 'auto',     // auto | #rrggbb
			'plate_size'       => 13,
			'plate_radius'     => 10,
			'plate_opacity'    => 72,
			'plate_padding'    => 18,
			'uninstall_cleanup' => 0,
		);
	}

	/**
	 * Numeric ranges: key => [min, max, step]. Shared by the sanitiser and the settings form.
	 *
	 * @return array
	 */
	public static function ranges() {
		return array(
			'width'          => array( 3, 60, 0.5 ),
			'margin'         => array( 0, 20, 0.5 ),
			'opacity'        => array( 5, 100, 1 ),
			'shadow_opacity' => array( 0, 100, 1 ),
			'min_cover'      => array( 0, 4000, 1 ),
			'jpeg_quality'   => array( 50, 100, 1 ),
			'plate_size'     => array( 5, 50, 0.5 ),
			'plate_radius'   => array( 0, 50, 1 ),
			'plate_opacity'  => array( 10, 100, 1 ),
			'plate_padding'  => array( 5, 40, 1 ),
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null === self::$cache ) {
			$stored      = get_option( HAMRANG_OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array(), true );
		}
		return self::$cache;
	}

	/**
	 * Save settings (already sanitised by the caller or here).
	 *
	 * @param array $settings Raw settings.
	 * @return array          Clean settings that were stored.
	 */
	public static function save( array $settings ) {
		$clean = self::sanitize( $settings );
		update_option( HAMRANG_OPTION, $clean, false );
		self::$cache = $clean;
		return $clean;
	}

	/**
	 * Forget the cached copy (tests / after option updates elsewhere).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitise a settings array. Unknown keys are dropped, missing keys get defaults.
	 *
	 * @param array $input  Raw input (form data or stored option).
	 * @param bool  $merge  When true, missing keys fall back to defaults; when false (form save), missing
	 *                      checkboxes are treated as unchecked.
	 * @return array
	 */
	public static function sanitize( array $input, $merge = false ) {
		$d = self::defaults();
		$o = array();

		$bools = array( 'enabled', 'skip_transparent', 'keep_original', 'shadow', 'uninstall_cleanup' );
		foreach ( $bools as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$o[ $key ] = empty( $input[ $key ] ) || 'false' === $input[ $key ] ? 0 : 1;
			} else {
				$o[ $key ] = $merge ? $d[ $key ] : 0;
			}
		}

		$mode            = isset( $input['apply_mode'] ) ? sanitize_key( $input['apply_mode'] ) : $d['apply_mode'];
		$o['apply_mode'] = in_array( $mode, array( 'all', 'featured', 'manual' ), true ) ? $mode : $d['apply_mode'];

		$o['post_types'] = array();
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $type ) {
				$type = sanitize_key( $type );
				if ( '' !== $type ) {
					$o['post_types'][] = $type;
				}
			}
			$o['post_types'] = array_values( array_unique( $o['post_types'] ) );
		}

		foreach ( array( 'logo', 'logo_dark' ) as $key ) {
			$o[ $key ] = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : ( $merge ? (int) $d[ $key ] : 0 );
		}

		$o['position'] = hamrang_wm_position_key( isset( $input['position'] ) ? $input['position'] : $d['position'] );

		foreach ( self::ranges() as $key => $range ) {
			$raw = isset( $input[ $key ] ) && '' !== $input[ $key ] ? $input[ $key ] : $d[ $key ];
			$raw = is_numeric( $raw ) ? (float) $raw : (float) $d[ $key ];
			$raw = min( $range[1], max( $range[0], $raw ) );
			if ( $range[2] >= 1 ) {
				$raw = (int) round( $raw );
			} else {
				$raw = round( $raw, 1 );
			}
			$o[ $key ] = $raw;
		}

		$plate      = isset( $input['plate'] ) ? sanitize_key( $input['plate'] ) : $d['plate'];
		$o['plate'] = in_array( $plate, array( 'none', 'auto', 'square', 'rect' ), true ) ? $plate : $d['plate'];

		$style            = isset( $input['plate_style'] ) ? sanitize_key( $input['plate_style'] ) : $d['plate_style'];
		$o['plate_style'] = in_array( $style, array( 'glass', 'solid' ), true ) ? $style : $d['plate_style'];

		$color = isset( $input['plate_color'] ) ? strtolower( trim( (string) $input['plate_color'] ) ) : 'auto';
		if ( 'custom' === $color && isset( $input['plate_color_hex'] ) ) {
			$color = strtolower( trim( (string) $input['plate_color_hex'] ) );
		}
		$rgb              = hamrang_wm_hex_to_rgb( $color );
		$o['plate_color'] = null !== $rgb ? hamrang_wm_rgb_to_hex( $rgb ) : 'auto';

		return $o;
	}

	/**
	 * Absolute file path of a logo attachment, or '' when unusable.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function logo_path( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return '';
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}
		return $file;
	}

	/**
	 * Engine options for the given (or current) settings.
	 *
	 * @param array|null $settings Settings; null = current.
	 * @return array
	 */
	public static function engine_options( $settings = null ) {
		$s = null === $settings ? self::get() : $settings;
		return hamrang_wm_normalize_options(
			array(
				'enabled'         => ! empty( $s['enabled'] ),
				'logo'            => self::logo_path( $s['logo'] ),
				'logo_dark'       => self::logo_path( $s['logo_dark'] ),
				'position'        => $s['position'],
				'width'           => $s['width'],
				'margin'          => $s['margin'],
				'opacity'         => $s['opacity'],
				'shadow'          => ! empty( $s['shadow'] ),
				'shadow_opacity'  => $s['shadow_opacity'],
				'min_cover'       => $s['min_cover'],
				'jpeg_quality'    => $s['jpeg_quality'],
				'plate'           => $s['plate'],
				'plate_color'     => $s['plate_color'],
				'plate_size'      => $s['plate_size'],
				'plate_radius'    => $s['plate_radius'],
				'plate_opacity'   => $s['plate_opacity'],
				'plate_padding'   => $s['plate_padding'],
				'plate_style'     => $s['plate_style'],
			)
		);
	}

	/**
	 * Fingerprint of the current watermark (logo files + look). Images carrying it are up to date.
	 *
	 * @param array|null $settings Settings; null = current.
	 * @return string
	 */
	public static function stamp( $settings = null ) {
		return hamrang_wm_stamp( self::engine_options( $settings ) );
	}

	/**
	 * Is a usable logo configured?
	 *
	 * @param array|null $settings Settings; null = current.
	 * @return bool
	 */
	public static function has_logo( $settings = null ) {
		$s = null === $settings ? self::get() : $settings;
		$info = hamrang_wm_logo_info( self::logo_path( $s['logo'] ) );
		return ! empty( $info['ok'] );
	}

	/**
	 * Post types offered in the "featured images" mode.
	 *
	 * @return array slug => label
	 */
	public static function post_type_choices() {
		$out   = array();
		$types = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			if ( ! post_type_supports( $type->name, 'thumbnail' ) && 'post' !== $type->name ) {
				continue;
			}
			$out[ $type->name ] = $type->labels->singular_name;
		}
		return $out;
	}

	/**
	 * Does the featured-image mode cover this post type?
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function post_type_allowed( $post_type ) {
		$s = self::get();
		if ( empty( $s['post_types'] ) ) {
			return true;
		}
		return in_array( $post_type, $s['post_types'], true );
	}
}
