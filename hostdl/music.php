<?php
/**
 * دریافت فایل‌های موزیک روی هاست دانلود
 *
 * ساختار پوشه‌ها (از طرف اسکریپت سایت در dir_file ارسال می‌شود):
 *   {نوع محتوا}/{حرف اول}/{نامک خواننده}
 *   مثال: music/m/mohsen-yeganeh
 *
 * نام فایل‌ها:
 *   Artist - Song [320].mp3
 *   Artist - Song [128].mp3
 *
 * دسترسی فقط با یوزر/رمز تعریف‌شده در config.php (HTTP Basic Auth)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
bm_require_auth('AhangBan Download Host');

$musicUrl320 = $_POST['music320'] ?? '';
$musicUrl128 = $_POST['music128'] ?? '';
$imageUrl = trim($_POST['image'] ?? '');
$artistName = $_POST['artist_en'] ?? '';
$songTitle = $_POST['song_en'] ?? '';

/**
 * نام فایل بدون جداکننده‌ی مسیر (جلوگیری از خروج از پوشه)
 */
function sanitizeFileName($name) {
    $name = str_replace(["\0", '/', '\\'], '', (string) $name);
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }
    return $name;
}

/**
 * مسیر نسبی پوشه: فقط بخش‌های سالم نگه داشته می‌شوند (بدون "..")
 * مثال: "music/m/mohsen-yeganeh"
 */
function sanitizeDirectory($dir) {
    $segments = [];
    foreach (preg_split('#[\\\\/]+#', (string) $dir) as $segment) {
        $segment = trim(str_replace("\0", '', $segment));
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $segments[] = $segment;
    }
    return implode('/', $segments);
}

$mp3FileName = sanitizeFileName($_POST['mp3_name'] ?? '');
$directory   = sanitizeDirectory($_POST['dir_file'] ?? '');

if ($mp3FileName === '' || $directory === '') {
    http_response_code(400);
    exit('Error: mp3_name and dir_file are required.');
}

// نام فایل‌ها از طرف سایت ارسال می‌شود؛ در غیر این صورت از الگوی پیش‌فرض استفاده می‌کنیم
$fileName320 = sanitizeFileName($_POST['file_320'] ?? '');
$fileName128 = sanitizeFileName($_POST['file_128'] ?? '');

if ($fileName320 === '') {
    $fileName320 = "{$mp3FileName} [320].mp3";
}
if ($fileName128 === '') {
    $fileName128 = "{$mp3FileName} [128].mp3";
}

$baseDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . "/{$directory}";

if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
    http_response_code(500);
    exit("Error: could not create directory {$directory}");
}

$trackPath320 = "{$baseDir}/{$fileName320}";
$trackPath128 = "{$baseDir}/{$fileName128}";

function writeMp3IdTags($filePath, $artist, $title, $coverImagePath) {
    $textEncoding = 'UTF-8';

    require_once('getid3/getid3.php');
    require_once('getid3/write.php');

    $getID3 = new getID3();
    $getID3->setOption(['encoding' => $textEncoding]);

    $tagWriter = new getID3_writetags();
    $tagWriter->filename = $filePath;
    $tagWriter->tagformats = ['id3v2.3'];
    $tagWriter->overwrite_tags = true;
    $tagWriter->remove_other_tags = true;
    $tagWriter->tag_encoding = $textEncoding;

    $tagData = [
        'title'       => ["{$title} [ AhangBan.ir ]"],
        'artist'      => [$artist],
        'album'       => ["{$title} Single [ AhangBan.ir ]"],
        'year'        => [date('Y')],
        'genre'       => ['[ AhangBan.ir ]'],
        'encoded_by'  => ['[ AhangBan.ir ]'],
        'comment'     => ['[ AhangBan.ir ]'],
        'publisher'   => ['[ AhangBan.ir ]'],
        'subtitle'    => ['[ AhangBan.ir ]'],
        'track_number'=> ['0'],
        'popularimeter' => ['email' => '[ AhangBan.ir ]', 'rating' => 255, 'data' => 0],
        'unique_file_identifier' => ['ownerid' => '[ AhangBan.ir ]', 'data' => md5(time())],
    ];

    if (file_exists($coverImagePath) && is_readable($coverImagePath)) {
        $imageData = file_get_contents($coverImagePath);
        if ($imageData !== false) {
            $tagData['attached_picture'] = [[
                'data'          => $imageData,
                'picturetypeid' => 2,
                'description'   => 'cover',
                'mime'          => 'image/jpeg',
            ]];
        }
    }

    $tagWriter->tag_data = $tagData;

    if ($tagWriter->WriteTags()) {
        echo 'Successfully wrote tags<br>';
        if (!empty($tagWriter->warnings)) {
            echo 'Warnings:<br>' . implode('<br><br>', $tagWriter->warnings);
        }
    } else {
        echo 'Failed to write tags!<br>' . implode('<br><br>', $tagWriter->errors);
    }
}

function downloadFile($url, $destination) {
    $content = file_get_contents($url);
    if ($content !== false) {
        file_put_contents($destination, $content);
    }
}

$coverImagePath = "{$baseDir}/{$mp3FileName}.jpg";
if ($imageUrl !== '') {
    downloadFile($imageUrl, $coverImagePath);
}

if (!empty($musicUrl320)) {
    downloadFile($musicUrl320, $trackPath320);
    writeMp3IdTags($trackPath320, $artistName, $songTitle, $coverImagePath);
}

if (!empty($musicUrl128)) {
    downloadFile($musicUrl128, $trackPath128);
    writeMp3IdTags($trackPath128, $artistName, $songTitle, $coverImagePath);
}

if (file_exists($coverImagePath)) {
    unlink($coverImagePath);
}
?>