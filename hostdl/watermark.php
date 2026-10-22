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
 *   - پیش‌فرض (plate = auto): پشت لوگو یک پلاک مربع/مستطیل با گوشه‌ی گرد می‌گذارد که رنگش رنگ غالبِ پررنگ همان کاور است
 *     (سبک مربع لوگوی رادیوجوان)؛ لوگوی سفید وسط پلاک. کاور سیاه‌وسفید → پلاک خاکستری تیره. plate_color می‌تواند رنگ ثابت باشد
 *   - plate_style = glass (پیش‌فرض): پلاک شیشه‌ای — کاورِ زیر پلاک تار می‌شود، رنگ پلاک با شفافیت plate_opacity رویش می‌نشیند
 *     و یک لبه/درخشش روشن ملایم می‌گیرد؛ plate_style = solid: پلاک پر و ساده (plate_opacity = 100 در هر دو حالت یعنی کاملاً پر)
 *   - plate = none: لوگوی PNG شفاف را با عرضی نسبی (درصدی از عرض کاور) در گوشه‌ی انتخابی می‌نشاند؛ حاشیه‌ی شفاف دور لوگو خودکار حذف می‌شود
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
            // پلاک رنگی پشت لوگو (سبک رادیوجوان): none = لوگوی آزاد با سایه؛ auto = مربع برای لوگوی مربعی، مستطیل برای لوگوی کشیده
            'plate'           => 'auto',        // none | auto | square | rect
            'plate_color'     => 'auto',        // auto = رنگ غالبِ پررنگ همان کاور؛ یا رنگ ثابت مثل '#1e1e1e'
            'plate_size'      => 13,            // ضلع پلاک مربع، درصد عرض کاور (پلاک مستطیل از عرض لوگو + حاشیه ساخته می‌شود)
            'plate_radius'    => 10,            // گردی گوشه‌ها، درصد ضلع کوچک‌تر پلاک (0 = تیز، 50 = کاملاً گرد)
            'plate_style'     => 'glass',       // glass = شیشه‌ای (پشت پلاک تار + لبه‌ی روشن) | solid = پر و ساده
            'plate_opacity'   => 72,            // پُری پلاک، درصد: 100 = کاملاً پر؛ کمتر = کاور (تار) از پشت دیده می‌شود
            'plate_padding'   => 18,            // فاصله‌ی لوگو تا لبه‌ی پلاک مربع، درصد ضلع
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
            'plate'         => 'plate',
            'plate_color'   => 'plate_color',
            'plate_size'    => 'plate_size',
            'plate_radius'  => 'plate_radius',
            'plate_opacity' => 'plate_opacity',
            'plate_style'   => 'plate_style',
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
        $plate                = strtolower(trim((string) $o['plate']));
        if ($plate === '' || $plate === '0' || $plate === 'false' || $plate === 'off') {
            $plate = 'none';
        } elseif ($plate === '1' || $plate === 'true' || $plate === 'on') {
            $plate = 'auto';
        }
        $o['plate']           = in_array($plate, ['none', 'auto', 'square', 'rect'], true) ? $plate : 'auto';
        $plate_color          = strtolower(trim((string) $o['plate_color']));
        $o['plate_color']     = bm_wm_hex_to_rgb($plate_color) !== null ? '#' . ltrim($plate_color, '#') : 'auto';
        $o['plate_size']      = min(50, max(5, (float) $o['plate_size']));
        $o['plate_radius']    = min(50, max(0, (float) $o['plate_radius']));
        $o['plate_opacity']   = min(100, max(10, (int) $o['plate_opacity']));
        $o['plate_padding']   = min(40, max(5, (float) $o['plate_padding']));
        $plate_style          = strtolower(trim((string) $o['plate_style']));
        $o['plate_style']     = in_array($plate_style, ['solid', 'flat', 'plain'], true) ? 'solid' : 'glass';
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

    /** RGB (۰..۲۵۵) → HSV (۰..۱) */
    function bm_wm_rgb_to_hsv($r, $g, $b) {
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

    /** HSV (۰..۱) → RGB (۰..۲۵۵) */
    function bm_wm_hsv_to_rgb($h, $s, $v) {
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

    /** '#1e90ff' → [30, 144, 255] یا null */
    function bm_wm_hex_to_rgb($hex) {
        $hex = ltrim(trim((string) $hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    function bm_wm_rgb_to_hex(array $rgb) {
        return sprintf('#%02x%02x%02x', (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
    }

    /** روشنایی ادراکی ۰..۱ یک رنگ */
    function bm_wm_rgb_luminance(array $rgb) {
        return (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;
    }

    /**
     * رنگ غالبِ پررنگ کاور (برای پلاک لوگو): رنگ اشباع‌شده‌ای که بیشترین سطح را دارد؛ سیاه/سفید/خاکستری نادیده گرفته می‌شود
     * (شبیه کاری که رادیوجوان با رنگ مربع لوگویش می‌کند). کاور ۶۴ پیکسلی نمونه‌برداری و رنگ‌ها در خانه‌های HSV جمع می‌شوند.
     * خروجی: ['rgb' => [r, g, b], 'hex' => '#rrggbb', 'saturated' => bool]
     */
    function bm_wm_dominant_color($image) {
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
                list($hue, $s, $v) = bm_wm_rgb_to_hsv($r, $g, $b);
                // وزن: اشباع بالا و نه خیلی تیره؛ تقریباً سفیدها حذف
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
        bm_wm_free($small);

        $best = null;
        foreach ($bins as $bin) {
            if ($best === null || $bin[0] > $best[0]) {
                $best = $bin;
            }
        }
        // کاور سیاه‌وسفید یا بدون رنگ شاخص → پلاک خاکستری تیره
        if ($best === null || $best[0] < $sw * $sh * 0.012) {
            return ['rgb' => [38, 38, 44], 'hex' => '#26262c', 'saturated' => false];
        }
        $rgb = [$best[1] / $best[0], $best[2] / $best[0], $best[3] / $best[0]];
        // بازه‌ی دلپذیر برای پلاک: نه خیلی روشن (لوگوی سفید خوانا بماند) نه خیلی تیره/کدر
        list($hue, $s, $v) = bm_wm_rgb_to_hsv($rgb[0], $rgb[1], $rgb[2]);
        $s   = min(0.92, max(0.3, $s));
        $v   = min(0.78, max(0.3, $v));
        $rgb = bm_wm_hsv_to_rgb($hue, $s, $v);
        return ['rgb' => $rgb, 'hex' => bm_wm_rgb_to_hex($rgb), 'saturated' => true];
    }

    /**
     * لایه‌ی پلاک: مستطیل با گوشه‌ی گرد و لبه‌ی نرم (۴ برابر بزرگ کشیده و کوچک می‌شود)
     * خروجی: ['img' => GD, 'w', 'h', 'coverage' => پوشش ۰..۱ هر پیکسل (برای سایه)]
     */
    function bm_wm_plate_layer($w, $h, $radius, array $rgb, $opacity) {
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
        bm_wm_free($big);

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
     * لایه‌های پلاک شیشه‌ای:
     *   backdrop = همان تکه‌ی کاور زیر پلاک، تارشده و بریده به شکل پلاک (زیر رنگ پلاک می‌نشیند)
     *   gloss    = لبه‌ی روشن نازک + درخشش ملایم بالای پلاک (روی رنگ پلاک، زیر لوگو)
     * $plate خروجی bm_wm_plate_layer است (برای شکل گوشه‌گرد)؛ $x,$y جای پلاک روی کاور
     */
    function bm_wm_glass_layers($cover, $x, $y, array $plate, $radius) {
        $w  = $plate['w'];
        $h  = $plate['h'];
        $cw = imagesx($cover);
        $ch = imagesy($cover);

        // --- تار کردن ناحیه‌ی زیر پلاک (با حاشیه تا لبه‌ها تیره/کدر نشوند) ---
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
        bm_wm_free($small);

        $backdrop = imagecreatetruecolor($w, $h);
        imagealphablending($backdrop, false);
        imagesavealpha($backdrop, true);
        imagefill($backdrop, 0, 0, 0x7F000000);
        $gloss = imagecreatetruecolor($w, $h);
        imagealphablending($gloss, false);
        imagesavealpha($gloss, true);
        imagefill($gloss, 0, 0, 0x7F000000);

        // شکل داخلی (کمی کوچک‌تر) برای به‌دست‌آوردن نوار لبه
        $inset = max(1, (int) round(min($w, $h) * 0.014));
        $inner = bm_wm_plate_layer(max(2, $w - 2 * $inset), max(2, $h - 2 * $inset), max(0, $radius - $inset), [255, 255, 255], 100);
        $iw    = $inner['w'];
        $ih    = $inner['h'];
        bm_wm_free($inner['img']);

        $ox = $x - $x0;
        $oy = $y - $y0;
        for ($py = 0; $py < $h; $py++) {
            $t = $py / max(1, $h - 1);                                   // ۰ بالا … ۱ پایین
            $edge_strength = 0.42 - 0.27 * $t;                           // لبه: بالا روشن‌تر
            $sheen = $t < 0.45 ? 0.11 * (1 - $t / 0.45) : 0.0;           // درخشش ملایم نیمه‌ی بالایی
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
        bm_wm_free($region);
        return ['backdrop' => $backdrop, 'gloss' => $gloss];
    }

    /**
     * نشاندن لوگو روی کاور
     * $src: فایل کاور؛ $dst: مسیر خروجی (می‌تواند همان $src باشد — فایل اول کامل خوانده می‌شود)
     * خروجی: [
     *   'ok' => bool, 'skipped' => bool, 'reason' => small, 'error' => gd|logo_missing|logo_not_image|logo_not_transparent|cover|write|disabled,
     *   'width', 'height' (کاور)، 'logo_width', 'logo_height', 'x', 'y', 'variant' => light|dark, 'luminance', 'position', 'format', 'stamp',
     *   'plate' => ''|square|rect, 'plate_style' => glass|solid, 'plate_color' => '#rrggbb', 'plate_width', 'plate_height'
     * ]
     * وقتی skipped = true فایلی نوشته نمی‌شود.
     */
    function bm_wm_apply($src, $dst, array $options) {
        $o      = bm_wm_normalize_options($options);
        $result = [
            'ok' => false, 'skipped' => false, 'reason' => '', 'error' => '', 'detail' => '',
            'width' => 0, 'height' => 0, 'logo_width' => 0, 'logo_height' => 0, 'x' => 0, 'y' => 0,
            'variant' => '', 'luminance' => null, 'position' => $o['position'], 'format' => '', 'stamp' => '',
            'logo' => $o['logo'], 'plate' => '', 'plate_style' => '', 'plate_color' => '', 'plate_width' => 0, 'plate_height' => 0,
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

        $source = bm_wm_logo_source($o['logo']);
        if (!$source) {
            bm_wm_free($cover);
            $result['error'] = 'logo_not_image';
            return $result;
        }
        $aspect = max(0.05, (float) $source['aspect']);
        $margin = (int) round($width * $o['margin'] / 100);

        // عرض لوگوی آزاد: درصدی از عرض کاور؛ برای لوگوهای خیلی کشیده ارتفاع از ۳٪ عرض کاور کمتر نمی‌شود
        // (تا حداکثر ۱.۷ برابر عرض تنظیم‌شده) و برای لوگوهای مربعی/بلند ارتفاع از ۱۲٪ عرض کاور بیشتر نمی‌شود
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
            /* ---------- حالت پلاک: مربع/مستطیل رنگی پشت لوگو (رنگ = رنگ غالب کاور یا رنگ ثابت) ---------- */
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
                $plate_rgb = bm_wm_hex_to_rgb($o['plate_color']);
            }
            if ($plate_rgb === null) {
                $dominant  = bm_wm_dominant_color($cover);
                $plate_rgb = $dominant['rgb'];
            }
            $plate_lum = bm_wm_rgb_luminance($plate_rgb);
            $variant   = 'light';
            $logo_path = $o['logo'];
            if ($plate_lum > 0.62) {
                $dark_info = $o['logo_dark'] !== '' ? bm_wm_logo_info($o['logo_dark']) : ['ok' => false];
                if ($dark_info['ok'] && bm_wm_logo_source($o['logo_dark'])) {
                    $variant   = 'dark';
                    $logo_path = $o['logo_dark'];
                } else {
                    // پلاک روشن بدون لوگوی تیره: پلاک کمی تیره‌تر می‌شود تا لوگوی سفید خوانا بماند
                    list($ph_h, $ph_s, $ph_v) = bm_wm_rgb_to_hsv($plate_rgb[0], $plate_rgb[1], $plate_rgb[2]);
                    $plate_rgb = bm_wm_hsv_to_rgb($ph_h, min(0.95, $ph_s + 0.1), $ph_v * 0.72);
                }
            }

            $logo_layer = bm_wm_logo_layer($logo_path, $lw, 100);
            if (!$logo_layer) {
                bm_wm_free($cover);
                $result['error'] = 'logo_not_image';
                return $result;
            }
            $lh = $logo_layer['h'];
            if ($shape === 'square' && $lh > $pw - 2 * (int) round($pw * $o['plate_padding'] / 100)) {
                // لوگوی بلندتر از پهن: با ارتفاع جا بیفتد
                $box        = max(4, $pw - 2 * (int) round($pw * $o['plate_padding'] / 100));
                $lw         = max(4, (int) round($box * $aspect));
                $logo_layer = bm_wm_logo_layer($logo_path, $lw, 100);
                $lh         = $logo_layer['h'];
            }
            if ($shape !== 'square') {
                $ph = $lh + 2 * $pad_y;
            }

            $radius = min($pw, $ph) * $o['plate_radius'] / 100;
            $plate  = bm_wm_plate_layer($pw, $ph, $radius, $plate_rgb, $o['plate_opacity']);

            list($x, $y) = $place($pw, $ph);
            $luminance   = bm_wm_region_luminance($cover, $x - (int) ($margin / 2), $y - (int) ($margin / 2), $pw + $margin, $ph + $margin);

            // شیشه‌ای: کاور زیر پلاک تار می‌شود و لبه/درخشش روشن می‌گیرد (با پُری ۱۰۰ تفاوتی با پلاک پر ندارد)
            $glass = ($o['plate_style'] === 'glass' && $o['plate_opacity'] < 100) ? bm_wm_glass_layers($cover, $x, $y, $plate, $radius) : null;

            $canvas = imagecreatetruecolor($width, $height);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
            imagecopy($canvas, $cover, 0, 0, 0, 0, $width, $height);
            bm_wm_free($cover);

            if ($o['shadow']) {
                $shadow = bm_wm_shadow_layer('plate|' . $pw . 'x' . $ph . '|' . round($radius, 1) . '|' . $o['plate_opacity'], $plate, 0.22, false);
                imagecopy($canvas, $shadow['img'], $x - $shadow['pad'], $y - $shadow['pad'] + $shadow['dy'], 0, 0, $shadow['w'], $shadow['h']);
            }
            if ($glass) {
                imagecopy($canvas, $glass['backdrop'], $x, $y, 0, 0, $pw, $ph);
            }
            imagecopy($canvas, $plate['img'], $x, $y, 0, 0, $pw, $ph);
            bm_wm_free($plate['img']);
            if ($glass) {
                imagecopy($canvas, $glass['gloss'], $x, $y, 0, 0, $pw, $ph);
                bm_wm_free($glass['backdrop']);
                bm_wm_free($glass['gloss']);
            }
            imagecopy($canvas, $logo_layer['img'], $x + (int) round(($pw - $lw) / 2), $y + (int) round(($ph - $lh) / 2), 0, 0, $logo_layer['w'], $logo_layer['h']);

            $result['plate']        = $shape;
            $result['plate_style']  = $glass ? 'glass' : 'solid';
            $result['plate_color']  = bm_wm_rgb_to_hex($plate_rgb);
            $result['plate_width']  = $pw;
            $result['plate_height'] = $ph;
            $result['logo_width']   = $logo_layer['w'];
            $result['logo_height']  = $logo_layer['h'];
        } else {
            /* ---------- حالت آزاد: لوگو با سایه، بدون پلاک ---------- */
            $layer = bm_wm_logo_layer($o['logo'], $logo_w, $o['opacity']);
            if (!$layer) {
                bm_wm_free($cover);
                $result['error'] = 'logo_not_image';
                return $result;
            }
            $variant   = 'light';
            $layer_key = $o['logo'];
            $logo_h    = $layer['h'];
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

            $result['logo_width']  = $layer['w'];
            $result['logo_height'] = $layer['h'];
        }

        $format = bm_wm_output_format($dst, $type);
        $saved  = bm_wm_save($canvas, $dst, $format, $o['jpeg_quality']);
        bm_wm_free($canvas);
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
        $result['stamp']     = bm_wm_stamp($o);
        return $result;
    }

    /** یک خط فارسی از نتیجه‌ی bm_wm_apply برای گزارش‌ها */
    function bm_wm_describe($result) {
        if (!is_array($result)) {
            return '';
        }
        if (!empty($result['ok']) && empty($result['skipped'])) {
            if (!empty($result['plate'])) {
                return 'لوگو نشست (پلاک ' . (isset($result['plate_style']) && $result['plate_style'] === 'glass' ? 'شیشه‌ای ' : '') . ($result['plate'] === 'square' ? 'مربع ' : 'مستطیل ') . (int) $result['plate_width'] . 'px به رنگ '
                    . $result['plate_color'] . '، ' . bm_wm_position_label($result['position'])
                    . ($result['variant'] === 'dark' ? '، لوگوی تیره روی پلاک روشن' : '') . ')';
            }
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
