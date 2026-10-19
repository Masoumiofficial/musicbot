<?php
/**
 * توابع مشترک گیرنده‌های پست‌ساز روی هاست دانلود (video.php ، album.php ، leech.php)
 *
 * هر گیرنده اول config.php و auth.php را بارگذاری و bm_require_auth() را اجرا می‌کند، بعد این فایل را.
 * پاسخ همه‌ی گیرنده‌ها JSON است: {"ok": true|false, ...}
 */

if (!function_exists('bm_require_auth')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!defined('PZ_BRAND')) {
    define('PZ_BRAND', isset($dl_brand) && $dl_brand !== '' ? (string) $dl_brand : '[ AhangBan.ir ]');
}

/** آماده‌سازی اجرا برای انتقال‌های طولانی */
function pz_prepare_runtime() {
    @set_time_limit(0);
    @ignore_user_abort(true);
    @ini_set('memory_limit', '512M');
    // هر خروجی ناخواسته (اخطارهای PHP یا getID3) بافر می‌شود تا JSON پاسخ خراب نشود
    ob_start();
}

function pz_json(array $data, $code = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pz_fail($message, $code = 400) {
    pz_json(['ok' => false, 'error' => (string) $message], $code);
}

function pz_post($key, $default = '') {
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }
    return trim((string) $_POST[$key]);
}

/**
 * مسیر نسبی پوشه: فقط بخش‌های سالم (بدون "." و ".." و کاراکتر کنترلی)
 * مثال: "album/m/mohsen-yeganeh"
 */
function pz_sanitize_dir($dir) {
    $segments = [];
    foreach (preg_split('#[\\\\/]+#', (string) $dir) as $segment) {
        $segment = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $segment));
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $segments[] = $segment;
    }
    return implode('/', $segments);
}

/**
 * نام فایل یا پوشه بدون جداکننده‌ی مسیر و کاراکتر کنترلی (جلوگیری از خروج از پوشه)
 */
function pz_sanitize_name($name) {
    $name = preg_replace('/[\x00-\x1F\x7F]+/', '', (string) $name);
    $name = str_replace(['/', '\\'], '', $name);
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }
    if (function_exists('mb_substr') && mb_strlen($name, 'UTF-8') > 200) {
        $name = mb_substr($name, 0, 200, 'UTF-8');
    }
    return $name;
}

function pz_valid_url($url) {
    return is_string($url)
        && $url !== ''
        && preg_match('#^https?://#i', $url)
        && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/** مسیر مطلق پوشه زیر ریشه‌ی هاست دانلود (ساخته می‌شود اگر نباشد)؛ '' در صورت خطا */
function pz_abs_dir($relative) {
    $root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\');
    $path = $root . '/' . $relative;
    if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
        return '';
    }
    return $path;
}

/**
 * دانلود یک URL در مسیر مقصد (استریم؛ کل فایل در حافظه نمی‌آید)
 * خروجی: ['ok' => bool, 'size' => int, 'error' => string, 'http' => int]
 */
function pz_download($url, $destination) {
    if (!pz_valid_url($url)) {
        return ['ok' => false, 'size' => 0, 'error' => 'invalid url', 'http' => 0];
    }

    $handle = @fopen($destination, 'wb');
    if (!$handle) {
        return ['ok' => false, 'size' => 0, 'error' => 'cannot write ' . basename($destination), 'http' => 0];
    }

    $http  = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 1800,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'AhangBan-DL/1.0',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $done  = curl_exec($ch);
        $error = curl_error($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        unset($ch);
        fclose($handle);
        if ($done === false) {
            @unlink($destination);
            return ['ok' => false, 'size' => 0, 'error' => $error !== '' ? $error : ('HTTP ' . $http), 'http' => $http];
        }
    } else {
        // بدون cURL: جریان http (با ignore_errors خاموش، پاسخ 4xx/5xx باز نمی‌شود)
        $context = stream_context_create([
            'http' => ['timeout' => 1800, 'follow_location' => 1, 'max_redirects' => 5, 'user_agent' => 'AhangBan-DL/1.0'],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $source = @fopen($url, 'rb', false, $context);
        if (!$source) {
            fclose($handle);
            @unlink($destination);
            return ['ok' => false, 'size' => 0, 'error' => 'cannot open url', 'http' => 0];
        }
        stream_copy_to_stream($source, $handle);
        fclose($source);
        fclose($handle);
    }

    clearstatcache(true, $destination);
    $size = (int) @filesize($destination);
    if ($size <= 0) {
        @unlink($destination);
        return ['ok' => false, 'size' => 0, 'error' => 'empty file', 'http' => $http];
    }

    return ['ok' => true, 'size' => $size, 'error' => '', 'http' => $http];
}

/** نوع واقعی فایل از روی بایت‌های اول */
function pz_sniff($path) {
    $head = @file_get_contents($path, false, null, 0, 16);
    if ($head === false || strlen($head) < 4) {
        return '';
    }
    if (substr($head, 0, 3) === 'ID3') {
        return 'mp3';
    }
    if (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) {
        return 'mp3';
    }
    if (substr($head, 4, 4) === 'ftyp') {
        return 'mp4';
    }
    if (substr($head, 0, 3) === "\xFF\xD8\xFF") {
        return 'jpg';
    }
    if (substr($head, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return 'png';
    }
    if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (preg_match('/^\s*<(!doctype|html|\?xml|head|body)/i', $head)) {
        return 'html';
    }
    return '';
}

/**
 * دانلود + بررسی نوع فایل؛ اگر محتوا با نوع موردانتظار نخواند (مثلاً صفحه‌ی HTML خطا) فایل پاک می‌شود
 * $kinds: audio | video | image
 */
function pz_download_checked($url, $destination, array $kinds) {
    $result = pz_download($url, $destination);
    if (!$result['ok']) {
        return $result;
    }

    $map = [
        'audio' => ['mp3'],
        'video' => ['mp4'],
        'image' => ['jpg', 'png', 'webp'],
    ];
    $sniff = pz_sniff($destination);
    $okay  = false;
    foreach ($kinds as $kind) {
        if (isset($map[$kind]) && in_array($sniff, $map[$kind], true)) {
            $okay = true;
            break;
        }
    }
    if (!$okay) {
        @unlink($destination);
        return ['ok' => false, 'size' => 0, 'error' => 'downloaded content is not ' . implode('/', $kinds) . ($sniff !== '' ? " ({$sniff})" : ''), 'http' => $result['http']];
    }

    return $result;
}

/**
 * نوشتن تگ ID3v2.3 با getID3 (همان قالب music.php)
 * $tags: title, artist, album, track, year, cover (مسیر فایل تصویر)
 * خروجی: ['ok' => bool, 'warnings' => [], 'errors' => []]
 */
function pz_write_mp3_tags($path, array $tags) {
    require_once __DIR__ . '/getid3/getid3.php';
    require_once __DIR__ . '/getid3/write.php';

    $encoding = 'UTF-8';
    $getID3   = new getID3();
    $getID3->setOption(['encoding' => $encoding]);

    $writer                    = new getID3_writetags();
    $writer->filename          = $path;
    $writer->tagformats        = ['id3v2.3'];
    $writer->overwrite_tags    = true;
    $writer->remove_other_tags = true;
    $writer->tag_encoding      = $encoding;

    $title  = isset($tags['title']) ? (string) $tags['title'] : '';
    $artist = isset($tags['artist']) ? (string) $tags['artist'] : '';
    $album  = isset($tags['album']) && $tags['album'] !== '' ? (string) $tags['album'] : ($title . ' Single');
    $track  = isset($tags['track']) ? (int) $tags['track'] : 0;
    $year   = isset($tags['year']) && $tags['year'] !== '' ? (string) $tags['year'] : date('Y');

    $data = [
        'title'                  => [trim($title . ' ' . PZ_BRAND)],
        'artist'                 => [$artist],
        'album'                  => [trim($album . ' ' . PZ_BRAND)],
        'year'                   => [$year],
        'genre'                  => [PZ_BRAND],
        'encoded_by'             => [PZ_BRAND],
        'comment'                => [PZ_BRAND],
        'publisher'              => [PZ_BRAND],
        'subtitle'               => [PZ_BRAND],
        'track_number'           => [(string) $track],
        'popularimeter'          => ['email' => PZ_BRAND, 'rating' => 255, 'data' => 0],
        'unique_file_identifier' => ['ownerid' => PZ_BRAND, 'data' => md5($path . microtime(true))],
    ];

    $cover = isset($tags['cover']) ? (string) $tags['cover'] : '';
    if ($cover !== '' && is_readable($cover)) {
        $image = @file_get_contents($cover);
        $mime  = pz_sniff($cover);
        $mime  = $mime === 'png' ? 'image/png' : ($mime === 'webp' ? 'image/webp' : 'image/jpeg');
        if ($image !== false && $image !== '') {
            $data['attached_picture'] = [[
                'data'          => $image,
                'picturetypeid' => 3,
                'description'   => 'cover',
                'mime'          => $mime,
            ]];
        }
    }

    $writer->tag_data = $data;
    $written          = $writer->WriteTags();

    return [
        'ok'       => (bool) $written,
        'warnings' => array_map('strval', (array) $writer->warnings),
        'errors'   => array_map('strval', (array) $writer->errors),
    ];
}

/**
 * زیپ کردن فایل‌های یک پوشه (بدون زیرپوشه) در $zip_path
 * خروجی: ['ok' => bool, 'size' => int, 'count' => int, 'error' => string]
 */
function pz_zip_dir($dir, $zip_path) {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'size' => 0, 'count' => 0, 'error' => 'ZipArchive is not available on this host'];
    }
    $files = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..' || !is_file($dir . '/' . $entry)) {
            continue;
        }
        $files[] = $entry;
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    if (empty($files)) {
        return ['ok' => false, 'size' => 0, 'count' => 0, 'error' => 'folder is empty'];
    }

    @unlink($zip_path);
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'size' => 0, 'count' => 0, 'error' => 'cannot create zip'];
    }
    foreach ($files as $entry) {
        $zip->addFile($dir . '/' . $entry, $entry);
        // فایل‌های MP3 فشرده نمی‌شوند؛ بدون فشرده‌سازی سریع‌تر ساخته می‌شود
        if (method_exists($zip, 'setCompressionName') && defined('ZipArchive::CM_STORE')) {
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
    clearstatcache(true, $zip_path);

    $size = (int) @filesize($zip_path);
    return ['ok' => $size > 0, 'size' => $size, 'count' => count($files), 'error' => $size > 0 ? '' : 'zip is empty'];
}

/** توضیح کوتاه نتیجه‌ی تگ برای گزارش */
function pz_tag_note(array $tag_result) {
    if ($tag_result['ok']) {
        return '';
    }
    return 'tags: ' . implode(' ; ', array_slice($tag_result['errors'], 0, 2));
}
