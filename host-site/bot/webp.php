<?php
/**
 * WebP برای سایزهای نمایشی کاور (bot/webp.php)
 *
 * فایل اصلی کاور (JPEG/PNG) همان‌طور که دانلود/برش/لوگودار شده، دست‌نخورده آپلود می‌شود و «full» وردپرس می‌ماند
 * (wp_get_attachment_url، اسکیما، og:image، باز کردن تصویر با کیفیت کامل). همه‌ی سایزهای نمایشی که وردپرس/قالب
 * از آن می‌سازد (thumb1 … thumb4، thumb2x، medium، large، …) به‌جای JPEG با WebP ساخته می‌شوند:
 *   Name.jpg (اصلی، دست‌نخورده)  +  Name-380x380.webp  +  Name-190x190.webp  +  …
 *
 * سازوکار: فیلتر استاندارد وردپرس image_editor_output_format (از نسخه‌ی ۵.۸) فقط در لحظه‌ی ساخت سایزها
 * (media_handle_sideload / wp_generate_attachment_metadata در خود ربات) روشن می‌شود و بلافاصله برداشته می‌شود؛
 * روی آپلودهای دیگر سایت اثری ندارد. اگر GD/Imagick هاست WebP نداشته باشد، همه‌چیز مثل قبل (JPEG) می‌ماند و فقط در گزارش می‌آید.
 *
 * تنظیمات ($bot_webp_* در config.php):
 *   $bot_webp         = true;   // false = سایزها مثل قبل با فرمت خود کاور ساخته می‌شوند
 *   $bot_webp_quality = 82;     // کیفیت WebP (۱ تا ۱۰۰)؛ پیش‌فرض خود وردپرس ۸۶
 *
 * کاورهای قدیمی آرشیو: bot/webp-batch.php
 */

if (!function_exists('bm_webp_options')) {

    /** تنظیمات از config.php ($bot_webp_*) با پیش‌فرض‌ها */
    function bm_webp_options() {
        $enabled = isset($GLOBALS['bot_webp']) ? (bool) $GLOBALS['bot_webp'] : true;
        $quality = isset($GLOBALS['bot_webp_quality']) ? (int) $GLOBALS['bot_webp_quality'] : 82;
        if ($quality < 1 || $quality > 100) {
            $quality = 82;
        }
        return ['enabled' => $enabled, 'quality' => $quality];
    }

    /** آیا ویرایشگر تصویر وردپرس (GD یا Imagick) روی این هاست WebP می‌سازد؟ */
    function bm_webp_supported() {
        static $supported = null;
        if ($supported === null) {
            $supported = function_exists('wp_image_editor_supports') && wp_image_editor_supports(['mime_type' => 'image/webp']);
        }
        return $supported;
    }

    /** WebP فعال است و هاست هم می‌تواند؟ */
    function bm_webp_active() {
        $opts = bm_webp_options();
        return !empty($opts['enabled']) && bm_webp_supported();
    }

    /** فرمت‌هایی که سایزهای فرعی‌شان WebP می‌شود (GIF عمداً نه: انیمیشن) */
    function bm_webp_source_mimes() {
        return ['image/jpeg', 'image/png'];
    }

    /** نام فایل بدون پسوند (Name-380x380.jpg → Name-380x380) */
    function bm_webp_stem($name) {
        $name = wp_basename((string) $name);
        return preg_replace('/\.[^.]*$/', '', $name);
    }

    /**
     * آیا این نام، یک سایز فرعی وردپرس از فایل اصلی است؟ (Name-380x380.jpg از Name.jpg)
     * ویرایشگر GD/Imagick وقت ساخت سایزهای فرعی نام فایل را به فیلتر نمی‌دهد (null) — آن حالت هم سایز فرعی است.
     * خود فایل اصلی و نسخه‌های -scaled / -rotated آن «سایز فرعی» نیستند و دست نمی‌خورند.
     */
    function bm_webp_is_subsize_filename($filename, $original_stem = '') {
        if ($filename === null || $filename === '') {
            return true;
        }
        $stem = bm_webp_stem($filename);
        if ($original_stem === '') {
            return (bool) preg_match('/-\d+x\d+$/', $stem);
        }
        return (bool) preg_match('/^' . preg_quote($original_stem, '/') . '-\d+x\d+$/', $stem);
    }

    /**
     * روشن کردن WebP برای ساخت سایزهای فرعی؛ خروجی را بعد از کار به bm_webp_end بدهید
     *   $original_file  مسیر یا نام فایل اصلی که آپلود/بازسازی می‌شود (خودش هرگز تبدیل نمی‌شود)
     *   $force          null = طبق config.php | true/false = اجباری (برای ابزار دسته‌ای و بازگشت)
     * خروجی: ['on' => bool, 'quality' => int, 'filters' => [...]]
     */
    function bm_webp_begin($original_file = '', $force = null) {
        $opts    = bm_webp_options();
        $handle  = ['on' => false, 'quality' => $opts['quality'], 'filters' => []];
        $enabled = $force === null ? $opts['enabled'] : (bool) $force;
        if (!$enabled || !bm_webp_supported()) {
            return $handle;
        }

        $stem    = (string) $original_file !== '' ? bm_webp_stem($original_file) : '';
        $quality = (int) $opts['quality'];

        $format_filter = function ($formats, $filename = null, $mime_type = null) use ($stem) {
            if (!is_array($formats)) {
                $formats = [];
            }
            if (!bm_webp_is_subsize_filename($filename, $stem)) {
                // خود فایل اصلی (یا نسخه‌ی scaled/rotated آن): همان فرمت خودش بماند
                foreach (bm_webp_source_mimes() as $mime) {
                    unset($formats[$mime]);
                }
                return $formats;
            }
            foreach (bm_webp_source_mimes() as $mime) {
                $formats[$mime] = 'image/webp';
            }
            return $formats;
        };
        $quality_filter = function ($q, $mime_type = '') use ($quality) {
            return $mime_type === 'image/webp' ? $quality : $q;
        };
        // «تصویر بزرگ» وردپرس (-scaled) ساخته نشود تا full همیشه خود فایل اصلی بماند (کاور ربات به‌هرحال حداکثر $bot_cover_size است)
        $threshold_filter = '__return_false';

        add_filter('image_editor_output_format', $format_filter, 20, 3);
        add_filter('wp_editor_set_quality', $quality_filter, 20, 2);
        add_filter('big_image_size_threshold', $threshold_filter, 20);

        $handle['on']      = true;
        $handle['filters'] = [
            ['image_editor_output_format', $format_filter, 20],
            ['wp_editor_set_quality', $quality_filter, 20],
            ['big_image_size_threshold', $threshold_filter, 20],
        ];
        return $handle;
    }

    /** برداشتن فیلترهای bm_webp_begin */
    function bm_webp_end($handle) {
        if (!is_array($handle) || empty($handle['filters'])) {
            return;
        }
        foreach ($handle['filters'] as $f) {
            remove_filter($f[0], $f[1], $f[2]);
        }
    }

    /**
     * پاک کردن فایل سایزهای قبلی که در متادیتای تازه نیستند (بعد از بازسازی سایزها؛ مثلاً JPEG‌های قدیمی وقتی سایزها WebP شدند)
     * ایمنی: فقط فایل‌های همان پوشه که نامشان با «stem-» شروع می‌شود؛ خود فایل اصلی و original_image هرگز پاک نمی‌شوند.
     * خروجی: ['deleted' => تعداد, 'bytes' => حجم آزادشده]
     */
    function bm_webp_delete_stale_sizes($file, $old_meta, $new_meta) {
        $out  = ['deleted' => 0, 'bytes' => 0];
        $file = (string) $file;
        if ($file === '' || !is_array($old_meta) || empty($old_meta['sizes']) || !is_array($old_meta['sizes'])) {
            return $out;
        }
        $dir  = dirname($file);
        $base = wp_basename($file);
        $stem = bm_webp_stem($base);
        $keep = [$base => true];
        foreach ([$old_meta, $new_meta] as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (!empty($m['original_image'])) {
                $keep[wp_basename((string) $m['original_image'])] = true;
            }
            if (!empty($m['file'])) {
                $keep[wp_basename((string) $m['file'])] = true;
            }
        }
        if (is_array($new_meta) && !empty($new_meta['sizes']) && is_array($new_meta['sizes'])) {
            foreach ($new_meta['sizes'] as $size) {
                if (is_array($size) && !empty($size['file'])) {
                    $keep[wp_basename((string) $size['file'])] = true;
                }
            }
        }
        $seen = [];
        foreach ($old_meta['sizes'] as $size) {
            if (!is_array($size) || empty($size['file'])) {
                continue;
            }
            $name = wp_basename((string) $size['file']);
            if (isset($keep[$name]) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            if ($stem === '' || strpos($name, $stem . '-') !== 0) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $bytes = (int) @filesize($path);
            if (@unlink($path)) {
                $out['deleted']++;
                $out['bytes'] += $bytes;
            }
        }
        return $out;
    }

    /**
     * خلاصه‌ی سایزهای یک اتچمنت از متادیتا
     * خروجی: ['sizes' => تعداد کل, 'webp' => تعداد WebP, 'other' => تعداد غیر WebP, 'bytes' => حجم همه‌ی سایزها, 'files' => نام فایل‌های یکتا]
     */
    function bm_webp_summary($meta) {
        $out = ['sizes' => 0, 'webp' => 0, 'other' => 0, 'bytes' => 0, 'files' => []];
        if (!is_array($meta) || empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return $out;
        }
        $seen = [];
        foreach ($meta['sizes'] as $size) {
            if (!is_array($size) || empty($size['file'])) {
                continue;
            }
            $out['sizes']++;
            $file = (string) $size['file'];
            $mime = isset($size['mime-type']) ? (string) $size['mime-type'] : '';
            if ($mime === '') {
                $mime = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'webp' ? 'image/webp' : 'image/jpeg';
            }
            if ($mime === 'image/webp') {
                $out['webp']++;
            } else {
                $out['other']++;
            }
            if (!isset($seen[$file])) {
                $seen[$file] = true;
                $out['files'][] = $file;
                $out['bytes'] += isset($size['filesize']) ? (int) $size['filesize'] : 0;
            }
        }
        return $out;
    }

    /** متن فارسی کوتاه برای گزارش ربات از روی متادیتای اتچمنت */
    function bm_webp_describe($meta, $handle = null) {
        $s = bm_webp_summary($meta);
        if ($s['sizes'] === 0) {
            return '';
        }
        $size_txt = $s['bytes'] > 0 && function_exists('size_format') ? ' (' . size_format($s['bytes']) . ')' : '';
        if ($s['other'] === 0) {
            return 'سایزهای نمایشی: ' . $s['webp'] . ' WebP' . $size_txt . '، فایل اصلی دست‌نخورده';
        }
        if ($s['webp'] === 0) {
            $opts = bm_webp_options();
            if (empty($opts['enabled'])) {
                return 'سایزهای نمایشی: ' . $s['other'] . ' (WebP در config.php خاموش است)';
            }
            if (!bm_webp_supported()) {
                return 'سایزهای نمایشی: ' . $s['other'] . ' (هاست WebP نمی‌سازد — GD/Imagick بدون WebP)';
            }
            if (is_array($handle) && empty($handle['on'])) {
                return 'سایزهای نمایشی: ' . $s['other'] . ' (WebP روشن نشد)';
            }
            return 'سایزهای نمایشی: ' . $s['other'] . ' (WebP ساخته نشد)';
        }
        return 'سایزهای نمایشی: ' . $s['webp'] . ' WebP و ' . $s['other'] . ' دیگر' . $size_txt;
    }
}
