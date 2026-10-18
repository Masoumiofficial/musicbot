<?php
/**
 * ارسال خودکار از فید — در هر اجرا یک آهنگ جدید ثبت می‌شود
 *
 * این فایل مثل بقیه‌ی فایل‌های ربات پشت یوزر/رمز است؛ کران‌جاب هم باید آن را بفرستد:
 *   هر ۱۰ دقیقه:  0,10,20,30,40,50 * * * *  curl -s -u 'sajad:رمز' https://ahangban.ir/bot/auto.php >/dev/null 2>&1
 *   یا از خط فرمان:  BM_AUTH_PASS='رمز' php /home/USER/public_html/bot/auto.php >/dev/null 2>&1
 *   (یا define('BM_AUTH_PASS', 'رمز'); در wp-config.php و بعد php auto.php)
 * بدون یوزر/رمز پاسخ 401 است و هیچ چیزی ثبت نمی‌شود.
 *
 * آیتم‌های بررسی‌شده‌ی فید در جدول wp_subcheck نگه داشته می‌شوند (اگر نباشد ساخته می‌شود).
 * اگر ثبت آیتمی با خطا تمام شود (مثلاً API در دسترس نباشد) وضعیتش failed می‌شود و فقط وقتی
 * آیتم جدیدی در فید نباشد، حداکثر $max_attempts بار دوباره امتحان می‌شود؛ یعنی یک آیتم خراب
 * جلوی آهنگ‌های جدید را نمی‌گیرد و یک قطعی موقت هم باعث از دست رفتن آهنگ نمی‌شود.
 */
include("config.php");
require_once __DIR__ . '/auth.php';
bm_require_auth();

include(dirname(__DIR__) . '/wp-load.php'); // مستقل از پوشه‌ی جاری (برای اجرای خط فرمان/کران)
set_time_limit(0);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
}

$rssFeedUrl     = "https://www.mybia2music.com/feed";
$subcheck_table = 'wp_subcheck'; // جدول آیتم‌های بررسی‌شده‌ی فید
$max_feed_items = 30;            // چند آیتم اول فید بررسی شود
$max_attempts   = 3;             // آیتم خطادار حداکثر چند بار (در اجراهای بعدی) دوباره امتحان شود

$arrContextOptions = [
    'ssl'  => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
    'http' => [
        'timeout' => 30,
        'header'  => "User-Agent: Mozilla/5.0 (compatible; AhangBanBot/1.0; +https://ahangban.ir)\r\n",
    ],
];

/**
 * جدول wp_subcheck: اگر نبود ساخته می‌شود؛ اگر از نسخه‌ی قدیمی (فقط ستون url) بود، ستون‌های وضعیت اضافه می‌شوند
 */
function bm_auto_prepare_table($wpdb, $table) {
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

    if ($exists !== $table) {
        $wpdb->query("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url VARCHAR(500) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'done',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY url (url(191))
        ) {$wpdb->get_charset_collate()}");
        return;
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    $needed  = [
        'status'     => "VARCHAR(20) NOT NULL DEFAULT 'done'", // ردیف‌های قدیمی = قبلاً ثبت شده
        'attempts'   => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'post_id'    => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'created_at' => 'DATETIME NULL',
    ];

    foreach ($needed as $column => $definition) {
        if (!in_array($column, (array) $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
}

/**
 * بعد از پایان اجرا (حتی اگر script.php با exit یا خطا تمام شده باشد) وضعیت آیتم ثبت می‌شود
 */
function bm_auto_finish() {
    global $wpdb;

    $job = isset($GLOBALS['bm_auto_job']) ? $GLOBALS['bm_auto_job'] : null;
    if (empty($job['url'])) {
        return;
    }

    if (!empty($job['done'])) {
        $wpdb->update($job['table'], ['status' => 'done', 'post_id' => (int) $job['post_id']], ['url' => $job['url']]);
        return;
    }

    $wpdb->update($job['table'], ['status' => 'failed'], ['url' => $job['url']]);

    $attempts = (int) $job['attempts'];
    echo '<p>ثبت نشد: ' . $job['title'] . ' — '
        . ($attempts < $job['max_attempts']
            ? 'وقتی آیتم جدیدی در فید نباشد دوباره امتحان می‌شود (تلاش ' . $attempts . ' از ' . $job['max_attempts'] . ').'
            : 'بعد از ' . $job['max_attempts'] . ' تلاش کنار گذاشته شد.')
        . '</p>';
}

/* -------------------------------------------------------
 |  1) پیش‌شرط‌ها — قبل از دست زدن به فید، تا آیتمی بی‌دلیل مصرف نشود
 * ------------------------------------------------------ */
if (bm_auth_userpwd() === '') {
    die('خطا: یوزر/رمز اتصال به هاست دانلود در دسترس نیست؛ هیچ آیتمی از فید پردازش نشد. '
        . 'کران را با یوزر/رمز ربات صدا بزنید (curl -u) یا در اجرای خط فرمان BM_AUTH_PASS را تعریف کنید.');
}

global $wpdb;
bm_auto_prepare_table($wpdb, $subcheck_table);

/* -------------------------------------------------------
 |  2) فید
 * ------------------------------------------------------ */
$rssContent = @file_get_contents($rssFeedUrl, false, stream_context_create($arrContextOptions));

if ($rssContent === FALSE) {
    die("خطا در بارگذاری فید");
}

$rssXml = @simplexml_load_string($rssContent);
if ($rssXml === FALSE) {
    die("خطا در تجزیه فید XML");
}

$items = [];
$i     = 0;
foreach ($rssXml->channel->item as $item) {
    if (++$i > $max_feed_items) {
        break;
    }
    $url = esc_url((string) $item->link);
    if ($url !== '') {
        $items[$url] = esc_html((string) $item->title);
    }
}

if (empty($items)) {
    die('فید خالی بود.');
}

/* -------------------------------------------------------
 |  3) انتخاب آیتم: اول آیتم‌های کاملاً جدید؛ اگر نبود، آیتم خطادار با تلاش باقی‌مانده
 * ------------------------------------------------------ */
$placeholders = implode(',', array_fill(0, count($items), '%s'));
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT url, status, attempts FROM {$subcheck_table} WHERE url IN ({$placeholders})",
    array_keys($items)
));

$seen = [];
foreach ((array) $rows as $row) {
    $seen[$row->url] = $row;
}

$candidate = null;
$retry     = false;

foreach ($items as $url => $title) {
    if (!isset($seen[$url])) {
        $candidate = $url;
        break;
    }
}

if ($candidate === null) {
    foreach ($items as $url => $title) {
        if ($seen[$url]->status === 'failed' && (int) $seen[$url]->attempts < $max_attempts) {
            $candidate = $url;
            $retry     = true;
            break;
        }
    }
}

if ($candidate === null) {
    echo 'آیتم جدیدی در فید نبود (' . count($items) . ' آیتم بررسی شد).';
    exit;
}

if ($retry) {
    $attempts = (int) $seen[$candidate]->attempts + 1;
    $wpdb->update($subcheck_table, ['status' => 'pending', 'attempts' => $attempts], ['url' => $candidate]);
} else {
    $attempts = 1;
    $wpdb->insert($subcheck_table, [
        'url'        => $candidate,
        'status'     => 'pending',
        'attempts'   => 1,
        'created_at' => current_time('mysql'),
    ]);
}

$GLOBALS['bm_auto_job'] = [
    'table'        => $subcheck_table,
    'url'          => $candidate,
    'title'        => $items[$candidate],
    'attempts'     => $attempts,
    'max_attempts' => $max_attempts,
    'done'         => false,
    'post_id'      => 0,
];
register_shutdown_function('bm_auto_finish');

echo '<p>در حال ثبت: <a href="' . esc_url($candidate) . '">' . $items[$candidate] . '</a>' . ($retry ? ' (تلاش دوباره)' : '') . '</p>';

/* -------------------------------------------------------
 |  4) ثبت پست — یک آیتم در هر اجرا
 * ------------------------------------------------------ */
$postUrl    = $candidate;
$bot_source = 'auto'; // ارسال خودکار از فید → فقط دسته‌ی دانلود آهنگ
include("script.php");

// رسیدن به این‌جا یعنی script.php بدون exit تمام شد و پست ساخته شده است
$GLOBALS['bm_auto_job']['done']    = true;
$GLOBALS['bm_auto_job']['post_id'] = isset($post_id) ? (int) $post_id : 0;