<?php
/**
 * دریافت فایل‌های موزیک روی هاست دانلود (نسخه‌ی ۲ — پاسخ JSON و گزارش خطای واقعی)
 *
 * ساختار پوشه‌ها (از طرف اسکریپت سایت در dir_file ارسال می‌شود):
 *   {نوع محتوا}/{حرف اول}/{نامک خواننده}      مثال: music/m/mohsen-yeganeh
 * نام فایل‌ها:
 *   Artist - Song [320].mp3  و  Artist - Song [128].mp3
 *
 * ورودی (POST): music320, music128, image, artist_en, song_en, mp3_name, file_320, file_128, dir_file
 * خروجی: JSON
 *   {"ok": true|false, "dir": "...", "files": [{"name", "ok", "size", "http", "error", "tags"}], "cover": {...}, "error": "..."}
 *   کد HTTP: 200 = همه‌ی فایل‌های خواسته‌شده منتقل شدند | 502 = دانلود از مبدأ ناموفق | 500 = خطای نوشتن روی هاست | 400 = ورودی ناقص
 *   (نسخه‌ی قبلی حتی وقتی فایل دانلود نمی‌شد 200 برمی‌گرداند و ربات «انجام شد» گزارش می‌کرد.)
 *
 * عیب‌یابی (GET با همان یوزر/رمز):
 *   music.php?ping=1                → وضعیت PHP/cURL/فضای دیسک/قابل‌نوشتن بودن پوشه + تست اتصال به سایت مبدأ
 *   music.php?ping=1&test=URL_FILE  → یک دانلود آزمایشی از همان آدرس و گزارش کد HTTP / حجم / خطا
 *
 * دسترسی فقط با یوزر/رمز تعریف‌شده در config.php (HTTP Basic Auth)
 * وابستگی: common.php (همین پوشه؛ نسخه‌ی هم‌زمان با این فایل) + پوشه‌ی getid3
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
bm_require_auth('AhangBan Download Host');
require_once __DIR__ . '/common.php';

pz_prepare_runtime();

/** تنظیمات دانلود از مبدأ: UA مرورگر + Referer سایت مبدأ (بعضی CDNها درخواست خام PHP را رد می‌کنند) */
function music_dl_opts($url) {
    $host = (string) parse_url($url, PHP_URL_HOST);
    $root = preg_replace('/^(dl|dls|cdn|files?|media|static)\d*\./i', 'www.', $host);
    return [
        'user_agent' => pz_browser_user_agent(),
        'referer'    => $host !== '' ? 'https://' . $root . '/' : '',
        'timeout'    => 1200,
    ];
}

/* -------------------------------------------------------
 |  حالت عیب‌یابی: ?ping=1[&test=URL]
 * ------------------------------------------------------ */
if (isset($_GET['ping'])) {
    $root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    $info = [
        'ok'              => true,
        'time'            => date('c'),
        'php'             => PHP_VERSION,
        'curl'            => function_exists('curl_init') ? (string) (curl_version()['version'] ?? 'yes') : 'NO',
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
        'getid3'          => is_file(__DIR__ . '/getid3/getid3.php') && is_file(__DIR__ . '/getid3/write.php'),
        'doc_root'        => $root,
        'root_writable'   => $root !== '' && is_writable($root),
        'disk_free_mb'    => $root !== '' && @disk_free_space($root) !== false ? round(disk_free_space($root) / 1048576) : null,
        'disk_total_mb'   => $root !== '' && @disk_total_space($root) !== false ? round(disk_total_space($root) / 1048576) : null,
        'server_ip'       => (string) ($_SERVER['SERVER_ADDR'] ?? ''),
        'max_execution'   => (string) ini_get('max_execution_time'),
        'memory_limit'    => (string) ini_get('memory_limit'),
    ];

    // تست نوشتن در پوشه‌ی music
    $probe_dir = pz_abs_dir('music/_ping');
    if ($probe_dir !== '') {
        $probe = $probe_dir . '/probe.txt';
        $info['write_test'] = (@file_put_contents($probe, 'ok') !== false) ? 'ok' : 'FAILED';
        @unlink($probe);
        @rmdir($probe_dir);
    } else {
        $info['write_test'] = 'FAILED (mkdir music/_ping)';
    }

    // تست اتصال به سایت مبدأ (فقط هدر)
    foreach (['https://www.mybia2music.com/' => 'source_site'] as $probe_url => $key) {
        if (function_exists('curl_init')) {
            $ch = curl_init($probe_url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => pz_browser_user_agent(),
            ]);
            curl_exec($ch);
            $info[$key] = ['http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'error' => curl_error($ch)];
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }
        }
    }

    // دانلود آزمایشی
    $test = isset($_GET['test']) ? trim((string) $_GET['test']) : '';
    if ($test !== '') {
        $tmp = ($probe_dir !== '' ? pz_abs_dir('music/_ping') : sys_get_temp_dir()) . '/test-' . substr(md5($test), 0, 8) . '.bin';
        $started = microtime(true);
        $result  = pz_download($test, $tmp, music_dl_opts($test));
        $result['seconds'] = round(microtime(true) - $started, 1);
        $result['sniff']   = $result['ok'] ? pz_sniff($tmp) : '';
        @unlink($tmp);
        @rmdir(dirname($tmp));
        $info['test_download'] = ['url' => $test] + $result;
    }

    pz_json($info);
}

/* -------------------------------------------------------
 |  ورودی
 * ------------------------------------------------------ */
$musicUrl320 = pz_post('music320');
$musicUrl128 = pz_post('music128');
$imageUrl    = pz_post('image');
$artistName  = pz_post('artist_en');
$songTitle   = pz_post('song_en');
$mp3FileName = pz_sanitize_name(pz_post('mp3_name'));
$directory   = pz_sanitize_dir(pz_post('dir_file'));

if ($mp3FileName === '' || $directory === '') {
    pz_fail('mp3_name and dir_file are required.', 400);
}
if ($musicUrl320 === '' && $musicUrl128 === '') {
    pz_fail('no music url (music320 / music128) was sent.', 400);
}

$fileName320 = pz_sanitize_name(pz_post('file_320'));
$fileName128 = pz_sanitize_name(pz_post('file_128'));
if ($fileName320 === '') {
    $fileName320 = "{$mp3FileName} [320].mp3";
}
if ($fileName128 === '') {
    $fileName128 = "{$mp3FileName} [128].mp3";
}

$baseDir = pz_abs_dir($directory);
if ($baseDir === '') {
    pz_json(['ok' => false, 'error' => "could not create directory {$directory}", 'dir' => $directory], 500);
}

/* -------------------------------------------------------
 |  کاور (برای تگ ID3؛ بعد از کار پاک می‌شود)
 * ------------------------------------------------------ */
$coverPath   = "{$baseDir}/{$mp3FileName}.jpg";
$coverResult = ['requested' => $imageUrl !== '', 'ok' => false, 'size' => 0, 'error' => ''];
if ($imageUrl !== '') {
    $r = pz_download_checked($imageUrl, $coverPath, ['image'], music_dl_opts($imageUrl));
    $coverResult = ['requested' => true, 'ok' => $r['ok'], 'size' => $r['size'], 'error' => $r['error']];
}

/* -------------------------------------------------------
 |  فایل‌های موزیک
 * ------------------------------------------------------ */
$files      = [];
$all_ok     = true;
$write_fail = false;

foreach ([[$musicUrl320, $fileName320, '320'], [$musicUrl128, $fileName128, '128']] as $job) {
    list($url, $name, $quality) = $job;
    if ($url === '') {
        continue;
    }
    $path   = "{$baseDir}/{$name}";
    $result = pz_download_checked($url, $path, ['audio'], music_dl_opts($url));
    $entry  = [
        'quality' => $quality,
        'name'    => $name,
        'ok'      => $result['ok'],
        'size'    => (int) $result['size'],
        'http'    => (int) $result['http'],
        'error'   => (string) $result['error'],
        'tags'    => '',
    ];
    if ($result['ok']) {
        $tag = pz_write_mp3_tags($path, ['title' => $songTitle, 'artist' => $artistName, 'cover' => ($coverResult['ok'] ? $coverPath : '')]);
        $entry['tags'] = pz_tag_note($tag);
    } else {
        $all_ok = false;
        if (strpos($result['error'], 'cannot write') === 0) {
            $write_fail = true;
        }
    }
    $files[] = $entry;
}

if (file_exists($coverPath)) {
    @unlink($coverPath);
}

$errors = [];
foreach ($files as $f) {
    if (!$f['ok']) {
        $errors[] = "[{$f['quality']}] {$f['error']}";
    }
}

pz_json([
    'ok'    => $all_ok,
    'dir'   => $directory,
    'files' => $files,
    'cover' => $coverResult,
    'error' => implode(' | ', $errors),
], $all_ok ? 200 : ($write_fail ? 500 : 502));
