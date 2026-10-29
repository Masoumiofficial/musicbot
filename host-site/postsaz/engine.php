<?php
/**
 * موتور پست‌ساز — توابع ps_*
 *
 * قبل از include شدن این فایل باید: bot/config.php، postsaz/config.php، احراز هویت و wp-load.php
 * بارگذاری شده باشند (submit.php و index.php این کار را می‌کنند).
 *
 * همه‌ی منطق مشترک با ربات (نام فایل‌ها و پوشه‌ی هاست دانلود، ترم هنرمند و جداسازی چند هنرمند،
 * کاور مربعی، ACF، Rank Math، نویسنده، اتصال به هاست دانلود) از توابع bm_* خود ربات
 * (bot/script.php در حالت «فقط توابع») استفاده می‌شود تا رفتار پست‌ها یکسان بماند.
 */

if (!defined('ABSPATH')) {
    exit;
}

$bm_functions_only = true;
require_once __DIR__ . '/../bot/script.php';
unset($bm_functions_only);

/* =======================================================
 |  تنظیمات
 * ====================================================== */

/**
 * خواندن تنظیمات از آرایه‌ی $postsaz با مسیر نقطه‌ای: ps_cfg('album.max_tracks', 30)
 */
function ps_cfg($path, $default = null) {
    $value = isset($GLOBALS['postsaz']) && is_array($GLOBALS['postsaz']) ? $GLOBALS['postsaz'] : [];
    foreach (explode('.', (string) $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

/**
 * نوع‌های تعریف‌شده در config.php (اختیاری: فقط نوع‌های یک ماژول)
 */
function ps_types($module = '') {
    $out = [];
    foreach ((array) ps_cfg('types', []) as $key => $type) {
        if (!is_array($type) || empty($type['label'])) {
            continue;
        }
        $type['key']    = (string) $key;
        $type['module'] = isset($type['module']) ? (string) $type['module'] : 'single';
        if ($module === '' || $type['module'] === $module) {
            $out[$key] = $type;
        }
    }
    return $out;
}

function ps_type($key) {
    $types = ps_types();
    return isset($types[$key]) ? $types[$key] : null;
}

/**
 * اولین نوع یک ماژول (برای ویدیو و آلبوم که یک نوع بیشتر ندارند)
 */
function ps_module_type($module) {
    $types = ps_types($module);
    return !empty($types) ? reset($types) : null;
}

function ps_site_url() {
    return trailingslashit(!empty($GLOBALS['urlsite']) ? (string) $GLOBALS['urlsite'] : home_url());
}

function ps_dl_host() {
    return !empty($GLOBALS['urldlhost']) ? trailingslashit((string) $GLOBALS['urldlhost']) : '';
}

/**
 * آدرس گیرنده روی هاست دانلود: مقدار config، وگرنه {$urldlhost}/{module}.php (تک‌آهنگ: $functions_dl_music)
 */
function ps_dl_endpoint($module) {
    $configured = trim((string) ps_cfg('dl.' . $module, ''));
    if ($configured !== '') {
        return $configured;
    }
    if ($module === 'single' && !empty($GLOBALS['functions_dl_music'])) {
        return (string) $GLOBALS['functions_dl_music'];
    }
    $host = ps_dl_host();
    if ($host === '') {
        return '';
    }
    return $host . ($module === 'single' ? 'music' : $module) . '.php';
}

/**
 * پوشه‌های سطح اول مجاز روی هاست دانلود (برای فرم آپلود فایل)
 */
function ps_dl_folders() {
    $folders = [];
    foreach (ps_types() as $type) {
        $dir = sanitize_key(isset($type['dir']) ? $type['dir'] : '');
        if ($dir !== '' && !isset($folders[$dir])) {
            $folders[$dir] = $type['label'];
        }
    }
    return $folders;
}

/* =======================================================
 |  ورودی فرم
 * ====================================================== */

function ps_in($key, $default = '') {
    if (!isset($_POST[$key])) {
        return $default;
    }
    return is_array($_POST[$key]) ? wp_unslash($_POST[$key]) : wp_unslash((string) $_POST[$key]);
}

/** متن یک‌خطی بدون تگ */
function ps_text($key) {
    return bm_clean_text(ps_in($key));
}

/** متن چندخطی (متن ترانه): خط‌ها حفظ می‌شوند، تگ‌های ناامن حذف */
function ps_lines($key) {
    $value = ps_in($key);
    if (!is_string($value)) {
        return '';
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return trim(wp_kses_post($value));
}

function ps_valid_url($url) {
    return is_string($url)
        && $url !== ''
        && preg_match('#^https?://#i', $url)
        && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/* =======================================================
 |  فایل‌های آپلودشده: نگه‌داری موقت با آدرس عمومی تا هاست دانلود و وردپرس آن‌ها را بردارند
 * ====================================================== */

function ps_temp_root() {
    $uploads = wp_upload_dir();
    $sub     = trim((string) ps_cfg('temp.dir', 'postsaz-tmp'), "/\\ ");
    if ($sub === '') {
        $sub = 'postsaz-tmp';
    }
    return [
        'path' => trailingslashit($uploads['basedir']) . $sub,
        'url'  => trailingslashit($uploads['baseurl']) . $sub,
    ];
}

function ps_temp_token() {
    static $token = '';
    if ($token === '') {
        $token = bin2hex(random_bytes(6));
    }
    return $token;
}

function ps_temp_dir() {
    $root = ps_temp_root();
    if (!is_dir($root['path'])) {
        wp_mkdir_p($root['path']);
    }
    if (!file_exists($root['path'] . '/index.html')) {
        @file_put_contents($root['path'] . '/index.html', '');
    }
    $dir = $root['path'] . '/' . ps_temp_token();
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir;
}

function ps_rmdir_recursive($dir) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            ps_rmdir_recursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** پاک کردن فایل‌های موقت همین درخواست */
function ps_cleanup_temp() {
    $root = ps_temp_root();
    ps_rmdir_recursive($root['path'] . '/' . ps_temp_token());
}

/** پاک کردن پوشه‌های موقت جامانده از درخواست‌های ناتمام قبلی */
function ps_gc_temp() {
    $root = ps_temp_root();
    if (!is_dir($root['path'])) {
        return;
    }
    $ttl = (int) ps_cfg('temp.ttl', 86400);
    if ($ttl < 600) {
        $ttl = 600;
    }
    foreach (scandir($root['path']) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $root['path'] . '/' . $entry;
        if (is_dir($path) && (time() - (int) @filemtime($path)) > $ttl) {
            ps_rmdir_recursive($path);
        }
    }
}

function ps_upload_error_message($code) {
    switch ((int) $code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'حجم فایل از حد مجاز سرور (upload_max_filesize = ' . ini_get('upload_max_filesize') . ') بیشتر است؛ لینک بدهید';
        case UPLOAD_ERR_FORM_SIZE:
            return 'حجم فایل از حد مجاز فرم بیشتر است';
        case UPLOAD_ERR_PARTIAL:
            return 'فایل ناقص آپلود شد؛ دوباره تلاش کنید';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'سرور نتوانست فایل آپلودشده را ذخیره کند (پوشه‌ی موقت/دسترسی نوشتن)';
        case UPLOAD_ERR_EXTENSION:
            return 'یک افزونه‌ی PHP آپلود را متوقف کرد';
    }
    return 'خطای آپلود (کد ' . (int) $code . ')';
}

/** پسوندهای مجاز هر دسته */
function ps_kind_extensions(array $kinds) {
    $map = [
        'audio' => ['mp3'],
        'video' => ['mp4'],
        'image' => ['jpg', 'jpeg', 'png', 'webp'],
    ];
    $out = [];
    foreach ($kinds as $kind) {
        if (isset($map[$kind])) {
            $out = array_merge($out, $map[$kind]);
        }
    }
    return $out;
}

/** نوع واقعی فایل از روی بایت‌های اول (نه پسوند) */
function ps_sniff($path) {
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

function ps_sniff_matches($sniff, array $kinds) {
    $map = [
        'audio' => ['mp3'],
        'video' => ['mp4'],
        'image' => ['jpg', 'png', 'webp'],
    ];
    foreach ($kinds as $kind) {
        if (isset($map[$kind]) && in_array($sniff, $map[$kind], true)) {
            return true;
        }
    }
    return false;
}

/**
 * انتقال فایل آپلودشده‌ی فیلد $field به پوشه‌ی موقت
 * خروجی: ['ok', 'has', 'url', 'path', 'name', 'size', 'error']
 */
function ps_stage_upload($field, array $kinds, $label) {
    $none = ['ok' => true, 'has' => false, 'url' => '', 'path' => '', 'name' => '', 'size' => 0, 'error' => ''];
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['error']) || is_array($_FILES[$field]['error'])) {
        return $none;
    }
    $file = $_FILES[$field];
    if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $none;
    }

    $fail = function ($message) use ($none) {
        return array_merge($none, ['ok' => false, 'error' => $message]);
    };

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        return $fail($label . ': ' . ps_upload_error_message($file['error']));
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return $fail($label . ': فایل آپلودشده معتبر نیست');
    }

    $original = (string) $file['name'];
    $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed  = ps_kind_extensions($kinds);
    if (!in_array($ext, $allowed, true)) {
        return $fail($label . ': پسوند «' . $ext . '» مجاز نیست (مجاز: ' . implode('، ', $allowed) . ')');
    }

    $sniff = ps_sniff($file['tmp_name']);
    if (!ps_sniff_matches($sniff, $kinds)) {
        return $fail($label . ': محتوای فایل با پسوند آن نمی‌خواند' . ($sniff === 'html' ? ' (فایل HTML است)' : ''));
    }
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

    $dir  = ps_temp_dir();
    $base = sanitize_file_name(pathinfo($original, PATHINFO_FILENAME));
    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', remove_accents($base));
    $base = trim((string) $base, '-');
    if ($base === '') {
        $base = $field;
    }
    $name = $base . '.' . $ext;
    $path = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        return $fail($label . ': انتقال فایل به پوشه‌ی موقت ناموفق بود');
    }
    @chmod($path, 0644);

    $root = ps_temp_root();
    return [
        'ok'    => true,
        'has'   => true,
        'url'   => $root['url'] . '/' . ps_temp_token() . '/' . rawurlencode($name),
        'path'  => $path,
        'name'  => $name,
        'size'  => (int) filesize($path),
        'error' => '',
    ];
}

/**
 * منبع یک فایل: اگر فایل آپلود شده باشد همان (آدرس موقت)، وگرنه لینک واردشده
 * خروجی: ['url', 'from' => file|link|'', 'size', 'error']
 */
function ps_source($url_field, $file_field, array $kinds, $label) {
    $upload = ps_stage_upload($file_field, $kinds, $label);
    if (!$upload['ok']) {
        return ['url' => '', 'from' => '', 'size' => 0, 'error' => $upload['error']];
    }
    if ($upload['has']) {
        return ['url' => $upload['url'], 'from' => 'file', 'size' => $upload['size'], 'error' => ''];
    }

    $raw = trim((string) ps_in($url_field));
    if ($raw === '') {
        return ['url' => '', 'from' => '', 'size' => 0, 'error' => ''];
    }
    if (!ps_valid_url($raw)) {
        return ['url' => '', 'from' => '', 'size' => 0, 'error' => $label . ': لینک معتبر نیست (باید با http:// یا https:// شروع شود)'];
    }
    return ['url' => $raw, 'from' => 'link', 'size' => 0, 'error' => ''];
}

/* =======================================================
 |  گزارش
 * ====================================================== */

function ps_report_new() {
    return [
        'ok'        => true,
        'post_id'   => 0,
        'title'     => '',
        'link'      => '',
        'edit_link' => '',
        'rows'      => [],
        'links'     => [],
        'errors'    => [],
    ];
}

/** یک سطر گزارش؛ status: ok | warn | error */
function ps_row(array &$report, $label, $value, $status = 'ok') {
    $report['rows'][] = ['label' => (string) $label, 'value' => (string) $value, 'status' => $status];
    if ($status === 'error') {
        $report['ok'] = false;
    }
}

function ps_fail(array &$report, $message) {
    $report['ok']       = false;
    $report['errors'][] = (string) $message;
    return $report;
}

function ps_format_size($bytes) {
    $bytes = (float) $bytes;
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return (int) $bytes . ' B';
}

/* =======================================================
 |  ساخت پست — مراحل مشترک
 * ====================================================== */

/**
 * عنوان، نامک، نام فایل‌ها و پوشه‌ی هاست دانلود — همان قاعده‌های ربات
 */
/**
 * نام‌های انگلیسی برای نام فایل/پوشه و اسلاگ لازم‌اند؛ باید حرف لاتین داشته باشند
 * خروجی: '' یا متن خطا
 */
function ps_check_english($artist_en, $song_en) {
    if ($artist_en === '' || $song_en === '') {
        return 'نام انگلیسی خواننده و نام انگلیسی اثر الزامی است (برای نام فایل، پوشه‌ی هاست دانلود و آدرس پست).';
    }
    if (!preg_match('/[A-Za-z]/', $artist_en) || !preg_match('/[A-Za-z]/', $song_en)) {
        return 'نام‌های انگلیسی باید با حروف لاتین نوشته شوند (مثال: Mohsen Yeganeh / Sarnevesht).';
    }
    return '';
}

function ps_names(array $type, $artist, $song, $artist_en, $song_en) {
    $vars = [
        '{artist}'    => $artist,
        '{song}'      => $song,
        '{artist_en}' => $artist_en,
        '{song_en}'   => $song_en,
        '{label}'     => $type['label'],
    ];
    $template = !empty($type['title']) ? (string) $type['title'] : 'دانلود {label} {artist} {song}';

    return [
        'title'     => trim(preg_replace('/\s+/u', ' ', strtr($template, $vars))),
        'slug'      => trim("{$artist_en} {$song_en}"),
        'alt'       => trim("{$type['label']} {$artist} {$song}"),
        'file_base' => bm_dl_file_name("{$artist_en} - {$song_en}"),
        'img_name'  => bm_safe_file_name("{$artist_en} {$song_en}"),
        'dl_dir'    => bm_download_dir(!empty($type['dir']) ? $type['dir'] : 'music', $artist_en),
    ];
}

function ps_keywords(array $type, $artist, $song, $artist_en, $song_en) {
    return array_values(array_unique(array_filter([
        trim("دانلود {$type['label']} {$artist} {$song}"),
        trim("{$artist} {$song}"),
        ($artist_en !== $artist || $song_en !== $song) ? trim("{$artist_en} {$song_en}") : '',
    ])));
}

/** لینک آرشیو نوع (دسته‌ی اصلی) برای لینک اول متن پست */
function ps_type_archive_link(array $type) {
    $categories = isset($type['categories']) ? (array) $type['categories'] : [];
    foreach ($categories as $category_id) {
        $link = get_category_link((int) $category_id);
        if (is_string($link) && $link !== '') {
            return $link;
        }
    }
    return ps_site_url();
}

/**
 * متن پست — همان ساختار ربات (bm_build_post_content) با برچسب نوع
 */
function ps_build_content(array $type, array $args) {
    $artist    = isset($args['artist']) ? $args['artist'] : '';
    $song      = isset($args['song']) ? $args['song'] : '';
    $artist_en = isset($args['artist_en']) ? $args['artist_en'] : '';
    $song_en   = isset($args['song_en']) ? $args['song_en'] : '';
    $info      = isset($args['info']) ? bm_clean_text($args['info']) : '';
    $artists   = (isset($args['artists']) && is_array($args['artists'])) ? $args['artists'] : [];

    $label_fa = isset($type['content']['fa']) ? $type['content']['fa'] : 'دانلود ' . $type['label'] . ' جدید';
    $label_en = isset($type['content']['en']) ? $type['content']['en'] : 'Download New Music';

    $first_html = '<a href="' . esc_url(ps_type_archive_link($type)) . '"><strong>' . esc_html($label_fa) . '</strong></a>';

    if (empty($artists)) {
        $artists = [['name' => $artist, 'link' => '']];
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

    $html  = '<p dir="rtl" lang="fa" style="text-align: center;">' . $first_html . ' ' . $singer_html . ' به نام <strong>' . esc_html($song) . '</strong></p>';
    if ($info !== '') {
        $html .= "\n\n" . '<p dir="rtl" lang="fa" style="text-align: center;">' . esc_html($info) . '</p>';
    }
    $html .= "\n\n\n";
    $html .= '<p dir="ltr" lang="en" style="text-align: center;">' . esc_html($label_en) . ' <strong>' . esc_html($artist_en) . '</strong> – <strong>' . esc_html($song_en) . '</strong></p>';
    $html .= "\n" . '<!--more-->';

    return $html;
}

/** آیدی پستی با همین عنوان (برای جلوگیری از پست تکراری) */
function ps_duplicate_post_id($title) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_title = %s AND post_type = 'post'
         AND post_status IN ('publish', 'draft', 'pending', 'future')
         ORDER BY ID DESC LIMIT 1",
        $title
    ));
}

/** نویسنده‌ی پست (مثل ربات) و اجرای ادامه‌ی کار با همان کاربر */
function ps_switch_user() {
    $author_id = bm_resolve_post_author(
        isset($GLOBALS['bot_post_author']) ? $GLOBALS['bot_post_author'] : '',
        isset($GLOBALS['bot_auth_user']) ? $GLOBALS['bot_auth_user'] : ''
    );
    if ($author_id > 0 && get_current_user_id() !== $author_id) {
        wp_set_current_user($author_id);
    }
    return $author_id;
}

function ps_insert_draft($author_id, $title, $slug, $content) {
    return wp_insert_post([
        'post_author'    => $author_id,
        'post_status'    => 'draft',
        'post_type'      => 'post',
        'post_title'     => $title,
        'post_name'      => sanitize_title($slug),
        'post_content'   => $content,
        'comment_status' => 'open',
        'ping_status'    => 'closed',
    ], true);
}

function ps_apply_seo($post_id, $title, $description, array $keywords) {
    $metas = [
        'rank_math_title'         => wp_strip_all_tags($title),
        'rank_math_description'   => wp_strip_all_tags($description),
        'rank_math_focus_keyword' => wp_strip_all_tags(implode(',', $keywords)),
        'rank_math_seo_score'     => '99',
    ];
    foreach ($metas as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }
}

/**
 * دسته‌های انتخاب‌شده در فرم (فقط دسته‌های موجود)؛ اگر چیزی انتخاب نشده بود: پیش‌فرض‌های نوع + extra_categories
 * دسته‌ی اول خروجی = دسته‌ی اصلی (اولین دسته‌ی پیش‌فرض نوع که انتخاب شده باشد)
 */
function ps_selected_categories(array $type) {
    $defaults = array_map('intval', (array) (isset($type['categories']) ? $type['categories'] : []));
    $chosen   = array_map('intval', (array) ps_in('categories', []));

    if (empty(array_filter($chosen))) {
        $chosen = array_merge($defaults, array_map('intval', (array) ps_cfg('extra_categories', [])));
    }

    $valid = [];
    foreach (array_unique($chosen) as $category_id) {
        if ($category_id > 0 && !in_array($category_id, $valid, true)) {
            $term = get_term($category_id, 'category');
            if ($term && !is_wp_error($term)) {
                $valid[] = $category_id;
            }
        }
    }
    if (empty($valid)) {
        return [];
    }

    $primary = $valid[0];
    foreach ($defaults as $default_id) {
        if (in_array($default_id, $valid, true)) {
            $primary = $default_id;
            break;
        }
    }

    return array_merge([$primary], array_values(array_diff($valid, [$primary])));
}

function ps_apply_categories($post_id, array $category_ids) {
    if (empty($category_ids)) {
        return 'هیچ دسته‌ای ثبت نشد';
    }
    wp_set_object_terms($post_id, $category_ids, 'category', false);
    update_post_meta($post_id, 'rank_math_primary_category', (int) $category_ids[0]);

    $names = [];
    foreach ($category_ids as $category_id) {
        $term    = get_term($category_id, 'category');
        $names[] = ($term && !is_wp_error($term) ? $term->name : '؟') . ' (' . (int) $category_id . ')';
    }
    return implode('، ', $names) . ' — دسته‌ی اصلی: ' . (int) $category_ids[0];
}

/** ترم‌های هنرمند با همان منطق ربات (taxonomy از config، جداسازی چند هنرمند) */
function ps_artist_terms($artist, $artist_en) {
    $taxonomy = bm_artist_taxonomy(isset($GLOBALS['bot_artist_taxonomy']) ? $GLOBALS['bot_artist_taxonomy'] : '');
    $split    = !isset($GLOBALS['bot_split_artists']) || !empty($GLOBALS['bot_split_artists']);
    $terms    = bm_get_artist_terms($artist, $artist_en, $taxonomy, $split);
    if (!isset($terms['taxonomy'])) {
        $terms['taxonomy'] = $taxonomy;
    }
    if (!isset($terms['artists']) || !is_array($terms['artists'])) {
        $terms['artists'] = [];
    }
    return $terms;
}

/** ثبت ترم‌های هنرمند روی پست؛ خروجی: ['text', 'status'] برای گزارش */
function ps_apply_artists($post_id, array $terms) {
    $taxonomy = isset($terms['taxonomy']) ? (string) $terms['taxonomy'] : '';
    if ($taxonomy === '') {
        return ['text' => 'ثبت نشد — taxonomy هنرمند (artist/singer) در سایت پیدا نشد', 'status' => 'warn'];
    }

    $ids   = [];
    $parts = [];
    foreach ($terms['artists'] as $one) {
        $ids[]   = !empty($one['id']) ? (int) $one['id'] : (string) $one['name'];
        $parts[] = $one['name'] . (!empty($one['id']) ? ' (ترم ' . (int) $one['id'] . ')' : ' (ترم جدید)');
    }
    if (!empty($ids)) {
        wp_set_object_terms($post_id, $ids, $taxonomy, false);
    }

    return [
        'text'   => implode('، ', $parts) . ' — taxonomy ' . $taxonomy . (count($parts) > 1 ? '، ' . count($parts) . ' هنرمند جدا' : ''),
        'status' => 'ok',
    ];
}

/** ذخیره‌ی متاها (مقدارهای خالی رد می‌شوند) */
function ps_apply_metas($post_id, array $metas) {
    foreach ($metas as $key => $value) {
        if ($value === '' || $value === null || $value === []) {
            continue;
        }
        update_post_meta($post_id, $key, $value);
    }
}

/** متاهای ثابت این نوع از config (metas) — مثل ty_content_type = video */
function ps_type_metas(array $type) {
    $metas = [];
    if (!empty($type['metas']) && is_array($type['metas'])) {
        foreach ($type['metas'] as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key !== '' && is_scalar($value)) {
                $metas[$key] = (string) $value;
            }
        }
    }
    return $metas;
}

/**
 * پیدا کردن فیلد انتخابی «نوع اثر» در ACF: فیلدی که گزینه‌هایش دست‌کم با دو نوع مختلف
 * (آهنگ/ویدیو/آلبوم/...) جور دربیاید. خروجی: ['name' => نام فیلد, 'map' => [نوع => کلید گزینه]] یا null
 */
function ps_detect_acf_type_field($post_id) {
    static $cache = [];
    if (array_key_exists($post_id, $cache)) {
        return $cache[$post_id];
    }
    $cache[$post_id] = null;

    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return null;
    }

    $needles = [];
    foreach (ps_types() as $type_key => $type) {
        $needles[$type_key] = array_filter(array_map('strval', (array) (isset($type['acf_match']) ? $type['acf_match'] : [])));
    }

    $groups = acf_get_field_groups(['post_id' => $post_id, 'post_type' => 'post']);
    foreach ((array) $groups as $group) {
        foreach ((array) acf_get_fields($group) as $field) {
            if (empty($field['name']) || empty($field['choices']) || !is_array($field['choices'])
                || !in_array($field['type'], ['select', 'radio', 'button_group'], true)) {
                continue;
            }

            $map = [];
            foreach ($needles as $type_key => $words) {
                foreach ($field['choices'] as $choice_key => $choice_label) {
                    foreach ($words as $word) {
                        $word_lc = mb_strtolower($word, 'UTF-8');
                        if (mb_strtolower((string) $choice_key, 'UTF-8') === $word_lc
                            || mb_strpos(mb_strtolower((string) $choice_label, 'UTF-8'), $word_lc) !== false) {
                            $map[$type_key] = (string) $choice_key;
                            continue 3;
                        }
                    }
                }
            }

            if (count(array_unique($map)) >= 2) {
                $cache[$post_id] = ['name' => (string) $field['name'], 'map' => $map];
                return $cache[$post_id];
            }
        }
    }

    return null;
}

/** فیلد ACF با این نام در گروه‌های پست (null اگر ACF نباشد یا فیلد پیدا نشود) */
function ps_acf_find_field($post_id, $name) {
    static $cache = [];
    $cache_key = $post_id . '|' . $name;
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }
    $cache[$cache_key] = null;

    if ($name === '' || !function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return null;
    }
    $groups = acf_get_field_groups(['post_id' => $post_id, 'post_type' => 'post']);
    foreach ((array) $groups as $group) {
        foreach ((array) acf_get_fields($group) as $field) {
            if (!empty($field['name']) && $field['name'] === $name && !empty($field['key'])) {
                $cache[$cache_key] = $field;
                return $field;
            }
        }
    }
    return null;
}

/** گزینه‌ای از $choices که کلید یا برچسبش با یکی از کلمه‌ها بخواند؛ null اگر نباشد */
function ps_acf_match_choice(array $choices, array $words) {
    foreach ($words as $word) {
        $word_lc = mb_strtolower(trim((string) $word), 'UTF-8');
        if ($word_lc === '') {
            continue;
        }
        foreach ($choices as $choice_key => $choice_label) {
            if (mb_strtolower((string) $choice_key, 'UTF-8') === $word_lc
                || mb_strpos(mb_strtolower((string) $choice_label, 'UTF-8'), $word_lc) !== false) {
                return (string) $choice_key;
            }
        }
    }
    return null;
}

/**
 * مقدار فیلدهای ACF برای این نوع، سوار بر $bot_acf_values (config ربات):
 *   - فیلد «نوع اثر» (acf_type_field، مثل musics_type) = acf_type نوع؛ اگر acf_match داشته باشد و بین
 *     گزینه‌های فیلد گزینه‌ی بهتری باشد همان انتخاب می‌شود
 *   - اگر acf_type_field خالی باشد، فیلد نوع اثر خودکار پیدا می‌شود (ps_detect_acf_type_field)
 *   - acf: مقدارهای ثابت دیگر
 * خروجی: [overrides, توضیح برای گزارش]
 */
function ps_acf_overrides(array $type, $post_id) {
    $overrides = isset($GLOBALS['bot_acf_values']) && is_array($GLOBALS['bot_acf_values']) ? $GLOBALS['bot_acf_values'] : [];
    if (!empty($type['acf']) && is_array($type['acf'])) {
        $overrides = array_merge($overrides, $type['acf']);
    }

    $notes      = [];
    $field_name = (string) ps_cfg('acf_type_field', '');
    $value      = isset($type['acf_type']) ? (string) $type['acf_type'] : '';
    $words      = isset($type['acf_match']) ? array_filter(array_map('strval', (array) $type['acf_match'])) : [];

    if ($field_name !== '') {
        $field   = ps_acf_find_field($post_id, $field_name);
        $choices = ($field && !empty($field['choices']) && is_array($field['choices'])) ? $field['choices'] : [];

        if (!empty($words) && !empty($choices)) {
            $match = ps_acf_match_choice($choices, $words);
            if ($match !== null && $match !== $value) {
                $notes[] = 'گزینه‌ی مخصوص «' . $type['label'] . '» (' . $match . ') انتخاب شد';
                $value   = $match;
            }
        }
        if ($value !== '') {
            $overrides[$field_name] = $value;
            array_unshift($notes, 'نوع اثر: ' . $field_name . ' = ' . $value);
            if (!$field) {
                $notes[] = 'فیلد ' . $field_name . ' در ACF پیدا نشد؛ به‌صورت متا ذخیره می‌شود';
            } elseif (!empty($choices) && !array_key_exists($value, $choices)) {
                $notes[] = 'توجه: ' . $value . ' بین گزینه‌های فیلد نیست (' . implode(', ', array_keys($choices)) . ')';
            }
        }
    } else {
        $detected = ps_detect_acf_type_field($post_id);
        if ($detected && isset($detected['map'][$type['key']])) {
            $overrides[$detected['name']] = $detected['map'][$type['key']];
            $notes[] = 'نوع اثر: ' . $detected['name'] . ' = ' . $detected['map'][$type['key']];
        } elseif ($detected) {
            $notes[] = 'گزینه‌ای برای «' . $type['label'] . '» در فیلد ' . $detected['name'] . ' پیدا نشد (گزینه‌ها: ' . implode(', ', array_unique($detected['map'])) . ')';
        } elseif ($value !== '') {
            $notes[] = 'فیلد «نوع اثر» پیدا نشد (acf_type_field خالی است)';
        }
    }

    return [$overrides, implode(' — ', $notes)];
}

/**
 * ذخیره‌ی فیلد تکرارشونده‌ی ACF (مثل قطعه‌های آلبوم)
 * $rows = [['song_names' => ..., 'albumlink320' => ..., 'albumlink128' => ...], ...]
 * اول متاهای خام نوشته می‌شوند ({name} = تعداد، {name}_{i}_{sub} = مقدار) تا بدون ACF هم کار کند،
 * بعد اگر فیلد در ACF باشد update_field با کلید فیلد صدا زده می‌شود تا مرجع‌های _{name}_{i}_{sub} هم ثبت شوند.
 * خروجی: ['ok' => bool, 'text' => توضیح]
 */
function ps_acf_update_repeater($post_id, $name, array $rows) {
    $rows = array_values($rows);
    if ($name === '') {
        return ['ok' => false, 'text' => 'نام فیلد تکرارشونده تنظیم نشده'];
    }

    update_post_meta($post_id, $name, count($rows));
    foreach ($rows as $i => $row) {
        foreach ((array) $row as $sub => $value) {
            update_post_meta($post_id, "{$name}_{$i}_{$sub}", $value);
        }
    }

    $field = ps_acf_find_field($post_id, $name);
    if (!$field || !function_exists('update_field')) {
        return ['ok' => false, 'text' => 'فیلد ACF «' . $name . '» پیدا نشد؛ متاهای قطعه‌ها مستقیم ذخیره شدند'];
    }

    $sub_names = [];
    foreach ((array) (isset($field['sub_fields']) ? $field['sub_fields'] : []) as $sub) {
        if (!empty($sub['name'])) {
            $sub_names[] = (string) $sub['name'];
        }
    }
    $missing = !empty($rows) ? array_diff(array_keys((array) $rows[0]), $sub_names) : [];

    update_field($field['key'], $rows, $post_id);

    $text = 'فیلد تکرارشونده‌ی ' . $name . ' (' . $field['key'] . ') با ' . count($rows) . ' سطر ذخیره شد';
    if (!empty($missing)) {
        $text .= ' — زیرفیلد پیدا نشد: ' . implode(', ', $missing) . ' (نام‌ها را در config بررسی کنید)';
    }
    return ['ok' => empty($missing), 'text' => $text];
}

/**
 * همگام‌سازی ACF مثل ربات (مرجع‌گذاری متاها + پیش‌فرض‌های فرم ویرایش + مقدارهای این نوع)
 * اگر ACF فعال نباشد، مقدارهای نوع به‌صورت متای ساده ذخیره می‌شوند. خروجی: متن گزارش
 */
function ps_acf_sync($post_id, array $type) {
    list($overrides, $note) = ps_acf_overrides($type, $post_id);
    $result = bm_acf_sync_post_fields($post_id, $overrides);
    if (!is_array($result)) {
        ps_apply_metas($post_id, $overrides);
        $pairs = [];
        foreach ($overrides as $key => $value) {
            $pairs[] = $key . '=' . (is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE));
        }
        return 'ACF فعال نیست؛ مقدارها به‌صورت متا ذخیره شدند' . (!empty($pairs) ? ' (' . implode(', ', $pairs) . ')' : '');
    }
    return ps_acf_report_text($result, $note);
}

function ps_acf_report_text($acf_sync, $note) {
    if (!is_array($acf_sync)) {
        return 'ACF فعال نیست';
    }
    $filled = [];
    foreach ($acf_sync['filled'] as $name => $value) {
        $filled[] = $name . '=' . (is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE));
    }
    return count($acf_sync['referenced']) . ' فیلد مرجع‌گذاری شد، ' . count($acf_sync['filled']) . ' فیلد مثل فرم ویرایش ذخیره شد'
        . (!empty($filled) ? ' (' . implode(', ', $filled) . ')' : '')
        . ($note !== '' ? ' — ' . $note : '');
}

/** تصویر شاخص از آدرس (لینک یا فایل موقت) — کاور مربعی حداکثر $bot_cover_size */
function ps_featured_image($post_id, $url, $img_name, $title, $alt) {
    if ($url === '') {
        return ['success' => false, 'message' => 'کاوری داده نشد', 'id' => 0];
    }
    $result = bm_upload_featured_image_from_url(
        $url,
        $post_id,
        $img_name,
        $title,
        $alt,
        $title,
        [],
        isset($GLOBALS['bot_cover_size']) ? (int) $GLOBALS['bot_cover_size'] : 1080
    );
    update_post_meta($post_id, '_bm_featured_image_result', maybe_serialize($result));
    return $result;
}

/* =======================================================
 |  هاست دانلود
 * ====================================================== */

/**
 * ارسال درخواست به گیرنده‌ی هاست دانلود با یوزر/رمز ورود همین کاربر
 * خروجی: success/code/error/body + json (اگر گیرنده JSON برگرداند)
 */
function ps_dl_request($endpoint, array $fields) {
    $userpwd = bm_auth_userpwd();
    if ($userpwd === '') {
        return ['success' => false, 'code' => 0, 'error' => 'یوزر/رمز اتصال به هاست دانلود در دسترس نیست', 'body' => '', 'json' => null];
    }
    if ($endpoint === '') {
        return ['success' => false, 'code' => 0, 'error' => 'آدرس گیرنده‌ی هاست دانلود تنظیم نشده ($urldlhost)', 'body' => '', 'json' => null];
    }

    $timeout = (int) ps_cfg('dl.timeout', 1500);
    if ($timeout < 60) {
        $timeout = 60;
    }

    $response = bm_curl_post_form($endpoint, $fields, $timeout, $userpwd);
    $body     = (string) $response['body'];
    $json     = json_decode($body, true);
    if (!is_array($json) && ($brace = strpos($body, '{')) !== false) {
        // اگر اخطاری قبل از JSON چاپ شده باشد، از اولین آکولاد به بعد را می‌خوانیم
        $json = json_decode(substr($body, $brace), true);
    }
    $response['json'] = is_array($json) ? $json : null;

    if ($response['json'] !== null && array_key_exists('ok', $response['json']) && !$response['json']['ok']) {
        $response['success'] = false;
    }
    return $response;
}

/** متن خطای هاست دانلود برای گزارش */
function ps_dl_error_text(array $response) {
    $text = 'HTTP ' . (int) $response['code'];
    if (!empty($response['error'])) {
        $text .= ' | ' . $response['error'];
    }
    if ((int) $response['code'] === 401) {
        $text .= ' | هاست دانلود یوزر/رمز را نپذیرفت (config.php هر دو هاست باید یکی باشد)';
    }
    if ((int) $response['code'] === 404) {
        $text .= ' | فایل گیرنده روی هاست دانلود پیدا نشد (video.php / album.php / leech.php آپلود شده؟)';
    }
    if (!empty($response['json']['error'])) {
        $text .= ' | ' . $response['json']['error'];
    } elseif (!empty($response['json']['errors']) && is_array($response['json']['errors'])) {
        $text .= ' | ' . implode(' ; ', array_map('strval', $response['json']['errors']));
    } elseif (!empty($response['body']) && $response['json'] === null) {
        $text .= ' | ' . mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $response['body']))), 0, 250);
    }
    return $text;
}

/** ذخیره‌ی نتیجه‌ی هاست دانلود روی پست (مثل ربات) */
function ps_store_dl_response($post_id, array $response) {
    update_post_meta($post_id, '_bm_download_response', maybe_serialize([
        'success' => $response['success'],
        'code'    => $response['code'],
        'error'   => $response['error'],
        'body'    => mb_substr((string) $response['body'], 0, 4000),
    ]));
}

/** سطر گزارش برای یک فایل برگشتی از گیرنده‌ی JSON */
function ps_dl_file_text(array $file) {
    if (!empty($file['ok'])) {
        return (isset($file['name']) ? $file['name'] : '') . ' (' . ps_format_size(isset($file['size']) ? $file['size'] : 0) . ')';
    }
    return (isset($file['name']) ? $file['name'] : '') . ' — خطا: ' . (isset($file['error']) ? $file['error'] : 'نامشخص');
}

/* =======================================================
 |  پایان کار: انتشار یا پیش‌نویس
 * ====================================================== */

/**
 * اگر کاربر انتشار خواسته و فایل‌ها سالم منتقل شده‌اند پست منتشر می‌شود (برای تک‌آهنگ، رسیدن دست‌کم یکی از دو کیفیت کافی است)؛
 * اگر هیچ فایلی منتقل نشد پست پیش‌نویس می‌ماند تا بعد از رفع مشکل از پیشخوان منتشر شود.
 */
function ps_finish(array &$report, $post_id, $want_publish, $files_ok) {
    $report['link']      = get_permalink($post_id);
    $report['edit_link'] = get_edit_post_link($post_id, 'raw');

    if (!$want_publish) {
        ps_row($report, 'وضعیت', 'پیش‌نویس (طبق انتخاب شما)', 'warn');
        return;
    }
    if (!$files_ok) {
        ps_row($report, 'وضعیت', 'پیش‌نویس ماند — انتقال فایل‌ها به هاست دانلود انجام نشد؛ بعد از رفع مشکل از پیشخوان منتشر کنید', 'error');
        return;
    }

    $result = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
    if (is_wp_error($result)) {
        ps_row($report, 'وضعیت', 'انتشار ناموفق — ' . $result->get_error_message(), 'error');
        return;
    }
    $report['link'] = get_permalink($post_id);
    ps_row($report, 'وضعیت', 'منتشر شد', 'ok');
}

/* =======================================================
 |  ماژول تک‌آهنگ (آهنگ / ریمیکس / مداحی / پادکست)
 * ====================================================== */

function ps_run_single() {
    $report = ps_report_new();

    $type = ps_type(ps_text('type'));
    if (!$type || $type['module'] !== 'single') {
        return ps_fail($report, 'نوع اثر نامعتبر است.');
    }
    $want_publish = ps_in('post_status') !== 'draft';

    $artist    = ps_text('artist');
    $song      = ps_text('song');
    $artist_en = ps_text('artist_en');
    $song_en   = ps_text('song_en');
    if ($artist === '' || $song === '') {
        return ps_fail($report, 'نام خواننده و نام اثر (فارسی) الزامی است.');
    }
    $english_error = ps_check_english($artist_en, $song_en);
    if ($english_error !== '') {
        return ps_fail($report, $english_error);
    }
    $names = ps_names($type, $artist, $song, $artist_en, $song_en);

    $cover = ps_source('cover_url', 'cover_file', ['image'], 'کاور');
    $m320  = ps_source('music320_url', 'music320_file', ['audio'], 'فایل 320');
    $m128  = ps_source('music128_url', 'music128_file', ['audio'], 'فایل 128');
    foreach ([$cover, $m320, $m128] as $source) {
        if ($source['error'] !== '') {
            return ps_fail($report, $source['error']);
        }
    }
    if ($m320['url'] === '' && $m128['url'] === '') {
        return ps_fail($report, 'حداقل یکی از فایل‌های 320 یا 128 (لینک یا آپلود) لازم است.');
    }

    if (!ps_in('allow_duplicate')) {
        $duplicate = ps_duplicate_post_id($names['title']);
        if ($duplicate) {
            return ps_fail($report, 'پستی با همین عنوان وجود دارد (ID ' . $duplicate . '): ' . get_permalink($duplicate) . ' — برای ثبت دوباره گزینه‌ی «اجازه‌ی عنوان تکراری» را بزنید.');
        }
    }
    if (bm_auth_userpwd() === '') {
        return ps_fail($report, 'یوزر/رمز اتصال به هاست دانلود در دسترس نیست.');
    }

    $lyrics = ps_lines('music_text');
    $crew   = [
        'composer'  => ps_text('composer'),
        'arranger'  => ps_text('arranger'),
        'lyricist'  => ps_text('lyricist'),
        'mixmaster' => ps_text('mixmaster'),
        'guitar'    => ps_text('guitar'),
        'publisher' => ps_text('publisher'),
    ];
    $genre = ps_text('genre');
    $mood  = ps_text('mood');

    $terms   = ps_artist_terms($artist, $artist_en);
    $content = ps_build_content($type, [
        'artist'    => $artist,
        'song'      => $song,
        'artist_en' => $artist_en,
        'song_en'   => $song_en,
        'artists'   => $terms['artists'],
        'info'      => ps_text('info'),
    ]);

    $author_id = ps_switch_user();
    $post_id   = ps_insert_draft($author_id, $names['title'], $names['slug'], $content);
    if (is_wp_error($post_id) || !$post_id) {
        return ps_fail($report, 'خطا در ایجاد پست: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'نامشخص'));
    }
    $report['post_id'] = (int) $post_id;
    $report['title']   = $names['title'];
    ps_row($report, 'پست', 'ID ' . (int) $post_id . ' — ' . $names['title']);
    $author = get_userdata($author_id);
    ps_row($report, 'نویسنده', $author ? $author->display_name . ' (' . $author->user_login . ' — ID ' . $author_id . ')' : 'ID ' . $author_id);

    $description = 'دانلود ' . $type['label'] . " جدید {$artist} به نام {$song} با کیفیت 320 و 128، پخش آنلاین" . ($lyrics !== '' ? ' و متن ترانه' : '');
    ps_apply_seo($post_id, $names['title'], $description, ps_keywords($type, $artist, $song, $artist_en, $song_en));

    ps_row($report, 'دسته‌ها', ps_apply_categories($post_id, ps_selected_categories($type)));
    $artist_result = ps_apply_artists($post_id, $terms);
    ps_row($report, 'هنرمند', $artist_result['text'], $artist_result['status']);
    bm_set_term_if_not_empty($post_id, $mood, 'mood', false);
    bm_set_term_if_not_empty($post_id, $genre, 'genre', false);

    $host    = ps_dl_host();
    $file320 = $names['file_base'] . ' [320].mp3';
    $file128 = $names['file_base'] . ' [128].mp3';
    $url320  = $m320['url'] !== '' ? $host . $names['dl_dir'] . '/' . $file320 : '';
    $url128  = $m128['url'] !== '' ? $host . $names['dl_dir'] . '/' . $file128 : '';
    $online  = $url128 !== '' ? $url128 : $url320;

    ps_apply_metas($post_id, [
        'music320'      => $url320 !== '' ? esc_url_raw($url320) : '',
        'music128'      => esc_url_raw($online),
        'online'        => esc_url_raw($online),
        'artist'        => $artist,
        'song'          => $song,
        'artist_en'     => $artist_en,
        'song_en'       => $song_en,
        'music_text'    => $lyrics,
        'genre'         => $genre,
        'mood'          => $mood,
        '_postsaz_type' => $type['key'],
        '_postsaz_time' => current_time('mysql'),
    ] + $crew + ps_type_metas($type));

    ps_row($report, 'ACF', ps_acf_sync($post_id, $type));

    $image = ps_featured_image($post_id, $cover['url'], $names['img_name'], $names['title'], $names['alt']);
    ps_row($report, 'تصویر شاخص', $image['message'], $image['success'] ? 'ok' : ($cover['url'] === '' ? 'warn' : 'error'));

    $response = ps_dl_request(ps_dl_endpoint('single'), [
        'music320'  => $m320['url'],
        'music128'  => $m128['url'],
        'artist_en' => $artist_en,
        'song_en'   => $song_en,
        'image'     => $cover['url'],
        'mp3_name'  => $names['file_base'],
        'file_320'  => $file320,
        'file_128'  => $file128,
        'dir_file'  => $names['dl_dir'],
    ]);
    ps_store_dl_response($post_id, $response);

    ps_row($report, 'پوشه‌ی هاست دانلود', $names['dl_dir']);
    $files_ok = (bool) $response['success'];
    if ($files_ok) {
        bm_set_download_state($post_id, 'ok');
        ps_row($report, 'انتقال فایل‌ها', 'انجام شد' . ($m320['url'] !== '' ? ' — 320' : '') . ($m128['url'] !== '' ? ' — 128' : ''));
    } else {
        // فقط یکی از دو کیفیت رسیده؟ (مثلاً لینک 320 مبدأ خراب بود) → همان کافی است: لینک کیفیت نرسیده از پست برداشته
        // و پست با کیفیت موجود منتشر می‌شود (همان قاعده‌ی ربات؛ bm_sync_music_metas در bot/script.php)
        $landed = is_array($response['json']) && !empty($response['json']['files']) && is_array($response['json']['files'])
            ? bm_dl_landed(['files' => $response['json']['files']])
            : null;
        $have = ['320' => is_array($landed) && $landed['320'] === true, '128' => is_array($landed) && $landed['128'] === true];
        if ($have['320'] || $have['128']) {
            $sync     = bm_sync_music_metas($post_id, $have, ['320' => $url320, '128' => $url128]);
            $files_ok = $sync['usable'];
            bm_set_download_state($post_id, 'partial', $sync['label'] . ' — ' . ps_dl_error_text($response));
            ps_row($report, 'انتقال فایل‌ها', 'ناقص — ' . $sync['label'] . ' روی هاست نشست و پست با همان قابل استفاده است: ' . ps_dl_error_text($response)
                . (!empty($sync['changes']) ? ' — ' . implode('؛ ', $sync['changes']) : ''), 'warn');
        } else {
            bm_set_download_state($post_id, 'failed');
            ps_row($report, 'انتقال فایل‌ها', 'ناموفق: ' . ps_dl_error_text($response), 'error');
        }
    }
    // لینک‌های گزارش از روی متاهای نهایی پست (کیفیتی که نرسیده، لینک ندارد)
    $final320 = (string) get_post_meta($post_id, 'music320', true);
    $final128 = (string) get_post_meta($post_id, 'music128', true);
    if ($final320 !== '') {
        $report['links'][] = ['label' => 'MP3 320', 'url' => $final320];
    }
    if ($final128 !== '' && $final128 !== $final320) {
        $report['links'][] = ['label' => 'MP3 128', 'url' => $final128];
    }

    ps_finish($report, $post_id, $want_publish, $files_ok);
    return $report;
}

/* =======================================================
 |  ماژول موزیک ویدیو
 * ====================================================== */

function ps_run_video() {
    $report = ps_report_new();

    $type = ps_module_type('video');
    if (!$type) {
        return ps_fail($report, 'نوع «موزیک ویدیو» در config تعریف نشده است.');
    }
    $want_publish = ps_in('post_status') !== 'draft';

    $artist    = ps_text('artist');
    $song      = ps_text('song');
    $artist_en = ps_text('artist_en');
    $song_en   = ps_text('song_en');
    if ($artist === '' || $song === '') {
        return ps_fail($report, 'نام خواننده و نام اثر (فارسی) الزامی است.');
    }
    $english_error = ps_check_english($artist_en, $song_en);
    if ($english_error !== '') {
        return ps_fail($report, $english_error);
    }
    $names = ps_names($type, $artist, $song, $artist_en, $song_en);

    $cover = ps_source('cover_url', 'cover_file', ['image'], 'کاور');
    if ($cover['error'] !== '') {
        return ps_fail($report, $cover['error']);
    }

    $qualities = array_map('strval', (array) ps_cfg('video.qualities', ['1080', '720', '480']));
    $videos    = [];
    foreach ($qualities as $quality) {
        $source = ps_source("video{$quality}_url", "video{$quality}_file", ['video'], "ویدیو {$quality}p");
        if ($source['error'] !== '') {
            return ps_fail($report, $source['error']);
        }
        if ($source['url'] !== '') {
            $videos[$quality] = $source;
        }
    }
    if (empty($videos)) {
        return ps_fail($report, 'حداقل یک کیفیت ویدیو (لینک یا آپلود) لازم است.');
    }

    if (!ps_in('allow_duplicate')) {
        $duplicate = ps_duplicate_post_id($names['title']);
        if ($duplicate) {
            return ps_fail($report, 'پستی با همین عنوان وجود دارد (ID ' . $duplicate . '): ' . get_permalink($duplicate) . ' — برای ثبت دوباره گزینه‌ی «اجازه‌ی عنوان تکراری» را بزنید.');
        }
    }
    if (bm_auth_userpwd() === '') {
        return ps_fail($report, 'یوزر/رمز اتصال به هاست دانلود در دسترس نیست.');
    }

    $lyrics = ps_lines('music_text');
    $crew   = [
        'composer'  => ps_text('composer'),
        'arranger'  => ps_text('arranger'),
        'lyricist'  => ps_text('lyricist'),
        'mixmaster' => ps_text('mixmaster'),
        'publisher' => ps_text('publisher'),
    ];

    $terms   = ps_artist_terms($artist, $artist_en);
    $content = ps_build_content($type, [
        'artist'    => $artist,
        'song'      => $song,
        'artist_en' => $artist_en,
        'song_en'   => $song_en,
        'artists'   => $terms['artists'],
        'info'      => ps_text('info'),
    ]);

    $author_id = ps_switch_user();
    $post_id   = ps_insert_draft($author_id, $names['title'], $names['slug'], $content);
    if (is_wp_error($post_id) || !$post_id) {
        return ps_fail($report, 'خطا در ایجاد پست: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'نامشخص'));
    }
    $report['post_id'] = (int) $post_id;
    $report['title']   = $names['title'];
    ps_row($report, 'پست', 'ID ' . (int) $post_id . ' — ' . $names['title']);
    $author = get_userdata($author_id);
    ps_row($report, 'نویسنده', $author ? $author->display_name . ' (' . $author->user_login . ' — ID ' . $author_id . ')' : 'ID ' . $author_id);

    $quality_labels = array_map(function ($q) {
        return $q . 'p';
    }, array_keys($videos));
    $description = "دانلود موزیک ویدیو جدید {$artist} به نام {$song} با کیفیت " . implode('، ', $quality_labels) . ' و پخش آنلاین';
    ps_apply_seo($post_id, $names['title'], $description, ps_keywords($type, $artist, $song, $artist_en, $song_en));

    ps_row($report, 'دسته‌ها', ps_apply_categories($post_id, ps_selected_categories($type)));
    $artist_result = ps_apply_artists($post_id, $terms);
    ps_row($report, 'هنرمند', $artist_result['text'], $artist_result['status']);

    $host      = ps_dl_host();
    $meta_map  = (array) ps_cfg('video.meta', []);
    $metas     = [];
    $dl_fields = ['dir_file' => $names['dl_dir'], 'base_name' => $names['file_base']];
    $online    = '';
    foreach ($videos as $quality => $source) {
        $file = $names['file_base'] . " [{$quality}].mp4";
        $url  = $host . $names['dl_dir'] . '/' . $file;
        if (!empty($meta_map[$quality])) {
            $metas[$meta_map[$quality]] = esc_url_raw($url);
        }
        $online                           = $url; // آخرین کیفیت = پایین‌ترین (ترتیب 1080 → 720 → 480)
        $dl_fields["video_{$quality}"]    = $source['url'];
        $dl_fields["file_{$quality}"]     = $file;
        $report['links'][]                = ['label' => "MP4 {$quality}p", 'url' => $url];
    }
    if (!empty($meta_map['online']) && $online !== '') {
        $metas[$meta_map['online']] = esc_url_raw($online);
    }

    ps_apply_metas($post_id, $metas + [
        'artist'        => $artist,
        'song'          => $song,
        'artist_en'     => $artist_en,
        'song_en'       => $song_en,
        'music_text'    => $lyrics,
        '_postsaz_type' => $type['key'],
        '_postsaz_time' => current_time('mysql'),
    ] + $crew + ps_type_metas($type));

    ps_row($report, 'ACF', ps_acf_sync($post_id, $type));

    $image = ps_featured_image($post_id, $cover['url'], $names['img_name'], $names['title'], $names['alt']);
    ps_row($report, 'تصویر شاخص', $image['message'], $image['success'] ? 'ok' : ($cover['url'] === '' ? 'warn' : 'error'));

    $response = ps_dl_request(ps_dl_endpoint('video'), $dl_fields);
    ps_store_dl_response($post_id, $response);

    ps_row($report, 'پوشه‌ی هاست دانلود', $names['dl_dir']);
    if (!empty($response['json']['files']) && is_array($response['json']['files'])) {
        foreach ($response['json']['files'] as $quality => $file) {
            ps_row($report, "ویدیو {$quality}p", ps_dl_file_text((array) $file), !empty($file['ok']) ? 'ok' : 'error');
        }
    }
    if ($response['success']) {
        ps_row($report, 'انتقال فایل‌ها', 'انجام شد');
    } else {
        ps_row($report, 'انتقال فایل‌ها', 'ناموفق: ' . ps_dl_error_text($response), 'error');
    }

    ps_finish($report, $post_id, $want_publish, $response['success']);
    return $report;
}

/* =======================================================
 |  ماژول آلبوم
 * ====================================================== */

/**
 * قطعه‌های واردشده در فرم (ردیف‌های خالی حذف می‌شوند؛ شماره‌گذاری به ترتیب ردیف‌های پر)
 * خروجی: ['tracks' => [...], 'error' => '']
 */
function ps_album_tracks() {
    $max   = (int) ps_cfg('album.max_tracks', 30);
    $fa    = (array) ps_in('track_fa', []);
    $en    = (array) ps_in('track_en', []);
    $u320  = (array) ps_in('track_320', []);
    $u128  = (array) ps_in('track_128', []);
    $count = max(count($fa), count($en), count($u320), count($u128));

    $tracks = [];
    for ($i = 0; $i < $count; $i++) {
        $name_fa = bm_clean_text(isset($fa[$i]) ? $fa[$i] : '');
        $name_en = bm_clean_text(isset($en[$i]) ? $en[$i] : '');
        $url320  = trim((string) (isset($u320[$i]) ? $u320[$i] : ''));
        $url128  = trim((string) (isset($u128[$i]) ? $u128[$i] : ''));

        if ($name_fa === '' && $name_en === '' && $url320 === '' && $url128 === '') {
            continue;
        }
        $row = $i + 1;
        if ($url320 === '' && $url128 === '') {
            return ['tracks' => [], 'error' => "ردیف {$row}: لینک 320 یا 128 قطعه وارد نشده است."];
        }
        if ($name_fa === '' && $name_en === '') {
            return ['tracks' => [], 'error' => "ردیف {$row}: نام قطعه وارد نشده است."];
        }
        if ($url320 !== '' && !ps_valid_url($url320)) {
            return ['tracks' => [], 'error' => "ردیف {$row}: لینک 320 معتبر نیست."];
        }
        if ($url128 !== '' && !ps_valid_url($url128)) {
            return ['tracks' => [], 'error' => "ردیف {$row}: لینک 128 معتبر نیست."];
        }
        $no = count($tracks) + 1;
        // نام فایل روی هاست دانلود همیشه لاتین است؛ اگر نام انگلیسی نباشد «Track NN» می‌شود
        $name_file = preg_match('/[A-Za-z]/', $name_en) ? $name_en : sprintf('Track %02d', $no);
        if ($name_fa === '') {
            $name_fa = $name_en;
        }

        $tracks[] = [
            'no'      => $no,
            'name'    => $name_file,
            'name_fa' => $name_fa,
            'url320'  => $url320,
            'url128'  => $url128,
            'no_en'   => $name_file !== $name_en,
        ];
        if (count($tracks) >= $max) {
            break;
        }
    }

    return ['tracks' => $tracks, 'error' => ''];
}

function ps_run_album() {
    $report = ps_report_new();

    $type = ps_module_type('album');
    if (!$type) {
        return ps_fail($report, 'نوع «آلبوم» در config تعریف نشده است.');
    }
    $want_publish = ps_in('post_status') !== 'draft';

    $artist    = ps_text('artist');
    $song      = ps_text('song');
    $artist_en = ps_text('artist_en');
    $song_en   = ps_text('song_en');
    if ($artist === '' || $song === '') {
        return ps_fail($report, 'نام خواننده و نام آلبوم (فارسی) الزامی است.');
    }
    $english_error = ps_check_english($artist_en, $song_en);
    if ($english_error !== '') {
        return ps_fail($report, $english_error);
    }
    $names = ps_names($type, $artist, $song, $artist_en, $song_en);

    $cover = ps_source('cover_url', 'cover_file', ['image'], 'کاور');
    if ($cover['error'] !== '') {
        return ps_fail($report, $cover['error']);
    }

    $parsed = ps_album_tracks();
    if ($parsed['error'] !== '') {
        return ps_fail($report, $parsed['error']);
    }
    $tracks = $parsed['tracks'];
    if (empty($tracks)) {
        return ps_fail($report, 'حداقل یک قطعه با نام و لینک لازم است.');
    }

    if (!ps_in('allow_duplicate')) {
        $duplicate = ps_duplicate_post_id($names['title']);
        if ($duplicate) {
            return ps_fail($report, 'پستی با همین عنوان وجود دارد (ID ' . $duplicate . '): ' . get_permalink($duplicate) . ' — برای ثبت دوباره گزینه‌ی «اجازه‌ی عنوان تکراری» را بزنید.');
        }
    }
    if (bm_auth_userpwd() === '') {
        return ps_fail($report, 'یوزر/رمز اتصال به هاست دانلود در دسترس نیست.');
    }

    $make_zip = ps_in('make_zip', null) === null ? (bool) ps_cfg('album.zip', true) : (bool) ps_in('make_zip');
    $info     = ps_text('info');

    $terms   = ps_artist_terms($artist, $artist_en);
    $content = ps_build_content($type, [
        'artist'    => $artist,
        'song'      => $song,
        'artist_en' => $artist_en,
        'song_en'   => $song_en,
        'artists'   => $terms['artists'],
        'info'      => $info,
    ]);

    $author_id = ps_switch_user();
    $post_id   = ps_insert_draft($author_id, $names['title'], $names['slug'], $content);
    if (is_wp_error($post_id) || !$post_id) {
        return ps_fail($report, 'خطا در ایجاد پست: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'نامشخص'));
    }
    $report['post_id'] = (int) $post_id;
    $report['title']   = $names['title'];
    ps_row($report, 'پست', 'ID ' . (int) $post_id . ' — ' . $names['title']);
    $author = get_userdata($author_id);
    ps_row($report, 'نویسنده', $author ? $author->display_name . ' (' . $author->user_login . ' — ID ' . $author_id . ')' : 'ID ' . $author_id);

    $description = "دانلود آلبوم جدید {$song} از {$artist} با " . count($tracks) . ' قطعه، کیفیت 320 و 128، پخش آنلاین';
    ps_apply_seo($post_id, $names['title'], $description, ps_keywords($type, $artist, $song, $artist_en, $song_en));

    ps_row($report, 'دسته‌ها', ps_apply_categories($post_id, ps_selected_categories($type)));
    $artist_result = ps_apply_artists($post_id, $terms);
    ps_row($report, 'هنرمند', $artist_result['text'], $artist_result['status']);

    // نام پوشه‌ها و فایل‌ها روی هاست دانلود
    $host      = ps_dl_host();
    $base_url  = $host . $names['dl_dir'] . '/';
    $folder320 = $names['file_base'] . ' [320]';
    $folder128 = $names['file_base'] . ' [128]';
    $zip320    = $names['file_base'] . ' [320].zip';
    $zip128    = $names['file_base'] . ' [128].zip';
    $has320    = false;
    $has128    = false;

    $meta_map  = (array) ps_cfg('album.meta', []);
    $repeater  = (array) ps_cfg('album.repeater', []);
    $sub_name  = !empty($repeater['name']) ? (string) $repeater['name'] : 'song_names';
    $sub_320   = !empty($repeater['link320']) ? (string) $repeater['link320'] : 'albumlink320';
    $sub_128   = !empty($repeater['link128']) ? (string) $repeater['link128'] : 'albumlink128';
    $metas     = [];
    $rows      = [];
    $dl_tracks = [];

    foreach ($tracks as $track) {
        $track_base = sprintf('%02d. ', $track['no']) . bm_dl_file_name("{$artist_en} - {$track['name']}");
        $file320    = $track_base . ' [320].mp3';
        $file128    = $track_base . ' [128].mp3';
        $url320     = $track['url320'] !== '' ? $base_url . $folder320 . '/' . $file320 : '';
        $url128     = $track['url128'] !== '' ? $base_url . $folder128 . '/' . $file128 : '';
        $has320     = $has320 || $url320 !== '';
        $has128     = $has128 || $url128 !== '';

        // یک سطر از فیلد تکرارشونده‌ی قالب؛ اگر قطعه فقط یک کیفیت دارد هر دو لینک به همان فایل اشاره می‌کنند
        $rows[] = [
            $sub_name => $track['name_fa'],
            $sub_320  => esc_url_raw($url320 !== '' ? $url320 : $url128),
            $sub_128  => esc_url_raw($url128 !== '' ? $url128 : $url320),
        ];
        if (!empty($track['no_en'])) {
            ps_row($report, "قطعه {$track['no']}", "نام انگلیسی نداشت؛ نام فایل «{$track['name']}» شد", 'warn');
        }

        $dl_tracks[] = [
            'no'       => $track['no'],
            'name'     => $track['name'],
            'url_320'  => $track['url320'],
            'url_128'  => $track['url128'],
            'file_320' => $file320,
            'file_128' => $file128,
        ];
    }

    if ($has320) {
        if ($make_zip && !empty($meta_map['zip320'])) {
            $metas[$meta_map['zip320']] = esc_url_raw($base_url . $zip320);
            $report['links'][]           = ['label' => 'ZIP 320', 'url' => $base_url . $zip320];
        }
        $report['links'][] = ['label' => 'پوشه‌ی 320', 'url' => $base_url . $folder320 . '/'];
    }
    if ($has128) {
        if ($make_zip && !empty($meta_map['zip128'])) {
            $metas[$meta_map['zip128']] = esc_url_raw($base_url . $zip128);
            $report['links'][]           = ['label' => 'ZIP 128', 'url' => $base_url . $zip128];
        }
        $report['links'][] = ['label' => 'پوشه‌ی 128', 'url' => $base_url . $folder128 . '/'];
    }

    ps_apply_metas($post_id, $metas + [
        'artist'        => $artist,
        'song'          => $song,
        'artist_en'     => $artist_en,
        'song_en'       => $song_en,
        '_postsaz_type' => $type['key'],
        '_postsaz_time' => current_time('mysql'),
    ] + ps_type_metas($type));
    ps_row($report, 'قطعه‌ها', count($tracks) . ' قطعه' . ($has320 ? ' — 320' : '') . ($has128 ? ' — 128' : '') . ($make_zip ? ' — با فایل ZIP' : ''));
    $repeater_result = ps_acf_update_repeater($post_id, !empty($repeater['field']) ? (string) $repeater['field'] : 'album', $rows);
    ps_row($report, 'لیست قطعه‌ها', $repeater_result['text'], $repeater_result['ok'] ? 'ok' : 'warn');

    ps_row($report, 'ACF', ps_acf_sync($post_id, $type));

    $image = ps_featured_image($post_id, $cover['url'], $names['img_name'], $names['title'], $names['alt']);
    ps_row($report, 'تصویر شاخص', $image['message'], $image['success'] ? 'ok' : ($cover['url'] === '' ? 'warn' : 'error'));

    $response = ps_dl_request(ps_dl_endpoint('album'), [
        'dir_file'   => $names['dl_dir'],
        'album_name' => $names['file_base'],
        'artist_en'  => $artist_en,
        'album_en'   => $song_en,
        'cover'      => $cover['url'],
        'folder_320' => $folder320,
        'folder_128' => $folder128,
        'zip'        => $make_zip ? '1' : '0',
        'zip_320'    => $zip320,
        'zip_128'    => $zip128,
        'tracks'     => wp_json_encode($dl_tracks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    ps_store_dl_response($post_id, $response);

    ps_row($report, 'پوشه‌ی هاست دانلود', $names['dl_dir']);
    if (!empty($response['json']['tracks']) && is_array($response['json']['tracks'])) {
        foreach ($response['json']['tracks'] as $track) {
            $track = (array) $track;
            foreach (['320', '128'] as $quality) {
                if (!empty($track[$quality]) && is_array($track[$quality])) {
                    ps_row($report, 'قطعه ' . (int) (isset($track['no']) ? $track['no'] : 0) . " [{$quality}]", ps_dl_file_text($track[$quality]), !empty($track[$quality]['ok']) ? 'ok' : 'error');
                }
            }
        }
    }
    if (!empty($response['json']['zips']) && is_array($response['json']['zips'])) {
        foreach ($response['json']['zips'] as $quality => $zip) {
            ps_row($report, "ZIP {$quality}", ps_dl_file_text((array) $zip), !empty($zip['ok']) ? 'ok' : 'error');
        }
    }
    if ($response['success']) {
        ps_row($report, 'انتقال فایل‌ها', 'انجام شد');
    } else {
        ps_row($report, 'انتقال فایل‌ها', 'ناموفق: ' . ps_dl_error_text($response), 'error');
    }

    ps_finish($report, $post_id, $want_publish, $response['success']);
    return $report;
}

/* =======================================================
 |  ماژول آپلود فایل (لیچ): فقط انتقال فایل به هاست دانلود با تگ ID3 و کاور، بدون ساخت پست
 * ====================================================== */

function ps_run_leech() {
    $report = ps_report_new();

    $folders = ps_dl_folders();
    $folder  = sanitize_key(ps_text('folder'));
    if ($folder === '' || !isset($folders[$folder])) {
        return ps_fail($report, 'پوشه‌ی مقصد نامعتبر است.');
    }

    $artist_en = ps_text('artist_en');
    $song_en   = ps_text('song_en');
    $english_error = ps_check_english($artist_en, $song_en);
    if ($english_error !== '') {
        return ps_fail($report, $english_error);
    }

    $cover = ps_source('cover_url', 'cover_file', ['image'], 'کاور');
    $mp3   = ps_source('mp3_url', 'mp3_file', ['audio'], 'فایل MP3');
    $mp4   = ps_source('mp4_url', 'mp4_file', ['video'], 'فایل MP4');
    foreach ([$cover, $mp3, $mp4] as $source) {
        if ($source['error'] !== '') {
            return ps_fail($report, $source['error']);
        }
    }
    if ($mp3['url'] === '' && $mp4['url'] === '') {
        return ps_fail($report, 'حداقل یک فایل MP3 یا MP4 (لینک یا آپلود) لازم است.');
    }
    if (bm_auth_userpwd() === '') {
        return ps_fail($report, 'یوزر/رمز اتصال به هاست دانلود در دسترس نیست.');
    }

    $mp3_quality = in_array(ps_in('mp3_quality'), ['320', '128'], true) ? ps_in('mp3_quality') : '320';
    $mp4_quality = in_array(ps_in('mp4_quality'), ['1080', '720', '480'], true) ? ps_in('mp4_quality') : '1080';

    $file_base = bm_dl_file_name("{$artist_en} - {$song_en}");
    $dl_dir    = bm_download_dir($folder, $artist_en);
    $mp3_name  = $file_base . " [{$mp3_quality}].mp3";
    $mp4_name  = $file_base . " [{$mp4_quality}].mp4";
    $host      = ps_dl_host();

    $response = ps_dl_request(ps_dl_endpoint('leech'), [
        'dir_file'  => $dl_dir,
        'artist_en' => $artist_en,
        'song_en'   => $song_en,
        'album'     => ps_text('album'),
        'cover'     => $cover['url'],
        'mp3'       => $mp3['url'],
        'mp3_name'  => $mp3_name,
        'mp4'       => $mp4['url'],
        'mp4_name'  => $mp4_name,
    ]);

    ps_row($report, 'پوشه‌ی هاست دانلود', $dl_dir);
    if (!empty($response['json']['files']) && is_array($response['json']['files'])) {
        foreach ($response['json']['files'] as $kind => $file) {
            ps_row($report, strtoupper((string) $kind), ps_dl_file_text((array) $file), !empty($file['ok']) ? 'ok' : 'error');
        }
    }
    if (!empty($response['json']['warnings']) && is_array($response['json']['warnings'])) {
        ps_row($report, 'هشدار', implode(' ; ', array_map('strval', $response['json']['warnings'])), 'warn');
    }
    if ($response['success']) {
        ps_row($report, 'انتقال فایل‌ها', 'انجام شد');
        if ($mp3['url'] !== '') {
            $report['links'][] = ['label' => "MP3 {$mp3_quality}", 'url' => $host . $dl_dir . '/' . $mp3_name];
        }
        if ($mp4['url'] !== '') {
            $report['links'][] = ['label' => "MP4 {$mp4_quality}p", 'url' => $host . $dl_dir . '/' . $mp4_name];
        }
    } else {
        ps_row($report, 'انتقال فایل‌ها', 'ناموفق: ' . ps_dl_error_text($response), 'error');
    }

    return $report;
}

/* =======================================================
 |  اجرای ماژول
 * ====================================================== */

function ps_run($module) {
    switch ($module) {
        case 'single':
            return ps_run_single();
        case 'video':
            return ps_run_video();
        case 'album':
            return ps_run_album();
        case 'leech':
            return ps_run_leech();
    }
    $report = ps_report_new();
    return ps_fail($report, 'ماژول نامعتبر است.');
}
