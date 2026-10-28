<?php
/**
 * Admin UI: menu, settings page with live preview, media library integration, notices.
 *
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hamrang_Admin {

	const SLUG       = 'hamrang';
	const SLUG_BATCH = 'hamrang-batch';
	const SLUG_GUIDE = 'hamrang-guide';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_media_script' ) );
		add_action( 'admin_post_hamrang_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_hamrang_single', array( __CLASS__, 'single_action' ) );
		add_action( 'wp_ajax_hamrang_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_hamrang_single', array( __CLASS__, 'ajax_single' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . HAMRANG_BASENAME, array( __CLASS__, 'action_links' ) );

		add_filter( 'media_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column_content' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_fields' ), 10, 2 );
	}

	/* ------------------------------------------------------------------------------------------------- helpers */

	/**
	 * URL of the settings page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Are we on one of the plugin screens?
	 *
	 * @param string $hook Current hook suffix.
	 * @return string      '' | settings | batch | guide
	 */
	private static function screen( $hook ) {
		$map = array(
			'toplevel_page_' . self::SLUG       => 'settings',
			'hamrang_page_' . self::SLUG_BATCH  => 'batch',
			'hamrang_page_' . self::SLUG_GUIDE  => 'guide',
		);
		if ( isset( $map[ $hook ] ) ) {
			return $map[ $hook ];
		}
		// Translated top-level titles change the hook prefix; fall back to the page query var.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG === $page ) {
			return 'settings';
		}
		if ( self::SLUG_BATCH === $page ) {
			return 'batch';
		}
		if ( self::SLUG_GUIDE === $page ) {
			return 'guide';
		}
		return '';
	}

	/**
	 * Menu icon as a data URI (coloured by WordPress to match the admin scheme).
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$svg = @file_get_contents( HAMRANG_DIR . 'assets/brand/menu-icon.svg' );
		if ( ! $svg ) {
			return 'dashicons-format-image';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Localised strings shared by the scripts.
	 *
	 * @return array
	 */
	private static function strings() {
		return array(
			'chooseLogo'     => __( 'Choose logo', 'hamrang-watermark' ),
			'useLogo'        => __( 'Use this logo', 'hamrang-watermark' ),
			'chooseSample'   => __( 'Choose a preview image', 'hamrang-watermark' ),
			'useSample'      => __( 'Use this image', 'hamrang-watermark' ),
			'previewError'   => __( 'Preview failed. Save the settings and try again.', 'hamrang-watermark' ),
			'notPng'         => __( 'The logo should be a transparent PNG.', 'hamrang-watermark' ),
			'working'        => __( 'Working…', 'hamrang-watermark' ),
			'done'           => __( 'Done', 'hamrang-watermark' ),
			'stopped'        => __( 'Stopped', 'hamrang-watermark' ),
			'nothing'        => __( 'Nothing to process with this selection.', 'hamrang-watermark' ),
			'confirmRestore' => __( 'Restore the originals of all selected images? Their watermark will be removed.', 'hamrang-watermark' ),
			'confirmForce'   => __( 'Re-apply the watermark to every selected image, even the ones already up to date?', 'hamrang-watermark' ),
			'requestError'   => __( 'The server did not answer — please try again.', 'hamrang-watermark' ),
			'applied'        => __( 'applied', 'hamrang-watermark' ),
			'skipped'        => __( 'skipped', 'hamrang-watermark' ),
			'restored'       => __( 'restored', 'hamrang-watermark' ),
			'errors'         => __( 'errors', 'hamrang-watermark' ),
			'of'             => __( 'of', 'hamrang-watermark' ),
		);
	}

	/* ------------------------------------------------------------------------------------------------- menu + assets */

	/**
	 * Admin menu.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Hamrang – Smart Cover Watermark', 'hamrang-watermark' ),
			__( 'Hamrang', 'hamrang-watermark' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_settings' ),
			self::menu_icon(),
			81
		);
		add_submenu_page( self::SLUG, __( 'Hamrang settings', 'hamrang-watermark' ), __( 'Settings', 'hamrang-watermark' ), 'manage_options', self::SLUG, array( __CLASS__, 'render_settings' ) );
		add_submenu_page( self::SLUG, __( 'Batch processing', 'hamrang-watermark' ), __( 'Batch processing', 'hamrang-watermark' ), 'manage_options', self::SLUG_BATCH, array( __CLASS__, 'render_batch' ) );
		add_submenu_page( self::SLUG, __( 'Hamrang guide', 'hamrang-watermark' ), __( 'Guide', 'hamrang-watermark' ), 'manage_options', self::SLUG_GUIDE, array( __CLASS__, 'render_guide' ) );
	}

	/**
	 * Scripts and styles.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook ) {
		$screen = self::screen( $hook );
		if ( '' === $screen ) {
			return;
		}
		wp_enqueue_style( 'hamrang-admin', HAMRANG_URL . 'admin/css/hamrang-admin.css', array(), HAMRANG_VERSION ); // uses logical properties; no separate RTL file needed

		if ( 'settings' === $screen ) {
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'hamrang-admin', HAMRANG_URL . 'admin/js/hamrang-admin.js', array( 'jquery', 'wp-color-picker', 'media-editor' ), HAMRANG_VERSION, true );
			$settings = Hamrang_Settings::get();
			wp_localize_script(
				'hamrang-admin',
				'hamrangAdmin',
				array(
					'ajax'        => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'hamrang_admin' ),
					'samples'     => array(
						'sample-1' => HAMRANG_URL . 'assets/samples/sample-1.jpg',
						'sample-2' => HAMRANG_URL . 'assets/samples/sample-2.jpg',
						'sample-3' => HAMRANG_URL . 'assets/samples/sample-3.jpg',
					),
					'hasLogo'     => Hamrang_Settings::has_logo( $settings ) ? 1 : 0,
					'i18n'        => self::strings(),
				)
			);
		} elseif ( 'batch' === $screen ) {
			wp_enqueue_script( 'hamrang-batch', HAMRANG_URL . 'admin/js/hamrang-batch.js', array( 'jquery' ), HAMRANG_VERSION, true );
			wp_localize_script(
				'hamrang-batch',
				'hamrangBatch',
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'hamrang_batch' ),
					'i18n'  => self::strings(),
				)
			);
		}
	}

	/**
	 * Small script for the attachment details panel / media list (wherever the media frame is loaded).
	 */
	public static function enqueue_media_script() {
		static $done = false;
		if ( $done || ! is_admin() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$done = true;
		wp_enqueue_style( 'hamrang-media', HAMRANG_URL . 'admin/css/hamrang-media.css', array(), HAMRANG_VERSION );
		wp_enqueue_script( 'hamrang-media', HAMRANG_URL . 'admin/js/hamrang-media.js', array( 'jquery' ), HAMRANG_VERSION, true );
		wp_localize_script(
			'hamrang-media',
			'hamrangMedia',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'hamrang_admin' ),
				'i18n'  => self::strings(),
			)
		);
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'hamrang-watermark' ) . '</a>' );
		return $links;
	}

	/* ------------------------------------------------------------------------------------------------- pages */

	/**
	 * Settings page.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Hamrang_Settings::get();
		$ranges   = Hamrang_Settings::ranges();
		include HAMRANG_DIR . 'admin/views/settings.php';
	}

	/**
	 * Batch page.
	 */
	public static function render_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Hamrang_Settings::get();
		include HAMRANG_DIR . 'admin/views/batch.php';
	}

	/**
	 * Guide page (Persian for fa locales, English otherwise).
	 */
	public static function render_guide() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$view   = 0 === strpos( $locale, 'fa' ) ? 'guide-fa.php' : 'guide-en.php';
		include HAMRANG_DIR . 'admin/views/' . $view;
	}

	/**
	 * Render a range + number pair (used by the settings view).
	 *
	 * @param string $key      Setting key.
	 * @param array  $settings Settings.
	 * @param array  $ranges   Ranges.
	 * @param string $unit     Unit label.
	 */
	public static function range_field( $key, array $settings, array $ranges, $unit = '%' ) {
		list( $min, $max, $step ) = $ranges[ $key ];
		$value = $settings[ $key ];
		printf(
			'<span class="hamrang-range"><input type="range" min="%1$s" max="%2$s" step="%3$s" value="%4$s" data-for="hamrang-%5$s" aria-hidden="true" tabindex="-1" /><input type="number" class="small-text" id="hamrang-%5$s" name="hamrang[%5$s]" min="%1$s" max="%2$s" step="%3$s" value="%4$s" /><span class="hamrang-unit">%6$s</span></span>',
			esc_attr( $min ),
			esc_attr( $max ),
			esc_attr( $step ),
			esc_attr( $value ),
			esc_attr( $key ),
			esc_html( $unit )
		);
	}

	/**
	 * Logo picker markup.
	 *
	 * @param string $key   logo | logo_dark.
	 * @param int    $value Attachment ID.
	 */
	public static function logo_field( $key, $value ) {
		$value = (int) $value;
		$src   = $value ? wp_get_attachment_image_url( $value, 'medium' ) : '';
		$name  = $value ? basename( (string) get_attached_file( $value ) ) : '';
		$dark  = 'logo_dark' === $key;
		?>
		<div class="hamrang-logo-picker<?php echo $value ? ' has-logo' : ''; ?><?php echo $dark ? ' is-dark' : ''; ?>" data-key="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="hamrang[<?php echo esc_attr( $key ); ?>]" id="hamrang-<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<div class="hamrang-logo-thumb"><?php if ( $src ) : ?><img src="<?php echo esc_url( $src ); ?>" alt="" /><?php endif; ?></div>
			<div class="hamrang-logo-actions">
				<span class="hamrang-logo-name"><?php echo esc_html( $name ); ?></span>
				<button type="button" class="button hamrang-pick-logo"><?php esc_html_e( 'Select PNG', 'hamrang-watermark' ); ?></button>
				<button type="button" class="button-link hamrang-remove-logo"><?php esc_html_e( 'Remove', 'hamrang-watermark' ); ?></button>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------------------------------------- save */

	/**
	 * Save settings (admin-post).
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hamrang-watermark' ) );
		}
		check_admin_referer( 'hamrang_save_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field in Hamrang_Settings::sanitize().
		$input = isset( $_POST['hamrang'] ) && is_array( $_POST['hamrang'] ) ? wp_unslash( $_POST['hamrang'] ) : array();
		Hamrang_Settings::save( $input );
		wp_safe_redirect( self::url( array( 'updated' => 1 ) ) );
		exit;
	}

	/* ------------------------------------------------------------------------------------------------- preview */

	/**
	 * AJAX live preview: renders the current (unsaved) form values on a sample image.
	 */
	public static function ajax_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'hamrang-watermark' ) ), 403 );
		}
		check_ajax_referer( 'hamrang_admin', 'nonce' );
		if ( ! hamrang_wm_available() ) {
			wp_send_json_error( array( 'message' => hamrang_wm_describe( array( 'error' => 'gd' ) ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		$raw      = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$settings = Hamrang_Settings::sanitize( array_merge( Hamrang_Settings::get(), $raw ), true );
		$opts     = Hamrang_Settings::engine_options( $settings );
		$opts['min_cover'] = 0; // the preview is downscaled; never skip it as "too small"

		$placeholder = false;
		if ( ! Hamrang_Settings::has_logo( $settings ) ) {
			$opts['logo']      = HAMRANG_DIR . 'assets/brand/sample-logo-white.png';
			$opts['logo_dark'] = '';
			$placeholder       = true;
		}

		$sample = isset( $_POST['sample'] ) ? sanitize_text_field( wp_unslash( $_POST['sample'] ) ) : 'sample-1';
		if ( preg_match( '/^sample-[123]$/', $sample ) ) {
			$source = HAMRANG_DIR . 'assets/samples/' . $sample . '.jpg';
		} else {
			$id     = absint( $sample );
			$source = $id && wp_attachment_is_image( $id ) ? get_attached_file( $id ) : '';
			if ( ! $source || ! file_exists( $source ) ) {
				$source = HAMRANG_DIR . 'assets/samples/sample-1.jpg';
			}
		}

		$tmp = wp_tempnam( 'hamrang-preview.jpg' );
		if ( ! $tmp ) {
			wp_send_json_error( array( 'message' => __( 'Could not create a temporary file.', 'hamrang-watermark' ) ) );
		}
		$ok = hamrang_wm_downscale( $source, $tmp, 760, 90 );
		if ( ! $ok ) {
			@unlink( $tmp );
			wp_send_json_error( array( 'message' => hamrang_wm_describe( array( 'error' => 'cover' ) ) ) );
		}
		$result = hamrang_wm_apply( $tmp, $tmp, $opts );
		$bytes  = @file_get_contents( $tmp );
		@unlink( $tmp );

		if ( false === $bytes || '' === $bytes ) {
			wp_send_json_error( array( 'message' => hamrang_wm_describe( $result ) ) );
		}
		$note = hamrang_wm_describe( $result );
		if ( $placeholder ) {
			$note = __( 'Sample logo shown — select your own logo above.', 'hamrang-watermark' ) . ' ' . $note;
		}
		wp_send_json_success(
			array(
				'image'       => 'data:image/jpeg;base64,' . base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'note'        => $note,
				'ok'          => ! empty( $result['ok'] ) && empty( $result['skipped'] ),
				'placeholder' => $placeholder,
			)
		);
	}

	/* ------------------------------------------------------------------------------------------------- single attachment */

	/**
	 * HTML snippet describing the state of an attachment (used in the media modal and the list column).
	 *
	 * @param int  $id      Attachment ID.
	 * @param bool $buttons Include action buttons.
	 * @return string
	 */
	public static function state_html( $id, $buttons = true ) {
		$state = Hamrang_Media::state( $id );
		$mime  = get_post_mime_type( $id );
		if ( ! in_array( $mime, Hamrang_Media::SUPPORTED, true ) ) {
			return '<span class="hamrang-state is-na">' . esc_html__( 'Not supported (JPEG, PNG, WebP only)', 'hamrang-watermark' ) . '</span>';
		}
		$html = '<span class="hamrang-state-wrap" data-id="' . (int) $id . '">';
		if ( $state['stamped'] ) {
			$when = ! empty( $state['meta']['time'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['meta']['time'] ) : '';
			if ( $state['current'] ) {
				$html .= '<span class="hamrang-state is-ok" title="' . esc_attr( $when ) . '">' . esc_html__( 'Watermarked', 'hamrang-watermark' ) . '</span>';
			} else {
				$html .= '<span class="hamrang-state is-stale" title="' . esc_attr( $when ) . '">' . esc_html__( 'Watermarked (older settings)', 'hamrang-watermark' ) . '</span>';
			}
			if ( ! $state['backup'] ) {
				$html .= ' <span class="hamrang-state-note">' . esc_html__( 'no original stored', 'hamrang-watermark' ) . '</span>';
			}
		} else {
			$html .= '<span class="hamrang-state is-none">' . esc_html__( 'No watermark', 'hamrang-watermark' ) . '</span>';
		}
		if ( $buttons && current_user_can( 'edit_post', $id ) ) {
			$html .= '<span class="hamrang-state-buttons">';
			if ( ! $state['stamped'] || ! $state['current'] ) {
				$html .= '<button type="button" class="button button-small hamrang-single" data-task="apply" data-id="' . (int) $id . '">' . esc_html__( 'Apply watermark', 'hamrang-watermark' ) . '</button> ';
			}
			if ( $state['stamped'] && $state['current'] ) {
				$html .= '<button type="button" class="button button-small hamrang-single" data-task="force" data-id="' . (int) $id . '">' . esc_html__( 'Re-apply', 'hamrang-watermark' ) . '</button> ';
			}
			if ( $state['backup'] ) {
				$html .= '<button type="button" class="button button-small hamrang-single" data-task="restore" data-id="' . (int) $id . '">' . esc_html__( 'Restore original', 'hamrang-watermark' ) . '</button>';
			}
			$html .= '</span><span class="hamrang-state-message"></span>';
		}
		$html .= '</span>';
		return $html;
	}

	/**
	 * Run a single task with capability checks.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $task apply | force | restore.
	 * @return array
	 */
	private static function run_single( $id, $task ) {
		if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'upload_files' ) ) {
			return array(
				'status'  => 'error',
				'code'    => 'cap',
				'message' => __( 'You are not allowed to do this.', 'hamrang-watermark' ),
			);
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'restore' === $task ) {
			return Hamrang_Media::restore( $id );
		}
		return Hamrang_Media::apply( $id, 'manual', 'force' === $task );
	}

	/**
	 * AJAX: apply / re-apply / restore one attachment.
	 */
	public static function ajax_single() {
		check_ajax_referer( 'hamrang_admin', 'nonce' );
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : 'apply';
		if ( ! $id || ! in_array( $task, array( 'apply', 'force', 'restore' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'hamrang-watermark' ) ), 400 );
		}
		$r = self::run_single( $id, $task );
		$thumb = wp_get_attachment_image_src( $id, 'medium' );
		wp_send_json(
			array(
				'success' => 'error' !== $r['status'],
				'data'    => array(
					'status'  => $r['status'],
					'message' => $r['message'],
					'html'    => self::state_html( $id ),
					'thumb'   => $thumb ? add_query_arg( 'hamrang', time(), $thumb[0] ) : '',
				),
			)
		);
	}

	/**
	 * Row action links (admin-post, no JavaScript needed).
	 */
	public static function single_action() {
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$task = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : 'apply';
		check_admin_referer( 'hamrang_single_' . $id );
		$r        = $id ? self::run_single( $id, $task ) : array( 'status' => 'error', 'message' => __( 'Invalid request.', 'hamrang-watermark' ) );
		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' );
		$redirect = remove_query_arg( array( 'hamrang_status', 'hamrang_msg' ), $redirect );
		wp_safe_redirect(
			add_query_arg(
				array(
					'hamrang_status' => $r['status'],
					'hamrang_msg'    => rawurlencode( $r['message'] ),
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Row actions in the media list.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! in_array( $post->post_mime_type, Hamrang_Media::SUPPORTED, true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$state = Hamrang_Media::state( $post->ID );
		$link  = function ( $task ) use ( $post ) {
			return wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'hamrang_single',
						'task'   => $task,
						'id'     => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'hamrang_single_' . $post->ID
			);
		};
		if ( ! $state['stamped'] || ! $state['current'] ) {
			$actions['hamrang_apply'] = '<a href="' . esc_url( $link( 'apply' ) ) . '">' . esc_html__( 'Apply watermark', 'hamrang-watermark' ) . '</a>';
		} else {
			$actions['hamrang_apply'] = '<a href="' . esc_url( $link( 'force' ) ) . '">' . esc_html__( 'Re-apply watermark', 'hamrang-watermark' ) . '</a>';
		}
		if ( $state['backup'] ) {
			$actions['hamrang_restore'] = '<a href="' . esc_url( $link( 'restore' ) ) . '">' . esc_html__( 'Restore original', 'hamrang-watermark' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Extra column in the media list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function media_column( $columns ) {
		$columns['hamrang'] = __( 'Hamrang', 'hamrang-watermark' );
		return $columns;
	}

	/**
	 * Column content.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Attachment ID.
	 */
	public static function media_column_content( $column, $id ) {
		if ( 'hamrang' !== $column ) {
			return;
		}
		if ( ! in_array( get_post_mime_type( $id ), Hamrang_Media::SUPPORTED, true ) ) {
			echo '<span class="hamrang-state is-na">—</span>';
			return;
		}
		echo wp_kses_post( self::state_html( $id, false ) );
	}

	/**
	 * Bulk actions.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions['hamrang_apply']   = __( 'Hamrang: apply watermark', 'hamrang-watermark' );
		$actions['hamrang_restore'] = __( 'Hamrang: restore originals', 'hamrang-watermark' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      IDs.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'hamrang_apply' !== $action && 'hamrang_restore' !== $action ) {
			return $redirect;
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$counts = array(
			'applied'  => 0,
			'restored' => 0,
			'skipped'  => 0,
			'error'    => 0,
		);
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			if ( ! $id ) {
				continue;
			}
			$r = self::run_single( $id, 'hamrang_apply' === $action ? 'apply' : 'restore' );
			$counts[ isset( $counts[ $r['status'] ] ) ? $r['status'] : 'error' ]++;
		}
		return add_query_arg(
			array(
				'hamrang_status' => 'bulk',
				'hamrang_msg'    => rawurlencode( wp_json_encode( $counts ) ),
			),
			remove_query_arg( array( 'hamrang_status', 'hamrang_msg' ), $redirect )
		);
	}

	/**
	 * Attachment details field (media modal + edit screen).
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public static function attachment_fields( $fields, $post ) {
		if ( ! in_array( $post->post_mime_type, Hamrang_Media::SUPPORTED, true ) ) {
			return $fields;
		}
		$fields['hamrang'] = array(
			'label' => __( 'Hamrang', 'hamrang-watermark' ),
			'input' => 'html',
			'html'  => self::state_html( $post->ID ),
		);
		return $fields;
	}

	/* ------------------------------------------------------------------------------------------------- notices */

	/**
	 * Admin notices.
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only feedback flags.
		if ( isset( $_GET['hamrang_status'], $_GET['hamrang_msg'] ) ) {
			$status = sanitize_key( wp_unslash( $_GET['hamrang_status'] ) );
			$msg    = sanitize_text_field( rawurldecode( wp_unslash( $_GET['hamrang_msg'] ) ) );
			if ( 'bulk' === $status ) {
				$counts = json_decode( $msg, true );
				if ( is_array( $counts ) ) {
					$msg = sprintf(
						/* translators: 1: applied, 2: restored, 3: skipped, 4: errors */
						__( 'Hamrang: %1$d applied, %2$d restored, %3$d skipped, %4$d errors.', 'hamrang-watermark' ),
						(int) $counts['applied'],
						(int) $counts['restored'],
						(int) $counts['skipped'],
						(int) $counts['error']
					);
					$status = (int) $counts['error'] > 0 ? 'warning' : 'success';
				}
			}
			$class = 'error' === $status ? 'notice-error' : ( 'skipped' === $status || 'warning' === $status ? 'notice-warning' : 'notice-success' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		// phpcs:enable

		if ( ! hamrang_wm_available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Hamrang: the PHP GD image library is not available, so no watermark can be applied. Ask your host to enable the gd extension.', 'hamrang-watermark' ) . '</p></div>';
			return;
		}

		$screen = get_current_screen();
		$ours   = $screen && ( false !== strpos( $screen->id, self::SLUG ) );
		if ( get_transient( 'hamrang_activated' ) && ! $ours ) {
			delete_transient( 'hamrang_activated' );
			echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL */
					__( 'Hamrang is active. <a href="%s">Choose your logo and the look of the watermark</a> to get started.', 'hamrang-watermark' ),
					esc_url( self::url() )
				)
			) . '</p></div>';
		}
	}
}
