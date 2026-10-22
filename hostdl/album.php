<?php
/**
 * گیرنده‌ی آلبوم روی هاست دانلود (از پست‌ساز سایت: postsaz/submit.php)
 *
 * ورودی (POST، با یوزر/رمز config.php):
 *   dir_file    : پوشه‌ی نسبی، مثال album/m/mohsen-yeganeh
 *   album_name  : نام پایه، مثال "Mohsen Yeganeh - Rage Khab Album"
 *   artist_en   : نام خواننده (برای تگ)
 *   album_en    : نام آلبوم (برای تگ)
 *   cover       : لینک کاور (برای تگ داخل فایل‌ها؛ اختیاری)
 *   folder_320  : نام پوشه‌ی کیفیت 320 (پیش‌فرض "{album_name} [320]")
 *   folder_128  : نام پوشه‌ی کیفیت 128 (پیش‌فرض "{album_name} [128]")
 *   zip         : 1 = ساخت فایل ZIP هر کیفیت
 *   zip_320 / zip_128 : نام فایل‌های ZIP (پیش‌فرض "{album_name} [320].zip")
 *   tracks      : JSON — [{"no":1,"name":"Sokoot","url_320":"...","url_128":"...","file_320":"...","file_128":"..."}, ...]
 *
 * ساختار نهایی:
 *   {dir_file}/{folder_320}/01. Artist - Track [320].mp3
 *   {dir_file}/{folder_128}/01. Artist - Track [128].mp3
 *   {dir_file}/{album_name} [320].zip  و  [128].zip
 *
 * خروجی JSON: {"ok":true,"dir":"...","tracks":[{"no":1,"320":{"ok":true,"name":"...","size":1},"128":{...}}],"zips":{"320":{...}},"errors":[],"warnings":[]}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
bm_require_auth('AhangBan Download Host');
require_once __DIR__ . '/common.php';

pz_prepare_runtime();

$directory = pz_sanitize_dir(pz_post('dir_file'));
$albumName = pz_sanitize_name(pz_post('album_name'));
if ($directory === '' || $albumName === '') {
    pz_fail('dir_file and album_name are required.');
}

$artistName = pz_post('artist_en');
$albumTitle = pz_post('album_en');
if ($albumTitle === '') {
    $albumTitle = $albumName;
}

$tracks = json_decode((string) pz_post('tracks', '[]'), true);
if (!is_array($tracks) || empty($tracks)) {
    pz_fail('tracks (JSON array) is required.');
}
if (count($tracks) > 60) {
    pz_fail('too many tracks (max 60).');
}

$folderNames = [
    '320' => pz_sanitize_name(pz_post('folder_320')) ?: "{$albumName} [320]",
    '128' => pz_sanitize_name(pz_post('folder_128')) ?: "{$albumName} [128]",
];
$zipNames = [
    '320' => pz_sanitize_name(pz_post('zip_320')) ?: "{$albumName} [320].zip",
    '128' => pz_sanitize_name(pz_post('zip_128')) ?: "{$albumName} [128].zip",
];
$makeZip = pz_post('zip') === '1';

$absDir = pz_abs_dir($directory);
if ($absDir === '') {
    pz_fail("could not create directory {$directory}", 500);
}

$errors   = [];
$warnings = [];

// کاور برای تگ فایل‌ها (موقت؛ در پایان پاک می‌شود)
$coverPath = '';
$coverUrl  = pz_post('cover');
if ($coverUrl !== '') {
    $candidate = "{$absDir}/{$albumName}.jpg";
    $cover     = pz_download_checked($coverUrl, $candidate, ['image']);
    if ($cover['ok']) {
        $coverPath = $candidate;
        $branded   = pz_cover_prepare($coverPath); // لوگوی سایت روی کاور (config.php)
        if (is_array($branded['watermark']) && empty($branded['watermark']['ok'])) {
            $warnings[] = 'cover watermark: ' . $branded['watermark']['note'];
        }
    } else {
        $warnings[] = 'cover: ' . $cover['error'];
    }
}

$absFolders = [];
$results    = [];

foreach ($tracks as $index => $track) {
    if (!is_array($track)) {
        continue;
    }
    $no   = isset($track['no']) ? (int) $track['no'] : ($index + 1);
    $name = isset($track['name']) ? pz_sanitize_name($track['name']) : '';
    if ($no < 1) {
        $no = $index + 1;
    }
    if ($name === '') {
        $name = 'Track ' . $no;
    }

    $row = ['no' => $no, 'name' => $name];

    foreach (['320', '128'] as $quality) {
        $url = isset($track["url_{$quality}"]) ? trim((string) $track["url_{$quality}"]) : '';
        if ($url === '') {
            continue;
        }

        if (!isset($absFolders[$quality])) {
            $absFolders[$quality] = pz_abs_dir($directory . '/' . $folderNames[$quality]);
            if ($absFolders[$quality] === '') {
                $errors[] = "could not create folder {$folderNames[$quality]}";
            }
        }
        if ($absFolders[$quality] === '') {
            $row[$quality] = ['ok' => false, 'name' => '', 'size' => 0, 'error' => 'folder not created'];
            continue;
        }

        $fileName = isset($track["file_{$quality}"]) ? pz_sanitize_name($track["file_{$quality}"]) : '';
        if ($fileName === '') {
            $fileName = sprintf('%02d. %s - %s [%s].mp3', $no, $artistName, $name, $quality);
        }

        $path   = $absFolders[$quality] . '/' . $fileName;
        $result = pz_download_checked($url, $path, ['audio']);
        $note   = '';
        if ($result['ok']) {
            $tag = pz_write_mp3_tags($path, [
                'title'  => $name,
                'artist' => $artistName,
                'album'  => $albumTitle,
                'track'  => $no,
                'cover'  => $coverPath,
            ]);
            $note = pz_tag_note($tag);
            if ($note !== '') {
                $warnings[] = "{$fileName}: {$note}";
            }
            clearstatcache(true, $path);
            $result['size'] = (int) @filesize($path);
        } else {
            $errors[] = "track {$no} [{$quality}]: {$result['error']}";
        }

        $row[$quality] = [
            'ok'    => $result['ok'],
            'name'  => $fileName,
            'size'  => $result['size'],
            'error' => $result['error'],
        ];
    }

    $results[] = $row;
}

// فایل‌های ZIP
$zips = [];
if ($makeZip) {
    foreach ($absFolders as $quality => $folder) {
        if ($folder === '') {
            continue;
        }
        $zipPath = "{$absDir}/{$zipNames[$quality]}";
        $zip     = pz_zip_dir($folder, $zipPath);
        $zips[$quality] = [
            'ok'    => $zip['ok'],
            'name'  => $zipNames[$quality],
            'size'  => $zip['size'],
            'count' => $zip['count'],
            'error' => $zip['error'],
        ];
        if (!$zip['ok']) {
            $errors[] = "zip {$quality}: {$zip['error']}";
        }
    }
}

if ($coverPath !== '' && file_exists($coverPath)) {
    @unlink($coverPath);
}

pz_json([
    'ok'       => empty($errors),
    'dir'      => $directory,
    'folders'  => $folderNames,
    'tracks'   => $results,
    'zips'     => $zips,
    'errors'   => $errors,
    'warnings' => $warnings,
]);
