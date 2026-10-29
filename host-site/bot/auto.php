<?php
/**
 * ارسال خودکار از فید — در هر اجرا یک آهنگ جدید ثبت می‌شود (نسخه ۳)
 *
 * دسترسی (دو حالت):
 *   الف) رمز ربات روی خود سرور تعریف شده باشد — یک خط در wp-config.php بالای «That's all, stop editing»:
 *            define('BM_AUTH_PASS', 'رمز ربات');
 *        در این حالت کران می‌تواند این فایل را بدون یوزر/رمز صدا بزند:
 *            wget -q -O /dev/null https://ahangban.ir/bot/auto.php
 *            یا   php /home/USER/public_html/bot/auto.php
 *        به درخواست‌های بدون یوزر/رمز فقط یک خط خلاصه نشان داده می‌شود؛ برای دیدن گزارش کامل
 *        auto.php?report=1 را در مرورگر باز کنید و با یوزر/رمز ربات وارد شوید.
 *   ب) اگر BM_AUTH_PASS تعریف نشده باشد، مثل بقیه‌ی فایل‌های ربات یوزر/رمز لازم است:
 *            wget -q -O /dev/null --user='sajad' --password='رمز' https://ahangban.ir/bot/auto.php
 *   هر ۵ دقیقه یک بار کافی است (0,5,10,15,... * * * *). اجراهای هم‌زمان قفل می‌شوند.
 *
 * آیتم‌های بررسی‌شده‌ی فید در جدول wp_subcheck نگه داشته می‌شوند (اگر نباشد ساخته می‌شود).
 * اگر ثبت آیتمی با خطا تمام شود (مثلاً API در دسترس نباشد) وضعیتش failed می‌شود و فقط وقتی
 * آیتم جدیدی در فید نباشد، حداکثر $max_attempts بار دوباره امتحان می‌شود؛ یعنی یک آیتم خراب
 * جلوی آهنگ‌های جدید را نمی‌گیرد و یک قطعی موقت هم باعث از دست رفتن آهنگ نمی‌شود.
 * ربات فقط تک‌آهنگ ثبت می‌کند: آیتم‌های آلبوم / موزیک ویدیو / پلی‌لیست فید (از روی عنوان «دانلود آلبوم …»،
 * «دانلود موزیک ویدیو …» یا نامک لینک) بدون تماس با API با وضعیت skipped کنار گذاشته می‌شوند و اگر صفحه‌ای
 * لینک فایل آهنگ نداشت (script.php قبل از ساختن پست متوجه می‌شود) هم skipped می‌شود — این‌ها را با پست‌ساز بفرستید.
 */
include("config.php");
require_once __DIR__ . '/auth.php';
include(dirname(__DIR__) . '/wp-load.php'); // قبل از بررسی دسترسی، تا BM_AUTH_PASS داخل wp-config.php دیده شود
set_time_limit(0);

$rssFeedUrl     = "https://www.mybia2music.com/feed";
$subcheck_table = 'wp_subcheck'; // جدول آیتم‌های بررسی‌شده‌ی فید
$max_feed_items = 30;            // چند آیتم اول فید بررسی شود
$max_attempts   = 3;             // آیتم خطادار حداکثر چند بار (در اجراهای بعدی) دوباره امتحان شود
$lock_minutes   = 15;            // اگر اجرای قبلی هنوز تمام نشده، تا این مدت اجرای جدید شروع نمی‌شود

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
 * وقتی BM_AUTH_PASS دیده نمی‌شود، روی صفحه‌ی 401 توضیح می‌دهد چرا (بدون نمایش مسیر یا رمز)
 */
function bm_auto_config_note() {
    $prefix = 'auto.php نسخه ۳: BM_AUTH_PASS تعریف نشده، به همین دلیل یوزر/رمز لازم است — ';

    if (!defined('ABSPATH')) {
        return $prefix . 'وردپرس بارگذاری نشد (wp-load.php کنار پوشه‌ی bot پیدا نشد).';
    }

    $config = file_exists(ABSPATH . 'wp-config.php') ? ABSPATH . 'wp-config.php' : dirname(ABSPATH) . '/wp-config.php';
    $text   = is_readable($config) ? (string) @file_get_contents($config) : '';

    if ($text === '') {
        return $prefix . 'wp-config.php قابل خواندن نبود.';
    }
    if (strpos($text, 'BM_AUTH_PASS') === false) {
        return $prefix . 'در wp-config.php که وردپرس استفاده می‌کند، خطی با BM_AUTH_PASS وجود ندارد (احتمالاً فایل دیگری ویرایش شده).';
    }
    return $prefix . 'خط BM_AUTH_PASS در wp-config.php هست ولی اجرا نمی‌شود (داخل کامنت است یا define اشتباه نوشته شده؛ آن را درست زیر define های DB_ بگذارید).';
}

/**
 * پایان اجرا با یک پیام (در حالت خلاصه فقط همین پیام نشان داده می‌شود)
 */
function bm_auto_stop($message) {
    $GLOBALS['bm_auto_summary'] = $message;
    exit($message);
}

/**
 * آیتم‌هایی که ربات نمی‌تواند ثبت کند (فقط تک‌آهنگ ثبت می‌شود): آلبوم، موزیک ویدیو، پلی‌لیست
 * از عنوان فید («دانلود آلبوم …»، «دانلود موزیک ویدیو …») و نامک لینک (…-album، …-video، /playlist/) تشخیص داده می‌شود.
 * خروجی: '' (قابل ثبت) یا نام نوع برای گزارش
 */
function bm_auto_item_kind($title, $url) {
    $title = trim(html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8'));
    $path  = strtolower((string) parse_url((string) $url, PHP_URL_PATH));

    if (preg_match('#^(?:دانلود\s+)?(آلبوم|موزیک\s*وید[یئ]و|وید[یئ]و\s*کلیپ|پلی\s*‌?لیست)#u', $title, $m)) {
        return preg_replace('/\s+/u', ' ', $m[1]);
    }
    if (preg_match('#-album(?:-\d+)?/?$#', $path)) {
        return 'آلبوم';
    }
    if (preg_match('#(?:^|[-/])(?:music-)?video(?:-\d+)?/?$#', $path)) {
        return 'موزیک ویدیو';
    }
    if (strpos($path, '/playlist') !== false) {
        return 'پلی‌لیست';
    }
    return '';
}

/**
 * بعد از پایان اجرا (حتی اگر script.php با exit یا خطا تمام شده باشد):
 * آزاد کردن قفل، ثبت وضعیت آیتم و چاپ خلاصه
 */
function bm_auto_finish() {
    global $wpdb;

    if (!empty($GLOBALS['bm_auto_locked'])) {
        delete_transient('bm_auto_lock');
    }

    $job  = isset($GLOBALS['bm_auto_job']) ? $GLOBALS['bm_auto_job'] : null;
    $line = isset($GLOBALS['bm_auto_summary']) ? (string) $GLOBALS['bm_auto_summary'] : '';

    if (!empty($job['url'])) {
        if (!empty($job['done'])) {
            $wpdb->update($job['table'], ['status' => 'done', 'post_id' => (int) $job['post_id']], ['url' => $job['url']]);
            $line = 'ثبت شد: ' . $job['title'] . ' (پست ' . (int) $job['post_id'] . ')';
        } elseif (!empty($job['skipped'])) {
            // script.php قبل از ساختن پست فهمیده صفحه لینک فایل آهنگ ندارد (آلبوم/ویدیو) → دیگر تکرار نمی‌شود
            $wpdb->update($job['table'], ['status' => 'skipped'], ['url' => $job['url']]);
            $line = 'رد شد: ' . $job['title'] . ' — ' . $job['skipped'];
        } else {
            $wpdb->update($job['table'], ['status' => 'failed'], ['url' => $job['url']]);
            $attempts = (int) $job['attempts'];
            $line = 'ثبت نشد: ' . $job['title'] . ' — '
                . ($attempts < $job['max_attempts']
                    ? 'وقتی آیتم جدیدی در فید نباشد دوباره امتحان می‌شود (تلاش ' . $attempts . ' از ' . $job['max_attempts'] . ').'
                    : 'بعد از ' . $job['max_attempts'] . ' تلاش کنار گذاشته شد.');
        }
    }

    if (!empty($GLOBALS['bm_auto_quiet'])) {
        // درخواست بدون یوزر/رمز (کران): گزارش کامل نشان داده نمی‌شود، فقط یک خط خلاصه
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo ($line !== '' ? $line : 'خطا در اجرا — برای دیدن جزئیات auto.php?report=1 را با یوزر/رمز ربات باز کنید.');
    } elseif (!empty($job['url'])) {
        echo '<p>' . $line . '</p>';
    }
}

/* -------------------------------------------------------
 |  1) دسترسی
 |  - یوزر/رمز فرستاده شده یا ?report=1 → باید درست باشد (گزارش کامل)
 |  - بدون یوزر/رمز ولی BM_AUTH_PASS روی سرور تعریف شده → اجرا مجاز است، فقط خلاصه چاپ می‌شود (کران)
 |  - در غیر این صورت → 401
 * ------------------------------------------------------ */
$bm_server_pass = (defined('BM_AUTH_PASS') && (string) BM_AUTH_PASS !== '') || (string) getenv('BM_AUTH_PASS') !== '';
list($bm_req_user) = bm_auth_request_credentials();
$bm_quiet = false;

if ($bm_req_user !== null || isset($_GET['report']) || !$bm_server_pass) {
    if ($bm_req_user === null && !$bm_server_pass && PHP_SAPI !== 'cli') {
        // زیر صفحه‌ی 401 دلیل لازم بودن یوزر/رمز را هم نشان بده (برای عیب‌یابی)
        register_shutdown_function(function () {
            echo '<p dir="rtl" style="font-family:tahoma,sans-serif;text-align:center;color:#555">' . bm_auto_config_note() . '</p>';
        });
    }
    bm_require_auth();
} elseif (PHP_SAPI !== 'cli') {
    $bm_quiet = true;
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
}

$GLOBALS['bm_auto_quiet']   = $bm_quiet;
$GLOBALS['bm_auto_summary'] = '';
$GLOBALS['bm_auto_job']     = null;
$GLOBALS['bm_auto_locked']  = false;
register_shutdown_function('bm_auto_finish');

if ($bm_quiet) {
    ob_start();
}

/* -------------------------------------------------------
 |  2) پیش‌شرط‌ها — قبل از دست زدن به فید، تا آیتمی بی‌دلیل مصرف نشود
 * ------------------------------------------------------ */
if (bm_auth_userpwd() === '') {
    bm_auto_stop('خطا: یوزر/رمز اتصال به هاست دانلود در دسترس نیست؛ هیچ آیتمی از فید پردازش نشد. '
        . 'یا BM_AUTH_PASS را در wp-config.php تعریف کنید یا کران را با یوزر/رمز ربات صدا بزنید.');
}

if (get_transient('bm_auto_lock')) {
    bm_auto_stop('اجرای قبلی هنوز تمام نشده است؛ این اجرا رد شد.');
}
set_transient('bm_auto_lock', time(), $lock_minutes * 60);
$GLOBALS['bm_auto_locked'] = true;

global $wpdb;
bm_auto_prepare_table($wpdb, $subcheck_table);

/* -------------------------------------------------------
 |  3) فید
 * ------------------------------------------------------ */
$rssContent = @file_get_contents($rssFeedUrl, false, stream_context_create($arrContextOptions));

if ($rssContent === FALSE) {
    bm_auto_stop("خطا در بارگذاری فید");
}

$rssXml = @simplexml_load_string($rssContent);
if ($rssXml === FALSE) {
    bm_auto_stop("خطا در تجزیه فید XML");
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
    bm_auto_stop('فید خالی بود.');
}

/* -------------------------------------------------------
 |  4) انتخاب آیتم: اول آیتم‌های کاملاً جدید؛ اگر نبود، آیتم خطادار با تلاش باقی‌مانده
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

// آلبوم / موزیک ویدیو / پلی‌لیست: بدون تماس با API کنار گذاشته می‌شود (status = skipped) تا آیتم بعدی فید نوبت بگیرد
$skipped_now = [];
foreach ($items as $url => $title) {
    if (isset($seen[$url])) {
        continue;
    }
    $kind = bm_auto_item_kind($title, $url);
    if ($kind === '') {
        continue;
    }
    $wpdb->insert($subcheck_table, [
        'url'        => $url,
        'status'     => 'skipped',
        'attempts'   => 0,
        'created_at' => current_time('mysql'),
    ]);
    $seen[$url]    = (object) ['url' => $url, 'status' => 'skipped', 'attempts' => 0];
    $skipped_now[] = $title . ' (' . $kind . ')';
}
if (!empty($skipped_now)) {
    echo '<p>رد شد (ربات فقط تک‌آهنگ ثبت می‌کند؛ با پست‌ساز بفرستید): ' . implode('، ', $skipped_now) . '</p>';
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
    bm_auto_stop('آیتم جدیدی در فید نبود (' . count($items) . ' آیتم بررسی شد' . (!empty($skipped_now) ? '؛ ' . count($skipped_now) . ' آیتم آلبوم/ویدیو رد شد' : '') . ').');
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

echo '<p>در حال ثبت: <a href="' . esc_url($candidate) . '">' . $items[$candidate] . '</a>' . ($retry ? ' (تلاش دوباره)' : '') . '</p>';

/* -------------------------------------------------------
 |  5) ثبت پست — یک آیتم در هر اجرا
 * ------------------------------------------------------ */
$postUrl    = $candidate;
$bot_source = 'auto'; // ارسال خودکار از فید → فقط دسته‌ی دانلود آهنگ
include("script.php");

// رسیدن به این‌جا یعنی script.php بدون exit تمام شد و پست ساخته شده است
$GLOBALS['bm_auto_job']['done']    = true;
$GLOBALS['bm_auto_job']['post_id'] = isset($post_id) ? (int) $post_id : 0;