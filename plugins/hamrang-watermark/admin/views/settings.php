<?php
/**
 * Settings page.
 *
 * @var array $settings
 * @var array $ranges
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hamrang_has_logo   = Hamrang_Settings::has_logo( $settings );
$hamrang_post_types = Hamrang_Settings::post_type_choices();
$hamrang_custom     = 'auto' !== $settings['plate_color'];
$hamrang_stamped    = Hamrang_Media::count_stamped();
$hamrang_gd         = hamrang_wm_available();
$hamrang_webp       = function_exists( 'imagewebp' );
$hamrang_positions  = hamrang_wm_positions();
?>
<div class="wrap hamrang-wrap">
	<h1 class="hamrang-title">
		<img src="<?php echo esc_url( HAMRANG_URL . 'assets/brand/hamrang-mark-128.png' ); ?>" alt="" width="40" height="40" />
		<span><?php esc_html_e( 'Hamrang', 'hamrang-watermark' ); ?> <small><?php esc_html_e( 'Smart cover watermark', 'hamrang-watermark' ); ?></small></span>
		<code class="hamrang-version"><?php echo esc_html( HAMRANG_VERSION ); ?></code>
	</h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'hamrang-watermark' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! $hamrang_has_logo ) : ?>
		<div class="notice notice-warning hamrang-inline-notice"><p><?php esc_html_e( 'No logo selected yet. Pick a transparent PNG below — a white logo works best on the colour plate.', 'hamrang-watermark' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hamrang-form" class="hamrang-layout">
		<input type="hidden" name="action" value="hamrang_save_settings" />
		<?php wp_nonce_field( 'hamrang_save_settings' ); ?>

		<div class="hamrang-main">

			<!-- ============================================================ general -->
			<section class="hamrang-card">
				<h2><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'When to watermark', 'hamrang-watermark' ); ?></h2>

				<div class="hamrang-row hamrang-row-switch">
					<label class="hamrang-switch">
						<input type="checkbox" name="hamrang[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
						<span class="hamrang-switch-track"></span>
						<strong><?php esc_html_e( 'Hamrang is enabled', 'hamrang-watermark' ); ?></strong>
					</label>
					<p class="description"><?php esc_html_e( 'Master switch. When off, nothing is watermarked — not even from the batch tool.', 'hamrang-watermark' ); ?></p>
				</div>

				<div class="hamrang-row">
					<span class="hamrang-label"><?php esc_html_e( 'Apply automatically to', 'hamrang-watermark' ); ?></span>
					<div class="hamrang-choices hamrang-mode">
						<label class="hamrang-choice">
							<input type="radio" name="hamrang[apply_mode]" value="featured" <?php checked( 'featured', $settings['apply_mode'] ); ?> />
							<span class="hamrang-choice-body">
								<strong><?php esc_html_e( 'Featured images', 'hamrang-watermark' ); ?></strong>
								<small><?php esc_html_e( 'The moment an image is set as the featured image of a post (editor, REST API, importers, bots). Recommended for covers.', 'hamrang-watermark' ); ?></small>
							</span>
						</label>
						<label class="hamrang-choice">
							<input type="radio" name="hamrang[apply_mode]" value="all" <?php checked( 'all', $settings['apply_mode'] ); ?> />
							<span class="hamrang-choice-body">
								<strong><?php esc_html_e( 'Every new image upload', 'hamrang-watermark' ); ?></strong>
								<small><?php esc_html_e( 'JPEG, PNG and WebP uploads are stamped before the thumbnails are generated.', 'hamrang-watermark' ); ?></small>
							</span>
						</label>
						<label class="hamrang-choice">
							<input type="radio" name="hamrang[apply_mode]" value="manual" <?php checked( 'manual', $settings['apply_mode'] ); ?> />
							<span class="hamrang-choice-body">
								<strong><?php esc_html_e( 'Nothing automatically', 'hamrang-watermark' ); ?></strong>
								<small><?php esc_html_e( 'Only when you use the batch tool or the buttons in the media library.', 'hamrang-watermark' ); ?></small>
							</span>
						</label>
					</div>
				</div>

				<div class="hamrang-row hamrang-when-featured">
					<span class="hamrang-label"><?php esc_html_e( 'Post types', 'hamrang-watermark' ); ?></span>
					<div class="hamrang-checks">
						<?php foreach ( $hamrang_post_types as $hamrang_slug => $hamrang_label ) : ?>
							<label><input type="checkbox" name="hamrang[post_types][]" value="<?php echo esc_attr( $hamrang_slug ); ?>" <?php checked( in_array( $hamrang_slug, (array) $settings['post_types'], true ) ); ?> /> <?php echo esc_html( $hamrang_label ); ?></label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Leave everything unchecked to cover all post types.', 'hamrang-watermark' ); ?></p>
				</div>

				<div class="hamrang-row">
					<label><input type="checkbox" name="hamrang[skip_transparent]" value="1" <?php checked( $settings['skip_transparent'] ); ?> /> <?php esc_html_e( 'Leave transparent PNGs alone (logos, icons, stickers)', 'hamrang-watermark' ); ?></label>
					<p class="description"><?php esc_html_e( 'Applies to automatic processing and the batch tool; "Re-apply" on a single image still stamps it.', 'hamrang-watermark' ); ?></p>
				</div>

				<div class="hamrang-row">
					<label><input type="checkbox" name="hamrang[keep_original]" value="1" <?php checked( $settings['keep_original'] ); ?> /> <?php esc_html_e( 'Keep the untouched original next to each processed image (lets you restore or re-style later)', 'hamrang-watermark' ); ?></label>
					<p class="description"><?php esc_html_e( 'Strongly recommended. Without it a watermark cannot be removed or re-applied cleanly.', 'hamrang-watermark' ); ?></p>
				</div>

				<div class="hamrang-grid-2">
					<div class="hamrang-row">
						<label class="hamrang-label" for="hamrang-min_cover"><?php esc_html_e( 'Skip images smaller than', 'hamrang-watermark' ); ?></label>
						<?php Hamrang_Admin::range_field( 'min_cover', $settings, $ranges, 'px' ); ?>
						<p class="description"><?php esc_html_e( 'Shorter side in pixels. Thumbnails and icons below this size are never touched.', 'hamrang-watermark' ); ?></p>
					</div>
					<div class="hamrang-row">
						<label class="hamrang-label" for="hamrang-jpeg_quality"><?php esc_html_e( 'JPEG / WebP quality', 'hamrang-watermark' ); ?></label>
						<?php Hamrang_Admin::range_field( 'jpeg_quality', $settings, $ranges, '' ); ?>
					</div>
				</div>
			</section>

			<!-- ============================================================ logo -->
			<section class="hamrang-card">
				<h2><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Logo', 'hamrang-watermark' ); ?></h2>

				<div class="hamrang-grid-2">
					<div class="hamrang-row">
						<span class="hamrang-label"><?php esc_html_e( 'Logo (transparent PNG, preferably white)', 'hamrang-watermark' ); ?></span>
						<?php Hamrang_Admin::logo_field( 'logo', $settings['logo'] ); ?>
					</div>
					<div class="hamrang-row">
						<span class="hamrang-label"><?php esc_html_e( 'Dark variant (optional)', 'hamrang-watermark' ); ?></span>
						<?php Hamrang_Admin::logo_field( 'logo_dark', $settings['logo_dark'] ); ?>
						<p class="description"><?php esc_html_e( 'Used automatically on light plates and light corners so the logo always stays readable.', 'hamrang-watermark' ); ?></p>
					</div>
				</div>

				<div class="hamrang-grid-2">
					<div class="hamrang-row">
						<span class="hamrang-label"><?php esc_html_e( 'Corner', 'hamrang-watermark' ); ?></span>
						<div class="hamrang-corners">
							<?php foreach ( $hamrang_positions as $hamrang_key => $hamrang_label ) : ?>
								<label class="hamrang-corner hamrang-corner-<?php echo esc_attr( $hamrang_key ); ?>" title="<?php echo esc_attr( $hamrang_label ); ?>">
									<input type="radio" name="hamrang[position]" value="<?php echo esc_attr( $hamrang_key ); ?>" <?php checked( $hamrang_key, $settings['position'] ); ?> />
									<span></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<div>
						<div class="hamrang-row">
							<label class="hamrang-label" for="hamrang-width"><?php esc_html_e( 'Logo width', 'hamrang-watermark' ); ?></label>
							<?php Hamrang_Admin::range_field( 'width', $settings, $ranges, '%' ); ?>
							<p class="description"><?php esc_html_e( 'Percent of the image width (free logo and rectangular plate).', 'hamrang-watermark' ); ?></p>
						</div>
						<div class="hamrang-row">
							<label class="hamrang-label" for="hamrang-margin"><?php esc_html_e( 'Distance from the edges', 'hamrang-watermark' ); ?></label>
							<?php Hamrang_Admin::range_field( 'margin', $settings, $ranges, '%' ); ?>
						</div>
					</div>
				</div>

				<div class="hamrang-grid-2">
					<div class="hamrang-row">
						<label><input type="checkbox" name="hamrang[shadow]" value="1" <?php checked( $settings['shadow'] ); ?> /> <?php esc_html_e( 'Soft shadow under the logo / plate', 'hamrang-watermark' ); ?></label>
						<div class="hamrang-sub hamrang-when-free">
							<label class="hamrang-label" for="hamrang-shadow_opacity"><?php esc_html_e( 'Shadow strength (no plate)', 'hamrang-watermark' ); ?></label>
							<?php Hamrang_Admin::range_field( 'shadow_opacity', $settings, $ranges, '%' ); ?>
						</div>
					</div>
					<div class="hamrang-row hamrang-when-free">
						<label class="hamrang-label" for="hamrang-opacity"><?php esc_html_e( 'Logo opacity (no plate)', 'hamrang-watermark' ); ?></label>
						<?php Hamrang_Admin::range_field( 'opacity', $settings, $ranges, '%' ); ?>
					</div>
				</div>
			</section>

			<!-- ============================================================ plate -->
			<section class="hamrang-card">
				<h2><span class="dashicons dashicons-art"></span> <?php esc_html_e( 'Colour plate', 'hamrang-watermark' ); ?></h2>
				<p class="description hamrang-lead"><?php esc_html_e( 'The plate is a rounded badge behind the logo. In "auto colour" mode it takes the boldest colour of each image, so the badge always looks like it belongs to the cover.', 'hamrang-watermark' ); ?></p>

				<div class="hamrang-row">
					<span class="hamrang-label"><?php esc_html_e( 'Plate shape', 'hamrang-watermark' ); ?></span>
					<div class="hamrang-choices hamrang-inline">
						<label class="hamrang-pill"><input type="radio" name="hamrang[plate]" value="auto" <?php checked( 'auto', $settings['plate'] ); ?> /><span><?php esc_html_e( 'Auto (square or rectangle, by logo shape)', 'hamrang-watermark' ); ?></span></label>
						<label class="hamrang-pill"><input type="radio" name="hamrang[plate]" value="square" <?php checked( 'square', $settings['plate'] ); ?> /><span><?php esc_html_e( 'Square', 'hamrang-watermark' ); ?></span></label>
						<label class="hamrang-pill"><input type="radio" name="hamrang[plate]" value="rect" <?php checked( 'rect', $settings['plate'] ); ?> /><span><?php esc_html_e( 'Rectangle', 'hamrang-watermark' ); ?></span></label>
						<label class="hamrang-pill"><input type="radio" name="hamrang[plate]" value="none" <?php checked( 'none', $settings['plate'] ); ?> /><span><?php esc_html_e( 'No plate (logo only)', 'hamrang-watermark' ); ?></span></label>
					</div>
				</div>

				<div class="hamrang-when-plate">
					<div class="hamrang-grid-2">
						<div class="hamrang-row">
							<span class="hamrang-label"><?php esc_html_e( 'Style', 'hamrang-watermark' ); ?></span>
							<div class="hamrang-choices hamrang-inline">
								<label class="hamrang-pill"><input type="radio" name="hamrang[plate_style]" value="glass" <?php checked( 'glass', $settings['plate_style'] ); ?> /><span><?php esc_html_e( 'Frosted glass', 'hamrang-watermark' ); ?></span></label>
								<label class="hamrang-pill"><input type="radio" name="hamrang[plate_style]" value="solid" <?php checked( 'solid', $settings['plate_style'] ); ?> /><span><?php esc_html_e( 'Solid', 'hamrang-watermark' ); ?></span></label>
							</div>
						</div>
						<div class="hamrang-row">
							<span class="hamrang-label"><?php esc_html_e( 'Colour', 'hamrang-watermark' ); ?></span>
							<div class="hamrang-choices hamrang-inline">
								<label class="hamrang-pill"><input type="radio" name="hamrang[plate_color]" value="auto" <?php checked( ! $hamrang_custom ); ?> /><span><?php esc_html_e( 'Auto — dominant colour of the image', 'hamrang-watermark' ); ?></span></label>
								<label class="hamrang-pill"><input type="radio" name="hamrang[plate_color]" value="custom" <?php checked( $hamrang_custom ); ?> /><span><?php esc_html_e( 'Fixed colour', 'hamrang-watermark' ); ?></span></label>
							</div>
							<div class="hamrang-color-wrap hamrang-when-custom">
								<input type="text" class="hamrang-color" name="hamrang[plate_color_hex]" value="<?php echo esc_attr( $hamrang_custom ? $settings['plate_color'] : '#1e1e24' ); ?>" data-default-color="#1e1e24" />
							</div>
						</div>
					</div>

					<div class="hamrang-grid-2">
						<div class="hamrang-row">
							<label class="hamrang-label" for="hamrang-plate_size"><?php esc_html_e( 'Square plate size', 'hamrang-watermark' ); ?></label>
							<?php Hamrang_Admin::range_field( 'plate_size', $settings, $ranges, '%' ); ?>
							<p class="description"><?php esc_html_e( 'Percent of the image width. The rectangular plate follows the logo width instead.', 'hamrang-watermark' ); ?></p>
						</div>
						<div class="hamrang-row">
							<label class="hamrang-label" for="hamrang-plate_opacity"><?php esc_html_e( 'Plate fill', 'hamrang-watermark' ); ?></label>
							<?php Hamrang_Admin::range_field( 'plate_opacity', $settings, $ranges, '%' ); ?>
							<p class="description"><?php esc_html_e( '100 = opaque. Lower values let the blurred image show through the glass.', 'hamrang-watermark' ); ?></p>
						</div>
						<div class="hamrang-row">
							<label class="hamrang-label" for="hamrang-plate_radius"><?php esc_html_e( 'Corner roundness', 'hamrang-watermark' ); ?></label>
							<?php Hamrang_Admin::range_field( 'plate_radius', $settings, $ranges, '%' ); ?>
						</div>
						<div class="hamrang-row">
							<label class="hamrang-label" for="hamrang-plate_padding"><?php esc_html_e( 'Padding around the logo', 'hamrang-watermark' ); ?></label>
							<?php Hamrang_Admin::range_field( 'plate_padding', $settings, $ranges, '%' ); ?>
						</div>
					</div>
				</div>
			</section>

			<!-- ============================================================ advanced -->
			<section class="hamrang-card hamrang-card-muted">
				<h2><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Housekeeping', 'hamrang-watermark' ); ?></h2>
				<div class="hamrang-row">
					<label><input type="checkbox" name="hamrang[uninstall_cleanup]" value="1" <?php checked( $settings['uninstall_cleanup'] ); ?> /> <?php esc_html_e( 'When the plugin is deleted, also remove its data (settings, per-image records and stored originals). Watermarked images stay as they are.', 'hamrang-watermark' ); ?></label>
					<p class="description"><?php esc_html_e( 'Tip: run "Restore originals" in the batch tool first if you want your untouched images back before uninstalling.', 'hamrang-watermark' ); ?></p>
				</div>
			</section>

			<p class="submit">
				<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save settings', 'hamrang-watermark' ); ?></button>
				<a class="button button-hero" href="<?php echo esc_url( add_query_arg( 'page', Hamrang_Admin::SLUG_BATCH, admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Batch processing →', 'hamrang-watermark' ); ?></a>
			</p>
		</div>

		<aside class="hamrang-side">
			<div class="hamrang-card hamrang-preview-card">
				<h2><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Live preview', 'hamrang-watermark' ); ?></h2>
				<div class="hamrang-preview-stage">
					<img id="hamrang-preview-img" src="<?php echo esc_url( HAMRANG_URL . 'assets/samples/sample-1.jpg' ); ?>" alt="" />
					<span class="spinner"></span>
				</div>
				<p class="hamrang-preview-note description"><?php esc_html_e( 'Change any option — the preview updates with the unsaved values.', 'hamrang-watermark' ); ?></p>
				<div class="hamrang-samples">
					<input type="hidden" id="hamrang-sample" value="sample-1" />
					<?php for ( $hamrang_i = 1; $hamrang_i <= 3; $hamrang_i++ ) : ?>
						<button type="button" class="hamrang-sample<?php echo 1 === $hamrang_i ? ' is-active' : ''; ?>" data-sample="sample-<?php echo (int) $hamrang_i; ?>">
							<img src="<?php echo esc_url( HAMRANG_URL . 'assets/samples/sample-' . $hamrang_i . '.jpg' ); ?>" alt="" />
						</button>
					<?php endfor; ?>
					<button type="button" class="hamrang-sample hamrang-sample-pick" id="hamrang-pick-sample" title="<?php esc_attr_e( 'Preview on one of your own images', 'hamrang-watermark' ); ?>">
						<span class="dashicons dashicons-plus-alt2"></span>
					</button>
				</div>
			</div>

			<div class="hamrang-card hamrang-status-card">
				<h2><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'Status', 'hamrang-watermark' ); ?></h2>
				<ul class="hamrang-status">
					<li><span class="hamrang-dot <?php echo $hamrang_gd ? 'is-ok' : 'is-bad'; ?>"></span> <?php esc_html_e( 'GD image library', 'hamrang-watermark' ); ?>: <?php echo $hamrang_gd ? esc_html__( 'available', 'hamrang-watermark' ) : esc_html__( 'missing', 'hamrang-watermark' ); ?></li>
					<li><span class="hamrang-dot <?php echo $hamrang_webp ? 'is-ok' : 'is-warn'; ?>"></span> <?php esc_html_e( 'WebP support', 'hamrang-watermark' ); ?>: <?php echo $hamrang_webp ? esc_html__( 'yes', 'hamrang-watermark' ) : esc_html__( 'no', 'hamrang-watermark' ); ?></li>
					<li><span class="hamrang-dot is-ok"></span> PHP <?php echo esc_html( PHP_VERSION ); ?> · <?php esc_html_e( 'memory', 'hamrang-watermark' ); ?> <?php echo esc_html( ini_get( 'memory_limit' ) ); ?></li>
					<li><span class="hamrang-dot <?php echo $hamrang_has_logo ? 'is-ok' : 'is-warn'; ?>"></span> <?php esc_html_e( 'Logo', 'hamrang-watermark' ); ?>: <?php echo $hamrang_has_logo ? esc_html__( 'selected', 'hamrang-watermark' ) : esc_html__( 'not selected', 'hamrang-watermark' ); ?></li>
					<li><span class="hamrang-dot is-ok"></span>
						<?php
						/* translators: %s: number of images */
						echo esc_html( sprintf( _n( '%s image carries the watermark', '%s images carry the watermark', $hamrang_stamped, 'hamrang-watermark' ), number_format_i18n( $hamrang_stamped ) ) );
						?>
					</li>
				</ul>
				<p class="description"><?php esc_html_e( 'Changed the logo or the look? Already-stamped images keep the old look until you run the batch tool (up-to-date ones are skipped automatically).', 'hamrang-watermark' ); ?></p>
			</div>
		</aside>
	</form>
</div>
