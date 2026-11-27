<?php
/**
 * Batch processing page.
 *
 * @var array $settings
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hamrang_has_logo = Hamrang_Settings::has_logo( $settings );
$hamrang_stamped  = Hamrang_Media::count_stamped();
?>
<div class="wrap hamrang-wrap">
	<h1 class="hamrang-title">
		<img src="<?php echo esc_url( HAMRANG_URL . 'assets/brand/hamrang-mark-128.png' ); ?>" alt="" width="40" height="40" />
		<span><?php esc_html_e( 'Batch processing', 'hamrang-watermark' ); ?> <small><?php esc_html_e( 'Watermark or restore the images you already have', 'hamrang-watermark' ); ?></small></span>
	</h1>

	<?php if ( empty( $settings['enabled'] ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'Hamrang is switched off in its settings, so nothing can be applied right now.', 'hamrang-watermark' ); ?></p></div>
	<?php elseif ( ! $hamrang_has_logo ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: settings URL */
					__( 'No logo selected yet — <a href="%s">choose one in the settings</a> before running the batch.', 'hamrang-watermark' ),
					esc_url( Hamrang_Admin::url() )
				)
			);
			?>
		</p></div>
	<?php endif; ?>

	<div class="hamrang-layout">
		<div class="hamrang-main">
			<section class="hamrang-card" id="hamrang-batch-setup">
				<h2><span class="dashicons dashicons-images-alt2"></span> <?php esc_html_e( '1. Which images?', 'hamrang-watermark' ); ?></h2>
				<div class="hamrang-choices hamrang-mode">
					<label class="hamrang-choice">
						<input type="radio" name="hamrang_scope" value="featured" checked />
						<span class="hamrang-choice-body"><strong><?php esc_html_e( 'Featured images only', 'hamrang-watermark' ); ?></strong><small><?php esc_html_e( 'Every image that is the featured image of at least one post.', 'hamrang-watermark' ); ?></small></span>
					</label>
					<label class="hamrang-choice">
						<input type="radio" name="hamrang_scope" value="all" />
						<span class="hamrang-choice-body"><strong><?php esc_html_e( 'Entire media library', 'hamrang-watermark' ); ?></strong><small><?php esc_html_e( 'All JPEG, PNG and WebP attachments.', 'hamrang-watermark' ); ?></small></span>
					</label>
					<label class="hamrang-choice">
						<input type="radio" name="hamrang_scope" value="unstamped" />
						<span class="hamrang-choice-body"><strong><?php esc_html_e( 'Never watermarked', 'hamrang-watermark' ); ?></strong><small><?php esc_html_e( 'Images Hamrang has not touched yet.', 'hamrang-watermark' ); ?></small></span>
					</label>
					<label class="hamrang-choice">
						<input type="radio" name="hamrang_scope" value="stamped" />
						<span class="hamrang-choice-body"><strong><?php esc_html_e( 'Already watermarked', 'hamrang-watermark' ); ?></strong><small>
							<?php
							/* translators: %s: number of images */
							echo esc_html( sprintf( __( 'Currently %s images. Use this with "Restore originals" or "Re-apply".', 'hamrang-watermark' ), number_format_i18n( $hamrang_stamped ) ) );
							?>
						</small></span>
					</label>
				</div>

				<h2><span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( '2. What to do', 'hamrang-watermark' ); ?></h2>
				<div class="hamrang-batch-buttons">
					<button type="button" class="button button-primary button-hero hamrang-start" data-task="apply" <?php disabled( ! $hamrang_has_logo || empty( $settings['enabled'] ) ); ?>><?php esc_html_e( 'Apply watermark', 'hamrang-watermark' ); ?></button>
					<button type="button" class="button button-hero hamrang-start" data-task="force" <?php disabled( ! $hamrang_has_logo || empty( $settings['enabled'] ) ); ?>><?php esc_html_e( 'Re-apply to all', 'hamrang-watermark' ); ?></button>
					<button type="button" class="button button-hero hamrang-start hamrang-danger" data-task="restore"><?php esc_html_e( 'Restore originals', 'hamrang-watermark' ); ?></button>
					<button type="button" class="button button-hero hamrang-stop" disabled><?php esc_html_e( 'Stop', 'hamrang-watermark' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( '"Apply" skips images that already carry the current watermark and refreshes the ones stamped with older settings. "Re-apply" redoes every selected image. "Restore" puts the stored originals back and rebuilds their thumbnails.', 'hamrang-watermark' ); ?></p>
				<p class="description"><?php esc_html_e( 'Keep this tab open while it runs. You can stop at any time and continue later — nothing is processed twice.', 'hamrang-watermark' ); ?></p>
			</section>

			<section class="hamrang-card" id="hamrang-batch-progress" hidden>
				<h2><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Progress', 'hamrang-watermark' ); ?></h2>
				<div class="hamrang-progress"><div class="hamrang-progress-bar" style="width:0%"></div></div>
				<p class="hamrang-progress-text"></p>
				<ul class="hamrang-counters">
					<li class="is-applied"><strong>0</strong> <span><?php esc_html_e( 'applied', 'hamrang-watermark' ); ?></span></li>
					<li class="is-restored"><strong>0</strong> <span><?php esc_html_e( 'restored', 'hamrang-watermark' ); ?></span></li>
					<li class="is-skipped"><strong>0</strong> <span><?php esc_html_e( 'skipped', 'hamrang-watermark' ); ?></span></li>
					<li class="is-error"><strong>0</strong> <span><?php esc_html_e( 'errors', 'hamrang-watermark' ); ?></span></li>
				</ul>
				<ul class="hamrang-log"></ul>
			</section>
		</div>

		<aside class="hamrang-side">
			<div class="hamrang-card">
				<h2><span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e( 'Good to know', 'hamrang-watermark' ); ?></h2>
				<ul class="hamrang-tips">
					<li><?php esc_html_e( 'Each image is processed on the server in small groups; a few thousand covers take a while — just leave the tab open.', 'hamrang-watermark' ); ?></li>
					<li><?php esc_html_e( 'Thumbnails of every size are rebuilt from the watermarked image, so the whole site shows the new cover.', 'hamrang-watermark' ); ?></li>
					<li><?php esc_html_e( 'If a CDN or cache plugin is in front of your site, purge its image cache afterwards.', 'hamrang-watermark' ); ?></li>
					<li><?php esc_html_e( 'Restoring needs the stored original ("Keep the untouched original" option). Images stamped without it cannot be reverted.', 'hamrang-watermark' ); ?></li>
				</ul>
			</div>
		</aside>
	</div>
</div>
