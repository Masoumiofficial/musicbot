<?php
/**
 * لوگوی سایت روی کاور (واترمارک) — فقط با GD، بدون وابستگی به وردپرس
 *
 * این فایل در دو جا عیناً یکی است و هر تغییری باید در هر دو اعمال شود:
 *   host-site/bot/watermark.php   تصویر شاخص پست‌ها (bot/script.php) و ابزار دسته‌ای bot/watermark-batch.php
 *   hostdl/watermark.php          کاور داخل فایل‌های MP3 (hostdl/common.php)
 *
 * تنظیمات از config.php همان هاست خوانده می‌شود (bm_wm_options_from_globals):
 *   سایت: $bot_watermark, $bot_watermark_file, $bot_watermark_dark, $bot_watermark_position, $bot_watermark_width,
 *         $bot_watermark_margin, $bot_watermark_opacity, $bot_watermark_shadow, $bot_watermark_min_cover
 *   هاست دانلود: همان نام‌ها با پیشوند $dl_
 *
 * چه می‌کند:
 *   - لوگوی PNG شفاف را با عرضی نسبی (درصدی از عرض کاور) در گوشه‌ی انتخابی می‌نشاند؛ حاشیه‌ی شفاف دور لوگو خودکار حذف می‌شود
 *   - روشنایی همان گوشه‌ی کاور را می‌سنجد؛ اگر روشن بود و نسخه‌ی تیره‌ی لوگو داده شده باشد از آن استفاده می‌کند،
 *     وگرنه سایه‌ی زیر لوگو را کمی پررنگ‌تر می‌کند تا لوگوی سفید خوانا بماند
 *   - زیر لوگو سایه‌ی نرم (محو) می‌اندازد تا روی کاورهای شلوغ هم دیده شود
 *   - کاورهای کوچک‌تر از حد تنظیم‌شده دست نمی‌خورند
 *
 * خروجی اصلی: bm_wm_apply($src, $dst, $opts) → آرایه (ok, skipped, reason, error, logo_width, variant, luminance, …)
 * و bm_wm_describe($result) → یک خط فارسی برای گزارش
 */

if (!function_exists('bm_wm_defaults')) {

    function bm_wm_defaults() {
        return [
            'enabled'         => true,
            'logo'            => '',            // مسیر PNG شفاف لوگو (ترجیحاً سفید)
            'logo_dark'       => '',            // اختیاری: نسخه‌ی تیره برای گوشه‌های روشن
            'position'        => 'bottom-right',
            'width'           => 15,            // درصد عرض کاور
            'min_width'       => 64,            // حداقل عرض لوگو (پیکسل)
            'max_width'       => 0,             // حداکثر عرض لوگو (پیکسل)؛ 0 = بدون سقف
            'margin'          => 3.5,           // فاصله از لبه‌ها، درصد عرض کاور
            'opacity'         => 85,            // درصد
            'shadow'          => true,
            'shadow_opacity'  => 45,            // درصد (روی گوشه‌های روشن بدون لوگوی تیره خودکار بیشتر می‌شود)
            'min_cover'       => 240,           // کاور با ضلع کوچک‌تر از این لوگو نمی‌گیرد
            'light_threshold' => 0.6,           // روشنایی ۰ تا ۱؛ بالاتر از این = گوشه‌ی روشن
            'jpeg_quality'    => 90,
        ];
    }

    /**
     * تنظیمات از متغیرهای سراسری config.php با پیشوند داده‌شده (bot یا dl)
     * {prefix}_watermark (فعال/غیرفعال)، {prefix}_watermark_file، _dark، _position، _width، _margin، _opacity، _shadow، _min_cover
     */
    function bm_wm_options_from_globals($prefix) {
        $options = bm_wm_defaults();
        $base    = $prefix . '_watermark';
        if (array_key_exists($base, $GLOBALS)) {
            $options['enabled'] = !empty($GLOBALS[$base]);
        } else {
            $options['enabled'] = false;
        }
        $map = [
            'file'      => 'logo',
            'dark'      => 'logo_dark',
            'position'  => 'position',
            'width'     => 'width',
            'margin'    => 'margin',
            'opacity'   => 'opacity',
            'shadow'    => 'shadow',
            'min_cover' => 'min_cover',
        ];
        foreach ($map as $suffix => $key) {
            if (array_key_exists($base . '_' . $suffix, $GLOBALS)) {
                $options[$key] = $GLOBALS[$base . '_' . $suffix];
            }
        }
        return bm_wm_normalize_options($options);
    }

    function bm_wm_normalize_options(array $options) {
        $o                    = array_merge(bm_wm_defaults(), $options);
        $o['enabled']         = !empty($o['enabled']);
        $o['logo']            = trim((string) $o['logo']);
        $o['logo_dark']       = trim((string) $o['logo_dark']);
        $o['position']        = bm_wm_position_key($o['position']);
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
        return $o;
    }

    function bm_wm_position_key($position) {
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

    function bm_wm_position_label($position) {
        $labels = [
            'bottom-right' => 'پایین راست',
            'bottom-left'  => 'پایین چپ',
            'top-right'    => 'بالا راست',
            'top-left'     => 'بالا چپ',
        ];
        $key = bm_wm_position_key($position);
        return $labels[$key];
    }

    /** آزاد کردن تصویر GD (در PHP 8 خودکار است و imagedestroy از 8.5 منسوخ شده) */
    function bm_wm_free($image) {
        if (PHP_VERSION_ID < 80000 && is_resource($image)) {
            imagedestroy($image);
        }
    }

    /** آیا GD با امکانات لازم روی این هاست هست؟ */
    function bm_wm_available() {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagecreatefromstring')
            && function_exists('imagecopyresampled')
            && function_exists('imagejpeg')
            && function_exists('imagecolorat');
    }

    /** نوع تصویر از روی بایت‌های اول: jpg | png | gif | webp | bmp | '' */
    function bm_wm_sniff_type($data) {
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
        return '';
    }

    /**
     * اطلاعات فایل لوگو (با کش در همان اجرا)
     * خروجی: ['ok' => bool, 'path', 'width', 'height', 'size', 'type', 'hash', 'error']
     */
    function bm_wm_logo_info($path) {
        static $cache = [];
        $path = (string) $path;
        if ($path === '') {
            return ['ok' => false, 'path' => '', 'width' => 0, 'height' => 0, 'size' => 0, 'type' => '', 'hash' => '', 'error' => 'no_path'];
        }
        if (isset($cache[$path])) {
            return $cache[$path];
        }
        $info = ['ok' => false, 'path' => $path, 'width' => 0, 'height' => 0, 'size' => 0, 'type' => '', 'hash' => '', 'error' => ''];
        if (!is_file($path) || !is_readable($path)) {
            $info['error'] = 'missing';
        } else {
            $info['size'] = (int) filesize($path);
            $dims         = @getimagesize($path);
            $head         = @file_get_contents($path, false, null, 0, 16);
            $info['type'] = bm_wm_sniff_type($head === false ? '' : $head);
            if ($info['size'] === 0 || !is_array($dims) || empty($dims[0]) || empty($dims[1])) {
                $info['error'] = 'not_image';
            } elseif (!in_array($info['type'], ['png', 'gif', 'webp'], true)) {
                // JPEG شفافیت ندارد؛ لوگو باید PNG (یا GIF/WebP شفاف) باشد
                $info['error'] = 'not_transparent';
            } else {
                $info['width']  = (int) $dims[0];
                $info['height'] = (int) $dims[1];
                $info['hash']   = md5_file($path);
                $info['ok']     = true;
            }
        }
        $cache[$path] = $info;
        return $info;
    }

    /** شناسه‌ی کوتاه این واترمارک (لوگو + تنظیمات ظاهری)؛ کاوری که همین شناسه را دارد دوباره لوگو نمی‌گیرد */
    function bm_wm_stamp(array $options) {
        $o     = bm_wm_normalize_options($options);
        $light = bm_wm_logo_info($o['logo']);
        $dark  = $o['logo_dark'] !== '' ? bm_wm_logo_info($o['logo_dark']) : ['ok' => false, 'hash' => ''];
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
        ];
        return substr(md5(implode('|', $parts)), 0, 12);
    }

    /** بارگذاری تصویر (jpg/png/gif/webp/bmp) به‌صورت truecolor؛ خروجی: [image, type] یا [null, خطا] */
    function bm_wm_load_image($path) {
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
        return [$image, bm_wm_sniff_type($data)];
    }

    /** کادر پیکسل‌های دیدنی (آلفای کمتر از شفاف کامل) — برای حذف حاشیه‌ی شفاف دور لوگو */
    function bm_wm_visible_box($image) {
        $w      = imagesx($image);
        $h      = imagesy($image);
        $top    = 0;
        $bottom = $h - 1;
        $left   = 0;
        $right  = $w - 1;
        $limit  = 120; // آلفای GD: 0 = کاملاً پیدا، 127 = کاملاً شفاف

        if ($w * $h > 3000000) {
            // لوگوی خیلی بزرگ: پیمایش پیکسل‌به‌پیکسل کند می‌شود؛ بدون برش حاشیه
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
     * تصویر لوگو با حاشیه‌ی شفاف حذف‌شده (با کش در همان اجرا)
     * خروجی: ['img' => GD, 'box' => کادر دیدنی, 'aspect' => عرض/ارتفاع] یا null
     */
    function bm_wm_logo_source($path) {
        static $cache = [];
        if (array_key_exists($path, $cache)) {
            return $cache[$path];
        }
        $cache[$path] = null;
        list($source, $type) = bm_wm_load_image($path);
        if (!$source) {
            return null;
        }
        imagealphablending($source, false);
        imagesavealpha($source, true);
        $box = bm_wm_visible_box($source);
        if ($box['empty'] || $box['w'] < 2 || $box['h'] < 2) {
            bm_wm_free($source);
            return null;
        }
        $cache[$path] = ['img' => $source, 'box' => $box, 'aspect' => $box['w'] / $box['h'], 'type' => $type];
        return $cache[$path];
    }

    /**
     * لایه‌ی لوگو با عرض هدف: اندازه تغییر و شفافیت کلی اعمال می‌شود (با کش در همان اجرا)
     * خروجی: ['img' => GD, 'w', 'h', 'coverage' => آرایه‌ی پوشش ۰..۱ هر پیکسل (برای سایه)] یا null
     * تصویر برگشتی مشترک است؛ فقط از آن کپی بگیرید، تغییرش ندهید و آزادش نکنید.
     */
    function bm_wm_logo_layer($path, $target_w, $opacity) {
        static $cache = [];
        $key = $path . '|' . (int) $target_w . '|' . (int) $opacity;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $cache[$key] = null;

        $source = bm_wm_logo_source($path);
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

        // شفافیت کلی + نقشه‌ی پوشش برای سایه
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

    /** محوِ جعبه‌ای افقی/عمودی روی نقشه‌ی پوشش (برای سایه‌ی نرم) */
    function bm_wm_box_blur(array $map, $w, $h, $radius, $vertical) {
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
     * لایه‌ی سایه‌ی نرم زیر لوگو (با کش): ['img' => GD, 'w', 'h', 'pad', 'dy']
     * $strength: ۰..۱ شدت سایه؛ $light = true → هاله‌ی روشن (برای لوگوی تیره روی گوشه‌های روشن)
     */
    function bm_wm_shadow_layer($cache_key, array $logo, $strength, $light = false) {
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
            $map = bm_wm_box_blur($map, $w, $h, $radius, false);
            $map = bm_wm_box_blur($map, $w, $h, $radius, true);
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

        // هاله‌ی روشن بدون جابه‌جایی، سایه‌ی تیره کمی پایین‌تر
        $cache[$key] = ['img' => $image, 'w' => $w, 'h' => $h, 'pad' => $pad, 'dy' => $light ? 0 : (int) round($radius * 0.5)];
        return $cache[$key];
    }

    /** میانگین روشنایی (۰ تا ۱) یک ناحیه از تصویر، با نمونه‌برداری */
    function bm_wm_region_luminance($image, $x0, $y0, $w, $h) {
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

    /** قالب خروجی از روی پسوند مقصد؛ اگر نامشخص بود همان نوع مبدأ (و در نهایت jpg) */
    function bm_wm_output_format($dst, $source_type) {
        $ext = strtolower((string) pathinfo(parse_url((string) $dst, PHP_URL_PATH) ?: (string) $dst, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, ['jpg', 'png', 'gif', 'webp'], true)) {
            $ext = in_array($source_type, ['jpg', 'png', 'gif', 'webp'], true) ? $source_type : 'jpg';
        }
        if ($ext === 'webp' && !function_exists('imagewebp')) {
            $ext = 'jpg';
        }
        if ($ext === 'gif' && !function_exists('imagegif')) {
            $ext = 'jpg';
        }
        return $ext;
    }

    /** ذخیره‌ی تصویر؛ برای فایل‌ها اول در فایل موقت کنار مقصد نوشته و بعد جابه‌جا می‌شود */
    function bm_wm_save($image, $dst, $format, $jpeg_quality) {
        $direct = strpos((string) $dst, '://') !== false;
        $target = $direct ? $dst : $dst . '.bmwm' . mt_rand(1000, 9999) . '.tmp';
        switch ($format) {
            case 'png':
                imagesavealpha($image, false);
                $ok = @imagepng($image, $target, 6);
                break;
            case 'gif':
                $ok = @imagegif($image, $target);
                break;
            case 'webp':
                $ok = @imagewebp($image, $target, 90);
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

    /**
     * نشاندن لوگو روی کاور
     * $src: فایل کاور؛ $dst: مسیر خروجی (می‌تواند همان $src باشد — فایل اول کامل خوانده می‌شود)
     * خروجی: [
     *   'ok' => bool, 'skipped' => bool, 'reason' => small, 'error' => gd|logo_missing|logo_not_image|logo_not_transparent|cover|write|disabled,
     *   'width', 'height' (کاور)، 'logo_width', 'logo_height', 'x', 'y', 'variant' => light|dark, 'luminance', 'position', 'format', 'stamp'
     * ]
     * وقتی skipped = true فایلی نوشته نمی‌شود.
     */
    function bm_wm_apply($src, $dst, array $options) {
        $o      = bm_wm_normalize_options($options);
        $result = [
            'ok' => false, 'skipped' => false, 'reason' => '', 'error' => '', 'detail' => '',
            'width' => 0, 'height' => 0, 'logo_width' => 0, 'logo_height' => 0, 'x' => 0, 'y' => 0,
            'variant' => '', 'luminance' => null, 'position' => $o['position'], 'format' => '', 'stamp' => '',
            'logo' => $o['logo'],
        ];

        if (!bm_wm_available()) {
            $result['error'] = 'gd';
            return $result;
        }
        $logo_info = bm_wm_logo_info($o['logo']);
        if (!$logo_info['ok']) {
            $result['error'] = $logo_info['error'] === 'missing' || $logo_info['error'] === 'no_path' ? 'logo_missing'
                : ($logo_info['error'] === 'not_transparent' ? 'logo_not_transparent' : 'logo_not_image');
            return $result;
        }

        list($cover, $type) = bm_wm_load_image($src);
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
            bm_wm_free($cover);
            $result['ok']      = true;
            $result['skipped'] = true;
            $result['reason']  = 'small';
            return $result;
        }

        // اندازه‌ی لوگو: درصدی از عرض کاور؛ برای لوگوهای خیلی کشیده (مثل نوشته‌ی تنها) ارتفاع از ۳٪ عرض کاور کمتر نمی‌شود
        // (تا حداکثر ۱.۷ برابر عرض تنظیم‌شده) و برای لوگوهای مربعی/بلند ارتفاع از ۱۲٪ عرض کاور بیشتر نمی‌شود
        $source = bm_wm_logo_source($o['logo']);
        if (!$source) {
            bm_wm_free($cover);
            $result['error'] = 'logo_not_image';
            return $result;
        }
        $aspect = max(0.05, (float) $source['aspect']);
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
        $margin = (int) round($width * $o['margin'] / 100);

        $layer = bm_wm_logo_layer($o['logo'], $logo_w, $o['opacity']);
        if (!$layer) {
            bm_wm_free($cover);
            $result['error'] = 'logo_not_image';
            return $result;
        }
        $variant   = 'light';
        $layer_key = $o['logo'];
        $logo_h    = $layer['h'];

        $place = function ($lw, $lh) use ($o, $width, $height, $margin) {
            $x = $o['position'] === 'bottom-left' || $o['position'] === 'top-left' ? $margin : $width - $margin - $lw;
            $y = $o['position'] === 'top-left' || $o['position'] === 'top-right' ? $margin : $height - $margin - $lh;
            return [max(0, $x), max(0, $y)];
        };
        list($x, $y) = $place($logo_w, $logo_h);

        // روشنایی گوشه → نسخه‌ی تیره یا سایه‌ی پررنگ‌تر
        $luminance       = bm_wm_region_luminance($cover, $x - (int) ($margin / 2), $y - (int) ($margin / 2), $logo_w + $margin, $logo_h + $margin);
        $shadow_strength = $o['shadow_opacity'] / 100;
        if ($luminance > $o['light_threshold']) {
            $dark_info = $o['logo_dark'] !== '' ? bm_wm_logo_info($o['logo_dark']) : ['ok' => false];
            $dark      = $dark_info['ok'] ? bm_wm_logo_layer($o['logo_dark'], $logo_w, $o['opacity']) : null;
            if ($dark) {
                // لوگوی تیره با هاله‌ی روشن (اگر گوشه لکه‌های تیره داشت باز هم خوانا می‌ماند)
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

        // بوم سفید (برای PNG شفاف هم خروجی درست می‌دهد) + کاور + سایه + لوگو
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $cover, 0, 0, 0, 0, $width, $height);
        bm_wm_free($cover);

        if ($o['shadow'] && $shadow_strength > 0) {
            $shadow = bm_wm_shadow_layer($layer_key . '|' . $logo_w . '|' . $o['opacity'], $layer, $shadow_strength, $variant === 'dark');
            imagecopy($canvas, $shadow['img'], $x - $shadow['pad'], $y - $shadow['pad'] + $shadow['dy'], 0, 0, $shadow['w'], $shadow['h']);
        }
        imagecopy($canvas, $layer['img'], $x, $y, 0, 0, $layer['w'], $layer['h']);

        $format = bm_wm_output_format($dst, $type);
        $saved  = bm_wm_save($canvas, $dst, $format, $o['jpeg_quality']);
        bm_wm_free($canvas);
        if (!$saved) {
            $result['error']  = 'write';
            $result['detail'] = (string) $dst;
            return $result;
        }

        $result['ok']          = true;
        $result['logo_width']  = $layer['w'];
        $result['logo_height'] = $layer['h'];
        $result['x']           = $x;
        $result['y']           = $y;
        $result['variant']     = $variant;
        $result['luminance']   = round($luminance, 3);
        $result['format']      = $format;
        $result['stamp']       = bm_wm_stamp($o);
        return $result;
    }

    /** یک خط فارسی از نتیجه‌ی bm_wm_apply برای گزارش‌ها */
    function bm_wm_describe($result) {
        if (!is_array($result)) {
            return '';
        }
        if (!empty($result['ok']) && empty($result['skipped'])) {
            return 'لوگو نشست (' . (int) $result['logo_width'] . 'px، ' . bm_wm_position_label($result['position'])
                . ($result['variant'] === 'dark' ? '، نسخه‌ی تیره روی گوشه‌ی روشن' : '') . ')';
        }
        if (!empty($result['skipped'])) {
            return 'کاور کوچک (' . (int) $result['width'] . '×' . (int) $result['height'] . ') — بدون لوگو';
        }
        $logo = isset($result['logo']) ? (string) $result['logo'] : '';
        switch (isset($result['error']) ? $result['error'] : '') {
            case 'gd':
                return 'لوگو ننشست — GD روی این هاست فعال نیست';
            case 'logo_missing':
                return 'لوگو ننشست — فایل لوگو پیدا نشد: ' . $logo;
            case 'logo_not_transparent':
                return 'لوگو ننشست — فایل لوگو باید PNG شفاف باشد: ' . $logo;
            case 'logo_not_image':
                return 'لوگو ننشست — فایل لوگو تصویر معتبر نیست: ' . $logo;
            case 'cover':
                return 'لوگو ننشست — کاور قابل خواندن نیست' . (!empty($result['detail']) ? ' (' . $result['detail'] . ')' : '');
            case 'write':
                return 'لوگو ننشست — ذخیره‌ی کاور ناموفق بود' . (!empty($result['detail']) ? ' (' . $result['detail'] . ')' : '');
            case 'disabled':
                return 'لوگو غیرفعال است';
        }
        return 'لوگو ننشست';
    }
}
