<?php
/**
 * بررسی و ترمیم فایل‌های هاست دانلود
 *
 *   /bot/dl-repair.php                      فهرست پست‌های اخیر + وضعیت واقعی فایل‌های 320/128 روی هاست دانلود (درخواست HEAD از سرور سایت)
 *   /bot/dl-repair.php?days=3&limit=80      بازه‌ی بررسی (پیش‌فرض ۳ روز / ۸۰ پست؛ هم منتشرشده هم پیش‌نویس)
 *   /bot/dl-repair.php?ping=1[&test=URL]    اجرای music.php?ping=1 روی هاست دانلود از طرف سرور سایت (با یوزر/رمز) و نمایش نتیجه
 *                                           test = آدرس یک فایل mp3 مبدأ؛ هاست دانلود همان را آزمایشی دانلود می‌کند و کد/حجم/خطا را می‌گوید
 *   دکمه‌ی «انتقال دوباره»                  فایل‌های پست‌های انتخاب‌شده دوباره به music.php فرستاده می‌شود؛ اگر پست به‌خاطر همین خطا
 *                                           پیش‌نویس مانده بود و انتقال موفق شد، منتشر می‌شود
 *
 * لینک‌های مبدأ از کجا می‌آید؟ (به ترتیب)
 *   1) متای _bm_media_urls (پست‌هایی که ربات نسخه‌ی جدید ساخته)
 *   2) لینک صفحه‌ی مبدأ در متای _bm_source_url یا جدول wp_subcheck (ارسال خودکار) → پرس‌وجوی دوباره از API
 *   3) لینکی که خودتان در ستون «لینک مبدأ» می‌نویسید (صفحه‌ی آهنگ در mybia2music)
 *
 * فقط پشت همان یوزر/رمز ربات. چیزی را حذف نمی‌کند؛ فقط فایل‌ها را دوباره منتقل و در صورت نیاز پست را منتشر می‌کند.
 */
include('config.php');
require_once __DIR__ . '/auth.php';
bm_require_auth();

require('../wp-load.php');

$bm_functions_only = true;
require_once __DIR__ . '/script.php';

@set_time_limit(0);
@ignore_user_abort(true);
header('Content-Type: text/html; charset=utf-8');

$bm_api_url      = 'https://api.wintheme.ir/b2m.php';
$subcheck_table  = isset($subcheck_table) ? $subcheck_table : 'wp_subcheck';
$dl_userpwd      = bm_auth_userpwd();
$dlr_days        = isset($_GET['days']) ? max(1, min(60, (int) $_GET['days'])) : 3;
$dlr_limit       = isset($_GET['limit']) ? max(1, min(300, (int) $_GET['limit'])) : 80;
$dlr_check       = !isset($_GET['check']) || $_GET['check'] !== '0';

function dlr_h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * درخواست HEAD به یک لینک (از سرور سایت به هاست دانلود): ['code' => int, 'size' => int, 'error' => string]
 * اگر چند بار پشت‌سرهم اصلاً به هاست وصل نشد، بقیه‌ی بررسی‌ها بی‌درنگ «اتصال برقرار نشد» برمی‌گردانند تا صفحه معطل نماند.
 */
function dlr_head($url) {
    static $connect_failures = 0;
    if ($url === '' || !function_exists('curl_init')) {
        return ['code' => 0, 'size' => 0, 'error' => $url === '' ? 'لینک خالی' : 'cURL در دسترس نیست'];
    }
    if ($connect_failures >= 3) {
        return ['code' => 0, 'size' => 0, 'error' => 'اتصال به هاست دانلود برقرار نشد (بررسی متوقف شد)'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'AhangBan-Repair/1.0',
    ]);
    curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size  = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
    $connect_failures = ($code === 0 && in_array($errno, [6, 7, 28, 35], true)) ? $connect_failures + 1 : 0;
    return ['code' => $code, 'size' => max(0, $size), 'error' => $error];
}

/** یک درخواست GET با Basic Auth (برای music.php?ping=1) */
function dlr_get_auth($url, $userpwd, $timeout = 120) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $userpwd,
    ]);
    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
    return ['code' => $code, 'body' => (string) $body, 'error' => $error];
}

/** لینک صفحه‌ی مبدأ پست: متای _bm_source_url یا جدول wp_subcheck */
function dlr_source_url($post_id) {
    global $wpdb, $subcheck_table;
    $url = (string) get_post_meta($post_id, '_bm_source_url', true);
    if ($url !== '') {
        return $url;
    }
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($subcheck_table)));
    if ($exists === $subcheck_table) {
        $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$subcheck_table}", 0);
        if (in_array('post_id', $columns, true)) {
            $url = (string) $wpdb->get_var($wpdb->prepare("SELECT url FROM {$subcheck_table} WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id));
        }
    }
    return $url;
}

/**
 * لینک‌های مبدأ (music320 / music128 / image) برای انتقال دوباره
 * خروجی: ['ok' => bool, 'media' => [...], 'from' => string, 'error' => string]
 */
function dlr_media_for_post($post_id, $manual_source, $api_url) {
    $saved = json_decode((string) get_post_meta($post_id, '_bm_media_urls', true), true);
    if (is_array($saved) && (!empty($saved['music320']) || !empty($saved['music128']))) {
        return ['ok' => true, 'media' => $saved, 'from' => 'متای _bm_media_urls', 'error' => ''];
    }

    $source = $manual_source !== '' ? $manual_source : dlr_source_url($post_id);
    if ($source === '') {
        return ['ok' => false, 'media' => [], 'from' => '', 'error' => 'لینک مبدأ این پست معلوم نیست؛ لینک صفحه‌ی آهنگ در mybia2music را در ستون «لینک مبدأ» بنویسید'];
    }
    if (!preg_match('#^https?://#i', $source)) {
        return ['ok' => false, 'media' => [], 'from' => '', 'error' => 'لینک مبدأ معتبر نیست: ' . $source];
    }

    $api = bm_curl_post_json($api_url, ['url' => $source]);
    if (empty($api['success'])) {
        return ['ok' => false, 'media' => [], 'from' => '', 'error' => 'API پاسخ نداد (HTTP ' . (int) $api['code'] . ' ' . $api['error'] . ')'];
    }
    $data = json_decode((string) $api['response'], true);
    if (!is_array($data) || !empty($data['error'])) {
        return ['ok' => false, 'media' => [], 'from' => '', 'error' => 'API: ' . (is_array($data) && !empty($data['error']) ? $data['error'] : 'پاسخ نامعتبر')];
    }
    $media = [
        'music320' => trim((string) ($data['music320'] ?? '')),
        'music128' => trim((string) ($data['music128'] ?? '')),
        'image'    => trim((string) ($data['image'] ?? '')),
        'source'   => $source,
    ];
    if ($media['music320'] === '' && $media['music128'] === '') {
        return ['ok' => false, 'media' => [], 'from' => '', 'error' => 'API لینک فایل برنگرداند'];
    }
    if ($manual_source !== '' && get_post_meta($post_id, '_bm_source_url', true) === '') {
        update_post_meta($post_id, '_bm_source_url', esc_url_raw($manual_source));
    }
    return ['ok' => true, 'media' => $media, 'from' => 'API (' . $source . ')', 'error' => ''];
}

/** مسیر و نام فایل‌ها از روی لینک‌های ذخیره‌شده در متای music320 / music128 (دقیقاً همان لینک‌هایی که در پست نمایش داده می‌شود) */
function dlr_target_from_post($post_id, $dl_host) {
    $link320 = (string) get_post_meta($post_id, 'music320', true);
    $link128 = (string) get_post_meta($post_id, 'music128', true);
    $base    = $link320 !== '' ? $link320 : $link128;
    $host    = rtrim((string) $dl_host, '/');

    if ($base === '' || $host === '' || stripos($base, $host . '/') !== 0) {
        return null; // لینک روی هاست دانلود ما نیست
    }
    $relative = rawurldecode(substr($base, strlen($host) + 1));
    $dir      = trim((string) dirname($relative), '/.');
    $name320  = $link320 !== '' ? rawurldecode(basename($link320)) : '';
    $name128  = $link128 !== '' ? rawurldecode(basename($link128)) : '';
    $mp3_name = preg_replace('/\s*\[(320|128)\]\.mp3$/i', '', $name320 !== '' ? $name320 : $name128);

    if ($dir === '' || $mp3_name === '') {
        return null;
    }
    return [
        'dir'      => $dir,
        'file_320' => $name320 !== '' ? $name320 : "{$mp3_name} [320].mp3",
        'file_128' => $name128 !== '' ? $name128 : "{$mp3_name} [128].mp3",
        'mp3_name' => $mp3_name,
        'link320'  => $link320,
        'link128'  => $link128,
    ];
}

/** انتقال دوباره‌ی یک پست؛ خروجی: ['ok' => bool, 'lines' => [گزارش], 'published' => bool] */
function dlr_repair_post($post_id, $manual_source) {
    global $functions_dl_music, $urldlhost, $dl_userpwd, $bm_api_url;

    $lines = [];
    $post  = get_post($post_id);
    if (!$post) {
        return ['ok' => false, 'lines' => ['پست پیدا نشد'], 'published' => false];
    }
    $target = dlr_target_from_post($post_id, $urldlhost);
    if ($target === null) {
        return ['ok' => false, 'lines' => ['متای music320/music128 پست لینک هاست دانلود ندارد؛ این پست را ربات نساخته یا لینک‌ها دستی عوض شده'], 'published' => false];
    }
    $media = dlr_media_for_post($post_id, $manual_source, $bm_api_url);
    if (!$media['ok']) {
        return ['ok' => false, 'lines' => [$media['error']], 'published' => false];
    }
    $lines[] = 'لینک مبدأ از ' . $media['from'];

    $artist_en = (string) get_post_meta($post_id, 'artist_en', true);
    $song_en   = (string) get_post_meta($post_id, 'song_en', true);
    if ($artist_en === '' || $song_en === '') {
        // از نام فایل: "Artist - Song"
        $parts     = explode(' - ', $target['mp3_name'], 2);
        $artist_en = $artist_en !== '' ? $artist_en : trim($parts[0]);
        $song_en   = $song_en !== '' ? $song_en : trim($parts[1] ?? $parts[0]);
    }

    $response = bm_curl_post_form($functions_dl_music, [
        'music320'  => (string) ($media['media']['music320'] ?? ''),
        'music128'  => (string) ($media['media']['music128'] ?? ''),
        'artist_en' => $artist_en,
        'song_en'   => $song_en,
        'image'     => (string) ($media['media']['image'] ?? ''),
        'mp3_name'  => $target['mp3_name'],
        'file_320'  => $target['file_320'],
        'file_128'  => $target['file_128'],
        'dir_file'  => $target['dir'],
    ], 600, $dl_userpwd);
    $info = bm_dl_response_info($response);
    update_post_meta($post_id, '_bm_download_response', maybe_serialize($response));
    update_post_meta($post_id, '_bm_media_urls', wp_json_encode($media['media'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $lines[] = ($info['ok'] ? 'انتقال انجام شد: ' : 'انتقال ناموفق: ') . $info['summary'];

    // بررسی نهایی: فایل‌ها واقعاً روی هاست هستند؟
    $verify_ok = true;
    foreach (['320' => $target['link320'], '128' => $target['link128']] as $q => $link) {
        if ($link === '') {
            continue;
        }
        $head = dlr_head($link);
        $good = $head['code'] >= 200 && $head['code'] < 300;
        $verify_ok = $verify_ok && $good;
        $lines[] = "[{$q}] " . ($good ? 'روی هاست هست (' . size_format($head['size']) . ')' : 'هنوز نیست (HTTP ' . $head['code'] . ($head['error'] !== '' ? ' ' . $head['error'] : '') . ')');
    }

    $published = false;
    if ($info['ok'] && $verify_ok) {
        delete_post_meta($post_id, '_bm_download_failed');
        if ($post->post_status === 'draft') {
            $r = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
            $published = !is_wp_error($r) && (int) $r > 0;
            $lines[]   = $published ? 'پست پیش‌نویس منتشر شد' : 'انتشار پست ناموفق: ' . (is_wp_error($r) ? $r->get_error_message() : '');
        }
    } else {
        update_post_meta($post_id, '_bm_download_failed', current_time('mysql'));
    }

    return ['ok' => $info['ok'] && $verify_ok, 'lines' => $lines, 'published' => $published];
}

/* -------------------------------------------------------
 |  خروجی HTML
 * ------------------------------------------------------ */
$self = esc_url(remove_query_arg(['ping', 'test']));
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>بررسی و ترمیم فایل‌های هاست دانلود</title>
<style>
body{font-family:tahoma,sans-serif;font-size:13px;line-height:1.9;background:#f5f6fa;color:#1f2937;margin:0;padding:20px}
.box{background:#fff;border:1px solid #e3e6ee;border-radius:12px;padding:16px 18px;margin-bottom:16px}
h1{font-size:18px;margin:0 0 8px}h2{font-size:15px;margin:0 0 10px}
table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #eef0f4;padding:7px 8px;text-align:right;vertical-align:top}
th{background:#f8f9fc;font-weight:bold;font-size:12px}
.ok{color:#0b9b70;font-weight:bold}.bad{color:#dc2626;font-weight:bold}.warn{color:#b45309;font-weight:bold}.muted{color:#6b7280}
code,pre{font-family:Consolas,monospace;font-size:12px;direction:ltr;text-align:left}
pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;overflow:auto;white-space:pre-wrap;word-break:break-all}
input[type=text]{width:100%;box-sizing:border-box;border:1px solid #d7dbe4;border-radius:8px;padding:5px 8px;font-size:12px;direction:ltr}
button,.btn{border:0;border-radius:10px;padding:9px 18px;background:#635bff;color:#fff;cursor:pointer;font-family:inherit;font-size:13px;text-decoration:none;display:inline-block}
.btn.gray{background:#e5e7eb;color:#111}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
small{font-size:11px}
ul.report{margin:4px 0 0 0;padding-right:18px}
</style>
</head>
<body>
<div class="box">
  <h1>بررسی و ترمیم فایل‌های هاست دانلود</h1>
  <div class="muted">هاست دانلود: <code><?php echo dlr_h($urldlhost); ?></code> — گیرنده: <code><?php echo dlr_h($functions_dl_music); ?></code>
  <?php if ($dl_userpwd === '') : ?><span class="bad"> — یوزر/رمز برای اتصال به هاست دانلود در دسترس نیست</span><?php endif; ?></div>
  <div class="toolbar">
    <a class="btn gray" href="<?php echo $self; ?>">فهرست پست‌ها</a>
    <a class="btn gray" href="<?php echo esc_url(add_query_arg(['ping' => 1], $self)); ?>">تست هاست دانلود (ping)</a>
    <form method="get" style="display:flex;gap:6px;align-items:center" action="<?php echo esc_url(strtok($self, '?')); ?>">
      <input type="hidden" name="ping" value="1">
      <input type="text" name="test" placeholder="https://.../file.mp3  (دانلود آزمایشی روی هاست دانلود)" style="width:420px" value="<?php echo dlr_h($_GET['test'] ?? ''); ?>">
      <button type="submit">دانلود آزمایشی</button>
    </form>
  </div>
</div>

<?php
/* ---------- ping ---------- */
if (isset($_GET['ping'])) {
    $test_url = isset($_GET['test']) ? trim((string) $_GET['test']) : '';
    $ping_url = $functions_dl_music . (strpos($functions_dl_music, '?') === false ? '?' : '&') . 'ping=1' . ($test_url !== '' ? '&test=' . rawurlencode($test_url) : '');
    $r        = dlr_get_auth($ping_url, $dl_userpwd, 300);
    $data     = json_decode($r['body'], true);
    echo '<div class="box"><h2>پاسخ هاست دانلود به music.php?ping=1</h2>';
    echo '<div>HTTP ' . (int) $r['code'] . ($r['error'] !== '' ? ' — <span class="bad">' . dlr_h($r['error']) . '</span>' : '') . '</div>';
    if (is_array($data)) {
        // مقدارها این‌جا HTML آماده‌ی نمایش‌اند (متن‌های خام با dlr_h امن شده‌اند)
        $disk_free = isset($data['disk_free_mb']) ? (int) $data['disk_free_mb'] : null;
        $rows = [
            'PHP'                  => dlr_h($data['php'] ?? ''),
            'cURL'                 => dlr_h($data['curl'] ?? ''),
            'allow_url_fopen'      => !empty($data['allow_url_fopen']) ? 'روشن' : 'خاموش (مهم نیست؛ نسخه‌ی ۲ با cURL دانلود می‌کند)',
            'getID3'               => !empty($data['getid3']) ? 'هست' : '<span class="bad">نیست — پوشه‌ی getid3 کنار music.php نیست</span>',
            'ریشه'                 => '<code>' . dlr_h($data['doc_root'] ?? '') . '</code>',
            'قابل نوشتن'           => !empty($data['root_writable']) ? '<span class="ok">بله</span>' : '<span class="bad">خیر — دسترسی پوشه‌ها را بررسی کنید</span>',
            'تست نوشتن فایل'      => (($data['write_test'] ?? '') === 'ok') ? '<span class="ok">ok</span>' : '<span class="bad">' . dlr_h($data['write_test'] ?? '') . ' — روی هاست دانلود نمی‌شود فایل نوشت (سهمیه‌ی دیسک یا دسترسی)</span>',
            'فضای آزاد دیسک'       => $disk_free === null ? 'نامشخص' : (($disk_free < 500 ? '<span class="bad">' : '<span class="ok">') . number_format($disk_free) . ' MB</span> از ' . number_format((int) ($data['disk_total_mb'] ?? 0)) . ' MB' . ($disk_free < 500 ? ' — <b>دیسک تقریباً پر است</b>' : '')),
            'IP سرور هاست دانلود' => '<code>' . dlr_h($data['server_ip'] ?? '') . '</code>',
            'max_execution_time'   => dlr_h($data['max_execution'] ?? ''),
            'memory_limit'         => dlr_h($data['memory_limit'] ?? ''),
        ];
        if (isset($data['source_site'])) {
            $sc = (int) ($data['source_site']['http'] ?? 0);
            $rows['اتصال به سایت مبدأ (mybia2music)'] = ($sc >= 200 && $sc < 400 ? '<span class="ok">' : '<span class="bad">') . 'HTTP ' . $sc . '</span> ' . dlr_h($data['source_site']['error'] ?? '')
                . ($sc === 403 ? ' — <b>سایت مبدأ IP هاست دانلود را مسدود کرده</b>' : ($sc === 0 ? ' — <b>هاست دانلود اصلاً به سایت مبدأ وصل نمی‌شود (فایروال/DNS)</b>' : ''));
        }
        echo '<table>';
        foreach ($rows as $k => $v) {
            echo '<tr><th style="width:220px">' . dlr_h($k) . '</th><td>' . $v . '</td></tr>';
        }
        echo '</table>';
        if (isset($data['test_download'])) {
            $t = $data['test_download'];
            echo '<h2 style="margin-top:14px">دانلود آزمایشی</h2>';
            echo '<div><code>' . dlr_h($t['url'] ?? '') . '</code></div>';
            $sniff = (string) ($t['sniff'] ?? '');
            echo '<div>' . (!empty($t['ok']) ? '<span class="ok">دانلود شد</span> — ' . size_format((int) ($t['size'] ?? 0)) . ' در ' . dlr_h($t['seconds'] ?? '') . ' ثانیه، نوع واقعی فایل: <b>' . dlr_h($sniff !== '' ? $sniff : 'نامشخص') . '</b>'
                    . (in_array($sniff, ['mp3', 'mp4', 'jpg', 'png', 'webp'], true) ? '' : ' — <span class="bad">این فایل صوتی/تصویری نیست؛ احتمالاً صفحه‌ی خطا یا مسدودسازی سایت مبدأ است</span>')
                : '<span class="bad">ناموفق</span> — HTTP ' . (int) ($t['http'] ?? 0) . ' — ' . dlr_h($t['error'] ?? '')) . '</div>';
        }
    } else {
        echo '<p class="bad">پاسخ JSON نیست — یعنی music.php روی هاست دانلود هنوز نسخه‌ی قدیمی است (نسخه‌ی ۲ را از hostdl/music.php و hostdl/common.php بگذارید) یا خطای دیگری داده:</p>';
        echo '<pre>' . dlr_h(mb_substr(strip_tags($r['body']), 0, 2000)) . '</pre>';
    }
    echo '</div>';
}

/* ---------- repair ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['repair']) && is_array($_POST['repair'])) {
    check_admin_referer('bm_dl_repair');
    $ids     = array_values(array_unique(array_map('intval', $_POST['repair'])));
    $sources = isset($_POST['source']) && is_array($_POST['source']) ? $_POST['source'] : [];
    echo '<div class="box"><h2>نتیجه‌ی انتقال دوباره (' . count($ids) . ' پست)</h2>';
    if ($dl_userpwd === '') {
        echo '<p class="bad">یوزر/رمز اتصال به هاست دانلود در دسترس نیست.</p>';
    } else {
        $done_ok = 0;
        echo '<table><tr><th style="width:70px">پست</th><th>عنوان</th><th>نتیجه</th></tr>';
        foreach ($ids as $id) {
            if ($id <= 0) {
                continue;
            }
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            $manual = isset($sources[$id]) ? trim((string) $sources[$id]) : '';
            $res    = dlr_repair_post($id, $manual);
            $done_ok += $res['ok'] ? 1 : 0;
            echo '<tr><td>' . $id . '</td><td><a href="' . esc_url(get_permalink($id)) . '" target="_blank">' . dlr_h(get_the_title($id)) . '</a></td><td>'
                . '<span class="' . ($res['ok'] ? 'ok' : 'bad') . '">' . ($res['ok'] ? 'درست شد' : 'ناموفق') . '</span>'
                . '<ul class="report"><li>' . implode('</li><li>', array_map('dlr_h', $res['lines'])) . '</li></ul></td></tr>';
        }
        echo '</table>';
        echo '<p><b>' . $done_ok . '</b> از <b>' . count($ids) . '</b> پست درست شد. <a href="' . $self . '">بازگشت به فهرست</a></p>';
    }
    echo '</div>';
}

/* ---------- list ---------- */
$posts = get_posts([
    'post_type'      => 'post',
    'post_status'    => ['publish', 'draft'],
    'posts_per_page' => $dlr_limit,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'date_query'     => [['after' => $dlr_days . ' days ago', 'inclusive' => true]],
    'meta_query'     => [['key' => 'music320', 'compare' => 'EXISTS']],
    'no_found_rows'  => true,
]);

$missing = 0;
$rows    = [];
foreach ($posts as $p) {
    $link320 = (string) get_post_meta($p->ID, 'music320', true);
    $link128 = (string) get_post_meta($p->ID, 'music128', true);
    $on_host = $urldlhost !== '' && (stripos($link320, rtrim($urldlhost, '/') . '/') === 0 || stripos($link128, rtrim($urldlhost, '/') . '/') === 0);
    // فقط لینک‌های روی هاست دانلود خودمان بررسی می‌شود
    $h320 = $dlr_check && $on_host && $link320 !== '' ? dlr_head($link320) : null;
    $h128 = $dlr_check && $on_host && $link128 !== '' ? dlr_head($link128) : null;
    $ok320 = $h320 ? ($h320['code'] >= 200 && $h320['code'] < 300) : null;
    $ok128 = $h128 ? ($h128['code'] >= 200 && $h128['code'] < 300) : null;
    $is_missing = ($ok320 === false) || ($ok128 === false);
    if ($is_missing) {
        $missing++;
    }
    $resp = maybe_unserialize(get_post_meta($p->ID, '_bm_download_response', true));
    $info = is_array($resp) ? bm_dl_response_info($resp) : null;
    $rows[] = [
        'post' => $p, 'link320' => $link320, 'link128' => $link128, 'on_host' => $on_host,
        'h320' => $h320, 'h128' => $h128, 'ok320' => $ok320, 'ok128' => $ok128, 'missing' => $is_missing,
        'info' => $info, 'failed_meta' => (string) get_post_meta($p->ID, '_bm_download_failed', true),
        'has_media' => get_post_meta($p->ID, '_bm_media_urls', true) !== '', 'source' => dlr_source_url($p->ID),
    ];
}
?>
<div class="box">
  <h2>پست‌های <?php echo $dlr_days; ?> روز اخیر (<?php echo count($rows); ?> پست<?php echo $dlr_check ? '، ' . $missing . ' پست با فایل ناموجود' : ''; ?>)</h2>
  <div class="muted" style="margin-bottom:8px">
    بازه: <a href="<?php echo esc_url(add_query_arg(['days' => 1, 'limit' => 80], $self)); ?>">۱ روز</a> |
    <a href="<?php echo esc_url(add_query_arg(['days' => 3, 'limit' => 120], $self)); ?>">۳ روز</a> |
    <a href="<?php echo esc_url(add_query_arg(['days' => 7, 'limit' => 200], $self)); ?>">۷ روز</a> |
    <a href="<?php echo esc_url(add_query_arg(['days' => 30, 'limit' => 300], $self)); ?>">۳۰ روز</a>
    — وضعیت هر فایل با درخواست HEAD از سرور سایت به هاست دانلود گرفته می‌شود (<?php echo $dlr_check ? '<a href="' . esc_url(add_query_arg(['check' => 0], $self)) . '">بدون بررسی، سریع‌تر</a>' : '<a href="' . esc_url(remove_query_arg('check', $self)) . '">با بررسی</a>'; ?>).
  </div>
  <form method="post" action="<?php echo $self; ?>">
    <?php wp_nonce_field('bm_dl_repair'); ?>
    <div class="toolbar">
      <button type="submit" onclick="return confirm('فایل‌های پست‌های تیک‌خورده دوباره از مبدأ به هاست دانلود منتقل می‌شود. ادامه می‌دهید؟');">انتقال دوباره‌ی پست‌های تیک‌خورده</button>
      <label><input type="checkbox" onclick="document.querySelectorAll('input[name=\'repair[]\']').forEach(c=>c.checked=this.checked)"> همه</label>
      <small class="muted">پیش‌فرض: پست‌هایی که فایلشان روی هاست نیست تیک خورده‌اند. اگر «لینک مبدأ» خالی است، لینک صفحه‌ی آهنگ در mybia2music را بنویسید.</small>
    </div>
    <table>
      <tr><th></th><th>پست</th><th>تاریخ</th><th>وضعیت</th><th>فایل 320</th><th>فایل 128</th><th>آخرین پاسخ هاست دانلود</th><th style="width:260px">لینک مبدأ</th></tr>
      <?php foreach ($rows as $r) : $p = $r['post']; ?>
      <tr>
        <td><input type="checkbox" name="repair[]" value="<?php echo (int) $p->ID; ?>" <?php echo ($r['missing'] || ($p->post_status === 'draft' && $r['failed_meta'] !== '')) ? 'checked' : ''; ?> <?php echo $r['on_host'] ? '' : 'disabled title="لینک روی هاست دانلود ما نیست"'; ?>></td>
        <td><a href="<?php echo esc_url(get_permalink($p)); ?>" target="_blank"><?php echo dlr_h($p->post_title); ?></a><br><small class="muted">ID <?php echo (int) $p->ID; ?></small></td>
        <td><small><?php echo dlr_h(get_date_from_gmt($p->post_date_gmt, 'Y-m-d H:i')); ?></small></td>
        <td><?php echo $p->post_status === 'publish' ? 'منتشرشده' : '<span class="warn">پیش‌نویس</span>'; ?><?php echo $r['failed_meta'] !== '' ? '<br><small class="bad">انتقال ناموفق ' . dlr_h($r['failed_meta']) . '</small>' : ''; ?></td>
        <?php foreach (['320', '128'] as $q) : $ok = $r['ok' . $q]; $h = $r['h' . $q]; $lnk = $r['link' . $q]; ?>
        <td><?php
            if ($lnk === '') {
                echo '<span class="muted">—</span>';
            } elseif (!$r['on_host']) {
                echo '<a href="' . esc_url($lnk) . '" target="_blank" class="muted">لینک خارجی (روی هاست دانلود ما نیست)</a>';
            } elseif ($ok === null) {
                echo '<a href="' . esc_url($lnk) . '" target="_blank">لینک</a>';
            } elseif ($ok) {
                echo '<span class="ok">هست</span> <small class="muted">' . dlr_h(size_format($h['size'])) . '</small>';
            } else {
                echo '<span class="bad">نیست</span> <small class="muted">HTTP ' . (int) $h['code'] . dlr_h($h['error'] !== '' ? ' ' . $h['error'] : '') . '</small>';
            }
        ?></td>
        <?php endforeach; ?>
        <td><small><?php
            if ($r['info'] === null) {
                echo '<span class="muted">ثبت نشده</span>';
            } else {
                echo '<span class="' . ($r['info']['ok'] ? 'ok' : 'bad') . '">' . ($r['info']['ok'] ? 'گفته انجام شد' : 'خطا') . '</span> ' . dlr_h(mb_substr($r['info']['summary'], 0, 300));
            }
        ?></small></td>
        <td><?php if ($r['has_media']) : ?><small class="ok">لینک‌های مبدأ روی پست هست</small>
            <?php elseif ($r['source'] !== '') : ?><small class="muted" title="<?php echo dlr_h($r['source']); ?>">از API با: <?php echo dlr_h(mb_substr($r['source'], 0, 60)); ?></small>
            <?php else : ?><input type="text" name="source[<?php echo (int) $p->ID; ?>]" placeholder="https://www.mybia2music.com/...">
            <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)) : ?><tr><td colspan="8" class="muted">پستی در این بازه نیست.</td></tr><?php endif; ?>
    </table>
  </form>
</div>
</body>
</html>
