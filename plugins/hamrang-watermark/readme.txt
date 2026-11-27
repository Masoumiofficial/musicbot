=== Hamrang – Smart Cover Watermark ===
Contributors: ahangban
Tags: watermark, featured image, logo, media library, covers
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Puts your logo on featured images and uploads inside a frosted-glass plate that takes the dominant colour of each picture. Originals are kept and can be restored any time.

== Description ==

Hamrang (Persian for "same colour") is a watermark plugin built for cover art: music, video, products, magazines.
Instead of a translucent logo dropped onto the photo, it draws a small **frosted-glass plate** behind your logo whose colour is taken from the **boldest dominant colour of that very image** — so the badge always looks like part of the cover.

**Highlights**

* Adaptive plate colour per image (or a fixed colour), glass or solid, square / rectangular / none.
* Automatic dark-logo variant on bright plates and bright corners.
* Applies automatically to **featured images** (editor, REST API, importers, bots), to **every upload**, or only on demand.
* Keeps the **untouched original** next to each processed file — restore or re-style at any time.
* Rebuilds every thumbnail size from the watermarked image.
* **Batch tool** with progress for the images you already have; skips up-to-date images, so it can be re-run safely.
* Media-library column, row actions, bulk actions and buttons in the attachment details.
* **Live preview** of every option on sample images or your own images.
* Theme-independent; GD only (no Imagick needed); PHP 7.4 – 8.5; no external requests.
* Persian (fa_IR) translation and an in-dashboard guide (Persian/English).

**Developers**

* `hamrang_apply( $attachment_id, $force = false )`, `hamrang_restore( $attachment_id )`, `hamrang_settings()`
* Filter `hamrang_should_apply( $apply, $attachment_id, $context )`
* Request parameter `hamrang_skip=1` bypasses stamping in "every upload" mode
* Attachment meta `_hamrang_wm` (stamp, dimensions, plate info) and `_hamrang_original` (stored original)

== Installation ==

1. Upload the `hamrang-watermark` folder to `/wp-content/plugins/` (or upload the zip from *Plugins → Add New → Upload*).
2. Activate the plugin. It checks for PHP 7.4+ and the GD extension.
3. Open **Hamrang → Settings**, pick a transparent PNG logo, choose when to apply, save.
4. Run **Hamrang → Batch processing** for the images you already have.

== Frequently Asked Questions ==

= Which image formats are processed? =
JPEG, PNG and WebP (and AVIF where PHP supports it). Animated GIF and SVG are left alone.

= Can I remove the watermark later? =
Yes — as long as "Keep the untouched original" was on when the image was stamped. Use *Restore original* on an image, the bulk action, or the batch tool.

= I changed my logo. What happens to the old images? =
They are marked "watermarked (older settings)". Run *Batch processing → Apply watermark*: stale images are restored and re-stamped, up-to-date ones are skipped.

= Does it send my images anywhere? =
No. Everything runs on your own server with PHP GD.

== Changelog ==

= 1.0.0 =
* First release.
