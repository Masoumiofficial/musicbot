<?php
$musicUrl320 = $_POST['music320'] ?? '';
$musicUrl128 = $_POST['music128'] ?? '';
$imageUrl = trim($_POST['image'] ?? '');
$artistName = $_POST['artist_en'] ?? '';
$songTitle = $_POST['song_en'] ?? '';
$mp3FileName = $_POST['mp3_name'] ?? '';
$directory = $_POST['dir_file'] ?? '';

if (!file_exists($directory)) {
    mkdir($directory, 0755, true);
}

$trackPath320 = $_SERVER['DOCUMENT_ROOT'] . "/{$directory}/{$mp3FileName}.mp3";
$trackPath128 = $_SERVER['DOCUMENT_ROOT'] . "/{$directory}/{$mp3FileName} (128).mp3";

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

$coverImagePath = $_SERVER['DOCUMENT_ROOT'] . "/{$directory}/{$mp3FileName}.jpg";
downloadFile($imageUrl, $coverImagePath);

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