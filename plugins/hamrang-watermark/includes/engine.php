<?php
/**
 * Hamrang – GD watermark engine (framework-agnostic, WordPress loads it as part of the plugin).
 *
 * What it does:
 *  - plate = auto (default): draws a rounded square/rectangular "plate" behind the logo whose colour is the
 *    boldest dominant colour of that very image; the logo sits solid in the middle of the plate.
 *    Greyscale images get a dark grey plate. plate_color may also be a fixed hex colour.
 *  - plate_style = glass (default): frosted plate – the image under the plate is blurred, the plate colour is
 *    laid over it with plate_opacity and a thin light rim + top sheen is added. plate_style = solid: flat plate.
 *  - plate = none: the transparent PNG logo is placed freely in the chosen corner with a soft shadow;
 *    the corner luminance is measured and the dark logo variant (if given) is used on light corners.
 *  - images smaller than min_cover are left untouched.
 *
 * Main entry points: hamrang_wm_apply( $src, $dst, $options ) and hamrang_wm_describe( $result ).
 *
 * @package Hamrang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Engine defaults (the plugin settings layer maps its own options onto these) */
function hamrang_wm_defaults() {
	return [
		'enabled'         => true,
		'logo'            => '',            // path of the transparent PNG logo (preferably white)
		'logo_dark'       => '',            // optional dark variant for light corners / light plates
		'position'        => 'bottom-right',
		'width'           => 15,            // % of the image width
		'min_width'       => 64,            // minimum logo width in px
		'max_width'       => 0,             // maximum logo width in px; 0 = no cap
		'margin'          => 3.5,           // distance from the edges, % of the image width
		'opacity'         => 85,            // % (free mode only; on a plate the logo is always solid)
		'shadow'          => true,
		'shadow_opacity'  => 45,            // % (raised automatically on light corners when no dark logo exists)
		'min_cover'       => 240,           // images whose shorter side is below this are left untouched
		'light_threshold' => 0.6,           // luminance 0..1; above = light corner
		'jpeg_quality'    => 90,
		// coloured plate behind the logo: none = free logo with shadow; auto = square for square-ish logos, rectangle for wide ones
		'plate'           => 'auto',        // none | auto | square | rect
		'plate_color'     => 'auto',        // auto = boldest dominant colour of the image, or a fixed colour like '#1e1e1e'
		'plate_size'      => 13,            // side of the square plate, % of the image width (the rectangular plate derives from the logo width + padding)
		'plate_radius'    => 10,            // corner radius, % of the shorter plate side (0 = sharp, 50 = fully round)
		'plate_style'     => 'glass',       // glass = frosted (blurred backdrop + light rim) | solid = flat
		'plate_opacity'   => 72,            // plate fill, %: 100 = opaque; less = the (blurred) image shows through
		'plate_padding'   => 18,            // logo-to-edge padding inside the square plate, % of the side
	];
}

/** Merge with defaults and clamp every option to its valid range */
function hamrang_wm_normalize_options(array $options) {
	$o                    = array_merge(hamrang_wm_defaults(), $options);
	$o['enabled']         = !empty($o['enabled']);
	$o['logo']            = trim((string) $o['logo']);
	$o['logo_dark']       = trim((string) $o['logo_dark']);
	$o['position']        = hamrang_wm_position_key($o['position']);
	$o['width']           = min(60, max(3, (float) $o['width']));
	$o['margin']          = min(20, max(0, (float) $o['margin']));
	$o['opacity']         = min(100, max(5, (int) $o['opacity']));
	$o['shadow']          = !empty($o['shadow']);
	$o['shadow_opacity']  = min(100, max(0, (int) $o['shadow_opacity']));
	$o['min_cover']       = max(0, (int) $o['min_cover']);
	$o['min_width']       = max(8, (int) $o['min_width']);
	$o['max_width']       = (int) $o['max_width'] > 0 ? max($o['min_width'], (int) $o['max_width']) : 0;
	$o['light_threshold'] = min(1, max(0, (float) $o['light_threshold']));
	$o['jpeg_quality']    = min(100, max(50, (int) $o['jpeg_quality']));
	$plate                = strtolower(trim((string) $o['plate']));
	if ($plate === '' || $plate === '0' || $plate === 'false' || $plate === 'off') {
		$plate = 'none';
	} elseif ($plate === '1' || $plate === 'true' || $plate === 'on') {
		$plate = 'auto';
	}
	$o['plate']           = in_array($plate, ['none', 'auto', 'square', 'rect'], true) ? $plate : 'auto';
	$plate_color          = strtolower(trim((string) $o['plate_color']));
	$o['plate_color']     = hamrang_wm_hex_to_rgb($plate_color) !== null ? '#' . ltrim($plate_color, '#') : 'auto';
	$o['plate_size']      = min(50, max(5, (float) $o['plate_size']));
	$o['plate_radius']    = min(50, max(0, (float) $o['plate_radius']));
	$o['plate_opacity']   = min(100, max(10, (int) $o['plate_opacity']));
	$o['plate_padding']   = min(40, max(5, (float) $o['plate_padding']));
	$plate_style          = strtolower(trim((string) $o['plate_style']));
	$o['plate_style']     = in_array($plate_style, ['solid', 'flat', 'plain'], true) ? 'solid' : 'glass';
	return $o;
}

function hamrang_wm_position_key($position) {
	$key = strtolower(trim((string) $position));
	$key = str_replace(['_', ' '], '-', $key);
	$aliases = [
		'br' => 'bottom-right', 'bottom-right' => 'bottom-right', 'right-bottom' => 'bottom-right',
		'bl' => 'bottom-left',  'bottom-left'  => 'bottom-left',  'left-bottom'  => 'bottom-left',
		'tr' => 'top-right',    'top-right'    => 'top-right',    'right-top'    => 'top-right',
		'tl' => 'top-left',     'top-left'     => 'top-left',     'left-top'     => 'top-left',
	];
	return isset($aliases[$key]) ? $aliases[$key] : 'bottom-right';
}

function hamrang_wm_position_label($position) {
	$labels = [
		'bottom-right' => __('bottom right', 'hamrang-watermark'),
		'bottom-left'  => __('bottom left', 'hamrang-watermark'),
		'top-right'    => __('top right', 'hamrang-watermark'),
		'top-left'     => __('top left', 'hamrang-watermark'),
	];
	$key = hamrang_wm_position_key($position);
	return $labels[$key];
}

/** All positions with their labels (for settings UI) */
function hamrang_wm_positions() {
	return [
		'top-right'    => __('top right', 'hamrang-watermark'),
		'top-left'     => __('top left', 'hamrang-watermark'),
		'bottom-right' => __('bottom right', 'hamrang-watermark'),
		'bottom-left'  => __('bottom left', 'hamrang-watermark'),
	];
}

/** Free a GD image (automatic on PHP 8; imagedestroy() is deprecated since 8.5) */
function hamrang_wm_free($image) {
	if (PHP_VERSION_ID < 80000 && is_resource($image)) {
		imagedestroy($image);
	}
}

/** Is GD available with everything we need? */
function hamrang_wm_available() {
	return function_exists('imagecreatetruecolor')
		&& function_exists('imagecreatefromstring')
		&& function_exists('imagecopyresampled')
		&& function_exists('imagejpeg')
		&& function_exists('imagecolorat');
}

/** Image type from the magic bytes: jpg | png | gif | webp | bmp | avif | '' */
function hamrang_wm_sniff_type($data) {
	$head = substr((string) $data, 0, 16);
	if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
		return 'jpg';
	}
	if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) {
		return 'png';
	}
	if (strncmp($head, 'GIF8', 4) === 0) {
		return 'gif';
	}
	if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
		return 'webp';
	}
	if (strncmp($head, 'BM', 2) === 0) {
		return 'bmp';
	}
	if (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true)) {
		return 'avif';
	}
	return '';
}

/**
 * Logo file info (cached per request)
 * Returns ['ok' => bool, 'path', 'width', 'height', 'size', 'type', 'hash', 'error']
 */
function hamrang_wm_logo_info($path) {
	static $cache = [];
	$path = (string) $path;
	if ($path === '') {
		return ['ok' => false, 'path' => '', 'width' => 0, 'height' => 0, 'size' => 0, 'type' => '', 'hash' => '', 'error' => 'no_path'];
	}
	// Cache per path + size + mtime so a file replaced during the same request is re-read.
	clearstatcache(true, $path);
	$key = $path . '|' . (int) @filesize($path) . '|' . (int) @filemtime($path);
	if (isset($cache[$key])) {
		return $cache[$key];
	}
	$info = ['ok' => false, 'path' => $path, 'width' => 0, 'height' => 0, 'size' => 0, 'type' => '', 'hash' => '', 'error' => ''];
	if (!is_file($path) || !is_readable($path)) {
		$info['error'] = 'missing';
	} else {
		$info['size'] = (int) filesize($path);
		$dims         = @getimagesize($path);
		$head         = @file_get_contents($path, false, null, 0, 16);
		$info['type'] = hamrang_wm_sniff_type($head === false ? '' : $head);
		if ($info['size'] === 0 || !is_array($dims) || empty($dims[0]) || empty($dims[1])) {
			$info['error'] = 'not_image';
		} elseif (!in_array($info['type'], ['png', 'gif', 'webp'], true)) {
			// JPEG has no alpha channel; the logo must be a PNG (or transparent GIF/WebP)
			$info['error'] = 'not_transparent';
		} else {
			$info['width']  = (int) $dims[0];
			$info['height'] = (int) $dims[1];
			$info['hash']   = md5_file($path);
			$info['ok']     = true;
		}
	}
	$cache[$key] = $info;
	return $info;
}

/** Short fingerprint of this watermark (logo files + visual options); an image carrying the same stamp is up to date */
function hamrang_wm_stamp(array $options) {
	$o     = hamrang_wm_normalize_options($options);
	$light = hamrang_wm_logo_info($o['logo']);
	$dark  = $o['logo_dark'] !== '' ? hamrang_wm_logo_info($o['logo_dark']) : ['ok' => false, 'hash' => ''];
	$parts = [
		$light['ok'] ? $light['hash'] : '-',
		$dark['ok'] ? $dark['hash'] : '-',
		$o['position'],
		(string) $o['width'],
		(string) $o['margin'],
		(string) $o['opacity'],
		$o['shadow'] ? '1' : '0',
		(string) $o['shadow_opacity'],
		(string) $o['min_cover'],
		$o['plate'],
		$o['plate_color'],
		(string) $o['plate_size'],
		(string) $o['plate_radius'],
		(string) $o['plate_opacity'],
		(string) $o['plate_padding'],
		$o['plate_style'],
	];
	return substr(md5(implode('|', $parts)), 0, 12);
}

/** Load an image (jpg/png/gif/webp/bmp/avif) as truecolor; returns [image, type] or [null, error] */
function hamrang_wm_load_image($path) {
	$data = @file_get_contents($path);
	if ($data === false || $data === '') {
		return [null, 'empty'];
	}
	$image = @imagecreatefromstring($data);
	if (!$image) {
		return [null, 'unsupported'];
	}
	if (!imageistruecolor($image) && function_exists('imagepalettetotruecolor')) {
		imagepalettetotruecolor($image);
	}
	$type = hamrang_wm_sniff_type($data);
	if ($type === 'jpg') {
		$image = hamrang_wm_fix_orientation($image, $path);
	}
	return [$image, $type];
}

/** Rotate/flip a freshly loaded JPEG according to its EXIF orientation tag (1 = as is) */
function hamrang_wm_fix_orientation($image, $path) {
	if (!function_exists('exif_read_data') || !function_exists('imagerotate')) {
		return $image;
	}
	$exif = @exif_read_data($path);
	if (!is_array($exif) || empty($exif['Orientation'])) {
		return $image;
	}
	$orientation = (int) $exif['Orientation'];
	if ($orientation < 2 || $orientation > 8) {
		return $image;
	}
	$flip = function_exists('imageflip');
	switch ($orientation) {
		case 2:
			if ($flip) {
				imageflip($image, IMG_FLIP_HORIZONTAL);
			}
			break;
		case 3:
			$image = imagerotate($image, 180, 0);
			break;
		case 4:
			if ($flip) {
				imageflip($image, IMG_FLIP_VERTICAL);
			}
			break;
		case 5:
			if ($flip) {
				imageflip($image, IMG_FLIP_VERTICAL);
			}
			$image = imagerotate($image, -90, 0);
			break;
		case 6:
			$image = imagerotate($image, -90, 0);
			break;
		case 7:
			if ($flip) {
				imageflip($image, IMG_FLIP_HORIZONTAL);
			}
			$image = imagerotate($image, -90, 0);
			break;
		case 8:
			$image = imagerotate($image, 90, 0);
			break;
	}
	return $image;
}

/** Bounding box of the visible pixels — used to trim the transparent margin around the logo */
function hamrang_wm_visible_box($image) {
	$w      = imagesx($image);
	$h      = imagesy($image);
	$top    = 0;
	$bottom = $h - 1;
	$left   = 0;
	$right  = $w - 1;
	$limit  = 120; // GD alpha: 0 = opaque, 127 = fully transparent

	if ($w * $h > 3000000) {
		// very large logo: a per-pixel scan would be slow; skip trimming
		return ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'empty' => false];
	}

	for (; $top < $h; $top++) {
		for ($x = 0; $x < $w; $x++) {
			if (((imagecolorat($image, $x, $top) >> 24) & 0x7F) < $limit) {
				break 2;
			}
		}
	}
	if ($top >= $h) {
		return ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'empty' => true];
	}
	for (; $bottom > $top; $bottom--) {
		for ($x = 0; $x < $w; $x++) {
			if (((imagecolorat($image, $x, $bottom) >> 24) & 0x7F) < $limit) {
				break 2;
			}
		}
	}
	for (; $left < $w; $left++) {
		for ($y = $top; $y <= $bottom; $y++) {
			if (((imagecolorat($image, $left, $y) >> 24) & 0x7F) < $limit) {
				break 2;
			}
		}
	}
	for (; $right > $left; $right--) {
		for ($y = $top; $y <= $bottom; $y++) {
			if (((imagecolorat($image, $right, $y) >> 24) & 0x7F) < $limit) {
				break 2;
			}
		}
	}
	return ['x' => $left, 'y' => $top, 'w' => $right - $left + 1, 'h' => $bottom - $top + 1, 'empty' => false];
}

/**
 * Logo source image with its visible box (cached per request)
 * Returns ['img' => GD, 'box' => visible box, 'aspect' => width/height] or null
 */
function hamrang_wm_logo_source($path) {
	static $cache = [];
	if (array_key_exists($path, $cache)) {
		return $cache[$path];
	}
	$cache[$path] = null;
	list($source, $type) = hamrang_wm_load_image($path);
	if (!$source) {
		return null;
	}
	imagealphablending($source, false);
	imagesavealpha($source, true);
	$box = hamrang_wm_visible_box($source);
	if ($box['empty'] || $box['w'] < 2 || $box['h'] < 2) {
		hamrang_wm_free($source);
		return null;
	}
	$cache[$path] = ['img' => $source, 'box' => $box, 'aspect' => $box['w'] / $box['h'], 'type' => $type];
	return $cache[$path];
}

/**
 * Logo layer at the target width: resized, with the global opacity applied (cached per request)
 * Returns ['img' => GD, 'w', 'h', 'coverage' => per-pixel coverage 0..1 (for the shadow)] or null
 * The returned image is shared: copy from it, never modify or free it.
 */
function hamrang_wm_logo_layer($path, $target_w, $opacity) {
	static $cache = [];
	$key = $path . '|' . (int) $target_w . '|' . (int) $opacity;
	if (array_key_exists($key, $cache)) {
		return $cache[$key];
	}
	$cache[$key] = null;

	$source = hamrang_wm_logo_source($path);
	if (!$source) {
		return null;
	}
	$box      = $source['box'];
	$target_w = max(1, (int) $target_w);
	$target_h = max(1, (int) round($target_w * $box['h'] / $box['w']));

	$layer = imagecreatetruecolor($target_w, $target_h);
	imagealphablending($layer, false);
	imagesavealpha($layer, true);
	imagefill($layer, 0, 0, 0x7F000000);
	imagecopyresampled($layer, $source['img'], 0, 0, $box['x'], $box['y'], $target_w, $target_h, $box['w'], $box['h']);

	// global opacity + coverage map for the shadow
	$factor   = min(100, max(0, (int) $opacity)) / 100;
	$coverage = array_fill(0, $target_w * $target_h, 0.0);
	for ($y = 0; $y < $target_h; $y++) {
		for ($x = 0; $x < $target_w; $x++) {
			$color   = imagecolorat($layer, $x, $y);
			$visible = 127 - (($color >> 24) & 0x7F);
			if ($visible <= 0) {
				continue;
			}
			$visible = (int) round($visible * $factor);
			$coverage[$y * $target_w + $x] = $visible / 127;
			if ($factor < 1) {
				imagesetpixel($layer, $x, $y, ((127 - $visible) << 24) | ($color & 0xFFFFFF));
			}
		}
	}

	$cache[$key] = ['img' => $layer, 'w' => $target_w, 'h' => $target_h, 'coverage' => $coverage];
	return $cache[$key];
}

/** Horizontal/vertical box blur over a coverage map (soft shadow) */
function hamrang_wm_box_blur(array $map, $w, $h, $radius, $vertical) {
	$out    = $map;
	$n      = 2 * $radius + 1;
	$outer  = $vertical ? $w : $h;
	$inner  = $vertical ? $h : $w;
	$stride = $vertical ? $w : 1;
	for ($o = 0; $o < $outer; $o++) {
		$base = $vertical ? $o : $o * $w;
		$sum  = 0.0;
		for ($i = -$radius; $i <= $radius; $i++) {
			$sum += $map[$base + min(max($i, 0), $inner - 1) * $stride];
		}
		for ($i = 0; $i < $inner; $i++) {
			$out[$base + $i * $stride] = $sum / $n;
			$sum += $map[$base + min($i + $radius + 1, $inner - 1) * $stride] - $map[$base + max($i - $radius, 0) * $stride];
		}
	}
	return $out;
}

/**
 * Soft shadow layer under the logo (cached): ['img' => GD, 'w', 'h', 'pad', 'dy']
 * $strength: 0..1; $light = true → light halo (for the dark logo on light corners)
 */
function hamrang_wm_shadow_layer($cache_key, array $logo, $strength, $light = false) {
	static $cache = [];
	$strength = min(1, max(0, (float) $strength));
	$key      = $cache_key . '|' . round($strength, 2) . '|' . ($light ? 'w' : 'b');
	if (array_key_exists($key, $cache)) {
		return $cache[$key];
	}

	$lw     = $logo['w'];
	$lh     = $logo['h'];
	$radius = max(1, (int) round($lh * 0.09));
	$pad    = $radius * 3;
	$w      = $lw + 2 * $pad;
	$h      = $lh + 2 * $pad;

	$map = array_fill(0, $w * $h, 0.0);
	foreach ($logo['coverage'] as $index => $value) {
		if ($value > 0) {
			$x = $index % $lw;
			$y = (int) ($index / $lw);
			$map[($y + $pad) * $w + $x + $pad] = $value;
		}
	}
	for ($pass = 0; $pass < 2; $pass++) {
		$map = hamrang_wm_box_blur($map, $w, $h, $radius, false);
		$map = hamrang_wm_box_blur($map, $w, $h, $radius, true);
	}

	$image = imagecreatetruecolor($w, $h);
	imagealphablending($image, false);
	imagesavealpha($image, true);
	imagefill($image, 0, 0, 0x7F000000);
	$rgb = $light ? 0xFFFFFF : 0x000000;
	for ($y = 0; $y < $h; $y++) {
		for ($x = 0; $x < $w; $x++) {
			$a = $map[$y * $w + $x] * $strength;
			if ($a > 0.004) {
				imagesetpixel($image, $x, $y, ((127 - (int) round($a * 127)) << 24) | $rgb);
			}
		}
	}

	// light halo stays centred, the dark shadow drops slightly
	$cache[$key] = ['img' => $image, 'w' => $w, 'h' => $h, 'pad' => $pad, 'dy' => $light ? 0 : (int) round($radius * 0.5)];
	return $cache[$key];
}

/** Average luminance (0..1) of an image region, sampled */
function hamrang_wm_region_luminance($image, $x0, $y0, $w, $h) {
	$iw = imagesx($image);
	$ih = imagesy($image);
	$x0 = max(0, (int) $x0);
	$y0 = max(0, (int) $y0);
	$x1 = min($iw, $x0 + (int) $w);
	$y1 = min($ih, $y0 + (int) $h);
	if ($x1 <= $x0 || $y1 <= $y0) {
		return 0.5;
	}
	$step = max(1, (int) floor(min($x1 - $x0, $y1 - $y0) / 24));
	$sum  = 0.0;
	$n    = 0;
	for ($y = $y0; $y < $y1; $y += $step) {
		for ($x = $x0; $x < $x1; $x += $step) {
			$c    = imagecolorat($image, $x, $y);
			$sum += 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
			$n++;
		}
	}
	return $n > 0 ? $sum / ($n * 255) : 0.5;
}

/** Output format from the destination extension; falls back to the source type, then jpg */
function hamrang_wm_output_format($dst, $source_type) {
	$ext = strtolower((string) pathinfo(parse_url((string) $dst, PHP_URL_PATH) ?: (string) $dst, PATHINFO_EXTENSION));
	if ($ext === 'jpeg') {
		$ext = 'jpg';
	}
	if (!in_array($ext, ['jpg', 'png', 'gif', 'webp', 'avif'], true)) {
		$ext = in_array($source_type, ['jpg', 'png', 'gif', 'webp', 'avif'], true) ? $source_type : 'jpg';
	}
	if ($ext === 'webp' && !function_exists('imagewebp')) {
		$ext = 'jpg';
	}
	if ($ext === 'avif' && !function_exists('imageavif')) {
		$ext = 'jpg';
	}
	if ($ext === 'gif' && !function_exists('imagegif')) {
		$ext = 'jpg';
	}
	return $ext;
}

/** Save the image; files are written to a temp file next to the destination first, then moved */
function hamrang_wm_save($image, $dst, $format, $jpeg_quality) {
	$direct = strpos((string) $dst, '://') !== false;
	$target = $direct ? $dst : $dst . '.hamrang' . mt_rand(1000, 9999) . '.tmp';
	switch ($format) {
		case 'png':
			imagesavealpha($image, false);
			$ok = @imagepng($image, $target, 6);
			break;
		case 'gif':
			$ok = @imagegif($image, $target);
			break;
		case 'webp':
			$ok = @imagewebp($image, $target, max(60, (int) $jpeg_quality));
			break;
		case 'avif':
			$ok = @imageavif($image, $target, max(50, (int) $jpeg_quality));
			break;
		default:
			$ok = @imagejpeg($image, $target, (int) $jpeg_quality);
	}
	if ($direct) {
		return (bool) $ok;
	}
	if (!$ok || !file_exists($target) || filesize($target) === 0) {
		@unlink($target);
		return false;
	}
	if (@rename($target, $dst)) {
		return true;
	}
	$copied = @copy($target, $dst);
	@unlink($target);
	return $copied;
}

/** RGB (0..255) → HSV (0..1) */
function hamrang_wm_rgb_to_hsv($r, $g, $b) {
	$r   = $r / 255;
	$g   = $g / 255;
	$b   = $b / 255;
	$max = max($r, $g, $b);
	$min = min($r, $g, $b);
	$d   = $max - $min;
	$v   = $max;
	$s   = $max > 0 ? $d / $max : 0;
	$h   = 0;
	if ($d > 0) {
		if ($max == $r) {
			$h = fmod(($g - $b) / $d, 6);
		} elseif ($max == $g) {
			$h = ($b - $r) / $d + 2;
		} else {
			$h = ($r - $g) / $d + 4;
		}
		$h = $h / 6;
		if ($h < 0) {
			$h += 1;
		}
	}
	return [$h, $s, $v];
}

/** HSV (0..1) → RGB (0..255) */
function hamrang_wm_hsv_to_rgb($h, $s, $v) {
	$i = (int) floor($h * 6) % 6;
	$f = $h * 6 - floor($h * 6);
	$p = $v * (1 - $s);
	$q = $v * (1 - $f * $s);
	$t = $v * (1 - (1 - $f) * $s);
	switch ($i) {
		case 0:
			$rgb = [$v, $t, $p];
			break;
		case 1:
			$rgb = [$q, $v, $p];
			break;
		case 2:
			$rgb = [$p, $v, $t];
			break;
		case 3:
			$rgb = [$p, $q, $v];
			break;
		case 4:
			$rgb = [$t, $p, $v];
			break;
		default:
			$rgb = [$v, $p, $q];
	}
	return [(int) round($rgb[0] * 255), (int) round($rgb[1] * 255), (int) round($rgb[2] * 255)];
}

/** '#1e90ff' → [30, 144, 255] or null */
function hamrang_wm_hex_to_rgb($hex) {
	$hex = ltrim(trim((string) $hex), '#');
	if (strlen($hex) === 3) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
		return null;
	}
	return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function hamrang_wm_rgb_to_hex(array $rgb) {
	return sprintf('#%02x%02x%02x', (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
}

/** Perceived luminance 0..1 of a colour */
function hamrang_wm_rgb_luminance(array $rgb) {
	return (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;
}

/**
 * Boldest dominant colour of the image (for the plate): the saturated colour covering the largest area;
 * black/white/grey are ignored. The image is sampled at 64 px and colours are binned in HSV space.
 * Returns ['rgb' => [r, g, b], 'hex' => '#rrggbb', 'saturated' => bool]
 */
function hamrang_wm_dominant_color($image) {
	$w  = imagesx($image);
	$h  = imagesy($image);
	$sw = 64;
	$sh = max(1, (int) round($sw * $h / max(1, $w)));
	$small = imagecreatetruecolor($sw, $sh);
	imagecopyresampled($small, $image, 0, 0, 0, 0, $sw, $sh, $w, $h);

	$bins = [];
	for ($y = 0; $y < $sh; $y++) {
		for ($x = 0; $x < $sw; $x++) {
			$c = imagecolorat($small, $x, $y);
			$r = ($c >> 16) & 0xFF;
			$g = ($c >> 8) & 0xFF;
			$b = $c & 0xFF;
			list($hue, $s, $v) = hamrang_wm_rgb_to_hsv($r, $g, $b);
			// weight: saturated and not too dark; near-whites dropped
			$weight = pow($s, 1.5) * min(1, $v / 0.45);
			if ($v > 0.93 && $s < 0.25) {
				$weight = 0;
			}
			if ($weight < 0.03) {
				continue;
			}
			$key = (((int) floor($hue * 24)) % 24) + 24 * ($s >= 0.5 ? 1 : 0) + 48 * min(2, (int) floor($v * 3));
			if (!isset($bins[$key])) {
				$bins[$key] = [0.0, 0.0, 0.0, 0.0];
			}
			$bins[$key][0] += $weight;
			$bins[$key][1] += $r * $weight;
			$bins[$key][2] += $g * $weight;
			$bins[$key][3] += $b * $weight;
		}
	}
	hamrang_wm_free($small);

	$best = null;
	foreach ($bins as $bin) {
		if ($best === null || $bin[0] > $best[0]) {
			$best = $bin;
		}
	}
	// greyscale image or no distinctive colour → dark grey plate
	if ($best === null || $best[0] < $sw * $sh * 0.012) {
		return ['rgb' => [38, 38, 44], 'hex' => '#26262c', 'saturated' => false];
	}
	$rgb = [$best[1] / $best[0], $best[2] / $best[0], $best[3] / $best[0]];
	// pleasant range for a plate: not too light (white logo must stay readable), not too dark/dull
	list($hue, $s, $v) = hamrang_wm_rgb_to_hsv($rgb[0], $rgb[1], $rgb[2]);
	$s   = min(0.92, max(0.3, $s));
	$v   = min(0.78, max(0.3, $v));
	$rgb = hamrang_wm_hsv_to_rgb($hue, $s, $v);
	return ['rgb' => $rgb, 'hex' => hamrang_wm_rgb_to_hex($rgb), 'saturated' => true];
}

/**
 * Plate layer: rounded rectangle with anti-aliased edges (drawn at 4× and downsampled)
 * Returns ['img' => GD, 'w', 'h', 'coverage' => per-pixel coverage 0..1 (for the shadow), 'shape' => coverage before opacity]
 */
function hamrang_wm_plate_layer($w, $h, $radius, array $rgb, $opacity) {
	$w  = max(2, (int) $w);
	$h  = max(2, (int) $h);
	$ss = 4;
	$W  = $w * $ss;
	$H  = $h * $ss;
	$R  = (int) round(min((float) $radius, min($w, $h) / 2) * $ss);

	$big = imagecreatetruecolor($W, $H);
	imagealphablending($big, false);
	imagesavealpha($big, true);
	imagefill($big, 0, 0, 0x7F000000);
	$color = imagecolorallocatealpha($big, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2], 0);
	if ($R > 0) {
		imagefilledrectangle($big, $R, 0, $W - 1 - $R, $H - 1, $color);
		imagefilledrectangle($big, 0, $R, $W - 1, $H - 1 - $R, $color);
		imagefilledellipse($big, $R, $R, 2 * $R, 2 * $R, $color);
		imagefilledellipse($big, $W - 1 - $R, $R, 2 * $R, 2 * $R, $color);
		imagefilledellipse($big, $R, $H - 1 - $R, 2 * $R, 2 * $R, $color);
		imagefilledellipse($big, $W - 1 - $R, $H - 1 - $R, 2 * $R, 2 * $R, $color);
	} else {
		imagefilledrectangle($big, 0, 0, $W - 1, $H - 1, $color);
	}

	$layer = imagecreatetruecolor($w, $h);
	imagealphablending($layer, false);
	imagesavealpha($layer, true);
	imagefill($layer, 0, 0, 0x7F000000);
	imagecopyresampled($layer, $big, 0, 0, 0, 0, $w, $h, $W, $H);
	hamrang_wm_free($big);

	$factor   = min(100, max(10, (int) $opacity)) / 100;
	$coverage = array_fill(0, $w * $h, 0.0);
	$shape    = $coverage;
	for ($y = 0; $y < $h; $y++) {
		for ($x = 0; $x < $w; $x++) {
			$c       = imagecolorat($layer, $x, $y);
			$visible = 127 - (($c >> 24) & 0x7F);
			if ($visible <= 0) {
				continue;
			}
			$shape[$y * $w + $x] = $visible / 127;
			if ($factor < 1) {
				$visible = (int) round($visible * $factor);
				imagesetpixel($layer, $x, $y, ((127 - $visible) << 24) | ($c & 0xFFFFFF));
			}
			$coverage[$y * $w + $x] = $visible / 127;
		}
	}
	return ['img' => $layer, 'w' => $w, 'h' => $h, 'coverage' => $coverage, 'shape' => $shape];
}

/**
 * Frosted-glass layers:
 *   backdrop = the image area under the plate, blurred and clipped to the plate shape (goes under the plate colour)
 *   gloss    = thin light rim + soft sheen on the upper half (goes over the plate colour, under the logo)
 * $plate is the hamrang_wm_plate_layer() result (for the rounded shape); $x,$y is the plate position on the image
 */
function hamrang_wm_glass_layers($cover, $x, $y, array $plate, $radius) {
	$w  = $plate['w'];
	$h  = $plate['h'];
	$cw = imagesx($cover);
	$ch = imagesy($cover);

	// --- blur the area under the plate (with padding so the edges do not darken) ---
	$blur = max(3.0, min($w, $h) * 0.09);
	$pad  = (int) ceil($blur * 2);
	$x0   = max(0, $x - $pad);
	$y0   = max(0, $y - $pad);
	$x1   = min($cw, $x + $w + $pad);
	$y1   = min($ch, $y + $h + $pad);
	$rw   = max(1, $x1 - $x0);
	$rh   = max(1, $y1 - $y0);
	$region = imagecreatetruecolor($rw, $rh);
	imagecopy($region, $cover, 0, 0, $x0, $y0, $rw, $rh);
	$sw    = max(1, (int) round($rw / $blur));
	$sh    = max(1, (int) round($rh / $blur));
	$small = imagecreatetruecolor($sw, $sh);
	imagecopyresampled($small, $region, 0, 0, 0, 0, $sw, $sh, $rw, $rh);
	if (function_exists('imagefilter') && defined('IMG_FILTER_GAUSSIAN_BLUR')) {
		imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
		imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
	}
	imagecopyresampled($region, $small, 0, 0, 0, 0, $rw, $rh, $sw, $sh);
	hamrang_wm_free($small);

	$backdrop = imagecreatetruecolor($w, $h);
	imagealphablending($backdrop, false);
	imagesavealpha($backdrop, true);
	imagefill($backdrop, 0, 0, 0x7F000000);
	$gloss = imagecreatetruecolor($w, $h);
	imagealphablending($gloss, false);
	imagesavealpha($gloss, true);
	imagefill($gloss, 0, 0, 0x7F000000);

	// inner shape (slightly smaller) to derive the rim band
	$inset = max(1, (int) round(min($w, $h) * 0.014));
	$inner = hamrang_wm_plate_layer(max(2, $w - 2 * $inset), max(2, $h - 2 * $inset), max(0, $radius - $inset), [255, 255, 255], 100);
	$iw    = $inner['w'];
	$ih    = $inner['h'];
	hamrang_wm_free($inner['img']);

	$ox = $x - $x0;
	$oy = $y - $y0;
	for ($py = 0; $py < $h; $py++) {
		$t = $py / max(1, $h - 1);                                   // 0 top … 1 bottom
		$edge_strength = 0.42 - 0.27 * $t;                           // rim: brighter at the top
		$sheen = $t < 0.45 ? 0.11 * (1 - $t / 0.45) : 0.0;           // soft sheen on the upper half
		for ($px = 0; $px < $w; $px++) {
			$cov = $plate['shape'][$py * $w + $px];
			if ($cov <= 0) {
				continue;
			}
			$c = imagecolorat($region, min($rw - 1, $ox + $px), min($rh - 1, $oy + $py)) & 0xFFFFFF;
			imagesetpixel($backdrop, $px, $py, ((127 - (int) round($cov * 127)) << 24) | $c);

			$ix = $px - $inset;
			$iy = $py - $inset;
			$in = ($ix >= 0 && $iy >= 0 && $ix < $iw && $iy < $ih) ? $inner['shape'][$iy * $iw + $ix] : 0.0;
			$a  = max(0.0, $cov - $in) * $edge_strength + $in * $sheen;
			if ($a > 0.004) {
				imagesetpixel($gloss, $px, $py, ((127 - (int) round(min(1.0, $a) * 127)) << 24) | 0xFFFFFF);
			}
		}
	}
	hamrang_wm_free($region);
	return ['backdrop' => $backdrop, 'gloss' => $gloss];
}

/**
 * Apply the watermark
 * $src: image file; $dst: output path (may equal $src — the file is fully read first)
 * Returns: [
 *   'ok' => bool, 'skipped' => bool, 'reason' => small, 'error' => gd|logo_missing|logo_not_image|logo_not_transparent|cover|write|disabled,
 *   'width', 'height' (image), 'logo_width', 'logo_height', 'x', 'y', 'variant' => light|dark, 'luminance', 'position', 'format', 'stamp',
 *   'plate' => ''|square|rect, 'plate_style' => glass|solid, 'plate_color' => '#rrggbb', 'plate_width', 'plate_height'
 * ]
 * When skipped = true nothing is written.
 */
function hamrang_wm_apply($src, $dst, array $options) {
	$o      = hamrang_wm_normalize_options($options);
	$result = [
		'ok' => false, 'skipped' => false, 'reason' => '', 'error' => '', 'detail' => '',
		'width' => 0, 'height' => 0, 'logo_width' => 0, 'logo_height' => 0, 'x' => 0, 'y' => 0,
		'variant' => '', 'luminance' => null, 'position' => $o['position'], 'format' => '', 'stamp' => '',
		'logo' => $o['logo'], 'plate' => '', 'plate_style' => '', 'plate_color' => '', 'plate_width' => 0, 'plate_height' => 0,
	];

	if (!hamrang_wm_available()) {
		$result['error'] = 'gd';
		return $result;
	}
	$logo_info = hamrang_wm_logo_info($o['logo']);
	if (!$logo_info['ok']) {
		$result['error'] = $logo_info['error'] === 'missing' || $logo_info['error'] === 'no_path' ? 'logo_missing'
			: ($logo_info['error'] === 'not_transparent' ? 'logo_not_transparent' : 'logo_not_image');
		return $result;
	}

	list($cover, $type) = hamrang_wm_load_image($src);
	if (!$cover) {
		$result['error']  = 'cover';
		$result['detail'] = $type;
		return $result;
	}
	$width            = imagesx($cover);
	$height           = imagesy($cover);
	$result['width']  = $width;
	$result['height'] = $height;

	if ($o['min_cover'] > 0 && min($width, $height) < $o['min_cover']) {
		hamrang_wm_free($cover);
		$result['ok']      = true;
		$result['skipped'] = true;
		$result['reason']  = 'small';
		return $result;
	}

	$source = hamrang_wm_logo_source($o['logo']);
	if (!$source) {
		hamrang_wm_free($cover);
		$result['error'] = 'logo_not_image';
		return $result;
	}
	$aspect = max(0.05, (float) $source['aspect']);
	$margin = (int) round($width * $o['margin'] / 100);

	// free logo width: % of the image width; very wide logos keep a height of at least 3 % of the image width
	// (up to 1.7× the configured width) and square/tall logos are capped at 12 % of the image width in height
	$logo_w = $width * $o['width'] / 100;
	$min_h  = $width * 0.03;
	$max_h  = $width * 0.12;
	if ($logo_w / $aspect < $min_h) {
		$logo_w = min($min_h * $aspect, $width * $o['width'] * 1.7 / 100);
	} elseif ($logo_w / $aspect > $max_h) {
		$logo_w = $max_h * $aspect;
	}
	$logo_w = (int) round($logo_w);
	$logo_w = max($o['min_width'], $o['max_width'] > 0 ? min($o['max_width'], $logo_w) : $logo_w);
	$logo_w = max(8, min($logo_w, (int) floor($width * 0.6)));

	$place = function ($lw, $lh) use ($o, $width, $height, $margin) {
		$x = $o['position'] === 'bottom-left' || $o['position'] === 'top-left' ? $margin : $width - $margin - $lw;
		$y = $o['position'] === 'top-left' || $o['position'] === 'top-right' ? $margin : $height - $margin - $lh;
		return [max(0, $x), max(0, $y)];
	};

	$shape = $o['plate'];
	if ($shape === 'auto') {
		$shape = $aspect <= 1.35 ? 'square' : 'rect';
	}

	if ($shape !== 'none') {
		/* ---------- plate mode: coloured square/rectangle behind the logo (dominant colour or fixed colour) ---------- */
		if ($shape === 'square') {
			$side = (int) round($width * $o['plate_size'] / 100);
			$side = max(32, min($side, (int) floor($width * 0.5)));
			$pad  = (int) round($side * $o['plate_padding'] / 100);
			$box  = max(4, $side - 2 * $pad);
			$lw   = $aspect >= 1 ? $box : max(4, (int) round($box * $aspect));
			$pw   = $side;
			$ph   = $side;
		} else {
			$lw    = $logo_w;
			$lh0   = max(1, (int) round($lw / $aspect));
			$pad_y = max(3, (int) round($lh0 * 0.5));
			$pad_x = max($pad_y, (int) round($lh0 * 0.7));
			$pw    = $lw + 2 * $pad_x;
			$ph    = $lh0 + 2 * $pad_y;
		}

		$plate_rgb = null;
		if ($o['plate_color'] !== 'auto') {
			$plate_rgb = hamrang_wm_hex_to_rgb($o['plate_color']);
		}
		if ($plate_rgb === null) {
			$dominant  = hamrang_wm_dominant_color($cover);
			$plate_rgb = $dominant['rgb'];
		}
		$plate_lum = hamrang_wm_rgb_luminance($plate_rgb);
		$variant   = 'light';
		$logo_path = $o['logo'];
		if ($plate_lum > 0.62) {
			$dark_info = $o['logo_dark'] !== '' ? hamrang_wm_logo_info($o['logo_dark']) : ['ok' => false];
			if ($dark_info['ok'] && hamrang_wm_logo_source($o['logo_dark'])) {
				$variant   = 'dark';
				$logo_path = $o['logo_dark'];
			} else {
				// light plate without a dark logo: darken the plate a little so the white logo stays readable
				list($ph_h, $ph_s, $ph_v) = hamrang_wm_rgb_to_hsv($plate_rgb[0], $plate_rgb[1], $plate_rgb[2]);
				$plate_rgb = hamrang_wm_hsv_to_rgb($ph_h, min(0.95, $ph_s + 0.1), $ph_v * 0.72);
			}
		}

		$logo_layer = hamrang_wm_logo_layer($logo_path, $lw, 100);
		if (!$logo_layer) {
			hamrang_wm_free($cover);
			$result['error'] = 'logo_not_image';
			return $result;
		}
		$lh = $logo_layer['h'];
		if ($shape === 'square' && $lh > $pw - 2 * (int) round($pw * $o['plate_padding'] / 100)) {
			// logo taller than wide: fit by height
			$box        = max(4, $pw - 2 * (int) round($pw * $o['plate_padding'] / 100));
			$lw         = max(4, (int) round($box * $aspect));
			$logo_layer = hamrang_wm_logo_layer($logo_path, $lw, 100);
			$lh         = $logo_layer['h'];
		}
		if ($shape !== 'square') {
			$ph = $lh + 2 * $pad_y;
		}

		$radius = min($pw, $ph) * $o['plate_radius'] / 100;
		$plate  = hamrang_wm_plate_layer($pw, $ph, $radius, $plate_rgb, $o['plate_opacity']);

		list($x, $y) = $place($pw, $ph);
		$luminance   = hamrang_wm_region_luminance($cover, $x - (int) ($margin / 2), $y - (int) ($margin / 2), $pw + $margin, $ph + $margin);

		// glass: the image under the plate is blurred and gets a light rim/sheen (identical to solid at 100 % fill)
		$glass = ($o['plate_style'] === 'glass' && $o['plate_opacity'] < 100) ? hamrang_wm_glass_layers($cover, $x, $y, $plate, $radius) : null;

		$canvas = imagecreatetruecolor($width, $height);
		imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
		imagealphablending($canvas, true);
		imagecopy($canvas, $cover, 0, 0, 0, 0, $width, $height);
		hamrang_wm_free($cover);

		if ($o['shadow']) {
			$shadow = hamrang_wm_shadow_layer('plate|' . $pw . 'x' . $ph . '|' . round($radius, 1) . '|' . $o['plate_opacity'], $plate, 0.22, false);
			imagecopy($canvas, $shadow['img'], $x - $shadow['pad'], $y - $shadow['pad'] + $shadow['dy'], 0, 0, $shadow['w'], $shadow['h']);
		}
		if ($glass) {
			imagecopy($canvas, $glass['backdrop'], $x, $y, 0, 0, $pw, $ph);
		}
		imagecopy($canvas, $plate['img'], $x, $y, 0, 0, $pw, $ph);
		hamrang_wm_free($plate['img']);
		if ($glass) {
			imagecopy($canvas, $glass['gloss'], $x, $y, 0, 0, $pw, $ph);
			hamrang_wm_free($glass['backdrop']);
			hamrang_wm_free($glass['gloss']);
		}
		imagecopy($canvas, $logo_layer['img'], $x + (int) round(($pw - $lw) / 2), $y + (int) round(($ph - $lh) / 2), 0, 0, $logo_layer['w'], $logo_layer['h']);

		$result['plate']        = $shape;
		$result['plate_style']  = $glass ? 'glass' : 'solid';
		$result['plate_color']  = hamrang_wm_rgb_to_hex($plate_rgb);
		$result['plate_width']  = $pw;
		$result['plate_height'] = $ph;
		$result['logo_width']   = $logo_layer['w'];
		$result['logo_height']  = $logo_layer['h'];
	} else {
		/* ---------- free mode: logo with a soft shadow, no plate ---------- */
		$layer = hamrang_wm_logo_layer($o['logo'], $logo_w, $o['opacity']);
		if (!$layer) {
			hamrang_wm_free($cover);
			$result['error'] = 'logo_not_image';
			return $result;
		}
		$variant   = 'light';
		$layer_key = $o['logo'];
		$logo_h    = $layer['h'];
		list($x, $y) = $place($logo_w, $logo_h);

		// corner luminance → dark variant or a stronger shadow
		$luminance       = hamrang_wm_region_luminance($cover, $x - (int) ($margin / 2), $y - (int) ($margin / 2), $logo_w + $margin, $logo_h + $margin);
		$shadow_strength = $o['shadow_opacity'] / 100;
		if ($luminance > $o['light_threshold']) {
			$dark_info = $o['logo_dark'] !== '' ? hamrang_wm_logo_info($o['logo_dark']) : ['ok' => false];
			$dark      = $dark_info['ok'] ? hamrang_wm_logo_layer($o['logo_dark'], $logo_w, $o['opacity']) : null;
			if ($dark) {
				// dark logo with a light halo (stays readable even if the corner has dark patches)
				$layer           = $dark;
				$layer_key       = $o['logo_dark'];
				$variant         = 'dark';
				$logo_h          = $layer['h'];
				$shadow_strength = min(0.9, $shadow_strength + 0.25);
				list($x, $y)     = $place($logo_w, $logo_h);
			} else {
				$shadow_strength = min(0.9, $shadow_strength + 0.3);
			}
		}

		// white canvas (correct output for transparent PNGs too) + image + shadow + logo
		$canvas = imagecreatetruecolor($width, $height);
		imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
		imagealphablending($canvas, true);
		imagecopy($canvas, $cover, 0, 0, 0, 0, $width, $height);
		hamrang_wm_free($cover);

		if ($o['shadow'] && $shadow_strength > 0) {
			$shadow = hamrang_wm_shadow_layer($layer_key . '|' . $logo_w . '|' . $o['opacity'], $layer, $shadow_strength, $variant === 'dark');
			imagecopy($canvas, $shadow['img'], $x - $shadow['pad'], $y - $shadow['pad'] + $shadow['dy'], 0, 0, $shadow['w'], $shadow['h']);
		}
		imagecopy($canvas, $layer['img'], $x, $y, 0, 0, $layer['w'], $layer['h']);

		$result['logo_width']  = $layer['w'];
		$result['logo_height'] = $layer['h'];
	}

	$format = hamrang_wm_output_format($dst, $type);
	$saved  = hamrang_wm_save($canvas, $dst, $format, $o['jpeg_quality']);
	hamrang_wm_free($canvas);
	if (!$saved) {
		$result['error']  = 'write';
		$result['detail'] = (string) $dst;
		return $result;
	}

	$result['ok']        = true;
	$result['x']         = $x;
	$result['y']         = $y;
	$result['variant']   = $variant;
	$result['luminance'] = round($luminance, 3);
	$result['format']    = $format;
	$result['stamp']     = hamrang_wm_stamp($o);
	return $result;
}

/** One human-readable line for a hamrang_wm_apply() result */
function hamrang_wm_describe($result) {
	if (!is_array($result)) {
		return '';
	}
	if (!empty($result['ok']) && empty($result['skipped'])) {
		$position = hamrang_wm_position_label($result['position']);
		if (!empty($result['plate'])) {
			$shape = $result['plate'] === 'square' ? __('square', 'hamrang-watermark') : __('rectangular', 'hamrang-watermark');
			if (isset($result['plate_style']) && $result['plate_style'] === 'glass') {
				/* translators: 1: square/rectangular, 2: plate width in px, 3: hex colour, 4: position */
				$line = sprintf(__('Logo applied on a frosted %1$s plate (%2$dpx, %3$s, %4$s)', 'hamrang-watermark'), $shape, (int) $result['plate_width'], $result['plate_color'], $position);
			} else {
				/* translators: 1: square/rectangular, 2: plate width in px, 3: hex colour, 4: position */
				$line = sprintf(__('Logo applied on a solid %1$s plate (%2$dpx, %3$s, %4$s)', 'hamrang-watermark'), $shape, (int) $result['plate_width'], $result['plate_color'], $position);
			}
			if ($result['variant'] === 'dark') {
				$line .= ' — ' . __('dark logo on a light plate', 'hamrang-watermark');
			}
			return $line;
		}
		/* translators: 1: logo width in px, 2: position */
		$line = sprintf(__('Logo applied (%1$dpx, %2$s)', 'hamrang-watermark'), (int) $result['logo_width'], $position);
		if ($result['variant'] === 'dark') {
			$line .= ' — ' . __('dark variant on a light corner', 'hamrang-watermark');
		}
		return $line;
	}
	if (!empty($result['skipped'])) {
		/* translators: 1: width, 2: height */
		return sprintf(__('Image too small (%1$d×%2$d) — left untouched', 'hamrang-watermark'), (int) $result['width'], (int) $result['height']);
	}
	$logo = isset($result['logo']) ? (string) $result['logo'] : '';
	switch (isset($result['error']) ? $result['error'] : '') {
		case 'gd':
			return __('Watermark failed — the GD image library is not available on this server', 'hamrang-watermark');
		case 'logo_missing':
			return __('Watermark failed — logo file not found', 'hamrang-watermark') . ($logo !== '' ? ': ' . basename($logo) : '');
		case 'logo_not_transparent':
			return __('Watermark failed — the logo must be a transparent PNG', 'hamrang-watermark');
		case 'logo_not_image':
			return __('Watermark failed — the logo is not a valid image', 'hamrang-watermark');
		case 'cover':
			return __('Watermark failed — the image could not be read', 'hamrang-watermark') . (!empty($result['detail']) ? ' (' . $result['detail'] . ')' : '');
		case 'write':
			return __('Watermark failed — the image could not be saved (check folder permissions)', 'hamrang-watermark');
		case 'disabled':
			return __('Watermarking is disabled', 'hamrang-watermark');
	}
	return __('Watermark failed', 'hamrang-watermark');
}

/**
 * Downscale an image for previews: longest side ≤ $max, saved as JPEG at $dst. Returns true on success.
 */
function hamrang_wm_downscale($src, $dst, $max = 800, $quality = 88) {
	list($image, $type) = hamrang_wm_load_image($src);
	if (!$image) {
		return false;
	}
	$w = imagesx($image);
	$h = imagesy($image);
	$scale = min(1, $max / max(1, max($w, $h)));
	$nw = max(1, (int) round($w * $scale));
	$nh = max(1, (int) round($h * $scale));
	$canvas = imagecreatetruecolor($nw, $nh);
	imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
	imagealphablending($canvas, true);
	if ($scale < 1) {
		imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
	} else {
		imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);
	}
	hamrang_wm_free($image);
	$ok = hamrang_wm_save($canvas, $dst, 'jpg', $quality);
	hamrang_wm_free($canvas);
	return $ok;
}
