<?php
/**
 * گیرنده‌ی «آپلود فایل» روی هاست دانلود (از پست‌ساز سایت: postsaz/submit.php)
 * یک فایل MP3 و/یا MP4 را از لینک می‌گیرد، MP3 را با کاور تگ می‌زند و در پوشه‌ی داده‌شده ذخیره می‌کند.
 *
 * ورودی (POST، با یوزر/رمز config.php):
 *   dir_file  : پوشه‌ی نسبی، مثال music/m/mohsen-yeganeh
 *   artist_en : نام خواننده (تگ)
 *   song_en   : نام اثر (تگ)
 *   album     : نام آلبوم برای تگ (اختیاری؛ پیش‌فرض "{song_en} Single")
 *   cover     : لینک کاور (اختیاری)
 *   mp3       : لینک فایل MP3      mp3_name : نام فایل مقصد (مثال "Artist - Song [320].mp3")
 *   mp4       : لینک فایل MP4      mp4_name : نام فایل مقصد (مثال "Artist - Song [1080].mp4")
 *
 * خروجی JSON: {"ok":true,"dir":"...","files":{"mp3":{"ok":true,"name":"...","size":1},"mp4":{...}},"errors":[],"warnings":[]}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
bm_require_auth('AhangBan Download Host');
require_once __DIR__ . '/common.php';

pz_prepare_runtime();

$directory  = pz_sanitize_dir(pz_post('dir_file'));
$artistName = pz_post('artist_en');
$songTitle  = pz_post('song_en');
$albumTitle = pz_post('album');
$coverUrl   = pz_post('cover');
$mp3Url     = pz_post('mp3');
$mp4Url     = pz_post('mp4');
$mp3Name    = pz_sanitize_name(pz_post('mp3_name'));
$mp4Name    = pz_sanitize_name(pz_post('mp4_name'));

if ($directory === '') {
    pz_fail('dir_file is required.');
}
if ($mp3Url === '' && $mp4Url === '') {
    pz_fail('mp3 or mp4 url is required.');
}

$absDir = pz_abs_dir($directory);
if ($absDir === '') {
    pz_fail("could not create directory {$directory}", 500);
}

$base = trim("{$artistName} - {$songTitle}", ' -');
if ($mp3Name === '') {
    $mp3Name = ($base !== '' ? $base : 'audio') . '.mp3';
}
if ($mp4Name === '') {
    $mp4Name = ($base !== '' ? $base : 'video') . '.mp4';
}

$files    = [];
$errors   = [];
$warnings = [];

$coverPath = '';
if ($coverUrl !== '' && $mp3Url !== '') {
    $candidate = "{$absDir}/" . pathinfo($mp3Name, PATHINFO_FILENAME) . '.jpg';
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

if ($mp3Url !== '') {
    $path   = "{$absDir}/{$mp3Name}";
    $result = pz_download_checked($mp3Url, $path, ['audio']);
    if ($result['ok']) {
        $tag = pz_write_mp3_tags($path, [
            'title'  => $songTitle,
            'artist' => $artistName,
            'album'  => $albumTitle !== '' ? $albumTitle : ($songTitle . ' Single'),
            'track'  => 0,
            'cover'  => $coverPath,
        ]);
        $note = pz_tag_note($tag);
        if ($note !== '') {
            $warnings[] = "{$mp3Name}: {$note}";
        }
        clearstatcache(true, $path);
        $result['size'] = (int) @filesize($path);
    } else {
        $errors[] = 'mp3: ' . $result['error'];
    }
    $files['mp3'] = ['ok' => $result['ok'], 'name' => $mp3Name, 'size' => $result['size'], 'error' => $result['error']];
}

if ($mp4Url !== '') {
    $path   = "{$absDir}/{$mp4Name}";
    $result = pz_download_checked($mp4Url, $path, ['video']);
    if (!$result['ok']) {
        $errors[] = 'mp4: ' . $result['error'];
    }
    $files['mp4'] = ['ok' => $result['ok'], 'name' => $mp4Name, 'size' => $result['size'], 'error' => $result['error']];
}

if ($coverPath !== '' && file_exists($coverPath)) {
    @unlink($coverPath);
}

pz_json([
    'ok'       => empty($errors),
    'dir'      => $directory,
    'files'    => $files,
    'errors'   => $errors,
    'warnings' => $warnings,
]);
