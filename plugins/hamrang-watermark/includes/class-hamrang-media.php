<?php
/**
 * Attachment processing: apply / restore, upload + featured-image hooks, backups.
 *
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hamrang_Media {

	/** Mime types the engine handles safely (GIF would lose animation, SVG/BMP are not covers). */
	const SUPPORTED = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** Suffix of the untouched copy kept next to the processed file. */
	const BACKUP_SUFFIX = '-hamrang-orig';

	/** Uploads stamped in wp_handle_upload before an attachment exists: normalised path => info. */
	private static $pending = array();

	/** Uploads too large to stamp before WordPress scales them: attachment ID => true. */
	private static $deferred = array();

	/** Re-entrancy guard (our own metadata regeneration fires the same filters). */
	private static $busy = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'on_upload' ), 20, 2 );
		add_filter( 'wp_handle_sideload', array( __CLASS__, 'on_upload' ), 20, 2 );
		add_action( 'add_attachment', array( __CLASS__, 'on_add_attachment' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 99, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_thumbnail_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_thumbnail_meta' ), 10, 4 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
	}

	/* ------------------------------------------------------------------------------------------------- results */

	/**
	 * Build a result array.
	 *
	 * @param string $status  applied | restored | skipped | error.
	 * @param string $code    Machine-readable reason.
	 * @param string $message Human-readable message.
	 * @param array  $extra   Extra data.
	 * @return array
	 */
	private static function result( $status, $code, $message, array $extra = array() ) {
		return array_merge(
			array(
				'status'  => $status,
				'code'    => $code,
				'message' => $message,
			),
			$extra
		);
	}

	/**
	 * Messages for the non-engine outcomes.
	 *
	 * @param string $code Reason code.
	 * @return string
	 */
	public static function message( $code ) {
		switch ( $code ) {
			case 'already':
				return __( 'Already up to date', 'hamrang-watermark' );
			case 'disabled':
				return __( 'Hamrang is switched off in its settings', 'hamrang-watermark' );
			case 'no_logo':
				return __( 'No logo selected yet — choose one in the Hamrang settings', 'hamrang-watermark' );
			case 'not_image':
				return __( 'Not an image attachment', 'hamrang-watermark' );
			case 'unsupported':
				return __( 'Unsupported image type (JPEG, PNG and WebP are supported)', 'hamrang-watermark' );
			case 'missing_file':
				return __( 'The image file is missing on the server', 'hamrang-watermark' );
			case 'memory':
				return __( 'Not enough PHP memory for an image this large', 'hamrang-watermark' );
			case 'backup':
				return __( 'Could not create the backup copy (check the uploads folder permissions)', 'hamrang-watermark' );
			case 'stamped_no_backup':
				return __( 'Watermarked earlier with different settings and no original is stored — use "Re-apply" to stamp it anyway', 'hamrang-watermark' );
			case 'not_stamped':
				return __( 'No Hamrang watermark on this image', 'hamrang-watermark' );
			case 'no_backup':
				return __( 'No original copy is stored for this image', 'hamrang-watermark' );
			case 'restore_write':
				return __( 'The original could not be written back (check the uploads folder permissions)', 'hamrang-watermark' );
			case 'restored':
				return __( 'Original restored', 'hamrang-watermark' );
			case 'transparent':
				return __( 'Transparent PNG left alone (see the "Leave transparent PNGs alone" option)', 'hamrang-watermark' );
			case 'is_logo':
				return __( 'This image is the watermark logo itself', 'hamrang-watermark' );
			case 'busy':
				return __( 'This image is being processed by another request — try again in a moment', 'hamrang-watermark' );
			case 'filtered':
				return __( 'Skipped by a hamrang_should_apply filter', 'hamrang-watermark' );
		}
		return $code;
	}

	/* ------------------------------------------------------------------------------------------------- lock */

	/**
	 * Short per-attachment lock so two requests (batch + featured hook, double clicks…) never stamp the same
	 * file at the same time.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool              True when the lock was acquired.
	 */
	private static function lock( $attachment_id ) {
		$key = 'hamrang_lock_' . (int) $attachment_id;
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function unlock( $attachment_id ) {
		delete_transient( 'hamrang_lock_' . (int) $attachment_id );
	}

	/* ------------------------------------------------------------------------------------------------- state */

	/**
	 * Watermark state of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array { stamped: bool, current: bool, backup: bool, meta: array, original: array }
	 */
	public static function state( $attachment_id ) {
		$meta     = get_post_meta( $attachment_id, HAMRANG_META, true );
		$original = get_post_meta( $attachment_id, HAMRANG_META_ORIGINAL, true );
		$meta     = is_array( $meta ) ? $meta : array();
		$original = is_array( $original ) ? $original : array();
		$stamped  = ! empty( $meta['stamp'] );
		return array(
			'stamped'  => $stamped,
			'current'  => $stamped && Hamrang_Settings::stamp() === $meta['stamp'],
			'backup'   => ! empty( $original['file'] ) && file_exists( self::absolute( $original['file'] ) ),
			'meta'     => $meta,
			'original' => $original,
		);
	}

	/**
	 * Number of attachments carrying a Hamrang watermark.
	 *
	 * @return int
	 */
	public static function count_stamped() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", HAMRANG_META ) );
	}

	/* ------------------------------------------------------------------------------------------------- paths */

	/**
	 * Absolute path for a path stored relative to the uploads base dir.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	public static function absolute( $relative ) {
		$uploads = wp_get_upload_dir();
		return path_join( $uploads['basedir'], ltrim( (string) $relative, '/\\' ) );
	}

	/**
	 * Path relative to the uploads base dir ('' when outside of it).
	 *
	 * @param string $absolute Absolute path.
	 * @return string
	 */
	public static function relative( $absolute ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$path    = wp_normalize_path( $absolute );
		if ( 0 === strpos( $path, $base ) ) {
			return substr( $path, strlen( $base ) );
		}
		return '';
	}

	/**
	 * Is the file inside the uploads directory? (protects restore/delete from bad meta)
	 *
	 * @param string $absolute Absolute path.
	 * @return bool
	 */
	private static function inside_uploads( $absolute ) {
		return '' !== self::relative( $absolute );
	}

	/**
	 * Backup file path for an image path: photo.jpg → photo-hamrang-orig.jpg
	 *
	 * @param string $path Image path.
	 * @return string
	 */
	public static function backup_path( $path ) {
		$info = pathinfo( $path );
		$ext  = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
		return path_join( $info['dirname'], $info['filename'] . self::BACKUP_SUFFIX . $ext );
	}

	/**
	 * Is this a Hamrang backup file name?
	 *
	 * @param string $path Path or file name.
	 * @return bool
	 */
	public static function is_backup_file( $path ) {
		return (bool) preg_match( '/' . preg_quote( self::BACKUP_SUFFIX, '/' ) . '\.[a-z0-9]+$/i', (string) $path );
	}

	/* ------------------------------------------------------------------------------------------------- checks */

	/**
	 * Basic eligibility of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|string      [ 'file' => path, 'mime' => type ] or an error code.
	 */
	private static function check_attachment( $attachment_id ) {
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return 'not_image';
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED, true ) ) {
			return wp_attachment_is_image( $attachment_id ) ? 'unsupported' : 'not_image';
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return 'missing_file';
		}
		return array(
			'file' => $file,
			'mime' => $mime,
		);
	}

	/**
	 * Enough memory for the engine on an image of this size?
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return bool
	 */
	public static function memory_ok( $width, $height ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		$need = (int) ( (int) $width * (int) $height * 5 * 2.6 ) + 12 * MB_IN_BYTES;
		return ( memory_get_usage() + $need ) < $limit;
	}

	/**
	 * Does a PNG file carry transparency (alpha channel or tRNS chunk)?
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function png_has_alpha( $path ) {
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, 33 );
		if ( strlen( $head ) < 29 || 'IHDR' !== substr( $head, 12, 4 ) ) {
			fclose( $fh );
			return false;
		}
		$color_type = ord( $head[25] );
		if ( 4 === $color_type || 6 === $color_type ) {
			fclose( $fh );
			return true;
		}
		$pos   = 33;
		$found = false;
		for ( $i = 0; $i < 64; $i++ ) {
			if ( 0 !== fseek( $fh, $pos ) ) {
				break;
			}
			$chunk = fread( $fh, 8 );
			if ( strlen( $chunk ) < 8 ) {
				break;
			}
			$len  = unpack( 'N', substr( $chunk, 0, 4 ) );
			$type = substr( $chunk, 4, 4 );
			if ( 'tRNS' === $type ) {
				$found = true;
				break;
			}
			if ( 'IDAT' === $type || 'IEND' === $type ) {
				break;
			}
			$pos += 12 + (int) $len[1];
		}
		fclose( $fh );
		return $found;
	}

	/* ------------------------------------------------------------------------------------------------- core */

	/**
	 * Backup (when enabled) + stamp a file in place.
	 *
	 * @param string $path     Image path.
	 * @param array  $settings Plugin settings.
	 * @param array  $opts     Engine options.
	 * @return array { ok, skipped, code, result, backup }
	 */
	private static function stamp_file( $path, array $settings, array $opts ) {
		$backup = '';
		$fresh  = false;
		if ( ! empty( $settings['keep_original'] ) ) {
			$backup = self::backup_path( $path );
			if ( ! file_exists( $backup ) ) {
				if ( ! @copy( $path, $backup ) ) {
					return array(
						'ok'      => false,
						'skipped' => false,
						'code'    => 'backup',
						'result'  => null,
						'backup'  => '',
					);
				}
				$fresh = true;
			}
		}

		$result = hamrang_wm_apply( $path, $path, $opts );
		$ok     = ! empty( $result['ok'] ) && empty( $result['skipped'] );
		if ( ! $ok ) {
			if ( $fresh ) {
				@unlink( $backup );
			}
			$backup = '';
		}
		return array(
			'ok'      => $ok,
			'skipped' => ! empty( $result['skipped'] ),
			'code'    => $ok ? 'applied' : ( ! empty( $result['skipped'] ) ? $result['reason'] : $result['error'] ),
			'result'  => $result,
			'backup'  => $backup,
		);
	}

	/**
	 * Regenerate intermediate sizes from a (new) full-size file, keeping metadata WordPress would otherwise drop.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Full-size file the sizes derive from.
	 */
	private static function regenerate( $attachment_id, $file ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$old  = wp_get_attachment_metadata( $attachment_id );
		$old  = is_array( $old ) ? $old : array();
		$new  = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( ! is_array( $new ) || empty( $new ) ) {
			return;
		}
		foreach ( array( 'original_image', 'image_meta' ) as $key ) {
			if ( isset( $old[ $key ] ) && empty( $new[ $key ] ) ) {
				$new[ $key ] = $old[ $key ];
			}
		}
		wp_update_attachment_metadata( $attachment_id, $new );
	}

	/**
	 * Record the watermark state on the attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $result        Engine result.
	 * @param string $context       upload | featured | batch | manual | api.
	 * @param string $backup        Backup path ('' when none).
	 * @param string $target        File that was stamped.
	 */
	private static function record( $attachment_id, array $result, $context, $backup, $target ) {
		update_post_meta(
			$attachment_id,
			HAMRANG_META,
			array(
				'stamp'       => $result['stamp'],
				'time'        => time(),
				'by'          => $context,
				'width'       => (int) $result['width'],
				'height'      => (int) $result['height'],
				'logo_width'  => (int) $result['logo_width'],
				'variant'     => $result['variant'],
				'position'    => $result['position'],
				'plate'       => $result['plate'],
				'plate_style' => $result['plate_style'],
				'plate_color' => $result['plate_color'],
				'version'     => HAMRANG_VERSION,
			)
		);
		if ( '' !== $backup ) {
			update_post_meta(
				$attachment_id,
				HAMRANG_META_ORIGINAL,
				array(
					'file'   => self::relative( $backup ),
					'target' => self::relative( $target ),
					'size'   => (int) @filesize( $backup ),
					'time'   => time(),
				)
			);
		}
	}

	/**
	 * Watermark an attachment with the current settings.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context       upload | featured | batch | manual | api.
	 * @param bool   $force         Re-apply even when up to date / when no original is stored.
	 * @return array                Result (status, code, message, …).
	 */
	public static function apply( $attachment_id, $context = 'manual', $force = false ) {
		$attachment_id = (int) $attachment_id;
		$settings      = Hamrang_Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return self::result( 'skipped', 'disabled', self::message( 'disabled' ) );
		}
		$check = self::check_attachment( $attachment_id );
		if ( ! is_array( $check ) ) {
			return self::result( 'missing_file' === $check ? 'error' : 'skipped', $check, self::message( $check ) );
		}
		if ( ! Hamrang_Settings::has_logo( $settings ) ) {
			return self::result( 'error', 'no_logo', self::message( 'no_logo' ) );
		}
		if ( ! hamrang_wm_available() ) {
			return self::result( 'error', 'gd', hamrang_wm_describe( array( 'error' => 'gd' ) ) );
		}

		/**
		 * Lets code skip specific attachments.
		 *
		 * @param bool   $apply         Whether to watermark.
		 * @param int    $attachment_id Attachment ID.
		 * @param string $context       Where the request comes from.
		 */
		if ( ! apply_filters( 'hamrang_should_apply', true, $attachment_id, $context ) ) {
			return self::result( 'skipped', 'filtered', self::message( 'filtered' ) );
		}

		// Never stamp the logo files themselves.
		if ( in_array( $attachment_id, array( (int) $settings['logo'], (int) $settings['logo_dark'] ), true ) ) {
			return self::result( 'skipped', 'is_logo', self::message( 'is_logo' ) );
		}

		$opts  = Hamrang_Settings::engine_options( $settings );
		$state = self::state( $attachment_id );
		$file  = $check['file'];

		// Transparent PNGs are usually logos, icons or stickers; leave them unless explicitly forced.
		if ( ! $force && ! $state['stamped'] && 'image/png' === $check['mime'] && ! empty( $settings['skip_transparent'] ) && self::png_has_alpha( $file ) ) {
			return self::result( 'skipped', 'transparent', self::message( 'transparent' ) );
		}
		if ( $state['stamped'] && $state['current'] && ! $force ) {
			return self::result( 'skipped', 'already', self::message( 'already' ) );
		}

		if ( ! self::lock( $attachment_id ) ) {
			return self::result( 'skipped', 'busy', self::message( 'busy' ) );
		}

		if ( $state['stamped'] ) {
			if ( $state['backup'] ) {
				// Put the clean original back first so the new watermark is not stacked on the old one.
				$restored = self::restore( $attachment_id, false, false );
				if ( 'restored' !== $restored['status'] ) {
					self::unlock( $attachment_id );
					return $restored;
				}
				$file = get_attached_file( $attachment_id );
			} elseif ( ! $force ) {
				self::unlock( $attachment_id );
				return self::result( 'skipped', 'stamped_no_backup', self::message( 'stamped_no_backup' ) );
			}
		}

		$dims = @getimagesize( $file );
		if ( is_array( $dims ) && ! self::memory_ok( $dims[0], $dims[1] ) ) {
			self::unlock( $attachment_id );
			return self::result( 'error', 'memory', self::message( 'memory' ) );
		}

		self::$busy = true;
		$done       = self::stamp_file( $file, $settings, $opts );
		if ( $done['ok'] ) {
			self::regenerate( $attachment_id, $file );
			self::record( $attachment_id, $done['result'], $context, $done['backup'], $file );
			clean_post_cache( $attachment_id );
		}
		self::$busy = false;
		self::unlock( $attachment_id );

		if ( $done['ok'] ) {
			return self::result( 'applied', 'applied', hamrang_wm_describe( $done['result'] ), array( 'result' => $done['result'] ) );
		}
		if ( $done['skipped'] ) {
			return self::result( 'skipped', $done['code'], hamrang_wm_describe( $done['result'] ) );
		}
		if ( 'backup' === $done['code'] ) {
			return self::result( 'error', 'backup', self::message( 'backup' ) );
		}
		return self::result( 'error', $done['code'], hamrang_wm_describe( $done['result'] ), array( 'result' => $done['result'] ) );
	}

	/**
	 * Restore the stored original of an attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $regenerate    Rebuild the intermediate sizes afterwards (false when apply() restores internally).
	 * @param bool $lock          Take the per-attachment lock (false when the caller already holds it).
	 * @return array              Result.
	 */
	public static function restore( $attachment_id, $regenerate = true, $lock = true ) {
		$attachment_id = (int) $attachment_id;
		$state         = self::state( $attachment_id );

		if ( ! $state['stamped'] && empty( $state['original']['file'] ) ) {
			return self::result( 'skipped', 'not_stamped', self::message( 'not_stamped' ) );
		}
		if ( empty( $state['original']['file'] ) || ! $state['backup'] ) {
			return self::result( 'error', 'no_backup', self::message( 'no_backup' ) );
		}

		$backup = self::absolute( $state['original']['file'] );
		$target = ! empty( $state['original']['target'] ) ? self::absolute( $state['original']['target'] ) : get_attached_file( $attachment_id );
		if ( ! self::inside_uploads( $backup ) || ! self::inside_uploads( $target ) || ! file_exists( $backup ) ) {
			return self::result( 'error', 'no_backup', self::message( 'no_backup' ) );
		}
		if ( $lock && ! self::lock( $attachment_id ) ) {
			return self::result( 'skipped', 'busy', self::message( 'busy' ) );
		}

		$tmp = $target . '.hamrang' . wp_rand( 1000, 9999 ) . '.tmp';
		if ( ! @copy( $backup, $tmp ) || ! @filesize( $tmp ) ) {
			@unlink( $tmp );
			if ( $lock ) {
				self::unlock( $attachment_id );
			}
			return self::result( 'error', 'restore_write', self::message( 'restore_write' ) );
		}
		if ( ! @rename( $tmp, $target ) ) {
			$copied = @copy( $tmp, $target );
			@unlink( $tmp );
			if ( ! $copied ) {
				if ( $lock ) {
					self::unlock( $attachment_id );
				}
				return self::result( 'error', 'restore_write', self::message( 'restore_write' ) );
			}
		}
		@unlink( $backup );
		delete_post_meta( $attachment_id, HAMRANG_META );
		delete_post_meta( $attachment_id, HAMRANG_META_ORIGINAL );

		if ( $regenerate ) {
			self::$busy = true;
			self::regenerate( $attachment_id, get_attached_file( $attachment_id ) );
			self::$busy = false;
		}
		clean_post_cache( $attachment_id );
		if ( $lock ) {
			self::unlock( $attachment_id );
		}
		return self::result( 'restored', 'restored', self::message( 'restored' ) );
	}

	/* ------------------------------------------------------------------------------------------------- hooks */

	/**
	 * Should automatic processing run right now?
	 *
	 * @param string $mode Required apply mode.
	 * @return bool
	 */
	private static function auto_mode( $mode ) {
		$s = Hamrang_Settings::get();
		return ! empty( $s['enabled'] ) && $s['apply_mode'] === $mode && Hamrang_Settings::has_logo( $s );
	}

	/**
	 * wp_handle_upload / wp_handle_sideload: stamp new image uploads in "all uploads" mode, before WordPress
	 * creates the intermediate sizes (so every size carries the watermark and nothing is generated twice).
	 *
	 * @param array  $upload  { file, url, type } or { error }.
	 * @param string $context 'upload' | 'sideload'.
	 * @return array
	 */
	public static function on_upload( $upload, $context = 'upload' ) {
		if ( self::$busy || ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( ! in_array( $upload['type'], self::SUPPORTED, true ) || ! self::auto_mode( 'all' ) || ! hamrang_wm_available() ) {
			return $upload;
		}
		// Uploads from the Hamrang settings screen (the logo itself) and explicit opt-outs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flags only, nothing is written from them.
		if ( ! empty( $_REQUEST['hamrang_skip'] ) ) {
			return $upload;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$upload_context = isset( $_REQUEST['context'] ) ? sanitize_key( wp_unslash( $_REQUEST['context'] ) ) : '';
		if ( in_array( $upload_context, array( 'site-icon', 'custom-logo', 'custom-header', 'custom-background' ), true ) ) {
			return $upload;
		}

		$settings = Hamrang_Settings::get();
		$file     = $upload['file'];
		if ( 'image/png' === $upload['type'] && ! empty( $settings['skip_transparent'] ) && self::png_has_alpha( $file ) ) {
			return $upload;
		}
		/** This filter is documented in apply(). */
		if ( ! apply_filters( 'hamrang_should_apply', true, 0, 'upload' ) ) {
			return $upload;
		}

		$dims = @getimagesize( $file );
		if ( ! is_array( $dims ) ) {
			return $upload;
		}
		$threshold = (int) apply_filters( 'big_image_size_threshold', 2560, array( $dims[0], $dims[1] ), $file, 0 );
		if ( ( $threshold > 0 && max( $dims[0], $dims[1] ) > $threshold ) || ! self::memory_ok( $dims[0], $dims[1] ) ) {
			// Too big to handle now — WordPress will scale it first, we stamp the scaled file afterwards.
			self::$pending[ wp_normalize_path( $file ) ] = array( 'deferred' => true );
			return $upload;
		}

		$opts = Hamrang_Settings::engine_options( $settings );
		$done = self::stamp_file( $file, $settings, $opts );
		if ( $done['ok'] ) {
			self::$pending[ wp_normalize_path( $file ) ] = array(
				'deferred' => false,
				'result'   => $done['result'],
				'backup'   => $done['backup'],
				'target'   => $file,
			);
		}
		return $upload;
	}

	/**
	 * add_attachment: attach the meta of an upload stamped in on_upload(), or remember a deferred one.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_add_attachment( $attachment_id ) {
		if ( empty( self::$pending ) ) {
			return;
		}
		$file = get_attached_file( $attachment_id );
		$key  = $file ? wp_normalize_path( $file ) : '';
		if ( '' === $key || ! isset( self::$pending[ $key ] ) ) {
			return;
		}
		$info = self::$pending[ $key ];
		unset( self::$pending[ $key ] );
		if ( ! empty( $info['deferred'] ) ) {
			self::$deferred[ (int) $attachment_id ] = true;
			return;
		}
		self::record( $attachment_id, $info['result'], 'upload', $info['backup'], $info['target'] );
	}

	/**
	 * wp_generate_attachment_metadata: stamp uploads that were deferred because they were too large.
	 *
	 * @param array  $metadata      Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context       'create' | 'update'.
	 * @return array
	 */
	public static function on_generate_metadata( $metadata, $attachment_id, $context = 'create' ) {
		if ( self::$busy || 'create' !== $context || empty( self::$deferred[ (int) $attachment_id ] ) ) {
			return $metadata;
		}
		unset( self::$deferred[ (int) $attachment_id ] );
		$check = self::check_attachment( $attachment_id );
		if ( ! is_array( $check ) ) {
			return $metadata;
		}
		$settings = Hamrang_Settings::get();
		$file     = $check['file']; // the -scaled file by now
		$dims     = @getimagesize( $file );
		if ( ! is_array( $dims ) || ! self::memory_ok( $dims[0], $dims[1] ) ) {
			return $metadata;
		}
		self::$busy = true;
		$done       = self::stamp_file( $file, $settings, Hamrang_Settings::engine_options( $settings ) );
		if ( $done['ok'] ) {
			if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$new = wp_create_image_subsizes( $file, $attachment_id );
			if ( is_array( $new ) && ! empty( $new ) ) {
				foreach ( array( 'original_image', 'image_meta' ) as $key ) {
					if ( isset( $metadata[ $key ] ) && empty( $new[ $key ] ) ) {
						$new[ $key ] = $metadata[ $key ];
					}
				}
				$metadata = $new;
			}
			self::record( $attachment_id, $done['result'], 'upload', $done['backup'], $file );
		}
		self::$busy = false;
		return $metadata;
	}

	/**
	 * added_post_meta / updated_post_meta: featured-image mode.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function on_thumbnail_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( '_thumbnail_id' !== $meta_key || self::$busy ) {
			return;
		}
		$thumbnail_id = (int) $meta_value;
		if ( $thumbnail_id <= 0 || ! self::auto_mode( 'featured' ) ) {
			return;
		}
		if ( wp_is_post_revision( $object_id ) || wp_is_post_autosave( $object_id ) ) {
			return;
		}
		if ( ! Hamrang_Settings::post_type_allowed( get_post_type( $object_id ) ) ) {
			return;
		}
		try {
			self::apply( $thumbnail_id, 'featured' );
		} catch ( \Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound -- PHP 7+.
			self::$busy = false;
		}
	}

	/**
	 * delete_attachment: remove the backup copy so no orphan files are left behind.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_delete_attachment( $attachment_id ) {
		$original = get_post_meta( $attachment_id, HAMRANG_META_ORIGINAL, true );
		if ( is_array( $original ) && ! empty( $original['file'] ) ) {
			$backup = self::absolute( $original['file'] );
			if ( self::inside_uploads( $backup ) && self::is_backup_file( $backup ) && file_exists( $backup ) ) {
				@unlink( $backup );
			}
		}
	}
}
