<?php
/**
 * WebP کردن سایزهای نمایشی کاورهای قدیمی (اجرای دسته‌ای) + وضعیت
 *
 *   /bot/webp-batch.php               وضعیت: پشتیبانی هاست از WebP، تنظیمات، شمار کاورها، سایزهای چند کاور اخیر با فرمت و حجم
 *   دکمه‌ی «شروع / ادامه»             پست‌ها از جدیدترین به قدیمی‌ترین، دسته‌دسته: همه‌ی سایزهای نمایشی هر کاور از روی فایل اصلی
 *                                     دوباره با WebP ساخته می‌شود؛ فایل اصلی (JPEG/PNG) دست نمی‌خورد و همان «full» می‌ماند.
 *                                     فایل‌های JPEG قدیمیِ سایزها (اگر تیک خورده باشد) پاک می‌شوند تا فضای هاست آزاد شود.
 *                                     صفحه خودش دسته‌ی بعد را ادامه می‌دهد؛ هر وقت خواستید «توقف» بزنید؛ بعداً «ادامه» از همان‌جا
 *   «بازگشت به JPEG»                  سایزها دوباره با فرمت خود کاور ساخته می‌شوند و فایل‌های WebP پاک می‌شوند (برگشت‌پذیر بودن کامل)
 *
 * کاوری که همه‌ی سایزهایش WebP است رد می‌شود؛ پس تکرار اجرا بی‌خطر است. پست‌های تازه‌ی ربات/پست‌ساز از همان اول WebP دارند
 * (bot/webp.php — تنظیمات $bot_webp_* در config.php). فقط پشت همان یوزر/رمز ربات.
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

$wpb_option  = 'bm_webp_batch_state';
$wpb_uploads = wp_upload_dir();
$wpb_basedir = rtrim((string) $wpb_uploads['basedir'], '/\\');

function wpb_h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function wpb_relative($abs) {
    global $wpb_basedir;
    $abs  = str_replace('\\', '/', (string) $abs);
    $base = str_replace('\\', '/', $wpb_basedir);
    return strpos($abs, $base . '/') === 0 ? substr($abs, strlen($base) + 1) : $abs;
}

/** فرمت فایل اصلی از روی محتوای فایل */
function wpb_mime($file) {
    $info = @getimagesize($file);
    return is_array($info) && !empty($info['mime']) ? (string) $info['mime'] : '';
}

/** نام کوتاه فرمت برای نمایش */
function wpb_format_label($mime) {
    $map = ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WebP', 'image/gif' => 'GIF', 'image/avif' => 'AVIF'];
    return isset($map[$mime]) ? $map[$mime] : ($mime !== '' ? $mime : '؟');
}

/**
 * ساخت دوباره‌ی سایزهای یک اتچمنت
 *   $mode = 'run' (سایزها WebP) | 'revert' (سایزها با فرمت خود کاور)
 * خروجی: ['status' => done|already|skipped|failed, 'note' => string, 'old_bytes' => حجم سایزهای قبلی, 'new_bytes' => حجم سایزهای تازه, 'freed' => حجم فایل‌های پاک‌شده]
 */
function wpb_process($attachment_id, $mode, $delete_old) {
    $r = ['status' => 'failed', 'note' => '', 'old_bytes' => 0, 'new_bytes' => 0, 'freed' => 0];

    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0 || !get_post($attachment_id)) {
        $r['note'] = 'اتچمنت تصویر شاخص وجود ندارد';
        return $r;
    }
    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
        $r['note'] = 'فایل کاور روی هاست نیست' . ($file ? ' (' . wpb_relative($file) . ')' : '');
        return $r;
    }
    if (!is_writable(dirname($file))) {
        $r['note'] = 'اجازه‌ی نوشتن در پوشه‌ی کاور نیست: ' . wpb_relative(dirname($file));
        return $r;
    }

    $mime = wpb_mime($file);
    if (!in_array($mime, bm_webp_source_mimes(), true)) {
        $r['status'] = 'skipped';
        $r['note']   = 'فایل اصلی ' . wpb_format_label($mime) . ' است؛ فقط کاورهای JPEG/PNG سایز WebP می‌گیرند';
        return $r;
    }

    $old = wp_get_attachment_metadata($attachment_id);
    if (!is_array($old)) {
        $old = [];
    }
    if (!empty($old['original_image'])) {
        $r['status'] = 'skipped';
        $r['note']   = 'تصویر بزرگ با نسخه‌ی -scaled (آپلود دستی)؛ دستی بررسی شود';
        return $r;
    }

    $before         = bm_webp_summary($old);
    $r['old_bytes'] = (int) $before['bytes'];
    if ($mode === 'run' && $before['sizes'] > 0 && $before['other'] === 0) {
        $r['status'] = 'already';
        $r['note']   = 'همه‌ی ' . $before['sizes'] . ' سایز WebP است';
        return $r;
    }
    if ($mode === 'revert' && $before['webp'] === 0) {
        $r['status'] = 'already';
        $r['note']   = $before['sizes'] > 0 ? 'سایزها WebP نیستند' : 'سایزی ندارد';
        return $r;
    }

    $webp = bm_webp_begin($file, $mode === 'run');
    if ($mode === 'run' && empty($webp['on'])) {
        $r['note'] = 'WebP روشن نشد (هاست WebP نمی‌سازد؟)';
        return $r;
    }
    $meta = wp_generate_attachment_metadata($attachment_id, $file);
    bm_webp_end($webp);

    if (!is_array($meta) || empty($meta)) {
        $r['note'] = 'ساخت دوباره‌ی سایزها ناموفق بود';
        return $r;
    }
    wp_update_attachment_metadata($attachment_id, $meta);

    $after          = bm_webp_summary($meta);
    $r['new_bytes'] = (int) $after['bytes'];
    if ($delete_old) {
        $deleted    = bm_webp_delete_stale_sizes($file, $old, $meta);
        $r['freed'] = (int) $deleted['bytes'];
    }

    if ($mode === 'run' && $after['webp'] === 0) {
        $r['status'] = $after['sizes'] === 0 ? 'skipped' : 'failed';
        $r['note']   = $after['sizes'] === 0 ? 'کاور کوچک‌تر از همه‌ی سایزهای قالب است؛ سایزی ساخته نمی‌شود' : 'سایزها ساخته شد ولی WebP نشد';
        return $r;
    }

    $r['status'] = 'done';
    $r['note']   = ($mode === 'run' ? $after['webp'] . ' سایز WebP' : $after['sizes'] . ' سایز ' . wpb_format_label($mime))
        . ($r['old_bytes'] > 0 && $r['new_bytes'] > 0 ? '؛ حجم سایزها ' . size_format($r['old_bytes']) . ' ← ' . size_format($r['new_bytes']) : '')
        . ($delete_old && $r['freed'] > 0 ? '؛ فایل‌های قدیمی پاک شد (' . size_format($r['freed']) . ')' : ($delete_old ? '' : '؛ فایل‌های قدیمی نگه داشته شد'));
    return $r;
}

/* ---------- شمارش‌ها ---------- */
function wpb_count_posts_with_cover() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value <> ''
         WHERE p.post_type = 'post' AND p.post_status IN ('publish','draft','pending','future','private')"
    );
}

/** کاورهایی که دست‌کم یک سایز WebP دارند (تقریبی، از روی متادیتا) */
function wpb_count_webp() {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value <> ''
         INNER JOIN {$wpdb->postmeta} am ON am.post_id = pm.meta_value AND am.meta_key = '_wp_attachment_metadata'
         WHERE p.post_type = 'post' AND p.post_status IN ('publish','draft','pending','future','private') AND am.meta_value LIKE %s",
        '%' . $wpdb->esc_like('image/webp') . '%'
    ));
}

/** دسته‌ی بعدی پست‌ها (جدیدترین اول) */
function wpb_next_posts($before, $limit) {
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

$wpb_state = get_option($wpb_option);
if (!is_array($wpb_state)) {
    $wpb_state = [];
}
$wpb_opts      = bm_webp_options();
$wpb_supported = bm_webp_supported();
$wpb_self      = strtok($_SERVER['REQUEST_URI'], '?');

header('Content-Type: text/html; charset=utf-8');

/* ---------- اجرای دسته‌ای (POST) ---------- */
$wpb_action = '';
$wpb_rows   = [];
$wpb_batch  = ['done' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0, 'old_bytes' => 0, 'new_bytes' => 0, 'freed' => 0, 'count' => 0, 'last' => 0];
$wpb_limit  = 20;
$wpb_delete = true;
$wpb_more   = false;
$wpb_error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    check_admin_referer('bm_webp_batch');
    $wpb_action = (string) $_POST['action'];
    $wpb_limit  = isset($_POST['n']) ? max(1, min(100, (int) $_POST['n'])) : 20;
    $wpb_delete = !empty($_POST['delete_old']);
    $before     = isset($_POST['before']) ? (int) $_POST['before'] : 0;

    if ($wpb_action === 'reset') {
        delete_option($wpb_option);
        $wpb_state  = [];
        $wpb_action = '';
    } elseif (in_array($wpb_action, ['run', 'revert'], true)) {
        if ($wpb_action === 'run' && !$wpb_supported) {
            $wpb_action = 'error';
            $wpb_error  = 'GD/Imagick این هاست WebP نمی‌سازد؛ از پشتیبانی هاست بخواهید WebP را در GD (یا Imagick) فعال کند.';
        } else {
            if (empty($wpb_state['action']) || $wpb_state['action'] !== $wpb_action || !empty($_POST['fresh'])) {
                $wpb_state = ['action' => $wpb_action, 'cursor' => 0, 'done' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0, 'old_bytes' => 0, 'new_bytes' => 0, 'freed' => 0, 'started' => time(), 'finished' => false];
            }
            if ($before <= 0) {
                $before = !empty($wpb_state['cursor']) ? (int) $wpb_state['cursor'] : 0;
            }
            $items = wpb_next_posts($before, $wpb_limit);
            foreach ($items as $item) {
                $post_id       = (int) $item->ID;
                $attachment_id = (int) $item->thumb;
                $r             = wpb_process($attachment_id, $wpb_action, $wpb_delete);
                $wpb_rows[]    = ['post' => $post_id, 'attachment' => $attachment_id, 'title' => (string) $item->post_title, 'r' => $r];
                $wpb_batch[$r['status']]++;
                if ($r['status'] === 'done') {
                    $wpb_batch['old_bytes'] += (int) $r['old_bytes'];
                    $wpb_batch['new_bytes'] += (int) $r['new_bytes'];
                    $wpb_batch['freed']     += (int) $r['freed'];
                }
                $wpb_batch['count']++;
                $wpb_batch['last'] = $post_id;
            }
            $wpb_more = count($items) >= $wpb_limit;
            foreach (['done', 'already', 'skipped', 'failed', 'old_bytes', 'new_bytes', 'freed'] as $k) {
                $wpb_state[$k] = (int) (isset($wpb_state[$k]) ? $wpb_state[$k] : 0) + $wpb_batch[$k];
            }
            $wpb_state['cursor']   = $wpb_more ? $wpb_batch['last'] : 0;
            $wpb_state['finished'] = !$wpb_more;
            $wpb_state['updated']  = time();
            $wpb_state['delete']   = $wpb_delete;
            $wpb_state['n']        = $wpb_limit;
            update_option($wpb_option, $wpb_state, false);
            if (!empty($_POST['stop'])) {
                $wpb_more = false;
            }
        }
    }
}

$wpb_total   = wpb_count_posts_with_cover();
$wpb_webp    = wpb_count_webp();
$wpb_recent  = wpb_next_posts(0, 3);
$wpb_labels  = ['done' => 'انجام شد', 'already' => 'قبلاً بود', 'skipped' => 'رد شد', 'failed' => 'خطا'];
$wpb_classes = ['done' => 'ok', 'already' => 'muted', 'skipped' => 'warn', 'failed' => 'bad'];
$wpb_running = in_array($wpb_action, ['run', 'revert'], true);
$wpb_editor  = function_exists('_wp_image_editor_choose') ? (string) _wp_image_editor_choose() : '';

function wpb_percent_less($old, $new) {
    if ($old <= 0 || $new <= 0 || $new >= $old) {
        return '';
    }
    return ' (' . (int) round(100 - $new * 100 / $old) . '٪ کمتر)';
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>WebP برای کاورها</title>
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
button[disabled]{opacity:.5;cursor:default}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
.grid{display:flex;gap:18px;flex-wrap:wrap}
.card{background:#f8f9fc;border:1px solid #eef0f4;border-radius:12px;padding:10px;width:460px}
.card img{width:96px;height:96px;object-fit:cover;border-radius:8px;display:block;background:#ddd;float:left;margin:0 0 8px 8px}
.card table{font-size:12px}.card td,.card th{padding:3px 6px}
.bar{height:10px;background:#e5e7eb;border-radius:6px;overflow:hidden;margin:6px 0}.bar i{display:block;height:100%;background:#635bff}
.tag{display:inline-block;padding:0 7px;border-radius:6px;font-size:11px;font-weight:bold;line-height:1.7}
.tag.webp{background:#d1fae5;color:#065f46}.tag.jpeg,.tag.png{background:#fef3c7;color:#92400e}.tag.other{background:#e5e7eb;color:#374151}
select{border:1px solid #d7dbe4;border-radius:8px;padding:5px 8px;font-size:12px;font-family:inherit}
small{font-size:11px}
img.thumb{width:48px;height:48px;object-fit:cover;border-radius:6px}
</style>
</head>
<body>
<div class="box">
  <h1>WebP برای سایزهای نمایشی کاورها</h1>
  <div class="toolbar">
    <a class="btn gray" href="<?php echo wpb_h($wpb_self); ?>">وضعیت</a>
    <a class="btn gray" href="watermark-batch.php">لوگو روی کاورها</a>
    <a class="btn gray" href="dl-repair.php">ترمیم هاست دانلود</a>
    <a class="btn gray" href="meta-compare.php">مقایسه‌ی متاها</a>
  </div>
  <p class="muted" style="margin:0"><small>فایل اصلی هر کاور (JPEG/PNG) دست نمی‌خورد و همان «full» وردپرس می‌ماند؛ فقط سایزهای نمایشی قالب (thumb1 … thumb4، thumb2x، medium، large، …) با WebP ساخته می‌شوند.</small></p>
</div>

<?php if ($wpb_action === 'error') : ?>
<div class="box"><span class="bad"><?php echo wpb_h($wpb_error); ?></span></div>
<?php endif; ?>

<?php if ($wpb_running) : ?>
<div class="box">
  <h2><?php echo $wpb_action === 'run' ? 'WebP کردن سایزها' : 'بازگشت سایزها به فرمت کاور'; ?> — این دسته: <?php echo (int) $wpb_batch['count']; ?> مورد</h2>
  <?php
  $processed = (int) $wpb_state['done'] + (int) $wpb_state['already'] + (int) $wpb_state['skipped'] + (int) $wpb_state['failed'];
  $percent   = $wpb_total > 0 ? min(100, (int) round($processed * 100 / $wpb_total)) : 100;
  ?>
  <div>تا این‌جا: <span class="ok"><?php echo (int) $wpb_state['done']; ?> انجام شد</span> | <span class="muted"><?php echo (int) $wpb_state['already']; ?> قبلاً بود</span> | <span class="warn"><?php echo (int) $wpb_state['skipped']; ?> رد شد</span> | <span class="bad"><?php echo (int) $wpb_state['failed']; ?> خطا</span>
    <?php if ((int) $wpb_state['old_bytes'] > 0 && (int) $wpb_state['new_bytes'] > 0) : ?> | حجم سایزها: <?php echo size_format((int) $wpb_state['old_bytes']); ?> ← <?php echo size_format((int) $wpb_state['new_bytes']) . wpb_percent_less((int) $wpb_state['old_bytes'], (int) $wpb_state['new_bytes']); ?><?php endif; ?>
    <?php if ((int) $wpb_state['freed'] > 0) : ?> | فضای آزادشده: <?php echo size_format((int) $wpb_state['freed']); ?><?php endif; ?>
    — <?php echo $processed; ?> از <?php echo (int) $wpb_total; ?></div>
  <div class="bar"><i style="width:<?php echo $percent; ?>%"></i></div>
  <table>
    <tr><th style="width:60px">پست</th><th style="width:56px"></th><th>عنوان</th><th style="width:90px">نتیجه</th><th>توضیح</th></tr>
    <?php foreach ($wpb_rows as $row) : ?>
    <tr>
      <td><?php echo (int) $row['post']; ?></td>
      <td><?php $thumb = wp_get_attachment_image_url((int) $row['attachment'], 'thumbnail'); if ($thumb) : ?><img class="thumb" src="<?php echo wpb_h($thumb . (strpos($thumb, '?') === false ? '?' : '&') . 'v=' . time()); ?>" alt=""><?php endif; ?></td>
      <td><a href="<?php echo wpb_h(get_permalink((int) $row['post'])); ?>" target="_blank" rel="noopener"><?php echo wpb_h($row['title']); ?></a></td>
      <td class="<?php echo $wpb_classes[$row['r']['status']]; ?>"><?php echo $wpb_labels[$row['r']['status']]; ?></td>
      <td><?php echo wpb_h($row['r']['note']); ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($wpb_rows)) : ?><tr><td colspan="5" class="muted">موردی نمانده.</td></tr><?php endif; ?>
  </table>
  <?php if ($wpb_more) : ?>
  <form method="post" id="nextForm" style="margin-top:12px">
    <?php wp_nonce_field('bm_webp_batch'); ?>
    <input type="hidden" name="action" value="<?php echo wpb_h($wpb_action); ?>">
    <input type="hidden" name="before" value="<?php echo (int) $wpb_batch['last']; ?>">
    <input type="hidden" name="n" value="<?php echo (int) $wpb_limit; ?>">
    <?php if ($wpb_delete) : ?><input type="hidden" name="delete_old" value="1"><?php endif; ?>
    <button type="submit">دسته‌ی بعد (خودکار تا <span id="cnt">3</span> ثانیه دیگر)</button>
    <a class="btn gray" href="<?php echo wpb_h($wpb_self); ?>" onclick="window.bmStop=true;clearInterval(window.bmTimer);">توقف</a>
    <small class="muted">هر وقت توقف بزنید، بعداً با «ادامه» از همین‌جا پی گرفته می‌شود.</small>
  </form>
  <script>
  (function(){var n=3,el=document.getElementById('cnt');window.bmTimer=setInterval(function(){n--;if(el)el.textContent=n;if(n<=0){clearInterval(window.bmTimer);if(!window.bmStop)document.getElementById('nextForm').submit();}},1000);})();
  </script>
  <?php else : ?>
  <p class="ok">تمام شد.</p>
  <a class="btn gray" href="<?php echo wpb_h($wpb_self); ?>">بازگشت به وضعیت</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$wpb_running || !$wpb_more) : ?>
<div class="box">
  <h2>وضعیت</h2>
  <table>
    <tr><th style="width:220px">WebP روی این هاست</th><td><?php if ($wpb_supported) : ?><span class="ok">پشتیبانی می‌شود</span><?php else : ?><span class="bad">پشتیبانی نمی‌شود</span> — GD/Imagick هاست WebP ندارد؛ تا فعال نشود سایزها مثل قبل JPEG ساخته می‌شوند<?php endif; ?><?php if ($wpb_editor !== '') : ?> <span class="muted">(ویرایشگر: <code><?php echo wpb_h($wpb_editor); ?></code>)</span><?php endif; ?></td></tr>
    <tr><th>تنظیمات config.php</th><td>$bot_webp = <?php echo $wpb_opts['enabled'] ? '<span class="ok">true</span> (پست‌های تازه‌ی ربات/پست‌ساز سایز WebP می‌گیرند)' : '<span class="warn">false</span> (پست‌های تازه سایز JPEG می‌گیرند)'; ?> | کیفیت WebP: <strong><?php echo (int) $wpb_opts['quality']; ?></strong></td></tr>
    <tr><th>سایزهای ثبت‌شده‌ی سایت</th><td><code><?php
        $sizes = function_exists('wp_get_registered_image_subsizes') ? wp_get_registered_image_subsizes() : [];
        $list  = [];
        foreach ($sizes as $name => $d) {
            $list[] = $name . ' ' . (int) $d['width'] . '×' . (int) $d['height'] . (!empty($d['crop']) ? ' (برش)' : '');
        }
        echo wpb_h(implode(' | ', $list));
    ?></code></td></tr>
  </table>
</div>

<div class="box">
  <h2>سایزهای چند کاور اخیر</h2>
  <?php if (empty($wpb_recent)) : ?>
  <p class="muted">پستی با تصویر شاخص پیدا نشد.</p>
  <?php else : ?>
  <div class="grid">
    <?php foreach ($wpb_recent as $post) :
        $aid   = (int) $post->thumb;
        $meta  = wp_get_attachment_metadata($aid);
        $file  = get_attached_file($aid);
        $mime  = $file && file_exists($file) ? wpb_mime($file) : '';
        $sum   = bm_webp_summary($meta);
        $thumb = wp_get_attachment_image_url($aid, 'thumbnail');
    ?>
    <div class="card">
      <div style="margin-bottom:6px"><a href="<?php echo wpb_h(get_permalink((int) $post->ID)); ?>" target="_blank" rel="noopener"><?php echo wpb_h($post->post_title); ?></a></div>
      <?php if ($thumb) : ?><img src="<?php echo wpb_h($thumb . (strpos($thumb, '?') === false ? '?' : '&') . 'v=' . time()); ?>" alt=""><?php endif; ?>
      <table>
        <tr><th>سایز</th><th>ابعاد</th><th>فرمت</th><th>حجم</th></tr>
        <tr><td>full (اصلی)</td><td><?php echo is_array($meta) && !empty($meta['width']) ? (int) $meta['width'] . '×' . (int) $meta['height'] : '؟'; ?></td>
            <td><?php $l = wpb_format_label($mime); echo '<span class="tag ' . strtolower($l === 'WebP' ? 'webp' : ($l === 'JPEG' ? 'jpeg' : ($l === 'PNG' ? 'png' : 'other'))) . '">' . wpb_h($l) . '</span>'; ?></td>
            <td><?php echo $file && file_exists($file) ? size_format(filesize($file)) : '—'; ?></td></tr>
        <?php if (is_array($meta) && !empty($meta['sizes'])) : foreach ($meta['sizes'] as $name => $s) :
            $sm = isset($s['mime-type']) ? (string) $s['mime-type'] : '';
            $sl = wpb_format_label($sm);
            $sp = $file ? dirname($file) . '/' . wp_basename((string) $s['file']) : '';
        ?>
        <tr><td><?php echo wpb_h($name); ?></td><td><?php echo (int) $s['width']; ?>×<?php echo (int) $s['height']; ?></td>
            <td><span class="tag <?php echo $sl === 'WebP' ? 'webp' : ($sl === 'JPEG' ? 'jpeg' : ($sl === 'PNG' ? 'png' : 'other')); ?>"><?php echo wpb_h($sl); ?></span></td>
            <td><?php echo $sp !== '' && file_exists($sp) ? size_format(filesize($sp)) : (isset($s['filesize']) ? size_format((int) $s['filesize']) : '—'); ?></td></tr>
        <?php endforeach; endif; ?>
      </table>
      <div class="muted" style="clear:both"><small>سایزهای نمایشی: <?php echo (int) $sum['webp']; ?> WebP، <?php echo (int) $sum['other']; ?> دیگر<?php echo $sum['bytes'] > 0 ? ' — جمع ' . size_format($sum['bytes']) : ''; ?></small></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="box">
  <h2>اجرای دسته‌ای روی آرشیو</h2>
  <?php
  $state_action = isset($wpb_state['action']) ? $wpb_state['action'] : '';
  $resume       = $state_action === 'run' && empty($wpb_state['finished']) && !empty($wpb_state['cursor']);
  ?>
  <p>پست‌های دارای تصویر شاخص: <strong><?php echo $wpb_total; ?></strong> | کاورهایی که سایز WebP دارند: <strong><?php echo $wpb_webp; ?></strong></p>
  <div class="bar"><i style="width:<?php echo $wpb_total > 0 ? min(100, (int) round($wpb_webp * 100 / $wpb_total)) : 0; ?>%"></i></div>
  <?php if ($state_action !== '' && !empty($wpb_state['updated'])) : ?>
  <p class="muted"><small>آخرین اجرا (<?php echo $state_action === 'run' ? 'WebP کردن' : 'بازگشت'; ?>): <?php echo wpb_h(get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $wpb_state['updated']), 'Y-m-d H:i')); ?> —
    <?php echo (int) $wpb_state['done']; ?> انجام شد، <?php echo (int) $wpb_state['already']; ?> قبلاً بود، <?php echo (int) $wpb_state['skipped']; ?> رد شد، <?php echo (int) $wpb_state['failed']; ?> خطا<?php echo !empty($wpb_state['finished']) ? ' — تمام شده' : ' — نیمه‌کاره (ادامه از پست ' . (int) $wpb_state['cursor'] . ')'; ?>
    <?php if ((int) $wpb_state['old_bytes'] > 0 && (int) $wpb_state['new_bytes'] > 0) : ?>| حجم سایزها <?php echo size_format((int) $wpb_state['old_bytes']); ?> ← <?php echo size_format((int) $wpb_state['new_bytes']) . wpb_percent_less((int) $wpb_state['old_bytes'], (int) $wpb_state['new_bytes']); ?><?php endif; ?>
    <?php if ((int) $wpb_state['freed'] > 0) : ?>| فضای آزادشده <?php echo size_format((int) $wpb_state['freed']); ?><?php endif; ?></small></p>
  <?php endif; ?>
  <form method="post" class="toolbar">
    <?php wp_nonce_field('bm_webp_batch'); ?>
    <input type="hidden" name="action" value="run">
    <label>در هر دسته <select name="n"><?php foreach ([10, 20, 40] as $n) : ?><option value="<?php echo $n; ?>"<?php echo (isset($wpb_state['n']) ? (int) $wpb_state['n'] : 20) === $n ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?></select> کاور</label>
    <label><input type="checkbox" name="delete_old" value="1"<?php echo !isset($wpb_state['delete']) || !empty($wpb_state['delete']) ? ' checked' : ''; ?>> فایل‌های JPEG قدیمیِ سایزها پاک شود (آزاد شدن فضا؛ فایل اصلی هرگز پاک نمی‌شود)</label>
    <?php if ($resume) : ?>
    <button type="submit"<?php echo $wpb_supported ? '' : ' disabled'; ?>>ادامه از پست <?php echo (int) $wpb_state['cursor']; ?></button>
    <button type="submit" name="fresh" value="1" class="gray"<?php echo $wpb_supported ? '' : ' disabled'; ?>>از اول</button>
    <?php else : ?>
    <button type="submit" name="fresh" value="1"<?php echo $wpb_supported ? '' : ' disabled'; ?>>شروع (از جدیدترین پست)</button>
    <?php endif; ?>
    <?php if (!$wpb_supported) : ?><span class="bad">هاست WebP نمی‌سازد</span><?php endif; ?>
  </form>
  <ul class="muted" style="margin:6px 0 0;padding-right:18px">
    <li>هر کاور: همه‌ی سایزهای نمایشی از روی فایل اصلی دوباره با WebP (کیفیت <?php echo (int) $wpb_opts['quality']; ?>) ساخته می‌شود؛ فایل اصلی دست نمی‌خورد. کاوری که همه‌ی سایزهایش WebP است رد می‌شود، پس تکرار اجرا بی‌خطر است.</li>
    <li>نام فایل سایزها از <code>-380x380.jpg</code> به <code>-380x380.webp</code> عوض می‌شود. اگر افزونه‌ی کش صفحه یا CDN دارید، بعد از پایان کار کش را خالی کنید تا صفحه‌های کش‌شده به فایل‌های قدیمی اشاره نکنند.</li>
    <li>با «بازگشت به JPEG» همه‌چیز به حالت قبل برمی‌گردد (سایزها از روی فایل اصلی دوباره ساخته می‌شوند)، حتی اگر فایل‌های قدیمی پاک شده باشند.</li>
  </ul>
</div>

<?php if ($wpb_webp > 0) : ?>
<div class="box">
  <h2>بازگشت</h2>
  <form method="post" class="toolbar" onsubmit="return confirm('سایزهای نمایشی همه‌ی کاورها دوباره با فرمت خود کاور (JPEG/PNG) ساخته و فایل‌های WebP پاک می‌شوند. ادامه؟');">
    <?php wp_nonce_field('bm_webp_batch'); ?>
    <input type="hidden" name="action" value="revert"><input type="hidden" name="fresh" value="1"><input type="hidden" name="n" value="20"><input type="hidden" name="delete_old" value="1">
    <button type="submit" class="red">بازگشت به JPEG (همه‌ی کاورها)</button>
    <small class="muted">پست‌های تازه همچنان طبق config.php ساخته می‌شوند؛ برای خاموش کردن کامل، <code>$bot_webp = false;</code> بگذارید.</small>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
