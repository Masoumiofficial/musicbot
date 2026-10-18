<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// توابع احراز هویت (برای ارسال یوزر/رمز به هاست دانلود)؛ خودِ بررسی ورود در manual.php / auto.php انجام می‌شود
require_once __DIR__ . '/auth.php';


$apiUrl = 'https://api.wintheme.ir/b2m.php';

if (empty($postUrl)) {
    exit('خطا: آدرس پست ورودی ($postUrl) مشخص نشده است.');
}

if (empty($urlsite)) {
    $urlsite = home_url();
}

/**
 * نوع محتوا = پوشه‌ی سطح اول روی هاست دانلود (music | album | video | ...)
 * این اسکریپت فعلاً فقط تک‌آهنگ ثبت می‌کند؛ اسکریپت‌های دیگر می‌توانند
 * قبل از include کردن این فایل، مقدار $content_type را تغییر دهند.
 */
if (empty($content_type)) {
    $content_type = 'music';
}

$dir_wp = date('Y/m');

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
 * نام فایل روی هاست دانلود (بدون پسوند)
 * کاراکترهایی که در نام فایل یا URL مشکل‌ساز هستند حذف می‌شوند
 * مثال: «Mohsen Yeganeh - Nadaramet»
 */
function bm_dl_file_name($text) {
    $text = bm_clean_text($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = preg_replace('/[\\\\\/\?#%\*:"<>\|\x00-\x1F]+/u', '', $text);
    return trim($text, " .-");
}

/**
 * نامک انگلیسی خواننده برای پوشه‌ی هاست دانلود
 * مثال: «Mohsen Yeganeh» => «mohsen-yeganeh»
 * اگر نام انگلیسی در دسترس نبود (مثلاً فارسی بود)، از نام امن‌شده‌ی همان متن استفاده می‌شود
 * اگر هیچ چیز قابل استفاده‌ای نبود، رشته‌ی خالی برمی‌گردد
 */
function bm_artist_dir_slug($artist_en) {
    $artist_en = bm_clean_text($artist_en);

    $slug = sanitize_title(remove_accents($artist_en));

    // sanitize_title برای متن غیرلاتین خروجی درصدی (%d9%85...) می‌دهد؛ در این حالت نام امن فایل را به کار می‌بریم
    if ($slug === '' || preg_match('/[^a-z0-9\-]/', $slug)) {
        $slug = bm_safe_file_name($artist_en);
        if (function_exists('mb_strtolower')) {
            $slug = mb_strtolower($slug, 'UTF-8');
        }
    }

    return trim($slug, '-');
}

/**
 * پوشه‌ی حرف اول نام خواننده
 * حروف a-z و ارقام 0-9 همان کاراکتر اول؛ در غیر این صورت «other»
 * مثال: «mohsen-yeganeh» => «m» | «25band» => «2»
 */
function bm_first_letter_dir($slug) {
    $first = substr((string) $slug, 0, 1);
    return preg_match('/^[a-z0-9]$/', $first) ? $first : 'other';
}

/**
 * مسیر نسبی پوشه‌ی فایل‌ها روی هاست دانلود
 * ساختار: {نوع محتوا}/{حرف اول}/{نامک خواننده}
 * مثال: music/m/mohsen-yeganeh
 */
function bm_download_dir($content_type, $artist_en) {
    $content_type = sanitize_key($content_type);
    if ($content_type === '') {
        $content_type = 'music';
    }

    $slug = bm_artist_dir_slug($artist_en);
    if ($slug === '') {
        return $content_type . '/other/unknown';
    }

    return $content_type . '/' . bm_first_letter_dir($slug) . '/' . $slug;
}

/**
 * جدا کردن چند نام از یک فیلد عوامل («علی و رضا»، «A & B»، «A، B»، «A / B»)
 * خروجی: آرایه‌ای از نام‌های تمیزشده
 */
function bm_split_names($value) {
    $value = bm_clean_text($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/\s*(?:[،,\/&|؛;]|\s+و\s+|\s+and\s+)\s*/iu', $value);
    $names = [];

    foreach ((array) $parts as $name) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name), " \t-–_:.");
        if ($name === '') {
            continue;
        }
        $names[] = $name;
    }

    return $names;
}

/**
 * تگ‌های پست
 *
 * فقط موجودیت‌هایی تگ می‌شوند که بین پست‌های مختلف تکرار می‌شوند و واقعاً جستجو می‌شوند:
 *   - نام خواننده
 *   - عوامل آهنگ: آهنگساز، تنظیم‌کننده، ترانه‌سرا
 * تگ‌های عمومی («دانلود آهنگ»، «دانلود آهنگ جدید») و تگ یکتای هر آهنگ («آهنگ X») حذف شده‌اند،
 * چون فقط صفحه‌های آرشیو تکراری/تک‌پستی می‌سازند و ارزشی برای رتبه‌بندی ندارند.
 */
function bm_build_post_tags($artist, array $credits, $max = 6) {
    $tags   = [];
    $artist = bm_clean_text($artist);

    // مقادیر بی‌معنی که گاهی از API برمی‌گردند
    $junk = ['-', '—', 'ندارد', 'نامشخص', 'نا مشخص', 'مشخص نیست', 'خودش', 'unknown', 'none', 'n/a'];

    if ($artist !== '') {
        $tags[] = $artist;
    }

    foreach ($credits as $credit) {
        foreach (bm_split_names($credit) as $name) {
            // نام‌های خیلی کوتاه یا مقادیر بی‌معنی
            if (mb_strlen($name, 'UTF-8') < 3 || in_array(mb_strtolower($name, 'UTF-8'), $junk, true)) {
                continue;
            }
            $tags[] = $name;
        }
    }

    $tags = array_values(array_unique($tags));

    return array_slice($tags, 0, $max);
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
 * $userpwd = «user:pass» برای HTTP Basic Auth (خالی = بدون احراز هویت)
 */
function bm_curl_post_form($url, array $payload, $timeout = 120, $userpwd = '') {
    if (empty($url)) {
        return [
            'success' => false,
            'error'   => 'Download endpoint is empty.',
            'code'    => 0,
            'body'    => '',
        ];
    }

    $ch = curl_init($url);

    $options = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];

    if ($userpwd !== '') {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD]  = $userpwd;
    }

    curl_setopt_array($ch, $options);

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

$mp3_name = bm_dl_file_name("{$artist_en} - {$song_en}");
$imgname  = bm_safe_file_name("{$artist_en} {$song_en}");

$post_cover = trailingslashit($urlsite) . "wp-content/uploads/{$dir_wp}/{$imgname}.jpg";

/* -------------------------------------------------------
 |  4-1) مسیر و نام فایل‌ها روی هاست دانلود
 |  پوشه:  {نوع محتوا}/{حرف اول}/{نامک خواننده}   مثال: music/m/mohsen-yeganeh
 |  فایل:  Artist - Song [320].mp3  و  Artist - Song [128].mp3
 * ------------------------------------------------------ */
$dl_dir       = bm_download_dir($content_type, $artist_en);
$mp3_file_320 = "{$mp3_name} [320].mp3";
$mp3_file_128 = "{$mp3_name} [128].mp3";

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
 |  5-1) یوزر/رمز اتصال به هاست دانلود (قبل از ساخت پست بررسی می‌شود تا پست بدون فایل ساخته نشود)
 * ------------------------------------------------------ */
$dl_userpwd = bm_auth_userpwd();

if (!empty($functions_dl_music) && $dl_userpwd === '') {
    exit('خطا: یوزر/رمز اتصال به هاست دانلود در دسترس نیست. در اجرای خط فرمان (کران‌جاب) باید BM_AUTH_PASS در wp-config.php تعریف شود.');
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
$wpseo_metadesc  = "دانلود آهنگ جدید {$artist} به نام {$song} با کیفیت 320 و 128، پخش آنلاین" . ($matnmusic !== '' ? ' و متن ترانه' : '');

// کلمه کانونی اصلی = همان عبارتی که کاربر جستجو می‌کند؛ کلمات بعدی (با کاما) کلمات کانونی فرعی Rank Math هستند
$focus_keywords = array_values(array_unique(array_filter([
    "دانلود آهنگ {$artist} {$song}",
    "{$artist} {$song}",
    ($artist_en !== $artist || $song_en !== $song) ? "{$artist_en} {$song_en}" : '',
])));


$meta_updates = [
    'rank_math_title'           => wp_strip_all_tags($wpseo_title),
    'rank_math_description'     => wp_strip_all_tags($wpseo_metadesc),
    'rank_math_focus_keyword'   => wp_strip_all_tags(implode(',', $focus_keywords)),
    'rank_math_seo_score'       => '99',
];

foreach ($meta_updates as $key => $value) {
    update_post_meta($post_id, $key, $value);
}


/* -------------------------------------------------------
 |  9) تگ‌ها و taxonomyها
 |  تگ = نام خواننده + عوامل (آهنگساز، تنظیم، ترانه‌سرا). بدون تگ عمومی یا تگ یکتای هر آهنگ.
 * ------------------------------------------------------ */
$post_tags = bm_build_post_tags($artist, [$composer, $regulator, $songwriter]);

if (!empty($post_tags)) {
    wp_set_object_terms($post_id, $post_tags, 'post_tag', false);
}

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
    $path_320 = trailingslashit($urldlhost) . "{$dl_dir}/{$mp3_file_320}";
    $path_128 = trailingslashit($urldlhost) . "{$dl_dir}/{$mp3_file_128}";
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
        'file_320'  => $mp3_file_320,
        'file_128'  => $mp3_file_128,
        'dir_file'  => $dl_dir, // مثال: music/m/mohsen-yeganeh
    ], 300, $dl_userpwd);

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
    echo 'پوشه هاست دانلود: ' . esc_html($dl_dir) . '<br>';
    echo 'دانلود موزیک: ' . ($download_response['success'] ? 'انجام شد' : 'ناموفق') . '<br>';
    if (!$download_response['success']) {
        $dl_error = 'HTTP ' . intval($download_response['code']);
        if (!empty($download_response['error'])) {
            $dl_error .= ' | ' . $download_response['error'];
        }
        if (intval($download_response['code']) === 401) {
            $dl_error .= ' | هاست دانلود یوزر/رمز را نپذیرفت (config.php هر دو هاست باید یکی باشد)';
        }
        if (!empty($download_response['body'])) {
            $dl_error .= ' | ' . mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $download_response['body']))), 0, 250);
        }
        echo 'خطای دانلود موزیک: ' . esc_html($dl_error) . '<br>';
    }
}

echo '</div>';