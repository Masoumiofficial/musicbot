<?php
/**
 * لوگوی سایت روی کاورهای قدیمی (اجرای دسته‌ای) + پیش‌نمایش تنظیمات
 *
 *   /bot/watermark-batch.php                      وضعیت: فایل لوگو، تنظیمات، شمار کاورها، پیش‌نمایش چند کاور اخیر (اصلی / با لوگو)
 *   /bot/watermark-batch.php?preview=ID           تصویر پیش‌نمایش برای یک پست (یا اتچمنت) با تنظیمات فعلی — چیزی ذخیره نمی‌شود
 *   /bot/watermark-batch.php?width=18&opacity=90&position=bottom-left&margin=4&shadow=1&plate=square&plate_color=auto&plate_size=13&plate_radius=10&plate_style=glass&plate_opacity=72
 *                                                 آزمایش تنظیمات دیگر در پیش‌نمایش بدون دست زدن به config.php
 *   دکمه‌ی «شروع / ادامه»                         پست‌ها از جدیدترین به قدیمی‌ترین، دسته‌دسته: نسخه‌ی اصلی کاور کنار فایل نگه داشته می‌شود
 *                                                 (…-bm-original.jpg)، لوگو روی کاور اصلی می‌نشیند و همه‌ی سایزهای بندانگشتی دوباره ساخته
 *                                                 می‌شود. صفحه خودش دسته‌ی بعد را ادامه می‌دهد؛ هر وقت خواستید «توقف» بزنید؛ بعداً «ادامه» از همان‌جا
 *   «بازگرداندن نسخه‌ی اصلی»                     کاورهایی که نسخه‌ی اصلی‌شان نگه داشته شده به حالت قبل برمی‌گردند (بندانگشتی‌ها هم)
 *   «حذف نسخه‌های اصلی»                          وقتی از نتیجه مطمئن شدید، کپی‌های اصلی پاک می‌شوند تا فضای هاست آزاد شود (دیگر بازگشت ندارد)
 *
 * هر اتچمنت با متای _bm_watermark (شناسه‌ی لوگو + تنظیمات) نشان‌دار می‌شود؛ کاوری که همین نشان را دارد دوباره لوگو نمی‌گیرد.
 * اگر لوگو یا تنظیمات را عوض کنید، شناسه عوض می‌شود: کاورهایی که نسخه‌ی اصلی دارند اول به اصل برمی‌گردند و بعد لوگوی جدید می‌گیرند؛
 * کاورهایی که نسخه‌ی اصلی ندارند رد می‌شوند تا دو لوگو روی هم نیفتد.
 * پست‌هایی که ربات نسخه‌ی جدید می‌سازد همین نشان را از همان اول دارند.
 *
 * فقط پشت همان یوزر/رمز ربات. تنظیمات: $bot_watermark_* در config.php
 */
include('config.php');
require_once __DIR__ . '/auth.php';
bm_require_auth();

require('../wp-load.php');

$bm_functions_only = true;
require_once __DIR__ . '/script.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

@set_time_limit(0);
@ignore_user_abort(true);
if (function_exists('wp_raise_memory_limit')) {
    wp_raise_memory_limit('image');
}

$wmb_option  = 'bm_wm_batch_state';
$wmb_uploads = wp_upload_dir();
$wmb_basedir = rtrim((string) $wmb_uploads['basedir'], '/\\');

function wmb_h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** پارامترهای آزمایشی پیش‌نمایش (نام پارامتر GET => کلید تنظیمات) */
function wmb_override_keys() {
    return [
        'width'        => 'width',
        'opacity'      => 'opacity',
        'position'     => 'position',
        'margin'       => 'margin',
        'shadow'       => 'shadow',
        'min_cover'    => 'min_cover',
        'plate'        => 'plate',
        'plate_color'  => 'plate_color',
        'plate_size'   => 'plate_size',
        'plate_radius' => 'plate_radius',
        'plate_opacity' => 'plate_opacity',
        'plate_style'  => 'plate_style',
    ];
}

/** تنظیمات config.php + پارامترهای آزمایشی GET (فقط برای پیش‌نمایش؛ در اجرای دسته‌ای فقط config.php ملاک است) */
function wmb_options(array $overrides = []) {
    $opts = bm_wm_options_from_globals('bot');
    foreach (wmb_override_keys() as $param => $key) {
        if (isset($overrides[$param]) && $overrides[$param] !== '') {
            $opts[$key] = in_array($key, ['position', 'plate', 'plate_color', 'plate_style'], true) ? (string) $overrides[$param] : (float) $overrides[$param];
        }
    }
    return bm_wm_normalize_options($opts);
}

/** پارامترهای آزمایشی فعلی (برای ساختن لینک پیش‌نمایش) */
function wmb_override_params() {
    $out = [];
    foreach (array_keys(wmb_override_keys()) as $param) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $out[$param] = (string) $_GET[$param];
        }
    }
    return $out;
}

/** شناسه‌ی اتچمنت از شناسه‌ی پست یا خود اتچمنت */
function wmb_attachment_id($id) {
    $id   = (int) $id;
    $post = get_post($id);
    if (!$post) {
        return 0;
    }
    if ($post->post_type === 'attachment') {
        return $id;
    }
    return (int) get_post_thumbnail_id($id);
}

/** مسیر نسخه‌ی اصلی نگه‌داشته‌شده (اگر باشد) */
function wmb_original_info($attachment_id) {
    global $wmb_basedir;
    $raw  = get_post_meta($attachment_id, '_bm_wm_original', true);
    $info = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($info) || empty($info['original'])) {
        return null;
    }
    $info['original_abs'] = $wmb_basedir . '/' . ltrim((string) $info['original'], '/');
    $info['file_abs']     = !empty($info['file']) ? $wmb_basedir . '/' . ltrim((string) $info['file'], '/') : '';
    $info['exists']       = file_exists($info['original_abs']);
    return $info;
}

function wmb_relative($abs) {
    global $wmb_basedir;
    $abs = str_replace('\\', '/', (string) $abs);
    $base = str_replace('\\', '/', $wmb_basedir);
    return strpos($abs, $base . '/') === 0 ? substr($abs, strlen($base) + 1) : $abs;
}

/** نشان واترمارک روی اتچمنت */
function wmb_mark_info($attachment_id) {
    $raw  = get_post_meta($attachment_id, '_bm_watermark', true);
    $info = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($info) ? $info : null;
}

/** ساخت دوباره‌ی همه‌ی سایزها از فایل اصلی اتچمنت (سایزها طبق config.php با WebP؛ فایل اصلی دست‌نخورده — bot/webp.php) */
function wmb_regenerate($attachment_id, $file) {
    if (get_attached_file($attachment_id) !== $file) {
        update_attached_file($attachment_id, $file);
    }
    $old  = wp_get_attachment_metadata($attachment_id);
    $webp = bm_webp_begin($file);
    $meta = wp_generate_attachment_metadata($attachment_id, $file);
    bm_webp_end($webp);
    if (is_array($meta) && !empty($meta)) {
        wp_update_attachment_metadata($attachment_id, $meta);
        // فایل سایزهای قبلی که دیگر در متادیتا نیستند (مثلاً JPEG‌های قدیمی وقتی سایزهای تازه WebP شدند) پاک شوند
        if (function_exists('bm_webp_delete_stale_sizes')) {
            bm_webp_delete_stale_sizes($file, $old, $meta);
        }
        return true;
    }
    return false;
}

/**
 * لوگو روی کاور یک اتچمنت
 * خروجی: ['status' => done|already|skipped|failed, 'note' => string, 'bytes' => حجم نسخه‌ی اصلی کپی‌شده]
 */
function wmb_process($attachment_id, array $opts, $stamp, $keep_original) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0 || !get_post($attachment_id)) {
        return ['status' => 'failed', 'note' => 'اتچمنت تصویر شاخص وجود ندارد', 'bytes' => 0];
    }
    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
        return ['status' => 'failed', 'note' => 'فایل کاور روی هاست نیست' . ($file ? ' (' . wmb_relative($file) . ')' : ''), 'bytes' => 0];
    }
    if (!is_writable($file) || !is_writable(dirname($file))) {
        return ['status' => 'failed', 'note' => 'اجازه‌ی نوشتن روی فایل/پوشه‌ی کاور نیست: ' . wmb_relative($file), 'bytes' => 0];
    }

    $mark     = wmb_mark_info($attachment_id);
    $original = wmb_original_info($attachment_id);
    if ($mark && isset($mark['stamp']) && $mark['stamp'] === $stamp) {
        return ['status' => 'already', 'note' => 'همین لوگو را دارد' . (!empty($mark['skipped']) ? ' (کاور کوچک، بدون لوگو)' : ''), 'bytes' => 0];
    }
    if ($mark && (!isset($mark['stamp']) || $mark['stamp'] !== $stamp)) {
        // لوگوی قبلی (لوگو/تنظیمات عوض شده): اول به نسخه‌ی اصلی برگرد
        if ($original && $original['exists']) {
            if (!@copy($original['original_abs'], $file)) {
                return ['status' => 'failed', 'note' => 'بازگرداندن نسخه‌ی اصلی قبل از لوگوی جدید ناموفق بود', 'bytes' => 0];
            }
        } elseif (empty($mark['skipped'])) {
            return ['status' => 'skipped', 'note' => 'لوگوی قبلی دارد و نسخه‌ی اصلی نگه داشته نشده — برای این‌که دو لوگو روی هم نیفتد رد شد', 'bytes' => 0];
        }
    }

    $bytes    = 0;
    $made_now = false;
    if ($keep_original && !($original && $original['exists'])) {
        $info     = pathinfo($file);
        $orig_abs = $info['dirname'] . '/' . $info['filename'] . '-bm-original' . (isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '');
        if (!@copy($file, $orig_abs)) {
            return ['status' => 'failed', 'note' => 'کپی نسخه‌ی اصلی ساخته نشد (فضای هاست یا دسترسی پوشه را بررسی کنید)', 'bytes' => 0];
        }
        $bytes    = (int) filesize($orig_abs);
        $made_now = true;
        update_post_meta($attachment_id, '_bm_wm_original', wp_json_encode([
            'file'     => wmb_relative($file),
            'original' => wmb_relative($orig_abs),
            'time'     => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $original = wmb_original_info($attachment_id);
    }

    $result = bm_wm_apply($file, $file, $opts);
    $note   = bm_wm_describe($result);

    if (empty($result['ok'])) {
        if ($made_now) {
            @unlink($original['original_abs']);
            delete_post_meta($attachment_id, '_bm_wm_original');
            $bytes = 0;
        }
        return ['status' => 'failed', 'note' => $note, 'bytes' => $bytes];
    }

    if (!empty($result['skipped'])) {
        if ($made_now) {
            @unlink($original['original_abs']);
            delete_post_meta($attachment_id, '_bm_wm_original');
            $bytes = 0;
        }
        update_post_meta($attachment_id, '_bm_watermark', wp_json_encode(['stamp' => $stamp, 'time' => time(), 'skipped' => 'small', 'by' => 'batch'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['status' => 'skipped', 'note' => $note, 'bytes' => 0];
    }

    $regen = wmb_regenerate($attachment_id, $file);
    update_post_meta($attachment_id, '_bm_watermark', wp_json_encode([
        'stamp'      => $stamp,
        'time'       => time(),
        'logo_width' => (int) $result['logo_width'],
        'variant'    => (string) $result['variant'],
        'position'   => (string) $result['position'],
        'plate'      => isset($result['plate']) ? (string) $result['plate'] : '',
        'plate_style' => isset($result['plate_style']) ? (string) $result['plate_style'] : '',
        'plate_color' => isset($result['plate_color']) ? (string) $result['plate_color'] : '',
        'by'         => 'batch',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return ['status' => 'done', 'note' => $note . ($regen ? '' : ' — بندانگشتی‌ها دوباره ساخته نشد'), 'bytes' => $bytes];
}

/** بازگرداندن نسخه‌ی اصلی یک اتچمنت */
function wmb_restore($attachment_id) {
    $original = wmb_original_info($attachment_id);
    if (!$original) {
        return ['status' => 'skipped', 'note' => 'نسخه‌ی اصلی ثبت نشده', 'bytes' => 0];
    }
    if (!$original['exists']) {
        delete_post_meta($attachment_id, '_bm_wm_original');
        return ['status' => 'failed', 'note' => 'فایل نسخه‌ی اصلی روی هاست نیست: ' . $original['original'], 'bytes' => 0];
    }
    $file = $original['file_abs'] !== '' ? $original['file_abs'] : get_attached_file($attachment_id);
    if (!@copy($original['original_abs'], $file)) {
        return ['status' => 'failed', 'note' => 'کپی نسخه‌ی اصلی روی کاور ناموفق بود', 'bytes' => 0];
    }
    $bytes = (int) filesize($original['original_abs']);
    wmb_regenerate($attachment_id, $file);
    @unlink($original['original_abs']);
    delete_post_meta($attachment_id, '_bm_wm_original');
    delete_post_meta($attachment_id, '_bm_watermark');
    return ['status' => 'done', 'note' => 'کاور و بندانگشتی‌ها به نسخه‌ی اصلی برگشت', 'bytes' => $bytes];
}

/** حذف کپی نسخه‌ی اصلی (بدون تغییر کاور لوگودار) */
function wmb_purge($attachment_id) {
    $original = wmb_original_info($attachment_id);
    if (!$original) {
        return ['status' => 'skipped', 'note' => 'نسخه‌ی اصلی ثبت نشده', 'bytes' => 0];
    }
    $bytes = $original['exists'] ? (int) filesize($original['original_abs']) : 0;
    if ($original['exists'] && !@unlink($original['original_abs'])) {
        return ['status' => 'failed', 'note' => 'فایل پاک نشد: ' . $original['original'], 'bytes' => 0];
    }
    delete_post_meta($attachment_id, '_bm_wm_original');
    return ['status' => 'done', 'note' => 'نسخه‌ی اصلی پاک شد' . ($bytes ? ' (' . size_format($bytes) . ')' : ''), 'bytes' => $bytes];
}

/* ---------- شمارش‌ها ---------- */
function wmb_count_posts_with_cover() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value <> ''
         WHERE p.post_type = 'post' AND p.post_status IN ('publish','draft','pending','future','private')"
    );
}

function wmb_count_marked($stamp) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bm_watermark' AND meta_value LIKE %s",
        '%' . $wpdb->esc_like('"stamp":"' . $stamp . '"') . '%'
    ));
}

function wmb_count_originals() {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bm_wm_original'");
}

/** دسته‌ی بعدی پست‌ها (جدیدترین اول) */
function wmb_next_posts($before, $limit) {
    global $wpdb;
    $before = $before > 0 ? (int) $before : PHP_INT_MAX;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm.meta_value AS thumb FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value <> ''
         WHERE p.post_type = 'post' AND p.post_status IN ('publish','draft','pending','future','private') AND p.ID < %d
         ORDER BY p.ID DESC LIMIT %d",
        $before,
        (int) $limit
    ));
}

/** دسته‌ی بعدی اتچمنت‌هایی که نسخه‌ی اصلی دارند */
function wmb_next_originals($before, $limit) {
    global $wpdb;
    $before = $before > 0 ? (int) $before : PHP_INT_MAX;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT pm.post_id AS ID FROM {$wpdb->postmeta} pm WHERE pm.meta_key = '_bm_wm_original' AND pm.post_id < %d ORDER BY pm.post_id DESC LIMIT %d",
        $before,
        (int) $limit
    ));
}

/** چند پست اخیر با تصویر شاخص (برای پیش‌نمایش) */
function wmb_recent_posts($limit) {
    return wmb_next_posts(0, $limit);
}

$wmb_state = get_option($wmb_option);
if (!is_array($wmb_state)) {
    $wmb_state = [];
}
$wmb_overrides = wmb_override_params();
$wmb_opts      = wmb_options($wmb_overrides);
$wmb_cfg_opts  = wmb_options();
$wmb_stamp     = bm_wm_stamp($wmb_cfg_opts);
$wmb_logo      = bm_wm_logo_info($wmb_cfg_opts['logo']);
$wmb_dark      = $wmb_cfg_opts['logo_dark'] !== '' ? bm_wm_logo_info($wmb_cfg_opts['logo_dark']) : null;
$wmb_self      = strtok($_SERVER['REQUEST_URI'], '?');

/* ---------- خود فایل لوگو (برای نمایش در این صفحه) ---------- */
if (isset($_GET['logo'])) {
    $info = $_GET['logo'] === 'dark' && $wmb_dark ? $wmb_dark : $wmb_logo;
    header('Cache-Control: no-store');
    if (!$info['ok']) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . ($info['type'] === 'gif' ? 'image/gif' : ($info['type'] === 'webp' ? 'image/webp' : 'image/png')));
    header('Content-Length: ' . (int) $info['size']);
    readfile($info['path']);
    exit;
}

/* ---------- پیش‌نمایش (تصویر) ---------- */
if (isset($_GET['preview'])) {
    $attachment_id = wmb_attachment_id((int) $_GET['preview']);
    $file          = $attachment_id ? get_attached_file($attachment_id) : '';
    $original      = $attachment_id ? wmb_original_info($attachment_id) : null;
    if ($original && $original['exists']) {
        $file = $original['original_abs']; // پیش‌نمایش همیشه روی کاور بدون لوگو
    }
    header('Cache-Control: no-store');
    if (!$file || !file_exists($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('cover file not found');
    }
    $side = isset($_GET['side']) ? (string) $_GET['side'] : 'wm';
    $type = bm_wm_sniff_type((string) @file_get_contents($file, false, null, 0, 16));
    $mime = $type === 'png' ? 'image/png' : ($type === 'gif' ? 'image/gif' : ($type === 'webp' ? 'image/webp' : 'image/jpeg'));
    if ($side === 'orig') {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    // با لوگو (حتی اگر در config.php غیرفعال باشد، پیش‌نمایش ساخته می‌شود)
    $opts            = $wmb_opts;
    $opts['enabled'] = true;
    $base            = wp_tempnam('bm-wm-preview');
    $tmp             = $base . '.' . ($type === 'png' ? 'png' : 'jpg');
    $result          = bm_wm_apply($file, $tmp, $opts);
    header('X-BM-Watermark: ' . rawurlencode(bm_wm_describe($result)));
    if (empty($result['ok']) || !empty($result['skipped']) || !file_exists($tmp)) {
        @unlink($tmp);
        @unlink($base);
        header('Content-Type: ' . $mime);
        readfile($file);
        exit;
    }
    header('Content-Type: ' . ($type === 'png' ? 'image/png' : 'image/jpeg'));
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    @unlink($base);
    exit;
}

header('Content-Type: text/html; charset=utf-8');

/* ---------- اجرای دسته‌ای (POST) ---------- */
$wmb_action = '';
$wmb_rows   = [];
$wmb_batch  = ['done' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0, 'count' => 0, 'last' => 0];
$wmb_limit  = 20;
$wmb_keep   = true;
$wmb_more   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    check_admin_referer('bm_wm_batch');
    $wmb_action = (string) $_POST['action'];
    $wmb_limit  = isset($_POST['n']) ? max(1, min(100, (int) $_POST['n'])) : 20;
    $wmb_keep   = !empty($_POST['keep']);
    $before     = isset($_POST['before']) ? (int) $_POST['before'] : 0;

    if ($wmb_action === 'reset') {
        delete_option($wmb_option);
        $wmb_state  = [];
        $wmb_action = '';
    } elseif (in_array($wmb_action, ['run', 'restore', 'purge'], true)) {
        if ($wmb_action === 'run' && !$wmb_logo['ok']) {
            $wmb_action = 'error';
            $wmb_error  = 'فایل لوگو آماده نیست؛ اول logo.png را بگذارید.';
        } else {
            if (empty($wmb_state['action']) || $wmb_state['action'] !== $wmb_action || !empty($_POST['fresh'])) {
                $wmb_state = ['action' => $wmb_action, 'cursor' => 0, 'done' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0, 'started' => time(), 'finished' => false, 'stamp' => $wmb_stamp];
            }
            if ($before <= 0) {
                $before = !empty($wmb_state['cursor']) ? (int) $wmb_state['cursor'] : 0;
            }
            $items = $wmb_action === 'run' ? wmb_next_posts($before, $wmb_limit) : wmb_next_originals($before, $wmb_limit);
            foreach ($items as $item) {
                $post_id       = (int) $item->ID;
                $attachment_id = $wmb_action === 'run' ? (int) $item->thumb : $post_id;
                if ($wmb_action === 'run') {
                    $r = wmb_process($attachment_id, $wmb_cfg_opts, $wmb_stamp, $wmb_keep);
                } elseif ($wmb_action === 'restore') {
                    $r = wmb_restore($attachment_id);
                } else {
                    $r = wmb_purge($attachment_id);
                }
                $wmb_rows[] = ['post' => $post_id, 'attachment' => $attachment_id, 'title' => $wmb_action === 'run' ? (string) $item->post_title : get_the_title($attachment_id), 'r' => $r];
                $wmb_batch[$r['status']]++;
                $wmb_batch['bytes'] += (int) $r['bytes'];
                $wmb_batch['count']++;
                $wmb_batch['last'] = $post_id;
            }
            $wmb_more = count($items) >= $wmb_limit;
            foreach (['done', 'already', 'skipped', 'failed'] as $k) {
                $wmb_state[$k] = (int) (isset($wmb_state[$k]) ? $wmb_state[$k] : 0) + $wmb_batch[$k];
            }
            if ($wmb_action === 'run') {
                $wmb_state['bytes'] = (int) (isset($wmb_state['bytes']) ? $wmb_state['bytes'] : 0) + $wmb_batch['bytes'];
            }
            $wmb_state['cursor']   = $wmb_more ? $wmb_batch['last'] : 0;
            $wmb_state['finished'] = !$wmb_more;
            $wmb_state['updated']  = time();
            $wmb_state['keep']     = $wmb_keep;
            $wmb_state['n']        = $wmb_limit;
            update_option($wmb_option, $wmb_state, false);
            if (!empty($_POST['stop'])) {
                $wmb_more = false;
            }
        }
    }
}

$wmb_total     = wmb_count_posts_with_cover();
$wmb_marked    = wmb_count_marked($wmb_stamp);
$wmb_originals = wmb_count_originals();
$wmb_preview   = wmb_recent_posts(3);
$wmb_labels    = ['done' => 'انجام شد', 'already' => 'قبلاً داشت', 'skipped' => 'رد شد', 'failed' => 'خطا'];
$wmb_classes   = ['done' => 'ok', 'already' => 'muted', 'skipped' => 'warn', 'failed' => 'bad'];
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>لوگو روی کاورها</title>
<style>
body{font-family:tahoma,sans-serif;font-size:13px;line-height:1.9;background:#f5f6fa;color:#1f2937;margin:0;padding:20px}
.box{background:#fff;border:1px solid #e3e6ee;border-radius:12px;padding:16px 18px;margin-bottom:16px}
h1{font-size:18px;margin:0 0 8px}h2{font-size:15px;margin:0 0 10px}
table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #eef0f4;padding:7px 8px;text-align:right;vertical-align:top}
th{background:#f8f9fc;font-weight:bold;font-size:12px}
.ok{color:#0b9b70;font-weight:bold}.bad{color:#dc2626;font-weight:bold}.warn{color:#b45309;font-weight:bold}.muted{color:#6b7280}
code{font-family:Consolas,monospace;font-size:12px;direction:ltr;unicode-bidi:embed}
button,.btn{border:0;border-radius:10px;padding:9px 18px;background:#635bff;color:#fff;cursor:pointer;font-family:inherit;font-size:13px;text-decoration:none;display:inline-block}
.btn.gray,button.gray{background:#e5e7eb;color:#111}.btn.red,button.red{background:#dc2626}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
.grid{display:flex;gap:18px;flex-wrap:wrap}
.card{background:#f8f9fc;border:1px solid #eef0f4;border-radius:12px;padding:10px;width:560px}
.card .pair{display:flex;gap:10px}.card .pair div{flex:1;text-align:center}
.card img{width:100%;height:auto;border-radius:8px;display:block;background:#ddd}
.logo-strip{display:flex;gap:10px;align-items:center}
.logo-strip span{display:inline-block;padding:14px 18px;border-radius:10px}
.logo-strip img{height:40px;width:auto;display:block}
.bar{height:10px;background:#e5e7eb;border-radius:6px;overflow:hidden;margin:6px 0}.bar i{display:block;height:100%;background:#635bff}
input[type=number],input[type=text],select{border:1px solid #d7dbe4;border-radius:8px;padding:5px 8px;font-size:12px;font-family:inherit}
small{font-size:11px}
img.thumb{width:48px;height:48px;object-fit:cover;border-radius:6px}
</style>
</head>
<body>
<div class="box">
  <h1>لوگوی سایت روی کاورها</h1>
  <div class="toolbar">
    <a class="btn gray" href="<?php echo wmb_h($wmb_self); ?>">وضعیت و پیش‌نمایش</a>
    <a class="btn gray" href="webp-batch.php">WebP برای کاورها</a>
    <a class="btn gray" href="dl-repair.php">ترمیم هاست دانلود</a>
    <a class="btn gray" href="meta-compare.php">مقایسه‌ی متاها</a>
  </div>
</div>

<?php if ($wmb_action === 'error') : ?>
<div class="box"><span class="bad"><?php echo wmb_h($wmb_error); ?></span></div>
<?php endif; ?>

<?php if (in_array($wmb_action, ['run', 'restore', 'purge'], true)) : ?>
<div class="box">
  <h2><?php echo $wmb_action === 'run' ? 'نشاندن لوگو' : ($wmb_action === 'restore' ? 'بازگرداندن نسخه‌ی اصلی' : 'حذف نسخه‌های اصلی'); ?> — این دسته: <?php echo (int) $wmb_batch['count']; ?> مورد</h2>
  <?php
  $processed = (int) $wmb_state['done'] + (int) $wmb_state['already'] + (int) $wmb_state['skipped'] + (int) $wmb_state['failed'];
  $target    = $wmb_action === 'run' ? $wmb_total : max($processed, $wmb_originals + $processed);
  $percent   = $target > 0 ? min(100, (int) round($processed * 100 / $target)) : 100;
  ?>
  <div>تا این‌جا: <span class="ok"><?php echo (int) $wmb_state['done']; ?> انجام شد</span> | <span class="muted"><?php echo (int) $wmb_state['already']; ?> قبلاً داشت</span> | <span class="warn"><?php echo (int) $wmb_state['skipped']; ?> رد شد</span> | <span class="bad"><?php echo (int) $wmb_state['failed']; ?> خطا</span>
    <?php if ($wmb_action === 'run') : ?> | نسخه‌های اصلی نگه‌داشته‌شده در این اجرا: <?php echo size_format((int) $wmb_state['bytes']); ?><?php endif; ?>
    — <?php echo $processed; ?> از <?php echo (int) $target; ?></div>
  <div class="bar"><i style="width:<?php echo $percent; ?>%"></i></div>
  <table>
    <tr><th style="width:60px">پست</th><th style="width:56px"></th><th>عنوان</th><th style="width:90px">نتیجه</th><th>توضیح</th></tr>
    <?php foreach ($wmb_rows as $row) : ?>
    <tr>
      <td><?php echo (int) $row['post']; ?></td>
      <td><?php $thumb = wp_get_attachment_image_url((int) $row['attachment'], 'thumbnail'); if ($thumb) : ?><img class="thumb" src="<?php echo wmb_h($thumb . (strpos($thumb, '?') === false ? '?' : '&') . 'v=' . time()); ?>" alt=""><?php endif; ?></td>
      <td><a href="<?php echo wmb_h(get_permalink((int) $row['post'])); ?>" target="_blank" rel="noopener"><?php echo wmb_h($row['title']); ?></a></td>
      <td class="<?php echo $wmb_classes[$row['r']['status']]; ?>"><?php echo $wmb_labels[$row['r']['status']]; ?></td>
      <td><?php echo wmb_h($row['r']['note']); ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($wmb_rows)) : ?><tr><td colspan="5" class="muted">موردی نمانده.</td></tr><?php endif; ?>
  </table>
  <?php if ($wmb_more) : ?>
  <form method="post" id="nextForm" style="margin-top:12px">
    <?php wp_nonce_field('bm_wm_batch'); ?>
    <input type="hidden" name="action" value="<?php echo wmb_h($wmb_action); ?>">
    <input type="hidden" name="before" value="<?php echo (int) $wmb_batch['last']; ?>">
    <input type="hidden" name="n" value="<?php echo (int) $wmb_limit; ?>">
    <?php if ($wmb_keep) : ?><input type="hidden" name="keep" value="1"><?php endif; ?>
    <button type="submit">دسته‌ی بعد (خودکار تا <span id="cnt">3</span> ثانیه دیگر)</button>
    <a class="btn gray" href="<?php echo wmb_h($wmb_self); ?>" onclick="window.bmStop=true;clearInterval(window.bmTimer);">توقف</a>
    <small class="muted">هر وقت توقف بزنید، بعداً با «ادامه» از همین‌جا پی گرفته می‌شود.</small>
  </form>
  <script>
  (function(){var n=3,el=document.getElementById('cnt');window.bmTimer=setInterval(function(){n--;if(el)el.textContent=n;if(n<=0){clearInterval(window.bmTimer);if(!window.bmStop)document.getElementById('nextForm').submit();}},1000);})();
  </script>
  <?php else : ?>
  <p class="ok">تمام شد.</p>
  <a class="btn gray" href="<?php echo wmb_h($wmb_self); ?>">بازگشت به وضعیت</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!in_array($wmb_action, ['run', 'restore', 'purge'], true) || !$wmb_more) : ?>
<div class="box">
  <h2>لوگو و تنظیمات</h2>
  <?php if (!bm_wm_available()) : ?>
  <p class="bad">GD روی این هاست فعال نیست؛ واترمارک کار نمی‌کند.</p>
  <?php endif; ?>
  <table>
    <tr><th style="width:180px">فایل لوگو</th><td>
      <code><?php echo wmb_h($wmb_cfg_opts['logo']); ?></code> —
      <?php if ($wmb_logo['ok']) : ?><span class="ok">هست</span> (<?php echo (int) $wmb_logo['width']; ?>×<?php echo (int) $wmb_logo['height']; ?>، <?php echo size_format($wmb_logo['size']); ?>)
        <div class="logo-strip" style="margin-top:6px"><span style="background:#1f2937"><img src="<?php echo wmb_h($wmb_self . '?logo=1'); ?>" alt=""></span><span style="background:#e5e7eb"><img src="<?php echo wmb_h($wmb_self . '?logo=1'); ?>" alt=""></span><?php if ($wmb_dark && $wmb_dark['ok']) : ?><span style="background:#e5e7eb"><img src="<?php echo wmb_h($wmb_self . '?logo=dark'); ?>" alt=""></span><?php endif; ?><span class="muted">لوگو روی زمینه‌ی تیره و روشن</span></div>
      <?php elseif ($wmb_logo['error'] === 'missing') : ?><span class="bad">نیست</span> — PNG شفاف (ترجیحاً سفید، عرض ۶۰۰ پیکسل یا بیشتر) را با همین نام آپلود کنید
      <?php elseif ($wmb_logo['error'] === 'not_transparent') : ?><span class="bad">PNG شفاف نیست</span>
      <?php else : ?><span class="bad">تصویر معتبر نیست</span><?php endif; ?>
    </td></tr>
    <tr><th>نسخه‌ی تیره (اختیاری)</th><td><code><?php echo wmb_h($wmb_cfg_opts['logo_dark']); ?></code> — <?php echo $wmb_dark && $wmb_dark['ok'] ? '<span class="ok">هست</span>؛ روی گوشه‌های روشن به‌جای سفید می‌نشیند' : '<span class="muted">نیست؛ روی گوشه‌های روشن لوگوی سفید با سایه‌ی پررنگ‌تر می‌نشیند</span>'; ?></td></tr>
    <tr><th>تنظیمات config.php</th><td>
      واترمارک: <?php echo $wmb_cfg_opts['enabled'] ? '<span class="ok">فعال</span>' : '<span class="bad">غیرفعال ($bot_watermark = false)</span>'; ?> |
      جای لوگو: <?php echo wmb_h(bm_wm_position_label($wmb_cfg_opts['position'])); ?> |
      عرض: <?php echo wmb_h($wmb_cfg_opts['width']); ?>٪ عرض کاور |
      فاصله از لبه: <?php echo wmb_h($wmb_cfg_opts['margin']); ?>٪ |
      شفافیت: <?php echo (int) $wmb_cfg_opts['opacity']; ?>٪ |
      سایه: <?php echo $wmb_cfg_opts['shadow'] ? 'دارد' : 'ندارد'; ?> |
      پلاک: <?php
        $wmb_plate_names = ['auto' => 'خودکار (مربع/مستطیل)', 'square' => 'مربع', 'rect' => 'مستطیل'];
        echo $wmb_cfg_opts['plate'] === 'none'
            ? 'ندارد (لوگوی آزاد)'
            : wmb_h(($wmb_plate_names[$wmb_cfg_opts['plate']] ?? $wmb_cfg_opts['plate']) . ($wmb_cfg_opts['plate_style'] === 'glass' ? ' شیشه‌ای' : ' پر') . '، رنگ ' . ($wmb_cfg_opts['plate_color'] === 'auto' ? 'غالبِ هر کاور' : 'ثابت ' . $wmb_cfg_opts['plate_color']) . '، ضلع ' . $wmb_cfg_opts['plate_size'] . '٪ عرض، گردی گوشه ' . $wmb_cfg_opts['plate_radius'] . '٪، پُری ' . $wmb_cfg_opts['plate_opacity'] . '٪');
      ?> |
      کاور کوچک‌تر از <?php echo (int) $wmb_cfg_opts['min_cover']; ?>px بدون لوگو |
      شناسه‌ی واترمارک: <code><?php echo wmb_h($wmb_stamp); ?></code>
    </td></tr>
  </table>
</div>

<div class="box">
  <h2>پیش‌نمایش (چیزی ذخیره نمی‌شود)</h2>
  <form method="get" class="toolbar" action="<?php echo wmb_h($wmb_self); ?>">
    <label>عرض ٪ <input type="number" name="width" min="3" max="60" step="0.5" value="<?php echo wmb_h($wmb_opts['width']); ?>" style="width:70px"></label>
    <label>شفافیت ٪ <input type="number" name="opacity" min="5" max="100" value="<?php echo (int) $wmb_opts['opacity']; ?>" style="width:70px"></label>
    <label>فاصله ٪ <input type="number" name="margin" min="0" max="20" step="0.5" value="<?php echo wmb_h($wmb_opts['margin']); ?>" style="width:70px"></label>
    <label>جای لوگو
      <select name="position">
        <?php foreach (['bottom-right', 'bottom-left', 'top-right', 'top-left'] as $pos) : ?>
        <option value="<?php echo $pos; ?>"<?php echo $wmb_opts['position'] === $pos ? ' selected' : ''; ?>><?php echo wmb_h(bm_wm_position_label($pos)); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>سایه <select name="shadow"><option value="1"<?php echo $wmb_opts['shadow'] ? ' selected' : ''; ?>>دارد</option><option value="0"<?php echo !$wmb_opts['shadow'] ? ' selected' : ''; ?>>ندارد</option></select></label>
    <label>پلاک
      <select name="plate">
        <?php foreach (['auto' => 'خودکار (مربع/مستطیل)', 'square' => 'مربع', 'rect' => 'مستطیل', 'none' => 'بدون پلاک'] as $pv => $pl) : ?>
        <option value="<?php echo $pv; ?>"<?php echo $wmb_opts['plate'] === $pv ? ' selected' : ''; ?>><?php echo $pl; ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>رنگ پلاک <input type="text" name="plate_color" value="<?php echo wmb_h($wmb_opts['plate_color']); ?>" style="width:90px;direction:ltr" placeholder="auto یا #1e1e1e"></label>
    <label>ضلع پلاک ٪ <input type="number" name="plate_size" min="5" max="50" step="0.5" value="<?php echo wmb_h($wmb_opts['plate_size']); ?>" style="width:70px"></label>
    <label>گردی ٪ <input type="number" name="plate_radius" min="0" max="50" step="1" value="<?php echo wmb_h($wmb_opts['plate_radius']); ?>" style="width:70px"></label>
    <label>جنس پلاک
      <select name="plate_style">
        <option value="glass"<?php echo $wmb_opts['plate_style'] === 'glass' ? ' selected' : ''; ?>>شیشه‌ای (تار + لبه‌ی روشن)</option>
        <option value="solid"<?php echo $wmb_opts['plate_style'] === 'solid' ? ' selected' : ''; ?>>پر و ساده</option>
      </select>
    </label>
    <label>پُری پلاک ٪ <input type="number" name="plate_opacity" min="10" max="100" step="5" value="<?php echo wmb_h($wmb_opts['plate_opacity']); ?>" style="width:70px" title="100 = کاملاً پر؛ کمتر = کاور از پشت پلاک دیده می‌شود (شیشه‌ای: تار)"></label>
    <button type="submit" class="gray">پیش‌نمایش با این مقادیر</button>
    <?php if ($wmb_overrides !== []) : ?><span class="warn">مقادیر آزمایشی — اجرای دسته‌ای فقط با مقادیر config.php انجام می‌شود؛ اگر این‌ها را پسندیدید در config.php بگذارید</span><?php endif; ?>
  </form>
  <?php if (!$wmb_logo['ok']) : ?>
  <p class="muted">بعد از آپلود فایل لوگو، این‌جا کاور چند پست اخیر را یک‌بار بدون لوگو و یک‌بار با لوگو می‌بینید.</p>
  <?php elseif (empty($wmb_preview)) : ?>
  <p class="muted">پستی با تصویر شاخص پیدا نشد.</p>
  <?php else : ?>
  <div class="grid">
    <?php foreach ($wmb_preview as $post) :
        $base = $wmb_self . '?preview=' . (int) $post->ID;
        $q    = $wmb_overrides !== [] ? '&' . http_build_query($wmb_overrides) : '';
        $mark = wmb_mark_info((int) $post->thumb);
    ?>
    <div class="card">
      <div style="margin-bottom:6px"><a href="<?php echo wmb_h(get_permalink((int) $post->ID)); ?>" target="_blank" rel="noopener"><?php echo wmb_h($post->post_title); ?></a>
        <?php if ($mark && isset($mark['stamp'])) : ?><small class="<?php echo $mark['stamp'] === $wmb_stamp ? 'ok' : 'warn'; ?>">— <?php echo $mark['stamp'] === $wmb_stamp ? 'همین لوگو را دارد' : 'لوگوی قبلی دارد'; ?><?php echo wmb_original_info((int) $post->thumb) ? ' (نسخه‌ی اصلی نگه داشته شده)' : ''; ?></small><?php endif; ?>
      </div>
      <div class="pair">
        <div><img src="<?php echo wmb_h($base . '&side=orig'); ?>" alt=""><small class="muted">بدون لوگو</small></div>
        <div><img src="<?php echo wmb_h($base . '&side=force' . $q); ?>" alt=""><small class="muted">با لوگو</small></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="muted"><small>پیش‌نمایش روی کاور اصلی (بدون لوگو) ساخته می‌شود، حتی برای پست‌هایی که لوگو گرفته‌اند. بندانگشتی‌های کوچک سایت (۶۰ تا ۱۵۰ پیکسل) لوگو را تقریباً نشان نمی‌دهند و این عادی است.</small></p>
  <?php endif; ?>
</div>

<div class="box">
  <h2>اجرای دسته‌ای روی آرشیو</h2>
  <?php
  $state_action = isset($wmb_state['action']) ? $wmb_state['action'] : '';
  $resume       = $state_action === 'run' && empty($wmb_state['finished']) && !empty($wmb_state['cursor']);
  ?>
  <p>پست‌های دارای تصویر شاخص: <strong><?php echo $wmb_total; ?></strong> | کاورهایی که همین لوگو را دارند: <strong><?php echo $wmb_marked; ?></strong> | نسخه‌های اصلی نگه‌داشته‌شده: <strong><?php echo $wmb_originals; ?></strong></p>
  <div class="bar"><i style="width:<?php echo $wmb_total > 0 ? min(100, (int) round($wmb_marked * 100 / $wmb_total)) : 0; ?>%"></i></div>
  <?php if ($state_action !== '' && !empty($wmb_state['updated'])) : ?>
  <p class="muted"><small>آخرین اجرا (<?php echo $state_action === 'run' ? 'نشاندن لوگو' : ($state_action === 'restore' ? 'بازگرداندن' : 'حذف نسخه‌های اصلی'); ?>): <?php echo wmb_h(get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $wmb_state['updated']), 'Y-m-d H:i')); ?> —
    <?php echo (int) $wmb_state['done']; ?> انجام شد، <?php echo (int) $wmb_state['already']; ?> قبلاً داشت، <?php echo (int) $wmb_state['skipped']; ?> رد شد، <?php echo (int) $wmb_state['failed']; ?> خطا<?php echo !empty($wmb_state['finished']) ? ' — تمام شده' : ' — نیمه‌کاره (ادامه از پست ' . (int) $wmb_state['cursor'] . ')'; ?>
    <?php if (!empty($wmb_state['stamp']) && $wmb_state['stamp'] !== $wmb_stamp) : ?><span class="warn">— از آن زمان لوگو/تنظیمات عوض شده</span><?php endif; ?></small></p>
  <?php endif; ?>
  <form method="post" class="toolbar">
    <?php wp_nonce_field('bm_wm_batch'); ?>
    <input type="hidden" name="action" value="run">
    <label>در هر دسته <select name="n"><?php foreach ([10, 20, 40] as $n) : ?><option value="<?php echo $n; ?>"<?php echo (isset($wmb_state['n']) ? (int) $wmb_state['n'] : 20) === $n ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?></select> کاور</label>
    <label><input type="checkbox" name="keep" value="1"<?php echo !isset($wmb_state['keep']) || !empty($wmb_state['keep']) ? ' checked' : ''; ?>> نسخه‌ی اصلی هر کاور نگه داشته شود (برای بازگشت یا تعویض لوگو؛ هر کاور ۱۰۸۰ حدود ۱۵۰ تا ۲۵۰ کیلوبایت)</label>
    <?php if ($resume) : ?>
    <button type="submit"<?php echo $wmb_logo['ok'] && $wmb_cfg_opts['enabled'] ? '' : ' disabled'; ?>>ادامه از پست <?php echo (int) $wmb_state['cursor']; ?></button>
    <button type="submit" name="fresh" value="1" class="gray"<?php echo $wmb_logo['ok'] && $wmb_cfg_opts['enabled'] ? '' : ' disabled'; ?>>از اول</button>
    <?php else : ?>
    <button type="submit" name="fresh" value="1"<?php echo $wmb_logo['ok'] && $wmb_cfg_opts['enabled'] ? '' : ' disabled'; ?>>شروع (از جدیدترین پست)</button>
    <?php endif; ?>
    <?php if (!$wmb_logo['ok']) : ?><span class="bad">اول فایل لوگو را بگذارید</span><?php elseif (!$wmb_cfg_opts['enabled']) : ?><span class="bad">واترمارک در config.php غیرفعال است</span><?php endif; ?>
  </form>
  <ul class="muted" style="margin:6px 0 0;padding-right:18px">
    <li>هر کاور: کپی نسخه‌ی اصلی (اگر تیک خورده) ← لوگو روی فایل اصلی ← ساخت دوباره‌ی همه‌ی سایزهای بندانگشتی. کاورهایی که همین لوگو را دارند رد می‌شوند، پس تکرار اجرا بی‌خطر است.</li>
    <li>نام فایل‌ها عوض نمی‌شود؛ مرورگر/CDN ممکن است تا مدتی نسخه‌ی قبلی بندانگشتی‌ها را نشان دهد.</li>
    <li>تنظیمات لوگو فقط از config.php خوانده می‌شود (مقادیر آزمایشی پیش‌نمایش در اجرای دسته‌ای اثر ندارد). کاور JPEG با کیفیت ۹۰ دوباره ذخیره می‌شود.</li>
  </ul>
</div>

<?php if ($wmb_originals > 0) : ?>
<div class="box">
  <h2>نسخه‌های اصلی (<?php echo $wmb_originals; ?> کاور)</h2>
  <div class="toolbar">
    <form method="post" onsubmit="return confirm('کاورهای لوگودار به نسخه‌ی اصلی برمی‌گردند و بندانگشتی‌ها دوباره ساخته می‌شوند. ادامه؟');">
      <?php wp_nonce_field('bm_wm_batch'); ?>
      <input type="hidden" name="action" value="restore"><input type="hidden" name="fresh" value="1"><input type="hidden" name="n" value="20">
      <button type="submit" class="gray">بازگرداندن نسخه‌ی اصلی همه‌ی کاورها</button>
    </form>
    <form method="post" onsubmit="return confirm('کپی‌های اصلی برای همیشه پاک می‌شوند و دیگر امکان بازگشت یا تعویض بی‌دردسر لوگو نیست. مطمئن هستید؟');">
      <?php wp_nonce_field('bm_wm_batch'); ?>
      <input type="hidden" name="action" value="purge"><input type="hidden" name="fresh" value="1"><input type="hidden" name="n" value="100">
      <button type="submit" class="red">حذف نسخه‌های اصلی (آزاد کردن فضا)</button>
    </form>
  </div>
  <p class="muted"><small>تا وقتی نسخه‌های اصلی هستند، تعویض لوگو یا تنظیمات ساده است: config.php را عوض کنید و دوباره «شروع» بزنید؛ هر کاور اول به اصل برمی‌گردد و بعد لوگوی جدید می‌گیرد.</small></p>
</div>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
