<?php
/**
 * بررسی و ترمیم فایل‌های هاست دانلود
 *
 *   /bot/dl-repair.php                      فهرست پست‌های اخیر + وضعیت واقعی فایل‌های 320/128 روی هاست دانلود (درخواست HEAD از سرور سایت)
 *   /bot/dl-repair.php?days=3&limit=80      بازه‌ی بررسی (پیش‌فرض ۳ روز / ۸۰ پست؛ هم منتشرشده هم پیش‌نویس)
 *   /bot/dl-repair.php?ping=1[&test=URL]    اجرای music.php?ping=1 روی هاست دانلود از طرف سرور سایت (با یوزر/رمز) و نمایش نتیجه
 *                                           test = آدرس یک فایل mp3 مبدأ؛ هاست دانلود همان را آزمایشی دانلود می‌کند و کد/حجم/خطا را می‌گوید
 *   /bot/dl-repair.php?probe=1              تشخیص مسدودسازی: چند درخواست با شکل‌های مختلف (بدون/با User-Agent، GET/POST، با لینک خارجی،
 *                                           بسته‌بندی‌شده) به music.php می‌فرستد و می‌گوید وب‌سرور/WAF هاست دانلود کدام را رد می‌کند
 *   دکمه‌ی «انتقال دوباره»                  فایل‌های پست‌های انتخاب‌شده دوباره به music.php فرستاده می‌شود؛ اگر پست به‌خاطر همین خطا
 *                                           پیش‌نویس مانده بود و دست‌کم یکی از دو کیفیت روی هاست نشست، منتشر می‌شود.
 *                                           متاهای music320/music128/online با فایل‌های واقعی هماهنگ می‌شود: کیفیتی که روی هاست
 *                                           نیست از پست برداشته می‌شود (دکمه‌ی 404 نمی‌ماند) و اگر 128 نباشد، پخش آنلاین به 320 وصل می‌شود.
 *   دکمه‌ی «زباله‌دان»                       پیش‌نویس‌های تیک‌خورده‌ای که فایلشان در مبدأ هم نیست (HTTP 404 / فایل غیرصوتی) به زباله‌دان
 *                                           می‌روند؛ پست‌های منتشرشده دست نمی‌خورند.
 *
 * لینک‌های مبدأ از کجا می‌آید؟ (به ترتیب)
 *   1) متای _bm_media_urls (پست‌هایی که ربات نسخه‌ی جدید ساخته)
 *   2) لینک صفحه‌ی مبدأ در متای _bm_source_url یا جدول wp_subcheck (ارسال خودکار) → پرس‌وجوی دوباره از API
 *   3) لینکی که خودتان در ستون «لینک مبدأ» می‌نویسید (صفحه‌ی آهنگ در mybia2music)
 *
 * فقط پشت همان یوزر/رمز ربات. فایلی از هاست دانلود پاک نمی‌کند؛ فقط فایل‌ها را دوباره منتقل، متاها را هماهنگ و در صورت نیاز
 * پست را منتشر می‌کند (یا با دکمه‌ی جداگانه، پیش‌نویس انتخاب‌شده را به زباله‌دان وردپرس می‌فرستد که قابل بازگردانی است).
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
        CURLOPT_USERAGENT      => bm_http_user_agent(),
        CURLOPT_HTTPHEADER     => bm_http_headers(),
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

/**
 * یک درخواست خام به هاست دانلود (برای ping و probe)
 * $o: method (GET|POST)، fields (آرایه = multipart، رشته = urlencoded، null = بدون بدنه)، ua (رشته؛ '' = بدون User-Agent)،
 *     headers (آرایه)، userpwd، timeout
 * خروجی: code, body, error, errno, type (Content-Type پاسخ)
 */
function dlr_raw_request($url, array $o = []) {
    $ch      = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => isset($o['timeout']) ? (int) $o['timeout'] : 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    if (!empty($o['userpwd'])) {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD]  = (string) $o['userpwd'];
    }
    if (isset($o['ua']) && $o['ua'] !== '') {
        $options[CURLOPT_USERAGENT] = (string) $o['ua'];
    }
    if (!empty($o['headers'])) {
        $options[CURLOPT_HTTPHEADER] = (array) $o['headers'];
    }
    if (isset($o['method']) && $o['method'] === 'POST') {
        $options[CURLOPT_POST]       = true;
        $options[CURLOPT_POSTFIELDS] = isset($o['fields']) ? $o['fields'] : '';
    }
    curl_setopt_array($ch, $options);
    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
    return ['code' => $code, 'body' => (string) $body, 'error' => $error, 'errno' => $errno, 'type' => $type];
}

/** یک درخواست GET با Basic Auth (برای music.php?ping=1) — با همان هدرهای ربات */
function dlr_get_auth($url, $userpwd, $timeout = 120) {
    return dlr_raw_request($url, ['userpwd' => $userpwd, 'timeout' => $timeout, 'ua' => bm_http_user_agent(), 'headers' => bm_http_headers()]);
}

/**
 * تفسیر پاسخ یک درخواست probe: به PHP رسید یا وب‌سرور/WAF رد کرد؟
 * خروجی: ['reached' => bool|null, 'label' => متن کوتاه, 'json' => array|null]
 */
function dlr_probe_verdict(array $r) {
    $code = (int) $r['code'];
    $body = (string) $r['body'];
    $json = json_decode(trim($body), true);
    if ($code === 0) {
        return ['reached' => null, 'label' => 'اتصال برقرار نشد: ' . $r['error'], 'json' => null];
    }
    if (is_array($json)) {
        if (!empty($json['probe']) || (isset($json['ok']) && $json['ok'] && isset($json['php']))) {
            return ['reached' => true, 'label' => 'رسید به PHP ✓ (HTTP ' . $code . ')', 'json' => $json];
        }
        return ['reached' => true, 'label' => 'رسید به PHP ✓ (HTTP ' . $code . ': ' . (isset($json['error']) ? mb_substr((string) $json['error'], 0, 80) : 'JSON') . ')', 'json' => $json];
    }
    if ($code === 401) {
        return ['reached' => true, 'label' => 'رسید به PHP ولی یوزر/رمز رد شد (401)', 'json' => null];
    }
    if ($code === 404) {
        return ['reached' => false, 'label' => 'music.php پیدا نشد (404)', 'json' => null];
    }
    if (bm_response_blocked_by_server($code, $body)) {
        return ['reached' => false, 'label' => 'وب‌سرور/WAF رد کرد ✗ (HTTP ' . $code . ')', 'json' => null];
    }
    $text = mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($body))), 0, 100);
    return ['reached' => $code < 400 ? true : false, 'label' => 'HTTP ' . $code . ($text !== '' ? ': ' . $text : ''), 'json' => null];
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

/**
 * مسیر و نام فایل‌ها از روی لینک‌های ذخیره‌شده در متای music320 / music128 (همان پوشه‌ای که فایل‌های پست در آن است)
 * نام هر کیفیت همیشه «نام پایه + [320]/[128]» ساخته می‌شود، نه از روی لینک همان کیفیت: وقتی 128 نبوده، music128 به فایل 320
 * اشاره می‌کند و اگر نام از روی آن گرفته می‌شد، فایل 128 روی فایل 320 نوشته می‌شد.
 */
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
    // نام پایه: پسوند کیفیت به هر دو شکل جدید «[320]» و قدیمی «(128)» و خود .mp3 برداشته می‌شود
    $mp3_name = preg_replace('/\s*(?:\[(?:320|128)\]|\((?:320|128)\))?\.mp3$/i', '', rawurldecode(basename($base)));

    if ($dir === '' || $mp3_name === '') {
        return null;
    }
    return [
        'dir'      => $dir,
        'file_320' => "{$mp3_name} [320].mp3",
        'file_128' => "{$mp3_name} [128].mp3",
        'mp3_name' => $mp3_name,
        'link320'  => $link320,
        'link128'  => $link128,
    ];
}

/**
 * انتقال دوباره‌ی یک پست
 * خروجی: ['ok' => bool (دست‌کم یک کیفیت روی هاست هست), 'all' => bool (همه‌ی کیفیت‌های مبدأ رسیده), 'label' => «فقط 128» و…,
 *          'lines' => [گزارش], 'published' => bool]
 */
function dlr_repair_post($post_id, $manual_source) {
    global $functions_dl_music, $urldlhost, $dl_userpwd, $bm_api_url;

    $lines = [];
    $post  = get_post($post_id);
    if (!$post) {
        return ['ok' => false, 'all' => false, 'label' => '', 'lines' => ['پست پیدا نشد'], 'published' => false];
    }
    $target = dlr_target_from_post($post_id, $urldlhost);
    if ($target === null) {
        return ['ok' => false, 'all' => false, 'label' => '', 'lines' => ['متای music320/music128 پست لینک هاست دانلود ندارد؛ این پست را ربات نساخته یا لینک‌ها دستی عوض شده'], 'published' => false];
    }
    $media = dlr_media_for_post($post_id, $manual_source, $bm_api_url);
    if (!$media['ok']) {
        return ['ok' => false, 'all' => false, 'label' => '', 'lines' => [$media['error']], 'published' => false];
    }
    $lines[] = 'لینک مبدأ از ' . $media['from'];

    $src320 = trim((string) ($media['media']['music320'] ?? ''));
    $src128 = trim((string) ($media['media']['music128'] ?? ''));

    $artist_en = (string) get_post_meta($post_id, 'artist_en', true);
    $song_en   = (string) get_post_meta($post_id, 'song_en', true);
    if ($artist_en === '' || $song_en === '') {
        // از نام فایل: "Artist - Song"
        $parts     = explode(' - ', $target['mp3_name'], 2);
        $artist_en = $artist_en !== '' ? $artist_en : trim($parts[0]);
        $song_en   = $song_en !== '' ? $song_en : trim($parts[1] ?? $parts[0]);
    }

    $response = bm_curl_post_form($functions_dl_music, [
        'music320'  => $src320,
        'music128'  => $src128,
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

    // بررسی نهایی: کدام فایل‌ها واقعاً روی هاست هستند؟ فقط کیفیت‌هایی که مبدأ دارد انتظار می‌رود
    // (لینک از پوشه/نام فایل پست ساخته می‌شود تا کیفیتی که قبلاً متایش برداشته شده هم بررسی شود)
    $host   = rtrim((string) $urldlhost, '/');
    $links  = [
        '320' => esc_url_raw($host . '/' . $target['dir'] . '/' . $target['file_320']),
        '128' => esc_url_raw($host . '/' . $target['dir'] . '/' . $target['file_128']),
    ];
    $wanted = ['320' => $src320 !== '', '128' => $src128 !== ''];
    $have   = ['320' => false, '128' => false];
    foreach (['320', '128'] as $q) {
        if (!$wanted[$q]) {
            continue;
        }
        $head     = dlr_head($links[$q]);
        $have[$q] = $head['code'] >= 200 && $head['code'] < 300;
        $why      = bm_dl_file_error($info, $q);
        $lines[]  = "[{$q}] " . ($have[$q]
            ? 'روی هاست هست (' . size_format($head['size']) . ')'
            : 'هنوز نیست (HTTP ' . $head['code'] . ($head['error'] !== '' ? ' ' . $head['error'] : '') . ')'
                . (bm_dl_error_is_permanent($why) ? ' — در مبدأ هم موجود/سالم نیست؛ انتقال دوباره فایده ندارد' : ''));
    }

    $all_ok    = (!$wanted['320'] || $have['320']) && (!$wanted['128'] || $have['128']);
    $usable    = $have['320'] || $have['128'];
    $published = false;
    $label     = bm_quality_label($have);

    if ($usable) {
        // متاهای پست با فایل‌های واقعی هماهنگ می‌شود: کیفیت نرسیده برداشته، کیفیت رسیده گذاشته می‌شود
        $sync = bm_sync_music_metas($post_id, $have, $links);
        foreach ($sync['changes'] as $change) {
            $lines[] = $change;
        }
        if ($all_ok) {
            bm_set_download_state($post_id, 'ok');
        } else {
            bm_set_download_state($post_id, 'partial', $label . ' — ' . $info['summary']);
            $lines[] = 'پست با ' . $label . ' قابل استفاده است';
        }
        if ($post->post_status === 'draft') {
            $r = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
            $published = !is_wp_error($r) && (int) $r > 0;
            $lines[]   = $published ? 'پست پیش‌نویس منتشر شد' . ($all_ok ? '' : ' (' . $label . ')') : 'انتشار پست ناموفق: ' . (is_wp_error($r) ? $r->get_error_message() : '');
        }
    } else {
        bm_set_download_state($post_id, 'failed');
    }

    return ['ok' => $usable, 'all' => $all_ok, 'label' => $label, 'lines' => $lines, 'published' => $published];
}

/**
 * تفسیر آخرین پاسخ هاست دانلود برای راهنمایی کاربر
 * خروجی: ['permanent' => bool, 'text' => string]
 *   permanent = هیچ کیفیتی نرسیده و همه‌ی خطاها دائمی است (فایل در مبدأ نیست / آدرس مبدأ به‌جای فایل صوتی عکس می‌دهد) → انتقال دوباره فایده ندارد
 *   اگر یک کیفیت رسیده و کیفیت دیگر خطای دائمی دارد → متن می‌گوید «انتقال دوباره» پست را با کیفیت موجود منتشر می‌کند
 */
function dlr_permanent_hint($info) {
    $none = ['permanent' => false, 'text' => ''];
    if (!is_array($info) || empty($info['files']) || !is_array($info['files'])) {
        return $none;
    }
    $ok = 0;
    $failed = 0;
    $permanent = 0;
    $bad = [];
    foreach ($info['files'] as $file) {
        if (!empty($file['ok'])) {
            $ok++;
            continue;
        }
        $failed++;
        if (bm_dl_error_is_permanent(isset($file['error']) ? $file['error'] : '')) {
            $permanent++;
            $bad[] = isset($file['quality']) ? (string) $file['quality'] : '?';
        }
    }
    if ($failed === 0 || $failed !== $permanent) {
        return $none;
    }
    if ($ok === 0) {
        return ['permanent' => true, 'text' => 'فایل در مبدأ نیست یا خراب است؛ انتقال دوباره فایده ندارد'];
    }
    return ['permanent' => false, 'text' => 'کیفیت ' . implode('/', $bad) . ' در مبدأ نیست یا خراب است؛ «انتقال دوباره» پست را با کیفیت موجود منتشر می‌کند'];
}

/** لینک‌های مبدأ ذخیره‌شده روی پست: null = متایی نیست، وگرنه ['has_file' => bool, 'source' => لینک صفحه] */
function dlr_saved_media($post_id) {
    $raw = (string) get_post_meta($post_id, '_bm_media_urls', true);
    if ($raw === '') {
        return null;
    }
    $saved = json_decode($raw, true);
    if (!is_array($saved)) {
        return null;
    }
    return [
        'has_file' => trim((string) ($saved['music320'] ?? '')) !== '' || trim((string) ($saved['music128'] ?? '')) !== '',
        'source'   => (string) ($saved['source'] ?? ''),
    ];
}

/* -------------------------------------------------------
 |  خروجی HTML
 * ------------------------------------------------------ */
$self = esc_url(remove_query_arg(['ping', 'test', 'probe']));
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
    <a class="btn gray" href="<?php echo esc_url(add_query_arg(['probe' => 1, 'check' => 0], $self)); ?>">تشخیص مسدودسازی (probe)</a>
    <a class="btn gray" href="watermark-batch.php">لوگو روی کاورها</a>
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

/* ---------- probe: چه شکلی از درخواست را هاست دانلود رد می‌کند؟ ---------- */
if (isset($_GET['probe'])) {
    $ping_url = $functions_dl_music . (strpos($functions_dl_music, '?') === false ? '?' : '&') . 'ping=1';
    $plain    = ['probe' => '1', 'artist_en' => 'Probe Artist', 'song_en' => 'Probe Song', 'mp3_name' => 'Probe Artist - Probe Song', 'dir_file' => 'music/p/probe-artist'];
    $with_url = $plain + ['music320' => 'https://example.com/files/Probe%20Artist%20-%20Probe%20Song%20%5B320%5D.mp3', 'music128' => 'https://example.com/files/probe-128.mp3', 'image' => 'https://example.com/img/probe.jpg'];
    $ua       = bm_http_user_agent();
    $hdr      = bm_http_headers();
    $wrapped  = http_build_query(bm_wrap_payload($with_url), '', '&');

    $urlenc_hdr = array_merge($hdr, ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8']);
    $variants = [
        'old'       => ['title' => 'شکل قدیمی ربات: POST multipart (شبیه آپلود فایل) با لینک‌های خارجی، بدون User-Agent', 'o' => ['method' => 'POST', 'fields' => $with_url, 'ua' => '']],
        'get_noua'  => ['title' => 'GET ping بدون User-Agent', 'url' => $ping_url, 'o' => ['method' => 'GET', 'ua' => '']],
        'get_ua'    => ['title' => 'GET ping با User-Agent مرورگر', 'url' => $ping_url, 'o' => ['method' => 'GET', 'ua' => $ua, 'headers' => $hdr]],
        'multipart' => ['title' => 'POST multipart بدون لینک خارجی، با User-Agent', 'o' => ['method' => 'POST', 'fields' => $plain, 'ua' => $ua, 'headers' => $hdr]],
        'form'      => ['title' => 'POST فرم ساده (urlencoded) با لینک‌های خارجی، با User-Agent — شکل جدید ربات', 'o' => ['method' => 'POST', 'fields' => http_build_query($with_url, '', '&'), 'ua' => $ua, 'headers' => $urlenc_hdr]],
        'wrapped'   => ['title' => 'POST بسته‌بندی‌شده (bm_payload) با User-Agent', 'o' => ['method' => 'POST', 'fields' => $wrapped, 'ua' => $ua, 'headers' => $urlenc_hdr]],
    ];

    $verdicts  = [];
    $client_ip = '';
    echo '<div class="box"><h2>تشخیص مسدودسازی — ' . dlr_h($functions_dl_music) . '</h2>';
    if ($dl_userpwd === '') {
        echo '<p class="bad">یوزر/رمز اتصال به هاست دانلود در دسترس نیست.</p>';
    } else {
        echo '<table><tr><th style="width:45%">شکل درخواست</th><th>نتیجه</th></tr>';
        foreach ($variants as $key => $v) {
            $o            = $v['o'] + ['userpwd' => $dl_userpwd, 'timeout' => 60];
            $r            = dlr_raw_request(isset($v['url']) ? $v['url'] : $functions_dl_music, $o);
            $verdict      = dlr_probe_verdict($r);
            $verdicts[$key] = $verdict;
            if ($client_ip === '' && is_array($verdict['json']) && !empty($verdict['json']['client_ip'])) {
                $client_ip = (string) $verdict['json']['client_ip'];
            }
            $extra = '';
            if (is_array($verdict['json']) && array_key_exists('wrapped', $verdict['json'])) {
                $extra = $verdict['json']['wrapped'] ? ' — بسته‌بندی باز شد' : '';
            }
            if ($verdict['reached'] === false && $verdict['json'] === null) {
                $extra = '<br><small class="muted">' . dlr_h(mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($r['body']))), 0, 120)) . '</small>';
            }
            echo '<tr><td>' . dlr_h($v['title']) . '</td><td><span class="' . ($verdict['reached'] === true ? 'ok' : ($verdict['reached'] === false ? 'bad' : 'warn')) . '">' . dlr_h($verdict['label']) . '</span>' . $extra . '</td></tr>';
        }
        echo '</table>';

        $ok = function ($k) use ($verdicts) {
            return isset($verdicts[$k]) && $verdicts[$k]['reached'] === true;
        };
        $blocked = function ($k) use ($verdicts) {
            return isset($verdicts[$k]) && $verdicts[$k]['reached'] === false;
        };
        $wrapped_supported = isset($verdicts['wrapped']['json']['wrapped']) && $verdicts['wrapped']['json']['wrapped'] === true;

        echo '<h2 style="margin-top:14px">نتیجه‌گیری</h2><ul class="report">';
        if ($client_ip !== '') {
            echo '<li>IP سرور سایت از دید هاست دانلود: <code>' . dlr_h($client_ip) . '</code> (اگر لازم شد همین را به پشتیبانی هاست دانلود بدهید تا آزاد کند)</li>';
        }
        if ($verdicts['old']['reached'] === null) {
            echo '<li class="bad">سرور سایت اصلاً به هاست دانلود وصل نمی‌شود (فایروال/DNS) — با پشتیبانی هاست دانلود (یا سایت) مطرح کنید.</li>';
        } elseif ($ok('form')) {
            $why = '';
            if ($blocked('old') && $blocked('multipart')) {
                $why = ' هاست دانلود درخواست‌های multipart (شکل آپلود فایل — همان چیزی که به‌خاطر آسیب‌پذیری PHP2Shell بسته‌اند) را 403 می‌کند؛ ربات قبلی با همین قالب می‌فرستاد. script.php جدید فرم ساده می‌فرستد و این قالب می‌رسد؛ مشکل حل است.';
            } elseif ($blocked('old') && $ok('multipart')) {
                $why = ' فقط درخواست بدون User-Agent رد می‌شود؛ script.php جدید User-Agent می‌فرستد؛ مشکل حل است.';
            } else {
                $why = ' الان مسدودسازی‌ای نیست.';
            }
            echo '<li class="ok">شکل جدید ربات (فرم ساده با User-Agent) به PHP می‌رسد.' . $why . ' از فهرست پست‌ها «انتقال دوباره» را بزنید.</li>';
        } elseif ($blocked('form') && $wrapped_supported) {
            echo '<li class="warn">WAF هاست دانلود فرم‌هایی را که لینک خارجی دارند رد می‌کند (قانون RFI)' . ($ok('multipart') ? '' : ' و multipart را هم') . '. ارسال بسته‌بندی‌شده می‌رسد و باز می‌شود؛ ربات در حالت auto خودش به آن می‌افتد. برای یک درخواست کمتر، در bot/config.php بگذارید: <code>$bot_dl_transport = \'wrapped\';</code></li>';
        } elseif ($blocked('form') && $ok('multipart') && !$wrapped_supported) {
            echo '<li class="bad">WAF هاست دانلود فرم‌هایی را که لینک خارجی دارند رد می‌کند و common.php روی هاست دانلود هنوز بسته‌بندی را باز نمی‌کند — hostdl/common.php و hostdl/music.php جدید را آپلود کنید و دوباره probe بگیرید.</li>';
        } elseif ($blocked('form') && $blocked('multipart') && ($ok('get_ua') || $ok('get_noua'))) {
            echo '<li class="bad">GET می‌رسد ولی هر POST به PHP رد می‌شود. این کار WAF/ModSecurity هاست دانلود است؛ از پشتیبانی هاست دانلود بخواهید درخواست‌های POST از IP بالا به music.php را آزاد کنند (یا بگویند دقیقاً چه قالبی مجاز است).</li>';
        } elseif ($blocked('get_ua') && $blocked('get_noua')) {
            echo '<li class="bad">هر درخواستی از سرور سایت به music.php رد می‌شود (حتی GET ساده) ولی فایل‌های استاتیک پاسخ می‌دهند؛ یعنی IP سرور سایت برای اجرای PHP روی هاست دانلود مسدود شده (فایروال، ModSecurity/Imunify، یا قانون deny در nginx). راه حل فقط دست پشتیبانی هاست دانلود است: «درخواست‌های IP ' . dlr_h($client_ip !== '' ? $client_ip : 'سرور سایت') . ' به dl.ahangban.ir/music.php با 403 رد می‌شود، لطفاً آزاد شود». تا آن موقع می‌توانید music.php?ping=1 را در مرورگر خودتان باز کنید تا ببینید از IP شما کار می‌کند.</li>';
        } else {
            echo '<li class="warn">الگوی مشخصی درنیامد؛ جدول بالا را برای پشتیبانی هاست دانلود بفرستید.</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

/* ---------- trash: پیش‌نویس‌های تیک‌خورده → زباله‌دان (پست منتشرشده دست نمی‌خورد) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['trash']) && !empty($_POST['repair']) && is_array($_POST['repair'])) {
    check_admin_referer('bm_dl_repair');
    $ids = array_values(array_unique(array_map('intval', $_POST['repair'])));
    echo '<div class="box"><h2>انتقال به زباله‌دان (' . count($ids) . ' پست تیک‌خورده)</h2>';
    echo '<table><tr><th style="width:70px">پست</th><th>عنوان</th><th>نتیجه</th></tr>';
    $trashed = 0;
    foreach ($ids as $id) {
        $p = $id > 0 ? get_post($id) : null;
        if (!$p) {
            continue;
        }
        if ($p->post_status !== 'draft') {
            $note = '<span class="warn">دست نخورد</span> — فقط پیش‌نویس‌ها به زباله‌دان می‌روند (این پست ' . ($p->post_status === 'publish' ? 'منتشرشده' : dlr_h($p->post_status)) . ' است)';
        } elseif (wp_trash_post($id)) {
            $trashed++;
            $note = '<span class="ok">به زباله‌دان رفت</span> — از پیشخوان › نوشته‌ها › زباله‌دان قابل بازگردانی است';
        } else {
            $note = '<span class="bad">نشد</span>';
        }
        echo '<tr><td>' . (int) $id . '</td><td>' . dlr_h($p->post_title) . '</td><td>' . $note . '</td></tr>';
    }
    echo '</table><p><b>' . $trashed . '</b> پیش‌نویس به زباله‌دان رفت. <a href="' . $self . '">بازگشت به فهرست</a></p></div>';
}

/* ---------- repair ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['trash']) && !empty($_POST['repair']) && is_array($_POST['repair'])) {
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
            if (!$res['ok']) {
                $verdict = '<span class="bad">ناموفق</span>';
            } elseif (!empty($res['all'])) {
                $verdict = '<span class="ok">درست شد</span>';
            } else {
                $verdict = '<span class="warn">ناقص ولی قابل استفاده (' . dlr_h($res['label']) . ')</span>';
            }
            echo '<tr><td>' . $id . '</td><td><a href="' . esc_url(get_permalink($id)) . '" target="_blank">' . dlr_h(get_the_title($id)) . '</a></td><td>'
                . $verdict
                . '<ul class="report"><li>' . implode('</li><li>', array_map('dlr_h', $res['lines'])) . '</li></ul></td></tr>';
        }
        echo '</table>';
        echo '<p><b>' . $done_ok . '</b> از <b>' . count($ids) . '</b> پست دست‌کم یک کیفیت سالم دارد و قابل استفاده است. <a href="' . $self . '">بازگشت به فهرست</a></p>';
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
    'meta_query'     => [
        'relation' => 'OR', // پست‌هایی که مبدأشان فقط 128 داشته، music320 ندارند
        ['key' => 'music320', 'compare' => 'EXISTS'],
        ['key' => 'music128', 'compare' => 'EXISTS'],
    ],
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
        'partial_meta' => (string) get_post_meta($p->ID, '_bm_download_partial', true),
        'hint' => dlr_permanent_hint($info),
        'saved_media' => dlr_saved_media($p->ID), 'source' => dlr_source_url($p->ID),
    ];
    // غیرقابل‌ترمیم با ربات: فایل در مبدأ نیست/خراب است، یا صفحه‌ی مبدأ اصلاً لینک فایل آهنگ نداشته (آلبوم/ویدیو)
    $last = count($rows) - 1;
    $rows[$last]['unfixable'] = $rows[$last]['hint']['permanent'] || (is_array($rows[$last]['saved_media']) && !$rows[$last]['saved_media']['has_file']);
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
      <button type="submit" name="trash" value="1" class="btn gray" onclick="return confirm('پیش‌نویس‌های تیک‌خورده به زباله‌دان وردپرس می‌روند (پست‌های منتشرشده دست نمی‌خورند؛ از زباله‌دان قابل بازگردانی است). ادامه می‌دهید؟');">پیش‌نویس‌های تیک‌خورده → زباله‌دان</button>
      <label><input type="checkbox" onclick="document.querySelectorAll('input[name=\'repair[]\']:not(:disabled)').forEach(c=>c.checked=this.checked)"> همه</label>
      <label title="پست‌هایی که فایلشان در مبدأ هم نیست (برای زباله‌دان)"><input type="checkbox" onclick="document.querySelectorAll('input[name=\'repair[]\'][data-permanent=\'1\']:not(:disabled)').forEach(c=>c.checked=this.checked)"> فقط غیرقابل‌ترمیم‌ها</label>
      <small class="muted">پیش‌فرض: پست‌هایی که فایلشان روی هاست نیست و انتقال دوباره برایشان معنی دارد تیک خورده‌اند؛ پستی که فایلش در مبدأ هم نیست تیک ندارد (برای زباله‌دان خودتان تیک بزنید). اگر پستی فقط یک کیفیت دارد، همان کافی است و منتشر می‌شود. اگر «لینک مبدأ» خالی است، لینک صفحه‌ی آهنگ در mybia2music را بنویسید.</small>
    </div>
    <table>
      <tr><th></th><th>پست</th><th>تاریخ</th><th>وضعیت</th><th>فایل 320</th><th>فایل 128</th><th>آخرین پاسخ هاست دانلود</th><th style="width:260px">لینک مبدأ</th></tr>
      <?php foreach ($rows as $r) : $p = $r['post']; ?>
      <tr>
        <td><input type="checkbox" name="repair[]" value="<?php echo (int) $p->ID; ?>" data-permanent="<?php echo $r['unfixable'] ? '1' : '0'; ?>" <?php echo (!$r['unfixable'] && ($r['missing'] || ($p->post_status === 'draft' && $r['failed_meta'] !== ''))) ? 'checked' : ''; ?> <?php echo $r['on_host'] ? '' : 'disabled title="لینک روی هاست دانلود ما نیست"'; ?>></td>
        <td><a href="<?php echo esc_url(get_permalink($p)); ?>" target="_blank"><?php echo dlr_h($p->post_title); ?></a><br><small class="muted">ID <?php echo (int) $p->ID; ?></small></td>
        <td><small><?php echo dlr_h(get_date_from_gmt($p->post_date_gmt, 'Y-m-d H:i')); ?></small></td>
        <td><?php echo $p->post_status === 'publish' ? 'منتشرشده' : '<span class="warn">پیش‌نویس</span>'; ?><?php
            echo $r['failed_meta'] !== '' ? '<br><small class="bad">انتقال ناموفق ' . dlr_h($r['failed_meta']) . '</small>' : '';
            echo $r['partial_meta'] !== '' ? '<br><small class="warn" title="' . dlr_h($r['partial_meta']) . '">ناقص: ' . dlr_h(mb_substr($r['partial_meta'], 0, 60)) . '</small>' : '';
        ?></td>
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
                if ($r['hint']['permanent']) {
                    echo '<br><b class="bad">' . dlr_h($r['hint']['text']) . '</b> — یا فایل را با پست‌ساز دستی بدهید یا پیش‌نویس را به زباله‌دان بفرستید';
                } elseif ($r['hint']['text'] !== '' && $r['partial_meta'] !== '') {
                    echo '<br><b class="warn">پست با همان کیفیت موجود منتشر شده؛ کیفیت دیگر در مبدأ نیست یا خراب است</b>';
                } elseif ($r['hint']['text'] !== '') {
                    echo '<br><b class="warn">' . dlr_h($r['hint']['text']) . '</b>';
                }
            }
        ?></small></td>
        <td><?php if (is_array($r['saved_media']) && $r['saved_media']['has_file']) : ?><small class="ok">لینک‌های مبدأ روی پست هست</small>
            <?php elseif (is_array($r['saved_media'])) : ?><small class="warn" title="<?php echo dlr_h($r['saved_media']['source']); ?>">صفحه‌ی مبدأ لینک فایل آهنگ نداشت (آلبوم/ویدیو؟) — این پست را ربات نمی‌تواند درست کند؛ با پست‌ساز ثبت کنید و این پیش‌نویس را به زباله‌دان بفرستید</small>
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
