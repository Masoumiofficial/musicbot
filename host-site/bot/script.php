<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// توابع احراز هویت (برای ارسال یوزر/رمز به هاست دانلود)؛ خودِ بررسی ورود در manual.php / auto.php انجام می‌شود
require_once __DIR__ . '/auth.php';

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

/* -------------------------------------------------------
 |  حالت «فقط توابع»
 |  پست‌ساز (postsaz/engine.php) این فایل را با $bm_functions_only = true بارگذاری می‌کند تا از
 |  توابع bm_* (نام فایل‌ها، ترم هنرمند، کاور، ACF، اتصال به هاست دانلود و ...) استفاده کند؛
 |  در این حالت جریان ربات (دریافت از API و ساخت پست) اجرا نمی‌شود.
 |  توابع این فایل در زمان بارگذاری تعریف می‌شوند، پس return زودهنگام مشکلی برایشان ندارد.
 * ------------------------------------------------------ */
if (!empty($bm_functions_only)) {
    return;
}

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
 * آیدی دسته‌های پست بر اساس مسیر ارسال
 *   $source = 'auto'   → [$cat_music]
 *   $source = 'manual' → [$cat_music, $cat_featured]
 * خروجی آرایه‌ای از آیدی‌های عددی (دسته‌ی اول = دسته‌ی اصلی)؛ مقادیر 0 یا تکراری حذف می‌شوند
 */
function bm_post_categories($source, $cat_music, $cat_featured) {
    $categories = [(int) $cat_music];

    if ($source === 'manual') {
        $categories[] = (int) $cat_featured;
    }

    $categories = array_filter($categories, function ($id) {
        return $id > 0;
    });

    return array_values(array_unique($categories));
}

/**
 * تشخیص مسیر ارسال وقتی فایل ورودی $bot_source را ست نکرده باشد
 * (از روی نام اسکریپت ورودی: auto.php → 'auto'، manual.php → 'manual'، غیر از این‌ها → '')
 * manual.php هم فرم robo.php و هم ارسال‌های /archive را دریافت می‌کند.
 */
function bm_detect_source() {
    $entry = '';

    foreach (['SCRIPT_FILENAME', 'SCRIPT_NAME', 'PHP_SELF'] as $key) {
        if (!empty($_SERVER[$key])) {
            $entry = strtolower(basename((string) $_SERVER[$key]));
            break;
        }
    }

    if ($entry === 'auto.php') {
        return 'auto';
    }
    if ($entry === 'manual.php') {
        return 'manual';
    }

    return '';
}

/**
 * نویسنده‌ی معتبر برای پست
 *   1) $bot_post_author در config.php (آیدی عددی، نام کاربری یا ایمیل)
 *   2) کاربر وردپرسی هم‌نام با یوزر ورود ربات ($bot_auth_user)
 *   3) اولین مدیر سایت (کوچک‌ترین آیدی)
 * قبلاً آیدی 1 ثابت بود؛ روی سایتی که کاربر شماره‌ی 1 حذف یا عوض شده باشد پست «بدون نویسنده» ساخته می‌شد.
 */
function bm_resolve_post_author($configured = '', $auth_user = '') {
    foreach ([$configured, $auth_user] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        if (ctype_digit($candidate)) {
            $user = get_user_by('id', (int) $candidate);
        } else {
            $user = get_user_by('login', $candidate);
            if (!$user && strpos($candidate, '@') !== false) {
                $user = get_user_by('email', $candidate);
            }
            if (!$user) {
                $user = get_user_by('slug', $candidate);
            }
        }

        if ($user && !empty($user->ID)) {
            return (int) $user->ID;
        }
    }

    $admins = get_users([
        'role'    => 'administrator',
        'orderby' => 'ID',
        'order'   => 'ASC',
        'number'  => 1,
        'fields'  => 'ID',
    ]);

    if (!empty($admins)) {
        return (int) $admins[0];
    }

    return 1;
}

/**
 * همگام‌سازی فیلدهای ACF (اگر افزونه فعال باشد) — همان کاری که زدن «به‌روزرسانی» در پیشخوان می‌کند:
 *   - برای فیلدهایی که ربات مقدارشان را ذخیره کرده، کلید مرجع ACF (_name = field_key) ثبت می‌شود تا
 *     get_field() / get_fields() قالب، پست ربات را دقیقاً مثل پست ذخیره‌شده از پیشخوان ببیند
 *   - فیلدهای ساده‌ای که ربات ست نکرده با همان مقداری ذخیره می‌شوند که فرم ویرایش ذخیره می‌کرد
 *     (مقدار پیش‌فرض؛ برای فیلدهای انتخابی بدون پیش‌فرض → گزینه‌ی اول، مثل «نوع اثر» = آهنگ)
 *   - $overrides (= $bot_acf_values در config.php): نام فیلد → مقدار؛ همیشه با همین مقدار ذخیره می‌شود
 * فیلدهای ترکیبی (repeater/group/flexible/clone) و taxonomy دست نمی‌خورند.
 * خروجی: ['referenced' => [...], 'filled' => [name => value]] یا null اگر ACF فعال نباشد.
 */
function bm_acf_editor_default($field) {
    // مقدار پیش‌فرض تعریف‌شده در ACF
    if (isset($field['default_value']) && $field['default_value'] !== '' && $field['default_value'] !== []) {
        return $field['default_value'];
    }

    // فیلد انتخابی بدون پیش‌فرض و بدون گزینه‌ی خالی: فرم ویرایش روی گزینه‌ی اول می‌ایستد و همان را ذخیره می‌کند
    $choice_types = ['select', 'radio', 'button_group'];
    if (in_array($field['type'], $choice_types, true)
        && empty($field['allow_null'])
        && empty($field['multiple'])
        && !empty($field['choices'])
        && is_array($field['choices'])
    ) {
        reset($field['choices']);
        return (string) key($field['choices']);
    }

    return '';
}

function bm_acf_sync_post_fields($post_id, $overrides = []) {
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields') || !function_exists('update_field')) {
        return null;
    }

    $skip_types = ['repeater', 'group', 'flexible_content', 'clone', 'tab', 'accordion', 'message', 'taxonomy'];
    $summary    = ['referenced' => [], 'filled' => []];

    $groups = acf_get_field_groups(['post_id' => $post_id, 'post_type' => 'post']);
    if (empty($groups) || !is_array($groups)) {
        return $summary;
    }

    foreach ($groups as $group) {
        $fields = acf_get_fields($group);
        if (empty($fields) || !is_array($fields)) {
            continue;
        }

        foreach ($fields as $field) {
            if (empty($field['name']) || empty($field['key']) || in_array($field['type'], $skip_types, true)) {
                continue;
            }

            $name = $field['name'];

            // مقدار تعیین‌شده در config.php همیشه اولویت دارد
            if (is_array($overrides) && array_key_exists($name, $overrides)) {
                update_field($field['key'], $overrides[$name], $post_id);
                $summary['filled'][$name] = $overrides[$name];
                continue;
            }

            if (metadata_exists('post', $post_id, $name)) {
                if (get_post_meta($post_id, '_' . $name, true) !== $field['key']) {
                    update_post_meta($post_id, '_' . $name, $field['key']);
                    $summary['referenced'][] = $name;
                }
                continue;
            }

            $value = bm_acf_editor_default($field);
            update_field($field['key'], $value, $post_id);
            $summary['filled'][$name] = $value;
        }
    }

    return $summary;
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
 * $max_size: کاور به مربعی با ضلع حداکثر این عدد برش می‌خورد (برش مرکزی)؛ تصویر کوچک‌تر بزرگ نمی‌شود. 0 = بدون تغییر اندازه
 */
function bm_upload_featured_image_from_url($image_url, $post_id, $img_name = '', $title = '', $alt = '', $desc = '', $arrContextOptions = [], $max_size = 1080) {
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

    // کاور: مربع با ضلع حداکثر $max_size (برش مرکزی). تصویر کوچک‌تر بزرگ نمی‌شود و
    // تصویری که از قبل مربع و در حد مجاز است دست نمی‌خورد (بدون فشرده‌سازی دوباره)
    $max_size = (int) $max_size;
    if ($max_size > 0) {
        $editor = wp_get_image_editor($tmp);
        if (!is_wp_error($editor)) {
            $size   = $editor->get_size();
            $width  = isset($size['width']) ? (int) $size['width'] : 0;
            $height = isset($size['height']) ? (int) $size['height'] : 0;
            $side   = min($width, $height, $max_size);
            if ($side > 0 && ($width !== $side || $height !== $side)) {
                $resized = $editor->resize($side, $side, true);
                if (!is_wp_error($resized)) {
                    $saved = $editor->save($tmp . '.' . $file_ext);
                    if (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path'])) {
                        @unlink($tmp);
                        $tmp = $saved['path'];
                    }
                }
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

    // اندازه‌ی نهایی برای گزارش
    $meta = wp_get_attachment_metadata($attachment_id);
    $dims = (!empty($meta['width']) && !empty($meta['height']))
        ? ' (' . (int) $meta['width'] . '×' . (int) $meta['height'] . ')'
        : '';

    return [
        'success' => true,
        'message' => 'تصویر شاخص با موفقیت ثبت شد' . $dims . '.',
        'id'      => $attachment_id,
    ];
}

/**
 * نام داخلی taxonomy خواننده/هنرمند (آرشیو هنرمند در قالب)
 * اول مقدار config.php ($bot_artist_taxonomy) اگر در سایت ثبت شده باشد؛
 * وگرنه اولین مورد موجود از «artist» (نام فعلی در قالب) و «singer» (نام قدیمی).
 * خروجی: نام taxonomy یا '' اگر هیچ‌کدام در سایت نبود
 */
function bm_artist_taxonomy($configured = '') {
    $candidates = array_unique(array_filter([
        sanitize_key((string) $configured),
        'artist',
        'singer',
    ]));

    foreach ($candidates as $taxonomy) {
        if (taxonomy_exists($taxonomy)) {
            return $taxonomy;
        }
    }

    return '';
}

/**
 * نامک انگلیسی ترم هنرمند از روی نام انگلیسی
 * مثال: «Kasra Zahedi» => «kasra-zahedi»
 * فقط اگر نامک واقعاً انگلیسی (ASCII) باشد برمی‌گردد؛ وگرنه رشته‌ی خالی
 */
function bm_artist_term_slug($artist_en) {
    $slug = sanitize_title(remove_accents(bm_clean_text($artist_en)));
    if ($slug === '' || preg_match('/[^a-z0-9\-]/', $slug)) {
        return '';
    }
    return $slug;
}

/**
 * شکل نرمال‌شده‌ی یک نام برای مقایسه: حروف کوچک، بدون فاصله/نیم‌فاصله/خط تیره/نقطه/اعراب،
 * ي→ی ، ك→ک ، ة→ه.  «محمد رضا شجریان» و «محمدرضا شجریان» و «mohammad-reza-shajarian» یکی می‌شوند
 */
function bm_normalize_name($text) {
    $text = mb_strtolower(bm_clean_text($text), 'UTF-8');
    $text = str_replace(["\xD9\x8A", "\xD9\x83", "\xD8\xA9"], ["\xDB\x8C", "\xDA\xA9", "\xD9\x87"], $text);
    $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text);
    $text = preg_replace('/[\s\x{200B}-\x{200F}\-_\.\x{060C},]+/u', '', $text);
    return (string) $text;
}

/**
 * ترم هنرمندی که «عملاً» همین نام است ولی با املای دیگری ثبت شده
 * (فاصله/نیم‌فاصله یا ي/ك عربی) یا نامک انگلیسی‌اش همین است.
 * خروجی: شناسه‌ی ترم یا 0
 */
function bm_find_similar_artist_term($artist, $slug, $taxonomy) {
    static $cache = [];

    if ($taxonomy === '' || !function_exists('get_terms')) {
        return 0;
    }
    if (!isset($cache[$taxonomy])) {
        $cache[$taxonomy] = ['names' => [], 'slugs' => []];
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (!is_object($term) || empty($term->term_id)) {
                    continue;
                }
                $name_key = bm_normalize_name($term->name);
                $slug_key = bm_normalize_name($term->slug);
                if ($name_key !== '' && !isset($cache[$taxonomy]['names'][$name_key])) {
                    $cache[$taxonomy]['names'][$name_key] = (int) $term->term_id;
                }
                if ($slug_key !== '' && !isset($cache[$taxonomy]['slugs'][$slug_key])) {
                    $cache[$taxonomy]['slugs'][$slug_key] = (int) $term->term_id;
                }
            }
        }
    }

    $name_key = bm_normalize_name($artist);
    if ($name_key !== '' && isset($cache[$taxonomy]['names'][$name_key])) {
        return $cache[$taxonomy]['names'][$name_key];
    }
    $slug_key = bm_normalize_name($slug);
    if ($slug_key !== '' && isset($cache[$taxonomy]['slugs'][$slug_key])) {
        return $cache[$taxonomy]['slugs'][$slug_key];
    }
    return 0;
}

/** شناسه‌ی پستی با این عنوان (منتشرشده، پیش‌نویس، در انتظار یا زمان‌بندی‌شده) یا 0 */
function bm_post_id_by_title($title) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_title = %s
         AND post_type = 'post'
         AND post_status IN ('publish', 'draft', 'pending', 'future')
         ORDER BY ID ASC LIMIT 1",
        $title
    ));
}

/** شناسه‌ی پستی که از همین لینک مبدأ ساخته شده (متای _bm_source_url) یا 0 */
function bm_post_id_by_source_url($url) {
    global $wpdb;
    $url = trim((string) $url);
    if ($url === '') {
        return 0;
    }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
         WHERE m.meta_key = '_bm_source_url' AND m.meta_value = %s
         AND p.post_type = 'post'
         AND p.post_status IN ('publish', 'draft', 'pending', 'future')
         ORDER BY p.ID ASC LIMIT 1",
        $url
    ));
}

/**
 * نشانگر ماشین‌خوان نتیجه برای اسکریپت‌های دیگر (آرشیو هنرمندان): در خروجی HTML به شکل کامنت می‌آید
 * status: ok | exists | error
 */
function bm_result_marker($status, array $data = []) {
    $data = array_merge(['status' => $status], $data);
    return "\n<!--BM_RESULT " . str_replace('-->', '--\u003e', wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . " -->\n";
}

/**
 * جدا کردن نام چند هنرمند از یک رشته
 * «همایون شجریان و تهمورس پورناظری» => ['همایون شجریان', 'تهمورس پورناظری']
 * «Homayoun Shajarian & Tahmoures Pournazeri» / «A Ft B» / «A, B» => ['Homayoun Shajarian', 'Tahmoures Pournazeri']
 * جداکننده‌ها: « و »، « فیت »، «،» «,» «&» «/» «|» «+» و در انگلیسی feat / ft / featuring / and
 * خروجی: آرایه‌ی نام‌ها (بدون تکرار)؛ برای ورودی خالی آرایه‌ی خالی
 */
function bm_split_artists($value) {
    $value = bm_clean_text($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/\s*(?:[،,&\/|+]|\s+و\s+|\s+فیت\s+|\s+(?:feat\.?|ft\.?|featuring|and)\s+)\s*/iu', $value);
    if (!is_array($parts)) {
        $parts = [$value];
    }

    $names = [];
    foreach ($parts as $part) {
        $part = preg_replace('/\s+/u', ' ', (string) $part);
        $part = preg_replace('/^[\s\-–—.]+|[\s\-–—.]+$/u', '', $part);
        if ($part === '' || $part === null) {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
        if (!isset($names[$key])) {
            $names[$key] = $part;
        }
    }

    return array_values($names);
}

/**
 * ترم(های) هنرمند پست
 * اگر نام خواننده چند نفر باشد ($split = true)، برای هر نفر یک ترم جدا پیدا/ساخته می‌شود؛
 * نامک انگلیسی هر نفر از بخش هم‌ردیفِ نام انگلیسی برداشته می‌شود (ترم‌هایی که از قبل وجود دارند،
 * نامک خودشان را نگه می‌دارند و همان نامک از فهرست نامک‌های آزاد کنار گذاشته می‌شود تا اگر ترتیب
 * نام‌های فارسی و انگلیسی فرق داشت، نامک اشتباهی به هنرمند جدید نرسد). اگر برای کسی نام انگلیسی
 * نبود، ترم بدون نامک دستی ساخته می‌شود و در اولین پست بعدی که نام انگلیسی‌اش معلوم شود اصلاح می‌شود.
 * $taxonomy: نام داخلی taxonomy هنرمند (خالی = تشخیص خودکار)
 * خروجی: ['taxonomy' => نام taxonomy, 'artists' => [['name' => نام, 'id' => شناسه ترم یا 0, 'link' => لینک یا ''], ...]]
 */
function bm_get_artist_terms($artist, $artist_en, $taxonomy = '', $split = true) {
    if ($taxonomy === '') {
        $taxonomy = bm_artist_taxonomy();
    }

    $artist    = bm_clean_text($artist);
    $artist_en = bm_clean_text($artist_en);

    $names = $split ? bm_split_artists($artist) : ($artist !== '' ? [$artist] : []);
    $result = ['taxonomy' => $taxonomy, 'artists' => []];
    if (empty($names)) {
        return $result;
    }

    $count = count($names);
    $en_for = array_fill(0, $count, '');

    if ($count === 1) {
        // یک هنرمند: مثل قبل، نامک از کل نام انگلیسی
        $en_for[0] = $artist_en;
    } else {
        $en_names = bm_split_artists($artist_en);
        $pool     = [];
        foreach ($en_names as $en_name) {
            $en_slug = bm_artist_term_slug($en_name);
            if ($en_slug !== '' && !in_array($en_slug, $pool, true)) {
                $pool[] = $en_slug;
            }
        }

        // ترم‌های موجود، نامک خودشان را از فهرست آزاد برمی‌دارند
        if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
            foreach ($names as $i => $name) {
                $found = term_exists($name, $taxonomy);
                if (!$found) {
                    continue;
                }
                $term_obj = get_term(is_array($found) ? (int) $found['term_id'] : (int) $found, $taxonomy);
                if (!$term_obj || is_wp_error($term_obj)) {
                    continue;
                }
                $k = array_search($term_obj->slug, $pool, true);
                if ($k !== false) {
                    $en_for[$i] = $term_obj->slug;
                    unset($pool[$k]);
                    $pool = array_values($pool);
                }
            }
        }

        // بقیه: اگر تعداد نام‌های فارسی و انگلیسی برابر بود نامک هم‌ردیف، وگرنه به ترتیب از فهرست آزاد
        foreach ($names as $i => $name) {
            if ($en_for[$i] !== '' || empty($pool)) {
                continue;
            }
            $k = 0;
            if ($count === count($en_names)) {
                $k_same = array_search(bm_artist_term_slug($en_names[$i]), $pool, true);
                if ($k_same !== false) {
                    $k = $k_same;
                }
            }
            $en_for[$i] = $pool[$k];
            unset($pool[$k]);
            $pool = array_values($pool);
        }
    }

    foreach ($names as $i => $name) {
        $term = bm_get_singer_term($name, $en_for[$i], $taxonomy);
        $result['artists'][] = [
            'name' => $name,
            'id'   => (int) $term['id'],
            'link' => $term['link'],
        ];
    }

    return $result;
}

/**
 * ساخت (یا پیدا کردن) ترم یک خواننده
 * نام ترم = $artist (فارسی) | نامک ترم = $artist_en (انگلیسی)
 * مثال: نام «کسری زاهدی» و نامک «kasra-zahedi»
 * اگر ترم از قبل با نامک فارسی ساخته شده باشد، نامکش به انگلیسی اصلاح می‌شود
 * (نامک انگلیسیِ ترم‌های موجود دست نمی‌خورد تا آدرس آرشیو هنرمند عوض نشود)
 * $taxonomy: نام داخلی taxonomy هنرمند (خالی = تشخیص خودکار با bm_artist_taxonomy)
 * خروجی: ['id' => شناسه ترم, 'link' => لینک آرشیو خواننده, 'taxonomy' => نام taxonomy]
 */
function bm_get_singer_term($artist, $artist_en, $taxonomy = '') {
    if ($taxonomy === '') {
        $taxonomy = bm_artist_taxonomy();
    }

    $result = ['id' => 0, 'link' => '', 'taxonomy' => $taxonomy];

    $artist = bm_clean_text($artist);
    if ($artist === '' || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return $result;
    }

    // نامک انگلیسی از روی نام انگلیسی خواننده (فقط اگر واقعاً انگلیسی باشد)
    $slug = bm_artist_term_slug($artist_en);

    // پیدا کردن ترم با نام فارسی
    $term_id = 0;
    $found   = term_exists($artist, $taxonomy);
    if ($found) {
        $term_id = is_array($found) ? (int) $found['term_id'] : (int) $found;
    }

    // اگر نبود: همین هنرمند با املای دیگر («محمد رضا» / «محمدرضا»، ي/ك عربی) یا با همین نامک انگلیسی
    // ثبت شده؟ از همان ترم استفاده می‌شود تا برای یک خواننده دو آرشیو ساخته نشود
    if (!$term_id) {
        $term_id = bm_find_similar_artist_term($artist, $slug, $taxonomy);
    }

    // اگر نبود، با نام فارسی و نامک انگلیسی بساز
    if (!$term_id) {
        $args = ($slug !== '') ? ['slug' => $slug] : [];
        $new  = wp_insert_term($artist, $taxonomy, $args);

        if (is_wp_error($new)) {
            $existing_id = $new->get_error_data('term_exists');
            if ($existing_id) {
                $term_id = (int) $existing_id;
            } else {
                // مثلاً نامک تکرارِ ترم دیگری بود؛ بدون نامک دستی می‌سازیم
                $new = wp_insert_term($artist, $taxonomy);
                if (is_wp_error($new)) {
                    return $result;
                }
                $term_id = (int) $new['term_id'];
            }
        } else {
            $term_id = (int) $new['term_id'];
        }
    } elseif ($slug !== '') {
        // ترم قبلاً وجود داشته؛ فقط اگر نامکش انگلیسی نیست (مثلاً فارسیِ درصدی) اصلاح کن
        $term_obj = get_term($term_id, $taxonomy);
        if ($term_obj && !is_wp_error($term_obj) && $term_obj->slug !== $slug
            && ($term_obj->slug === '' || preg_match('/[^a-z0-9\-]/', $term_obj->slug))) {
            wp_update_term($term_id, $taxonomy, ['slug' => $slug]);
        }
    }

    $link = get_term_link($term_id, $taxonomy);
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
    // چند هنرمند: [['name' => نام, 'link' => لینک آرشیو], ...] (خروجی bm_get_artist_terms)
    $artists     = (isset($args['artists']) && is_array($args['artists'])) ? $args['artists'] : [];

    // لینک آهنگ جدید
    $music_html = '<strong>دانلود آهنگ جدید</strong>';
    if ($music_url !== '') {
        $music_html = '<a href="' . esc_url($music_url) . '">' . $music_html . '</a>';
    }

    // لینک خواننده (هر هنرمند به صفحه‌ی خودش؛ با « و » کنار هم)
    if (empty($artists)) {
        $artists = [['name' => $artist, 'link' => $singer_link]];
    }
    $singer_parts = [];
    foreach ($artists as $one) {
        $name = isset($one['name']) ? bm_clean_text($one['name']) : '';
        if ($name === '') {
            continue;
        }
        $one_html = '<strong>' . esc_html($name) . '</strong>';
        if (!empty($one['link'])) {
            $one_html = '<a class="ty-singer-link" href="' . esc_url($one['link']) . '">' . $one_html . '</a>';
        }
        $singer_parts[] = $one_html;
    }
    $singer_html = !empty($singer_parts) ? implode(' و ', $singer_parts) : '<strong>' . esc_html($artist) . '</strong>';

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
    $api_fail = 'خطا در ارتباط با API. HTTP: ' . intval($api_response['code'])
        . ' | cURL: ' . $api_response['error']
        . ' | Response: ' . mb_substr((string) $api_response['response'], 0, 500);
    exit(esc_html($api_fail) . bm_result_marker('error', ['reason' => 'api', 'message' => $api_fail]));
}

$result = json_decode($api_response['response'], true);

if (!is_array($result)) {
    exit('خطا: پاسخ API معتبر نیست.' . bm_result_marker('error', ['reason' => 'api', 'message' => 'پاسخ API معتبر نیست']));
}

if (!empty($result['error'])) {
    exit('API Error: ' . esc_html($result['error']) . bm_result_marker('error', ['reason' => 'api', 'message' => 'API: ' . $result['error']]));
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
    exit('خطا: نام خواننده یا نام آهنگ از API دریافت نشد.' . bm_result_marker('error', ['reason' => 'api', 'message' => 'نام خواننده یا نام آهنگ از API دریافت نشد']));
}

if ($artist_en === '') {
    $artist_en = $artist;
}

if ($song_en === '') {
    $song_en = $song;
}

/* -------------------------------------------------------
 |  3-1) جلوگیری از پست تکراری
 |  الف) همین لینک مبدأ قبلاً ثبت شده (متای _bm_source_url) → همیشه رد می‌شود
 |  ب) عنوان یکسان ولی لینک مبدأ دیگر (قطعه‌ی دیگری با نام عمومی مثل «ساز و آواز» یا «ربنا»):
 |     اگر $bm_same_title = 'number' باشد (ارسال دستی/آرشیو با گزینه‌ی «ثبت با شماره») با شماره‌ی تمایز
 |     ثبت می‌شود: «ساز و آواز (2)» و نام فایل «… - Saz o Avaz 2 [320].mp3»؛ وگرنه (پیش‌فرض و ارسال
 |     خودکار) رد می‌شود. پست‌های قدیمی که لینک مبدأ ندارند فقط با عنوان مقایسه می‌شوند.
 * ------------------------------------------------------ */
$bm_same_title_note = '';
$bm_artist_note     = '';
$same_title_mode    = isset($bm_same_title) ? (string) $bm_same_title : (isset($bot_same_title) ? (string) $bot_same_title : 'skip');

// املای نام هنرمند: اگر همین هنرمند با املای دیگری در سایت ثبت شده («محمد رضا شجریان» ← «محمدرضا شجریان»)،
// نام ثبت‌شده مبنا می‌شود تا عنوان، متا و ترم همه یکدست بمانند (فقط برای نام تک‌نفره)
$bm_artist_tax_early = bm_artist_taxonomy(isset($bot_artist_taxonomy) ? $bot_artist_taxonomy : '');
if ($bm_artist_tax_early !== '' && count(bm_split_artists($artist)) === 1 && !term_exists($artist, $bm_artist_tax_early)) {
    $similar_id = bm_find_similar_artist_term($artist, bm_artist_term_slug($artist_en), $bm_artist_tax_early);
    if ($similar_id) {
        $similar_term = get_term($similar_id, $bm_artist_tax_early);
        if ($similar_term && !is_wp_error($similar_term) && $similar_term->name !== $artist) {
            $bm_artist_note = 'نام هنرمند «' . $artist . '» با ترم موجود «' . $similar_term->name . '» (ترم ' . $similar_id . ') یکی گرفته شد';
            $artist = bm_clean_text($similar_term->name);
        }
    }
}

$source_exists = bm_post_id_by_source_url($postUrl);
if ($source_exists) {
    exit('همین آهنگ (همین لینک مبدأ) قبلاً ثبت شده است. ID: ' . intval($source_exists)
        . bm_result_marker('exists', ['reason' => 'source', 'post_id' => $source_exists, 'link' => get_permalink($source_exists), 'message' => 'همین لینک قبلاً ثبت شده (ID ' . $source_exists . ')']));
}

$title_exists = bm_post_id_by_title(trim("دانلود آهنگ {$artist} {$song}"));
if ($title_exists) {
    if ($same_title_mode !== 'number') {
        exit('پست با این عنوان قبلاً ثبت شده است. ID: ' . intval($title_exists)
            . ' — اگر قطعه‌ی دیگری با همین نام است، با گزینه‌ی «ثبت با شماره» دوباره بفرستید.'
            . bm_result_marker('exists', ['reason' => 'title', 'post_id' => $title_exists, 'link' => get_permalink($title_exists), 'message' => 'پستی با همین عنوان هست (ID ' . $title_exists . ')']));
    }
    $same_n = 2;
    while ($same_n < 100 && bm_post_id_by_title(trim("دانلود آهنگ {$artist} {$song} ({$same_n})"))) {
        $same_n++;
    }
    $song    .= " ({$same_n})";
    $song_en .= " {$same_n}";
    $bm_same_title_note = "عنوان تکراری بود (پست ID {$title_exists})؛ با شماره‌ی {$same_n} ثبت شد";
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
 |  5) جلوگیری از پست تکراری → به بخش 3-1 منتقل شد (قبل از ساخت نام‌ها)
 * ------------------------------------------------------ */

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
// taxonomy هنرمند: از config.php ($bot_artist_taxonomy)، وگرنه تشخیص خودکار artist/singer
$artist_taxonomy = bm_artist_taxonomy(isset($bot_artist_taxonomy) ? $bot_artist_taxonomy : '');

// ترم هنرمند(ان): اگر خواننده چند نفر باشد («همایون شجریان و تهمورس پورناظری») برای هر نفر یک ترم
// ($bot_split_artists در config.php؛ پیش‌فرض فعال). عنوان، متاها و نام فایل‌ها همان نام کامل می‌مانند.
$artist_terms = bm_get_artist_terms(
    $artist,
    $artist_en,
    $artist_taxonomy,
    !isset($bot_split_artists) || !empty($bot_split_artists)
);

$my_post_content = bm_build_post_content([
    'artist'      => $artist,
    'song'        => $song,
    'artist_en'   => $artist_en,
    'song_en'     => $song_en,
    'music_url'   => trailingslashit($urlsite) . 'music/',
    'artists'     => $artist_terms['artists'],
]);

/* -------------------------------------------------------
 |  7) ساخت پست
 |  پست اول به صورت پیش‌نویس ساخته می‌شود و بعد از آماده شدن متاها، دسته‌ها، تصویر شاخص و
 |  فایل‌ها (بخش 14) منتشر می‌شود؛ مثل وقتی که پست در پیشخوان ذخیره می‌شود.
 * ------------------------------------------------------ */
$post_author_id = bm_resolve_post_author(
    isset($bot_post_author) ? $bot_post_author : '',
    isset($bot_auth_user) ? $bot_auth_user : ''
);

// بقیه‌ی مراحل با همین کاربر اجرا می‌شود (مثل ذخیره از پیشخوان): هوک‌هایی که current_user_can را
// بررسی می‌کنند اجرا می‌شوند و محتوا مثل کاربر مدیر بدون فیلتر kses ذخیره می‌شود
if ($post_author_id > 0 && get_current_user_id() !== $post_author_id) {
    wp_set_current_user($post_author_id);
}

$post_data = [
    'post_author'   => $post_author_id,
    'post_status'   => 'draft',
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
    exit('خطا در ایجاد پست: ' . esc_html($msg) . bm_result_marker('error', ['reason' => 'insert', 'message' => 'خطا در ایجاد پست: ' . $msg]));
}

// لینک مبدأ روی پست می‌ماند تا همان آهنگ دوباره ثبت نشود (و آرشیو هنرمندان وضعیت را دقیق نشان دهد)
update_post_meta($post_id, '_bm_source_url', esc_url_raw($postUrl));

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
 |  9) taxonomyها
 |  به پست‌ها تگ (post_tag) داده نمی‌شود: آرشیو خواننده را taxonomy هنرمند («artist»؛ قبلاً «singer»،
 |  قابل تنظیم با $bot_artist_taxonomy؛ برای هر خواننده‌ی آهنگ یک ترم)، «دانلود آهنگ» را دسته‌بندی و
 |  سبک/حال‌وهوا را «genre» و «mood» پوشش می‌دهند.
 |  تگ فقط صفحه‌های آرشیو تکراری یا تک‌پستی می‌سازد که برای سئو ضرر دارد.
 * ------------------------------------------------------ */

// دسته‌بندی بر اساس مسیر ارسال ($bot_source را auto.php = 'auto' و manual.php = 'manual' ست می‌کنند)
//   خودکار (فید)              → فقط $cat_music
//   دستی (robo.php و آرشیو)   → $cat_music + $cat_featured
//   اگر منبع مشخص نبود، محافظه‌کارانه فقط $cat_music داده می‌شود تا «آثار منتخب» اشتباهی پر نشود.
if (!isset($bot_source)) {
    // فایل ورودی قدیمی است و $bot_source را ست نکرده → از نام اسکریپت ورودی تشخیص بده
    $bot_source = bm_detect_source();
}

// آیدی دسته‌ها از config.php خوانده می‌شود؛ اگر آن‌جا تعریف نشده بود: 3 = دانلود آهنگ، 249 = آثار منتخب
$post_categories = bm_post_categories(
    $bot_source,
    isset($cat_music) ? $cat_music : 3,
    isset($cat_featured) ? $cat_featured : 249
);

if (taxonomy_exists('category') && !empty($post_categories)) {
    wp_set_object_terms($post_id, $post_categories, 'category', false);

    // دسته‌ی اصلی Rank Math = دانلود آهنگ (برای لینک/بردکرامب وقتی پست دو دسته دارد)
    update_post_meta($post_id, 'rank_math_primary_category', (int) $post_categories[0]);
}

// تاکسونومی‌های اختصاصی (هنرمند: همه‌ی ترم‌ها؛ اگر ترمی ساخته نشده بود با نام ست می‌شود تا وردپرس خودش بسازد)
if ($artist_taxonomy !== '') {
    $artist_term_ids = [];
    foreach ($artist_terms['artists'] as $one_artist) {
        $artist_term_ids[] = !empty($one_artist['id']) ? (int) $one_artist['id'] : (string) $one_artist['name'];
    }
    if (!empty($artist_term_ids)) {
        wp_set_object_terms($post_id, $artist_term_ids, $artist_taxonomy, false);
    }
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
 |  11-1) همگام‌سازی با ACF (فقط اگر افزونه فعال باشد)
 |  بعد از متاها و دسته‌ها اجرا می‌شود تا گروه‌های فیلدی که بر اساس دسته نمایش داده می‌شوند هم پیدا شوند.
 * ------------------------------------------------------ */
$acf_sync = bm_acf_sync_post_fields($post_id, isset($bot_acf_values) && is_array($bot_acf_values) ? $bot_acf_values : []);

/* -------------------------------------------------------
 |  12) ثبت تصویر شاخص
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
        $arrContextOptions,
        isset($bot_cover_size) ? (int) $bot_cover_size : 1080
    );
}

// ذخیره نتیجه برای بررسی‌های بعدی
update_post_meta($post_id, '_bm_featured_image_result', maybe_serialize($image_result));

/* -------------------------------------------------------
 |  13) دانلود موزیک‌ها روی هاست دانلود
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
 |  14) انتشار پست
 |  تا این‌جا پست پیش‌نویس بود؛ حالا که همه چیز آماده است منتشر می‌شود. این همان اتفاقی است که با
 |  زدن «به‌روزرسانی» در پیشخوان می‌افتد: هوک‌های save_post / publish_post قالب و افزونه‌ها
 |  (کش، پلیر صفحه‌ی اصلی، ارسال تلگرام، نقشه‌ی سایت و ...) پست را کامل می‌بینند.
 * ------------------------------------------------------ */
$publish_result = wp_update_post([
    'ID'          => $post_id,
    'post_status' => 'publish',
], true);

$post_published = !is_wp_error($publish_result) && (int) $publish_result > 0;
$publish_error  = is_wp_error($publish_result) ? $publish_result->get_error_message() : '';

/* -------------------------------------------------------
 |  15) خروجی نهایی
 * ------------------------------------------------------ */
echo '<div style="direction:rtl;text-align:right;background:#fff;border:1px solid #ddd;padding:15px;margin:15px 0;font-family:tahoma;font-size:13px;line-height:2">';
echo '<strong>پست با موفقیت ساخته شد.</strong><br>';
echo 'ID پست: ' . intval($post_id) . '<br>';
echo 'عنوان: ' . esc_html($mytitle) . '<br>';
if ($bm_same_title_note !== '') {
    echo 'عنوان تکراری: ' . esc_html($bm_same_title_note) . '<br>';
}
if ($bm_artist_note !== '') {
    echo 'املای هنرمند: ' . esc_html($bm_artist_note) . '<br>';
}
echo 'تصویر شاخص: ' . esc_html($image_result['message']) . '<br>';
$author_user = get_userdata($post_author_id);
echo 'نویسنده: ' . esc_html($author_user ? $author_user->display_name . ' (' . $author_user->user_login . ' — ID ' . $post_author_id . ')' : 'ID ' . $post_author_id) . '<br>';
echo 'وضعیت انتشار: ' . ($post_published ? 'منتشر شد' : 'پیش‌نویس ماند — ' . esc_html($publish_error)) . '<br>';
if (is_array($acf_sync)) {
    $acf_filled = [];
    foreach ($acf_sync['filled'] as $acf_name => $acf_value) {
        $acf_filled[] = $acf_name . '=' . (is_scalar($acf_value) ? (string) $acf_value : wp_json_encode($acf_value, JSON_UNESCAPED_UNICODE));
    }
    echo 'ACF: ' . count($acf_sync['referenced']) . ' فیلد مرجع‌گذاری شد، ' . count($acf_sync['filled']) . ' فیلد مثل فرم ویرایش ذخیره شد'
        . (!empty($acf_filled) ? ' (' . esc_html(implode(', ', $acf_filled)) . ')' : '') . '<br>';
}
echo 'دسته‌بندی‌ها: ' . esc_html(implode(', ', $post_categories)) . ' (' . ($bot_source === 'manual' ? 'ارسال دستی' : 'ارسال خودکار') . ')<br>';
if ($artist_taxonomy === '') {
    echo 'هنرمند: ثبت نشد — taxonomy هنرمند (artist/singer) در سایت پیدا نشد<br>';
} else {
    $artist_report = [];
    foreach ($artist_terms['artists'] as $one_artist) {
        $artist_report[] = $one_artist['name'] . (!empty($one_artist['id']) ? ' (ترم ' . intval($one_artist['id']) . ')' : ' (ترم ساخته نشد)');
    }
    echo 'هنرمند: ' . esc_html(implode('، ', $artist_report)) . ' — taxonomy ' . esc_html($artist_taxonomy)
        . (count($artist_report) > 1 ? '، ' . count($artist_report) . ' هنرمند جدا' : '') . '<br>';
}

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
echo bm_result_marker('ok', [
    'post_id'   => (int) $post_id,
    'link'      => get_permalink($post_id),
    'title'     => $mytitle,
    'published' => $post_published,
    'download'  => is_array($download_response) ? (bool) $download_response['success'] : null,
    'note'      => trim($bm_same_title_note . ($bm_artist_note !== '' ? ($bm_same_title_note !== '' ? '؛ ' : '') . $bm_artist_note : '')),
]);