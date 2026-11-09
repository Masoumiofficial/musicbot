<?php
/**
 * English guide (shown when the dashboard language is not Persian).
 *
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hamrang_settings_url = Hamrang_Admin::url();
$hamrang_batch_url    = add_query_arg( 'page', Hamrang_Admin::SLUG_BATCH, admin_url( 'admin.php' ) );
?>
<div class="wrap hamrang-wrap">
	<h1 class="hamrang-title">
		<img src="<?php echo esc_url( HAMRANG_URL . 'assets/brand/hamrang-mark-128.png' ); ?>" alt="" width="40" height="40" />
		<span>Hamrang guide <small>Smart watermark with a colour‑matched glass plate</small></span>
		<code class="hamrang-version"><?php echo esc_html( HAMRANG_VERSION ); ?></code>
	</h1>

	<div class="hamrang-card hamrang-guide">
		<div class="hamrang-toc">
			<strong>Contents</strong>
			<ol>
				<li><a href="#h-what">What Hamrang does</a></li>
				<li><a href="#h-install">Requirements</a></li>
				<li><a href="#h-quick">Quick start</a></li>
				<li><a href="#h-logo">Preparing a logo</a></li>
				<li><a href="#h-settings">Settings</a></li>
				<li><a href="#h-batch">Batch processing</a></li>
				<li><a href="#h-media">Media library</a></li>
				<li><a href="#h-restore">Originals and restoring</a></li>
				<li><a href="#h-dev">For developers</a></li>
				<li><a href="#h-faq">FAQ</a></li>
			</ol>
		</div>

		<h2 id="h-what">1. What Hamrang does</h2>
		<p>Hamrang places your logo on images — but instead of a translucent logo dropped onto the photo, it builds a <strong>frosted glass plate</strong> whose colour is taken from the <strong>boldest dominant colour of that very image</strong>, blurs what is behind it and sets the logo cleanly in the middle. The result looks like part of the cover design on dark, bright, busy or black‑and‑white images alike.</p>
		<ul>
			<li>Automatic plate colour per image (or a fixed colour), glass or solid, square, rectangular or no plate at all.</li>
			<li>Automatic dark logo variant on bright plates/corners.</li>
			<li>The untouched original is kept next to each processed image so you can restore or re‑render later.</li>
			<li>All thumbnail sizes are rebuilt from the watermarked image.</li>
			<li>Theme‑independent; everything runs on your own server.</li>
		</ul>

		<h2 id="h-install">2. Requirements</h2>
		<table>
			<tr><th>Requirement</th><th>Minimum</th></tr>
			<tr><td>WordPress</td><td>5.8 (tested with 6.x and 7.x)</td></tr>
			<tr><td>PHP</td><td>7.4 – 8.5</td></tr>
			<tr><td>GD extension</td><td>enabled (Imagick is not needed)</td></tr>
			<tr><td>PHP memory</td><td>128 MB; 256 MB recommended for large images</td></tr>
		</table>

		<h2 id="h-quick">3. Quick start</h2>
		<ol>
			<li>Open <a href="<?php echo esc_url( $hamrang_settings_url ); ?>">Hamrang → Settings</a> and pick a transparent PNG logo (white works best). The live preview updates immediately.</li>
			<li>Choose when to apply. The default “Featured images” stamps any image the moment it becomes a post’s featured image — from the editor, the REST API, importers or bots. Save.</li>
			<li>Open <a href="<?php echo esc_url( $hamrang_batch_url ); ?>">Hamrang → Batch processing</a>, select “Featured images only” and click “Apply watermark” to process your existing library. Keep the tab open.</li>
		</ol>

		<h2 id="h-logo">4. Preparing a logo</h2>
		<ul>
			<li>Transparent PNG (transparent WebP/GIF also accepted). JPEG has no transparency and is rejected.</li>
			<li>A white version gives the best result on the coloured plate; add a dark version as “Dark variant” for bright plates/corners.</li>
			<li>500–1200 px wide is plenty; transparent padding is trimmed automatically.</li>
			<li>Square/round logos get a square plate in “Auto” mode, wide wordmarks get a rectangular one.</li>
		</ul>

		<h2 id="h-settings">5. Settings</h2>
		<h3>When to apply</h3>
		<table>
			<tr><th>Option</th><th>Meaning</th></tr>
			<tr><td>Enabled</td><td>Master switch; when off nothing is stamped, not even from the batch tool.</td></tr>
			<tr><td>Featured images</td><td>Only images set as featured image are processed. Optionally restrict the post types (none ticked = all).</td></tr>
			<tr><td>Every new upload</td><td>Each uploaded JPEG/PNG/WebP is stamped before thumbnails are generated. “Leave transparent PNGs alone” protects logos and icons; site icon, custom logo and background uploads are skipped automatically.</td></tr>
			<tr><td>Nothing automatic</td><td>Only via the batch tool or the media library buttons.</td></tr>
			<tr><td>Keep the untouched original</td><td>Stores a copy with the suffix <code>-hamrang-orig</code>. Strongly recommended — without it, restoring is impossible.</td></tr>
			<tr><td>Skip images smaller than</td><td>Images whose shorter side is below this value are never touched (default 240 px).</td></tr>
			<tr><td>JPEG / WebP quality</td><td>Output quality (default 90). PNG is lossless.</td></tr>
		</table>
		<h3>Logo</h3>
		<table>
			<tr><th>Option</th><th>Meaning</th></tr>
			<tr><td>Logo / Dark variant</td><td>Picked from the media library; uploads from this screen are never stamped.</td></tr>
			<tr><td>Corner</td><td>One of the four corners (default bottom‑right).</td></tr>
			<tr><td>Logo width</td><td>Percentage of the image width (default 15 %). With a square plate the plate size wins.</td></tr>
			<tr><td>Margin</td><td>Distance from the edges, percentage of the image width (default 3.5 %).</td></tr>
			<tr><td>Logo opacity / shadow strength</td><td>Only used without a plate; on a plate the logo is always fully opaque.</td></tr>
		</table>
		<h3>Colour plate</h3>
		<table>
			<tr><th>Option</th><th>Meaning</th></tr>
			<tr><td>Shape</td><td>Auto, square, rectangle or none.</td></tr>
			<tr><td>Style</td><td>Glass (blurred backdrop, tinted fill, thin light rim) or solid.</td></tr>
			<tr><td>Colour</td><td>Auto (boldest dominant colour of each image, clamped to a readable range) or a fixed hex colour.</td></tr>
			<tr><td>Square plate size</td><td>Side of the square plate, percentage of the image width (default 13 %).</td></tr>
			<tr><td>Plate fill</td><td>100 = fully opaque; lower lets the blurred image show through (default 72).</td></tr>
			<tr><td>Corner roundness / Padding</td><td>Rounding 0–50; padding between logo and plate edge (default 18 % of the side).</td></tr>
		</table>
		<p>The live preview renders every change on a sample image without saving; you may also preview with one of your own images (nothing on disk changes).</p>

		<h2 id="h-batch">6. Batch processing</h2>
		<p>Choose a scope (featured only, whole library, never stamped, already stamped) and a task: <strong>Apply</strong> skips up‑to‑date images and refreshes images stamped with older settings; <strong>Re‑apply</strong> redoes everything selected; <strong>Restore</strong> puts the stored originals back and rebuilds thumbnails. Images are processed in small groups with a progress bar and per‑image log; you can stop any time and continue later. Purge your cache/CDN afterwards.</p>

		<h2 id="h-media">7. Media library</h2>
		<p>The list view gets a “Hamrang” column (stamped / stamped with old settings / not stamped), row actions (apply, re‑apply, restore), bulk actions and a “Hamrang” box in the attachment details with the same buttons.</p>

		<h2 id="h-restore">8. Originals and restoring</h2>
		<p>Before the first stamp, an untouched copy named <code>file-hamrang-orig.ext</code> is stored next to the image and recorded on the attachment. Restoring copies it back, rebuilds all sizes and deletes the copy. Deleting an attachment also deletes its stored original. Images stamped while “keep original” was off cannot be restored. If you crop/rotate an image with the WordPress editor afterwards, stamp it again. Very large uploads are stamped on the <code>-scaled</code> file WordPress generates; the raw upload stays untouched.</p>

		<h2 id="h-dev">9. For developers</h2>
		<table>
			<tr><td><code>hamrang_apply( $id, $force = false )</code></td><td>Stamp an attachment with the saved settings; returns <code>status</code> (applied / skipped / error), <code>code</code>, <code>message</code>.</td></tr>
			<tr><td><code>hamrang_restore( $id )</code></td><td>Restore the original (restored / skipped / error).</td></tr>
			<tr><td><code>hamrang_settings()</code></td><td>Current settings array.</td></tr>
			<tr><td><code>hamrang_should_apply</code></td><td><code>apply_filters( 'hamrang_should_apply', true, $attachment_id, $context )</code>; context is <code>upload</code>, <code>featured</code>, <code>batch</code>, <code>manual</code> or <code>api</code>.</td></tr>
			<tr><td><code>hamrang_skip=1</code></td><td>Send it with an upload request to bypass stamping in “every upload” mode.</td></tr>
			<tr><td><code>_hamrang_wm</code>, <code>_hamrang_original</code></td><td>Attachment meta: stamp/time/dimensions/plate info, and the stored original path.</td></tr>
		</table>

		<h2 id="h-faq">10. FAQ</h2>
		<p><strong>“GD is not available”</strong> — enable the <code>gd</code> PHP extension in your hosting panel.</p>
		<p><strong>The image changed but the site still shows the old one</strong> — browser cache, caching plugin or CDN. File names are intentionally kept so links never break; purge the cache.</p>
		<p><strong>“Not enough PHP memory”</strong> — raise <code>memory_limit</code> (or <code>WP_MAX_MEMORY_LIMIT</code>) to 256M. Hamrang estimates the memory first and skips the image rather than crashing the site.</p>
		<p><strong>Why no GIF/SVG?</strong> — animated GIFs would lose their animation and SVG is not a bitmap. JPEG, PNG and WebP (and AVIF where PHP supports it) are covered.</p>
		<p><strong>Uninstall</strong> — images stay as they are. With “remove its data on uninstall” ticked, settings, records and stored originals are deleted too; restore first if you want the untouched files back.</p>
		<p><strong>Privacy</strong> — Hamrang sends nothing anywhere; all processing happens on your own server.</p>
	</div>
</div>
