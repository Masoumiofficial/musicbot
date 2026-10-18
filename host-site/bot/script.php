<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

require_once __DIR__ . '/files/jdf.php';


$apiUrl = 'https://api.wintheme.ir/b2m.php';

if (empty($postUrl)) {
    exit('خطا: آدرس پست ورودی ($postUrl) مشخص نشده است.');
}

if (empty($urlsite)) {
    $urlsite = home_url();
}

$dir_jalali = jdate_win('Y/m/d', time());
$dir_wp     = date('Y/m');

$arrContextOptions = array(
    "ssl" => array(
        "verify_peer"      => false,
        "verify_peer_name" => false,
    ),
);

if (!function_exists('Download')) {
    function Download($url, $saveto, $arrContextOptions) {
        $content = file_get_contents($url, false, stream_context_create($arrContextOptions));
        file_put_contents($saveto, $content);
    }
}

function bm_clean_text($value) {
    return is_string($value) ? trim(wp_strip_all_tags($value)) : '';
}


function bm_safe_file_name($text) {
    $text = remove_accents($text);
    $text = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text));
    $text = str_replace(' ', '-', $text);
    return sanitize_file_name($text);
}

/**
 * درخواست POST JSON با cURL
 */
function bm_curl_post_json($url, array $payload, $timeout = 30) {
    $ch = curl_init($url);

    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json),
        ],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $errno    = curl_errno($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'success'  => ($errno === 0 && $code >= 200 && $code < 300 && !empty($response)),
        'response' => $response,
        'error'    => $error,
        'errno'    => $errno,
        'code'     => $code,
    ];
}

/**
 * درخواست POST فرم‌دیتا با cURL
 */
function bm_curl_post_form($url, array $payload, $timeout = 120) {
    if (empty($url)) {
        return [
            'success' => false,
            'error'   => 'Download endpoint is empty.',
            'code'    => 0,
            'body'    => '',
        ];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'success' => ($errno === 0 && $code >= 200 && $code < 300),
        'error'   => $error,
        'code'    => $code,
        'body'    => $body,
    ];
}

/**
 * اگر term وجود نداشت بساز و سپس روی پست ست کن
 */
function bm_set_term_if_not_empty($post_id, $value, $taxonomy, $append = false) {
    $value = bm_clean_text($value);
    if ($value === '') {
        return;
    }

    // اگر taxonomy وجود نداشت، سعی می‌کنیم فقط ست نکنیم تا خطا ندهد
    if (!taxonomy_exists($taxonomy)) {
        return;
    }

    wp_set_object_terms($post_id, $value, $taxonomy, $append);
}

/**
 * آپلود تصویر از URL و ثبت به عنوان تصویر شاخص
 * از media_sideload_image استفاده می‌کنیم چون پایدارتر از file_get_contents است
 */
function bm_upload_featured_image_from_url($image_url, $post_id, $img_name = '', $title = '', $alt = '', $desc = '', $arrContextOptions = []) {
    if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
        return [
            'success' => false,
            'message' => 'آدرس تصویر نامعتبر است.',
            'id'      => 0,
        ];
    }

    if (empty($post_id) || is_wp_error($post_id)) {
        return [
            'success' => false,
            'message' => 'شناسه پست نامعتبر است.',
            'id'      => 0,
        ];
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // اگر قبلاً تصویر شاخص دارد، اول پاک نکن؛ فقط برگردان
    $existing_thumb = get_post_thumbnail_id($post_id);
    if ($existing_thumb) {
        return [
            'success' => true,
            'message' => 'پست از قبل تصویر شاخص دارد.',
            'id'      => $existing_thumb,
        ];
    }

    // دانلود فایل تصویر به صورت موقت با تابع Download
    if (empty($arrContextOptions)) {
        $arrContextOptions = [
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ];
    }

    $tmp = wp_tempnam($image_url);

    if (!$tmp) {
        return [
            'success' => false,
            'message' => 'خطا در ساخت فایل موقت برای تصویر.',
            'id'      => 0,
        ];
    }

    Download($image_url, $tmp, $arrContextOptions);

    if (!file_exists($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        return [
            'success' => false,
            'message' => 'خطا در دانلود تصویر: فایل دریافت نشد.',
            'id'      => 0,
        ];
    }

    // تعیین پسوند فایل از روی URL (در صورت نبود، jpg)
    $path_only = parse_url($image_url, PHP_URL_PATH);
    $file_ext  = $path_only ? pathinfo($path_only, PATHINFO_EXTENSION) : '';
    if (empty($file_ext)) {
        $file_ext = 'jpg';
    }

    // تغییر اندازه تصویر شاخص به 500x500 (برش مرکزی برای حفظ تناسب)
    $editor = wp_get_image_editor($tmp);
    if (!is_wp_error($editor)) {
        $resized = $editor->resize(500, 500, true);
        if (!is_wp_error($resized)) {
            $saved = $editor->save($tmp . '.' . $file_ext);
            if (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path'])) {
                @unlink($tmp);
                $tmp = $saved['path'];
            }
        }
    }

    // نام دقیق فایل نهایی؛ اگر $img_name داده نشده بود از نام اصلی فایل استفاده می‌شود
    $desired_name = !empty($img_name) ? $img_name . '.' . $file_ext : basename($tmp);

    $file_array = [
        'name'     => $desired_name,
        'tmp_name' => $tmp,
    ];

    // مجبور کردن وردپرس به استفاده از نام دقیق $imgname بدون هیچ تغییری (بدون افزودن -1، -2 و ...)
    $force_exact_name = function ($filename, $ext, $dir, $unique_filename_callback) use ($desired_name) {
        return $desired_name;
    };
    add_filter('wp_unique_filename', $force_exact_name, 10, 4);

    $attachment_id = media_handle_sideload($file_array, $post_id, $desc);

    remove_filter('wp_unique_filename', $force_exact_name, 10);

    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return [
            'success' => false,
            'message' => 'خطا در دانلود/ثبت تصویر: ' . $attachment_id->get_error_message(),
            'id'      => 0,
        ];
    }

    // عنوان و alt
    if (!empty($title)) {
        wp_update_post([
            'ID'         => $attachment_id,
            'post_title' => sanitize_text_field($title),
        ]);
    }

    if (!empty($alt)) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
    }

    // ست کردن تصویر شاخص
    $set = set_post_thumbnail($post_id, $attachment_id);

    if (!$set) {
        return [
            'success' => false,
            'message' => 'اتچمنت ساخته شد ولی تصویر شاخص ثبت نشد.',
            'id'      => $attachment_id,
        ];
    }

    return [
        'success' => true,
        'message' => 'تصویر شاخص با موفقیت ثبت شد.',
        'id'      => $attachment_id,
    ];
}

/**
 * ساخت (یا پیدا کردن) ترم خواننده
 * نام ترم = $artist (فارسی) | نامک ترم = $artist_en (انگلیسی)
 * مثال: نام «کسری زاهدی» و نامک «kasra-zahedi»
 * اگر ترم از قبل با نامک فارسی ساخته شده باشد، نامکش به انگلیسی اصلاح می‌شود
 * خروجی: ['id' => شناسه ترم, 'link' => لینک آرشیو خواننده]
 */
function bm_get_singer_term($artist, $artist_en) {
    $result = ['id' => 0, 'link' => ''];

    $artist = bm_clean_text($artist);
    if ($artist === '' || !taxonomy_exists('singer')) {
        return $result;
    }

    // نامک انگلیسی از روی نام انگلیسی خواننده
    $slug = sanitize_title(remove_accents(bm_clean_text($artist_en)));
    // فقط اگر نامک واقعاً انگلیسی (ASCII) باشد استفاده می‌شود
    if ($slug === '' || preg_match('/[^a-z0-9\-]/', $slug)) {
        $slug = '';
    }

    // پیدا کردن ترم با نام فارسی
    $term_id = 0;
    $found   = term_exists($artist, 'singer');
    if ($found) {
        $term_id = is_array($found) ? (int) $found['term_id'] : (int) $found;
    }

    // اگر نبود، با نام فارسی و نامک انگلیسی بساز
    if (!$term_id) {
        $args = ($slug !== '') ? ['slug' => $slug] : [];
        $new  = wp_insert_term($artist, 'singer', $args);

        if (is_wp_error($new)) {
            $existing_id = $new->get_error_data('term_exists');
            if ($existing_id) {
                $term_id = (int) $existing_id;
            } else {
                // مثلاً نامک تکرارِ ترم دیگری بود؛ بدون نامک دستی می‌سازیم
                $new = wp_insert_term($artist, 'singer');
                if (is_wp_error($new)) {
                    return $result;
                }
                $term_id = (int) $new['term_id'];
            }
        } else {
            $term_id = (int) $new['term_id'];
        }
    } elseif ($slug !== '') {
        // ترم قبلاً وجود داشته؛ اگر نامکش انگلیسی نیست اصلاح کن
        $term_obj = get_term($term_id, 'singer');
        if ($term_obj && !is_wp_error($term_obj) && $term_obj->slug !== $slug) {
            wp_update_term($term_id, 'singer', ['slug' => $slug]);
        }
    }

    $link = get_term_link($term_id, 'singer');
    if (!is_wp_error($link)) {
        $result['id']   = $term_id;
        $result['link'] = $link;
    }

    return $result;
}

/**
 * ساخت محتوای اولیه پست
 */
function bm_build_post_content($args = []) {
    $artist      = $args['artist'] ?? '';
    $song        = $args['song'] ?? '';
    $artist_en   = $args['artist_en'] ?? '';
    $song_en     = $args['song_en'] ?? '';
    $music_url   = $args['music_url'] ?? '';
    $singer_link = $args['singer_link'] ?? '';

    // لینک آهنگ جدید
    $music_html = '<strong>دانلود آهنگ جدید</strong>';
    if ($music_url !== '') {
        $music_html = '<a href="' . esc_url($music_url) . '">' . $music_html . '</a>';
    }

    // لینک خواننده
    $singer_html = '<strong>' . esc_html($artist) . '</strong>';
    if ($singer_link !== '') {
        $singer_html = '<a class="ty-singer-link" href="' . esc_url($singer_link) . '">' . $singer_html . '</a>';
    }

    $html  = '<p dir="rtl" lang="fa" style="text-align: center;">' . $music_html . ' ' . $singer_html . ' به نام <strong>' . esc_html($song) . '</strong></p>';
    $html .= "\n\n\n";
    $html .= '<p dir="ltr" lang="en" style="text-align: center;">Download New Music <strong>' . esc_html($artist_en) . '</strong> – <strong>' . esc_html($song_en) . '</strong></p>';
    $html .= "\n" . '<!--more-->';

    return $html;
}

/* -------------------------------------------------------
 |  1) دریافت داده از API
 * ------------------------------------------------------ */
$api_response = bm_curl_post_json($apiUrl, [
    'url' => $postUrl,
]);

if (!$api_response['success']) {
    exit(
        'خطا در ارتباط با API. ' .
        'HTTP: ' . intval($api_response['code']) .
        ' | cURL: ' . esc_html($api_response['error']) .
        ' | Response: ' . esc_html(mb_substr((string) $api_response['response'], 0, 500))
    );
}

$result = json_decode($api_response['response'], true);

if (!is_array($result)) {
    exit('خطا: پاسخ API معتبر نیست.');
}

if (!empty($result['error'])) {
    exit('API Error: ' . esc_html($result['error']));
}

/* -------------------------------------------------------
 |  2) دریافت فیلدها
 * ------------------------------------------------------ */
$song       = bm_clean_text($result['song'] ?? '');
$artist     = bm_clean_text($result['artist'] ?? '');
$song_en    = bm_clean_text($result['song_en'] ?? '');
$artist_en  = bm_clean_text($result['artist_en'] ?? '');
$imageUrl   = trim($result['image'] ?? '');

$music320   = trim($result['music320'] ?? '');
$music128   = trim($result['music128'] ?? '');
$video_low  = trim($result['video_low'] ?? '');
$video_med  = trim($result['video_medium'] ?? '');

$mood       = bm_clean_text($result['mood'] ?? '');

// اگر حالت آهنگ «ریتمیک» بود، به «شاد» تغییر کند
if ($mood === 'ریتمیک') {
    $mood = 'شاد';
}
$style      = bm_clean_text($result['style'] ?? '');
$publisher  = bm_clean_text($result['publisher'] ?? '');
$guitar     = bm_clean_text($result['guitar'] ?? '');
$mix        = bm_clean_text($result['mix'] ?? '');
$songwriter = bm_clean_text($result['songwriter'] ?? '');
$regulator  = bm_clean_text($result['regulator'] ?? '');
$composer   = bm_clean_text($result['composer'] ?? '');
$matnmusic  = trim($result['matnmusic'] ?? '');

/* -------------------------------------------------------
 |  3) اعتبارسنجی داده‌های حیاتی
 * ------------------------------------------------------ */
if ($artist === '' || $song === '') {
    exit('خطا: نام خواننده یا نام آهنگ از API دریافت نشد.');
}

if ($artist_en === '') {
    $artist_en = $artist;
}

if ($song_en === '') {
    $song_en = $song;
}

/* -------------------------------------------------------
 |  4) ساخت نام‌ها و متن‌ها
 * ------------------------------------------------------ */
$mytitle     = trim("دانلود آهنگ {$artist} {$song}");
$post_name   = trim("{$artist_en} {$song_en}");
$description = trim("دانلود آهنگ {$artist} {$song}");
$alt         = trim("آهنگ {$artist} {$song}");

$mp3_name = trim("{$artist_en} - {$song_en}");
$imgname  = bm_safe_file_name("{$artist_en} {$song_en}");

$post_cover = trailingslashit($urlsite) . "wp-content/uploads/{$dir_wp}/{$imgname}.jpg";

/* -------------------------------------------------------
 |  5) جلوگیری از پست تکراری
 * ------------------------------------------------------ */
$post_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_title = %s
     AND post_type = 'post'
     AND post_status IN ('publish', 'draft', 'pending', 'future')",
    $mytitle
));

if ($post_exists) {
    exit('پست با این عنوان قبلاً ثبت شده است. ID: ' . intval($post_exists));
}

/* -------------------------------------------------------
 |  6) ساخت محتوای پست
 * ------------------------------------------------------ */
$singer_term = bm_get_singer_term($artist, $artist_en);

$my_post_content = bm_build_post_content([
    'artist'      => $artist,
    'song'        => $song,
    'artist_en'   => $artist_en,
    'song_en'     => $song_en,
    'music_url'   => trailingslashit($urlsite) . 'music/',
    'singer_link' => $singer_term['link'],
]);

/* -------------------------------------------------------
 |  7) ساخت پست
 * ------------------------------------------------------ */
$post_data = [
    'post_author'   => 1,
    'post_status'   => 'publish',
    'post_type'     => 'post',
    'post_title'    => $mytitle,
    'post_name'     => sanitize_title($post_name),
    'post_content'  => $my_post_content,
    'comment_status'=> 'open',
    'ping_status'   => 'closed',
];

$post_id = wp_insert_post($post_data, true);

if (is_wp_error($post_id) || !$post_id) {
    $msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error';
    exit('خطا در ایجاد پست: ' . esc_html($msg));
}

/* -------------------------------------------------------
 |  8) سئو (Yoast)
 * ------------------------------------------------------ */
$wpseo_title     = $mytitle;
$wpseo_metadesc  = "دانلود آهنگ {$artist} {$song} با لینک مستقیم و کیفیت 320 و 128";
$wpseo_focuskw   = "دانلود آهنگ {$artist} {$song}";


$meta_updates = [
    'rank_math_title'           => wp_strip_all_tags($wpseo_title),
    'rank_math_description'     => wp_strip_all_tags($wpseo_metadesc),
    'rank_math_focus_keyword'   => wp_strip_all_tags($artist),
    'rank_math_seo_score'       => '99',
];

foreach ($meta_updates as $key => $value) {
    update_post_meta($post_id, $key, $value);
}


/* -------------------------------------------------------
 |  9) تگ‌ها و taxonomyها
 * ------------------------------------------------------ */
$post_tags = [
    'دانلود آهنگ',
    'دانلود آهنگ جدید',
    "آهنگ {$song}",
];

wp_set_object_terms($post_id, array_filter(array_unique($post_tags)), 'post_tag');

// دسته‌بندی اصلی
if (taxonomy_exists('category')) {
    wp_set_object_terms($post_id, ['music'], 'category', false);
}

// تاکسونومی‌های اختصاصی
if (!empty($singer_term['id'])) {
    wp_set_object_terms($post_id, [(int) $singer_term['id']], 'singer', false);
} else {
    bm_set_term_if_not_empty($post_id, $artist, 'singer', false);
}
bm_set_term_if_not_empty($post_id, $mood, 'mood', false);
bm_set_term_if_not_empty($post_id, $style, 'genre', false);

/* -------------------------------------------------------
 |  10) ساخت لینک فایل‌های دانلود روی هاست خودت
 * ------------------------------------------------------ */
$path_320 = '';
$path_128 = '';

if (!empty($urldlhost)) {
    $path_320 = trailingslashit($urldlhost) . "{$dir_jalali}/{$mp3_name}.mp3";
    $path_128 = trailingslashit($urldlhost) . "{$dir_jalali}/{$mp3_name} (128).mp3";
}

/* -------------------------------------------------------
 |  11) ذخیره متاهای موزیک
 * ------------------------------------------------------ */

// اگر فایل روی هاست خودت دانلود می‌شود، معمولاً بهتر است لینک‌های نهایی هاست خودت را ذخیره کنی.
// ولی اگر خواستی مستقیم لینک API ذخیره شود، این منطق را عوض کن.
$final_music320 = !empty($path_320) ? $path_320 : $music320;
$final_music128 = !empty($path_128) ? $path_128 : $music128;

// کیفیت‌ها
if (!empty($final_music320)) {
    update_post_meta($post_id, 'music320', esc_url_raw($final_music320));
}

if (!empty($final_music128)) {
    update_post_meta($post_id, 'music128', esc_url_raw($final_music128));
    update_post_meta($post_id, 'online', esc_url_raw($final_music128));
} elseif (!empty($final_music320)) {
    update_post_meta($post_id, 'music128', esc_url_raw($final_music320));
    update_post_meta($post_id, 'online', esc_url_raw($final_music320));
}

// ویدیوها
if (!empty($video_low)) {
    update_post_meta($post_id, 'video_low', esc_url_raw($video_low));
}
if (!empty($video_med)) {
    update_post_meta($post_id, 'video_medium', esc_url_raw($video_med));
}

// اطلاعات اصلی
if (!empty($artist))     update_post_meta($post_id, 'artist', $artist);
if (!empty($song))       update_post_meta($post_id, 'song', $song);
if (!empty($artist_en))  update_post_meta($post_id, 'artist_en', $artist_en);
if (!empty($song_en))    update_post_meta($post_id, 'song_en', $song_en);
if (!empty($matnmusic))  update_post_meta($post_id, 'music_text', wp_kses_post($matnmusic));

// عوامل
if (!empty($composer))   update_post_meta($post_id, 'composer', $composer);
if (!empty($regulator))  update_post_meta($post_id, 'arranger', $regulator);
if (!empty($songwriter)) update_post_meta($post_id, 'lyricist', $songwriter);
if (!empty($mix))        update_post_meta($post_id, 'mixmaster', $mix);
if (!empty($guitar))     update_post_meta($post_id, 'guitar', $guitar);
if (!empty($publisher))  update_post_meta($post_id, 'publisher', $publisher);

// سبک و مود
if (!empty($style))      update_post_meta($post_id, 'genre', $style);
if (!empty($mood))       update_post_meta($post_id, 'mood', $mood);

/* -------------------------------------------------------
 |  12) دانلود موزیک‌ها روی هاست دانلود
 * ------------------------------------------------------ */
$download_response = null;

if (!empty($functions_dl_music)) {
    $download_response = bm_curl_post_form($functions_dl_music, [
        'music320'  => $music320,
        'music128'  => $music128,
        'artist_en' => $artist_en,
        'song_en'   => $song_en,
        'image'     => $imageUrl,
        'mp3_name'  => $mp3_name,
        'dir_file'  => $dir_jalali,
    ], 300);

    // برای بررسی لاگ
    update_post_meta($post_id, '_bm_download_response', maybe_serialize($download_response));
}

/* -------------------------------------------------------
 |  13) ثبت تصویر شاخص
 * ------------------------------------------------------ */
$image_result = [
    'success' => false,
    'message' => 'تصویری برای ثبت وجود نداشت.',
    'id'      => 0,
];

if (!empty($imageUrl)) {
    $image_result = bm_upload_featured_image_from_url(
        $imageUrl,
        $post_id,
        $imgname,
        $mytitle,
        $alt,
        $description,
        $arrContextOptions
    );
}

// ذخیره نتیجه برای بررسی‌های بعدی
update_post_meta($post_id, '_bm_featured_image_result', maybe_serialize($image_result));

/* -------------------------------------------------------
 |  14) خروجی نهایی
 * ------------------------------------------------------ */
echo '<div style="direction:rtl;text-align:right;background:#fff;border:1px solid #ddd;padding:15px;margin:15px 0;font-family:tahoma;font-size:13px;line-height:2">';
echo '<strong>پست با موفقیت ساخته شد.</strong><br>';
echo 'ID پست: ' . intval($post_id) . '<br>';
echo 'عنوان: ' . esc_html($mytitle) . '<br>';
echo 'تصویر شاخص: ' . esc_html($image_result['message']) . '<br>';

if (is_array($download_response)) {
    echo 'دانلود موزیک: ' . ($download_response['success'] ? 'انجام شد' : 'ناموفق') . '<br>';
    if (!$download_response['success'] && !empty($download_response['error'])) {
        echo 'خطای دانلود موزیک: ' . esc_html($download_response['error']) . '<br>';
    }
}

echo '</div>';