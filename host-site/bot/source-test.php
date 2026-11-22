<?php
/**
 * آزمایش خواندن سایت مبدأ — بدون ساختن پست
 *   bot/source-test.php?url=لینک-صفحه‌ی-آهنگ   → آنچه ربات از صفحه می‌خواند (نام‌ها، کاور، لینک‌های 320/128، عوامل، متن ترانه، نوع صفحه)
 *   bot/source-test.php?url=…&mode=api          → همان لینک با API قدیمی (مقایسه)
 *   bot/source-test.php?feed=1                  → فهرست آیتم‌های جدید که auto.php می‌بیند ($bot_feed_urls در config.php)
 * با یوزر/رمز پنل ربات باز می‌شود.
 */
include('config.php');
require_once __DIR__ . '/auth.php';
bm_require_auth();

require('../wp-load.php');

$bm_functions_only = true;
require_once __DIR__ . '/script.php'; // bm_fetch_source + source.php

@set_time_limit(120);
header('Content-Type: text/html; charset=utf-8');

$st_url  = isset($_GET['url']) ? trim((string) wp_unslash($_GET['url'])) : '';
$st_mode = isset($_GET['mode']) && in_array($_GET['mode'], ['auto', 'page', 'api'], true) ? $_GET['mode'] : '';
$st_feed = !empty($_GET['feed']);

function st_h($text) {
    return esc_html((string) $text);
}

function st_row($label, $value, $is_link = false) {
    $value = (string) $value;
    $cell  = $value === ''
        ? '<span class="empty">— خالی —</span>'
        : ($is_link ? '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . st_h($value) . '</a>' : nl2br(st_h($value)));
    return '<tr><th>' . st_h($label) . '</th><td>' . $cell . '</td></tr>';
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>آزمایش خواندن سایت مبدأ</title>
<style>
body{font-family:tahoma,sans-serif;background:#f3f4f6;color:#111827;margin:0;padding:24px;line-height:1.9}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;max-width:1100px;margin:0 auto 18px}
h1{font-size:18px;margin:0 0 12px}h2{font-size:15px;margin:18px 0 8px}
input[type=url]{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:8px;font:inherit;direction:ltr}
button,.btn{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:9px 18px;font:inherit;cursor:pointer;text-decoration:none;display:inline-block}
.btn.gray{background:#6b7280}
table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:7px 10px;text-align:right;vertical-align:top;font-size:13px}
th{background:#f9fafb;width:170px;white-space:nowrap}td{direction:rtl;word-break:break-all}
.ok{color:#047857}.bad{color:#b91c1c}.empty{color:#9ca3af}.note{color:#6b7280;font-size:12px}
img.cover{max-width:160px;border-radius:8px;display:block;margin-top:6px}
ol{padding-right:22px}ol li{font-size:13px}code{background:#f3f4f6;padding:1px 5px;border-radius:4px;direction:ltr;unicode-bidi:embed}
</style>
</head>
<body>
<div class="box">
    <h1>آزمایش خواندن سایت مبدأ</h1>
    <form method="get">
        <input type="url" name="url" placeholder="https://www.mybia2music.com/114816530/shahin-banan-aroosi" value="<?php echo esc_attr($st_url); ?>">
        <p>
            <button type="submit">خواندن صفحه</button>
            <button type="submit" name="mode" value="api" class="btn gray">با API قدیمی</button>
            <a class="btn gray" href="?feed=1">فهرست آیتم‌های جدید (auto.php)</a>
        </p>
    </form>
    <p class="note">حالت فعلی در config.php: <code>$bot_source_parser = '<?php echo st_h(isset($bot_source_parser) ? $bot_source_parser : 'auto'); ?>'</code> — این صفحه چیزی ثبت نمی‌کند.</p>
</div>
<?php
if ($st_feed) {
    $feed_urls = (isset($bot_feed_urls) && is_array($bot_feed_urls) && !empty($bot_feed_urls))
        ? $bot_feed_urls
        : ['https://www.mybia2music.com/new-sitemap.xml', 'https://www.mybia2music.com/feed'];
    $t0   = microtime(true);
    $feed = bm_source_feed_items($feed_urls, 30);
    echo '<div class="box"><h2>فهرست آیتم‌های جدید (' . round(microtime(true) - $t0, 1) . ' ثانیه)</h2>';
    if (empty($feed['items'])) {
        echo '<p class="bad">هیچ منبعی آیتم نداشت.</p>';
    } else {
        echo '<p class="ok">منبع: ' . st_h($feed['source']) . ' — ' . count($feed['items']) . ' آیتم (جدیدترین اول)</p><ol>';
        foreach ($feed['items'] as $url => $title) {
            echo '<li><a href="?url=' . rawurlencode($url) . '">' . st_h($title) . '</a> <span class="note">' . st_h($url) . '</span></li>';
        }
        echo '</ol>';
    }
    if (!empty($feed['errors'])) {
        echo '<h2>منابعی که جواب ندادند</h2><ul>';
        foreach ($feed['errors'] as $error) {
            echo '<li class="bad">' . st_h($error) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

if ($st_url !== '') {
    if (!preg_match('#^https?://#i', $st_url)) {
        echo '<div class="box"><p class="bad">لینک معتبر نیست.</p></div>';
    } else {
        if ($st_mode !== '') {
            $GLOBALS['bot_source_parser'] = $st_mode;
        }
        $t0    = microtime(true);
        $fetch = bm_fetch_source($st_url, isset($apiUrl) ? $apiUrl : '');
        $took  = round(microtime(true) - $t0, 1);
        echo '<div class="box"><h2>نتیجه (' . $took . ' ثانیه)</h2>';
        if (empty($fetch['ok']) || !is_array($fetch['result'])) {
            echo '<p class="bad">خوانده نشد: ' . st_h($fetch['error']) . '</p>';
        } else {
            $r    = $fetch['result'];
            $kind = isset($r['kind']) ? (string) $r['kind'] : (string) $fetch['kind'];
            echo '<p class="ok">منبع داده: ' . ($fetch['from'] === 'page' ? 'خودِ صفحه' : 'API قدیمی') . '</p>';
            foreach ((array) $fetch['notes'] as $note) {
                echo '<p class="note">' . st_h($note) . '</p>';
            }
            $has_file = trim((string) ($r['music320'] ?? '')) !== '' || trim((string) ($r['music128'] ?? '')) !== '';
            if ($kind !== '' && $kind !== 'music') {
                echo '<p class="bad">نوع صفحه: ' . st_h(bm_source_kind_label($kind)) . ' — ربات این صفحه را رد می‌کند (فقط تک‌آهنگ).</p>';
            } elseif (!$has_file) {
                echo '<p class="bad">لینک فایل آهنگ پیدا نشد — ربات پست نمی‌سازد.</p>';
            } else {
                echo '<p class="ok">تک‌آهنگ با لینک فایل — قابل ثبت.</p>';
            }
            echo '<table>';
            echo st_row('نام آهنگ', $r['song'] ?? '');
            echo st_row('خواننده', $r['artist'] ?? '');
            echo st_row('Song (en)', $r['song_en'] ?? '');
            echo st_row('Artist (en)', $r['artist_en'] ?? '');
            echo st_row('کیفیت 320', $r['music320'] ?? '', true);
            echo st_row('کیفیت 128', $r['music128'] ?? '', true);
            $image = (string) ($r['image'] ?? '');
            echo '<tr><th>کاور</th><td>' . ($image !== '' ? '<a href="' . esc_url($image) . '" target="_blank" rel="noopener">' . st_h($image) . '</a><img class="cover" src="' . esc_url($image) . '" alt="">' : '<span class="empty">— خالی —</span>') . '</td></tr>';
            echo st_row('ژانر', $r['style'] ?? '');
            echo st_row('حس و حال', $r['mood'] ?? '');
            echo st_row('ترانه‌سرا', $r['songwriter'] ?? '');
            echo st_row('آهنگساز', $r['composer'] ?? '');
            echo st_row('تنظیم', $r['regulator'] ?? '');
            echo st_row('میکس و مستر', $r['mix'] ?? '');
            echo st_row('ناشر', $r['publisher'] ?? '');
            echo st_row('تاریخ انتشار', $r['date'] ?? '');
            echo st_row('ویدیو', $r['video_medium'] ?? '', true);
            $lyrics = (string) ($r['matnmusic'] ?? '');
            echo st_row('متن ترانه (' . ($lyrics === '' ? '0' : count(preg_split('/\n/', $lyrics))) . ' خط)', $lyrics);
            echo '</table>';
        }
        echo '</div>';
    }
}
?>
</body>
</html>
