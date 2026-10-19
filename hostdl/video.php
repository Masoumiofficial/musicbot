<?php
/**
 * گیرنده‌ی موزیک ویدیو روی هاست دانلود (از پست‌ساز سایت: postsaz/submit.php)
 *
 * ورودی (POST، با یوزر/رمز config.php):
 *   dir_file   : پوشه‌ی نسبی، مثال music-video/m/mohsen-yeganeh
 *   base_name  : نام پایه‌ی فایل‌ها، مثال "Mohsen Yeganeh - Behet Ghol Midam"
 *   video_1080 / video_720 / video_480 : لینک فایل هر کیفیت (هر کدام خالی باشد رد می‌شود)
 *   file_1080  / file_720  / file_480  : نام فایل مقصد (پیش‌فرض: "{base_name} [1080].mp4")
 *
 * خروجی JSON: {"ok":true,"dir":"...","files":{"1080":{"ok":true,"name":"...","size":123}},"errors":[]}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
bm_require_auth('AhangBan Download Host');
require_once __DIR__ . '/common.php';

pz_prepare_runtime();

$directory = pz_sanitize_dir(pz_post('dir_file'));
$baseName  = pz_sanitize_name(pz_post('base_name'));
if ($directory === '' || $baseName === '') {
    pz_fail('dir_file and base_name are required.');
}

$absDir = pz_abs_dir($directory);
if ($absDir === '') {
    pz_fail("could not create directory {$directory}", 500);
}

$files  = [];
$errors = [];

foreach (['1080', '720', '480'] as $quality) {
    $url = pz_post("video_{$quality}");
    if ($url === '') {
        continue;
    }

    $name = pz_sanitize_name(pz_post("file_{$quality}"));
    if ($name === '') {
        $name = "{$baseName} [{$quality}].mp4";
    }

    $result = pz_download_checked($url, "{$absDir}/{$name}", ['video']);
    $files[$quality] = [
        'ok'    => $result['ok'],
        'name'  => $name,
        'size'  => $result['size'],
        'error' => $result['error'],
    ];
    if (!$result['ok']) {
        $errors[] = "{$quality}p: {$result['error']}";
    }
}

if (empty($files)) {
    pz_fail('no video url given (video_1080 / video_720 / video_480).');
}

pz_json([
    'ok'     => empty($errors),
    'dir'    => $directory,
    'files'  => $files,
    'errors' => $errors,
]);
