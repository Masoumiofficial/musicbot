<?php
/**
 * ارسال خودکار از سایت مبدأ — در هر اجرا یک آهنگ جدید ثبت می‌شود (نسخه ۴)
 *
 * منبع آیتم‌های جدید: $bot_feed_urls در config.php (به ترتیب امتحان می‌شود؛ RSS، Atom یا نقشه‌ی سایت). بیاتوموزیک آذر ۱۴۰۵
 * فید /feed را حذف کرد؛ آیتم‌ها حالا از نقشه‌ی سایت «آهنگ‌های تازه» (new-sitemap.xml) خوانده می‌شود و خود صفحه‌ی هر آهنگ
 * مستقیم خوانده می‌شود (bot/source.php) — API قدیمی فقط پشتیبان است. آزمایش: bot/source-test.php?feed=1
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
 *   هر ۵ دقیقه یک بار کافی است (0,5,10,15,... * * * *). اجراهای هم‌زمان قفل می‌شوند («اجرای قبلی هنوز تمام نشده است»
 *   یعنی همان لحظه اجرای کران در جریان است — طبیعی است؛ اگر اجرایی قطع شده باشد قفل بعد از ۱۰ دقیقه ($lock_minutes) خودش
 *   آزاد می‌شود یا با auto.php?unlock=1 (با یوزر/رمز ربات) همان لحظه).
 *
 * آیتم‌های بررسی‌شده در جدول wp_subcheck نگه داشته می‌شوند (اگر نباشد ساخته می‌شود).
 * اگر ثبت آیتمی با خطا تمام شود (مثلاً API در دسترس نباشد) وضعیتش failed می‌شود و فقط وقتی
 * آیتم جدیدی در فهرست نباشد، حداکثر $max_attempts بار دوباره امتحان می‌شود؛ یعنی یک آیتم خراب
 * جلوی آهنگ‌های جدید را نمی‌گیرد و یک قطعی موقت هم باعث از دست رفتن آهنگ نمی‌شود.
 * ربات فقط تک‌آهنگ ثبت می‌کند: آیتم‌های آلبوم / موزیک ویدیو / پلی‌لیست (از روی عنوان «دانلود آلبوم …»،
 * «دانلود موزیک ویدیو …» یا نامک لینک) بدون خواندن صفحه با وضعیت skipped کنار گذاشته می‌شوند؛ آلبوم/ویدیویی که از نامک
 * معلوم نیست را script.php بعد از خواندن صفحه (JSON-LD و متن صفحه) رد می‌کند (skipped) — این‌ها را با پست‌ساز بفرستید.
 * تک‌آهنگی که در صفحه‌اش لینک فایل پیدا نشد failed می‌شود و مثل خطاهای دیگر تا $max_attempts بار دوباره امتحان می‌شود.
 */
include("config.php");
require_once __DIR__ . '/auth.php';
include(dirname(__DIR__) . '/wp-load.php'); // قبل از بررسی دسترسی، تا BM_AUTH_PASS داخل wp-config.php دیده شود
set_time_limit(0);

require_once __DIR__ . '/source.php'; // خواندن فهرست آیتم‌های جدید (فید / نقشه‌ی سایت) و صفحه‌ی آهنگ

// فهرست منابع آیتم‌های جدید: config.php ($bot_feed_urls)؛ اگر تعریف نشده بود، پیش‌فرض‌ها
$feed_urls = (isset($bot_feed_urls) && is_array($bot_feed_urls) && !empty($bot_feed_urls))
    ? $bot_feed_urls
    : ['https://www.mybia2music.com/new-sitemap.xml', 'https://www.mybia2music.com/feed'];
if (!empty($rssFeedUrl)) {
    array_unshift($feed_urls, $rssFeedUrl); // سازگاری با تنظیم قدیمی
}
$subcheck_table = 'wp_subcheck'; // جدول آیتم‌های بررسی‌شده‌ی فهرست
$max_feed_items = 30;            // چند آیتم اول فهرست (جدیدترین‌ها) بررسی شود
$max_attempts   = 3;             // آیتم خطادار حداکثر چند بار (در اجراهای بعدی) دوباره امتحان شود
$lock_minutes   = 10;            // اگر اجرای قبلی هنوز تمام نشده، تا این مدت اجرای جدید شروع نمی‌شود (هر اجرا معمولاً ۱ تا ۵ دقیقه؛
                                 // اگر اجرایی وسط کار قطع شود قفل خودش بعد از این مدت آزاد می‌شود — auto.php?unlock=1 همین حالا)

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

/** «۴۵ ثانیه پیش» / «۳ دقیقه پیش» */
function bm_auto_age_text($seconds) {
    $seconds = max(0, (int) $seconds);
    if ($seconds < 90) {
        return $seconds . ' ثانیه پیش';
    }
    if ($seconds < 5400) {
        return (int) round($seconds / 60) . ' دقیقه پیش';
    }
    return (int) round($seconds / 3600) . ' ساعت پیش';
}

/**
 * قفل اجرای جاری (transient bm_auto_lock): ['time' => شروع، 'url' => آیتم در حال ثبت، 'title'] یا null
 * نسخه‌های قبلی فقط زمان را ذخیره می‌کردند؛ آن هم خوانده می‌شود
 */
function bm_auto_lock_info() {
    $lock = get_transient('bm_auto_lock');
    if ($lock === false || $lock === '' || $lock === null) {
        return null;
    }
    if (is_array($lock)) {
        return [
            'time'  => isset($lock['time']) ? (int) $lock['time'] : 0,
            'url'   => isset($lock['url']) ? (string) $lock['url'] : '',
            'title' => isset($lock['title']) ? (string) $lock['title'] : '',
        ];
    }
    return ['time' => (int) $lock, 'url' => '', 'title' => ''];
}

/** گرفتن / به‌روز کردن قفل (زمان شروع همان اولین بار می‌ماند؛ آیتم در حال ثبت بعد از انتخاب اضافه می‌شود) */
function bm_auto_lock_set($url = '', $title = '') {
    global $lock_minutes;
    if (empty($GLOBALS['bm_auto_started'])) {
        $GLOBALS['bm_auto_started'] = time();
    }
    $title = wp_strip_all_tags((string) $title);
    set_transient('bm_auto_lock', ['time' => (int) $GLOBALS['bm_auto_started'], 'url' => (string) $url, 'title' => $title], max(1, (int) $lock_minutes) * 60);
    $GLOBALS['bm_auto_locked'] = true;
    if ($url !== '') {
        // بر خلاف قفل، منقضی نمی‌شود: در اجرای بعدی معلوم می‌کند آیتم pending مال چند دقیقه پیش است (bm_auto_finish پاکش می‌کند)
        update_option('bm_auto_running', ['time' => time(), 'url' => (string) $url, 'title' => $title], false);
    }
}

/** متن «آخرین اجرای تمام‌شده» (option bm_auto_last) برای پیام قفل */
function bm_auto_last_text() {
    $last = get_option('bm_auto_last');
    if (!is_array($last) || empty($last['time'])) {
        return '';
    }
    return 'آخرین اجرای تمام‌شده: ' . bm_auto_age_text(time() - (int) $last['time']) . (!empty($last['text']) ? ' — ' . $last['text'] : '');
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
    if (preg_match('#^/(?:albums?)(?:/|$)#', $path)) {
        return 'آلبوم';
    }
    if (preg_match('#^/(?:videos?|teasers?|karaoke)(?:/|$)#', $path)) {
        return 'موزیک ویدیو';
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
        delete_option('bm_auto_running');
    }

    $job  = isset($GLOBALS['bm_auto_job']) ? $GLOBALS['bm_auto_job'] : null;
    $line = isset($GLOBALS['bm_auto_summary']) ? (string) $GLOBALS['bm_auto_summary'] : '';

    if (!empty($job['url'])) {
        if (!empty($job['done'])) {
            $wpdb->update($job['table'], ['status' => 'done', 'post_id' => (int) $job['post_id']], ['url' => $job['url']]);
            $line = 'ثبت شد: ' . $job['title'] . ' (پست ' . (int) $job['post_id'] . ')';
        } elseif (!empty($job['exists'])) {
            // script.php: همین لینک/عنوان قبلاً در سایت هست → همان پست؛ دیگر تکرار نمی‌شود
            $wpdb->update($job['table'], ['status' => 'done', 'post_id' => (int) $job['exists']], ['url' => $job['url']]);
            $line = 'قبلاً ثبت شده بود: ' . $job['title'] . ' (پست ' . (int) $job['exists'] . ')';
        } elseif (!empty($job['skipped'])) {
            // script.php قبل از ساختن پست فهمیده صفحه لینک فایل آهنگ ندارد (آلبوم/ویدیو) → دیگر تکرار نمی‌شود
            $wpdb->update($job['table'], ['status' => 'skipped'], ['url' => $job['url']]);
            $line = 'رد شد: ' . $job['title'] . ' — ' . $job['skipped'];
        } else {
            $wpdb->update($job['table'], ['status' => 'failed'], ['url' => $job['url']]);
            $attempts = (int) $job['attempts'];
            $line = 'ثبت نشد: ' . $job['title'] . ' — '
                . ($attempts < $job['max_attempts']
                    ? 'وقتی آیتم جدیدی در فهرست نباشد دوباره امتحان می‌شود (تلاش ' . $attempts . ' از ' . $job['max_attempts'] . ').'
                    : 'بعد از ' . $job['max_attempts'] . ' تلاش کنار گذاشته شد.');
        }
    }

    if (!empty($GLOBALS['bm_auto_locked'])) {
        // فقط اجراهایی که واقعاً شروع شده‌اند (قفل را گرفته‌اند) «آخرین اجرا» حساب می‌شوند — نه اجراهای ردشده با قفل
        update_option('bm_auto_last', ['time' => time(), 'text' => mb_substr(wp_strip_all_tags($line), 0, 300)], false);
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
 |  - یوزر/رمز فرستاده شده یا ?report=1 یا ?unlock=1 → باید درست باشد (گزارش کامل / آزاد کردن قفل)
 |  - بدون یوزر/رمز ولی BM_AUTH_PASS روی سرور تعریف شده → اجرا مجاز است، فقط خلاصه چاپ می‌شود (کران)
 |  - در غیر این صورت → 401
 * ------------------------------------------------------ */
$bm_server_pass = (defined('BM_AUTH_PASS') && (string) BM_AUTH_PASS !== '') || (string) getenv('BM_AUTH_PASS') !== '';
list($bm_req_user) = bm_auth_request_credentials();
$bm_quiet = false;

if ($bm_req_user !== null || isset($_GET['report']) || isset($_GET['unlock']) || !$bm_server_pass) {
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
$GLOBALS['bm_auto_started'] = 0;
register_shutdown_function('bm_auto_finish');

if ($bm_quiet) {
    ob_start();
}

/* -------------------------------------------------------
 |  2) پیش‌شرط‌ها — قبل از خواندن فهرست، تا آیتمی بی‌دلیل مصرف نشود
 * ------------------------------------------------------ */
if (bm_auth_userpwd() === '') {
    bm_auto_stop('خطا: یوزر/رمز اتصال به هاست دانلود در دسترس نیست؛ هیچ آیتمی پردازش نشد. '
        . 'یا BM_AUTH_PASS را در wp-config.php تعریف کنید یا کران را با یوزر/رمز ربات صدا بزنید.');
}

global $wpdb;
bm_auto_prepare_table($wpdb, $subcheck_table);

/* قفل اجراهای هم‌زمان: هر اجرا یک آهنگ ثبت می‌کند و ۱ تا ۵ دقیقه طول می‌کشد (خواندن صفحه‌ی مبدأ، کاور، انتقال فایل‌ها به
   هاست دانلود). اگر اجرایی وسط کار قطع شود (مثلاً سرور پروسه را بکشد) قفل خودش بعد از $lock_minutes دقیقه آزاد می‌شود و
   آیتمی که pending مانده در اجرای بعدی failed می‌شود تا دوباره امتحان شود. auto.php?unlock=1 (با یوزر/رمز) قفل را همین حالا آزاد می‌کند. */
$bm_lock = bm_auto_lock_info();
if (isset($_GET['unlock']) && !$bm_quiet) { // ?unlock=1 همیشه یوزر/رمز می‌خواهد (بخش ۱)؛ !$bm_quiet فقط محض اطمینان
    if ($bm_lock === null) {
        bm_auto_stop('قفلی وجود نداشت؛ اجرای بعدی (کران یا باز کردن auto.php) طبق معمول انجام می‌شود.');
    }
    delete_transient('bm_auto_lock');
    delete_option('bm_auto_running');
    if ($bm_lock['url'] !== '') {
        // آیتم آن اجرا اگر هنوز pending است، failed می‌شود تا (وقتی آیتم جدیدی نباشد) دوباره امتحان شود
        $wpdb->update($subcheck_table, ['status' => 'failed'], ['url' => $bm_lock['url'], 'status' => 'pending']);
    }
    bm_auto_stop('قفل اجرای قبلی آزاد شد (شروع ' . bm_auto_age_text(time() - $bm_lock['time'])
        . ($bm_lock['title'] !== '' ? ' — در حال ثبت «' . $bm_lock['title'] . '»' : '') . ').'
        . ($bm_lock['url'] !== '' ? ' اگر آن آهنگ ثبت نشده باشد، وقتی آیتم جدیدی در فهرست نباشد دوباره امتحان می‌شود.' : '')
        . ' اجرای بعدی (کران یا باز کردن auto.php) طبق معمول انجام می‌شود.');
}
if ($bm_lock !== null) {
    $bm_lock_age = $bm_lock['time'] > 0 ? time() - $bm_lock['time'] : 0;
    bm_auto_stop('اجرای قبلی هنوز تمام نشده است (شروع ' . bm_auto_age_text($bm_lock_age)
        . ($bm_lock['title'] !== '' ? ' — در حال ثبت «' . $bm_lock['title'] . '»' : '') . ')؛ این اجرا رد شد.'
        . ' هر اجرا یک آهنگ ثبت می‌کند و معمولاً ۱ تا ۵ دقیقه طول می‌کشد؛ کران هر ۵ دقیقه دوباره امتحان می‌کند.'
        . ' اگر اجرای قبلی قطع شده باشد، قفل خودش حداکثر ' . max(0, $lock_minutes - (int) floor($bm_lock_age / 60)) . ' دقیقه‌ی دیگر آزاد می‌شود'
        . (!$bm_quiet ? ' — یا اگر مطمئنی قطع شده، همین حالا: auto.php?unlock=1' : '') . '.'
        . (bm_auto_last_text() !== '' ? ' ' . bm_auto_last_text() . '.' : ''));
}
bm_auto_lock_set();

/* آیتمی که از اجرای قطع‌شده‌ی قبلی pending مانده → failed، تا دوباره امتحان شود. فقط وقتی مطمئنیم آن اجرا زنده نیست:
   bm_auto_running با شروع ثبت هر آیتم نوشته و در پایان پاک می‌شود؛ قفل منقضی شده ولی یک اجرای خیلی کند (set_time_limit(0))
   می‌تواند هنوز زنده باشد و نباید همان آهنگ دو بار ثبت شود. بدون این نشانه (نسخه‌ی قدیمی) یا بعد از ۳۰ دقیقه → قطعاً مرده. */
$bm_running     = get_option('bm_auto_running');
$bm_running_age = (is_array($bm_running) && !empty($bm_running['time'])) ? time() - (int) $bm_running['time'] : PHP_INT_MAX;
if ($bm_running_age >= max(30, 3 * $lock_minutes) * 60) {
    $bm_orphans = (int) $wpdb->query("UPDATE {$subcheck_table} SET status = 'failed' WHERE status = 'pending'");
    if (is_array($bm_running)) {
        delete_option('bm_auto_running');
    }
    if ($bm_orphans > 0) {
        echo '<p>' . $bm_orphans . ' آیتم از اجرای قطع‌شده‌ی قبلی (pending مانده بود) به failed برگشت؛ وقتی آیتم جدیدی در فهرست نباشد دوباره امتحان می‌شود.</p>';
    }
}

/* -------------------------------------------------------
 |  3) فهرست آیتم‌های جدید (فید RSS/Atom یا نقشه‌ی سایت؛ اولین منبعی که آیتم داشت)
 * ------------------------------------------------------ */
$feed = bm_source_feed_items($feed_urls, $max_feed_items);

if (empty($feed['items'])) {
    bm_auto_stop('خطا در بارگذاری فهرست آهنگ‌های جدید سایت مبدأ — ' . implode(' | ', $feed['errors'])
        . ' — منابع در config.php ($bot_feed_urls) تعریف می‌شوند؛ آزمایش: bot/source-test.php?feed=1');
}

$items = [];
foreach ($feed['items'] as $url => $title) {
    $url = esc_url_raw((string) $url);
    if ($url !== '') {
        $items[$url] = esc_html((string) $title);
    }
}

if (empty($items)) {
    bm_auto_stop('فهرست آهنگ‌های جدید خالی بود (' . $feed['source'] . ').');
}
echo '<p>منبع فهرست: ' . esc_html($feed['source']) . ' (' . count($items) . ' آیتم)</p>';

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

// آلبوم / موزیک ویدیو / پلی‌لیست (از عنوان یا نامک): بدون خواندن صفحه کنار گذاشته می‌شود (status = skipped) تا آیتم بعدی نوبت بگیرد
// (آیتم‌های نقشه‌ی سایت عنوان ندارند؛ آلبوم/ویدیویی که از نامک معلوم نیست را script.php بعد از خواندن صفحه رد می‌کند)
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
    bm_auto_stop('آیتم جدیدی در فهرست نبود (' . count($items) . ' آیتم بررسی شد' . (!empty($skipped_now) ? '؛ ' . count($skipped_now) . ' آیتم آلبوم/ویدیو رد شد' : '') . ').');
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

bm_auto_lock_set($candidate, $items[$candidate]); // پیام قفل برای اجراهای هم‌زمان: «در حال ثبت …»

echo '<p>در حال ثبت: <a href="' . esc_url($candidate) . '">' . $items[$candidate] . '</a>' . ($retry ? ' (تلاش دوباره)' : '') . '</p>';

/* -------------------------------------------------------
 |  5) ثبت پست — یک آیتم در هر اجرا
 * ------------------------------------------------------ */
$postUrl    = $candidate;
$bot_source = 'auto'; // ارسال خودکار → فقط دسته‌ی دانلود آهنگ
include("script.php");

// رسیدن به این‌جا یعنی script.php بدون exit تمام شد و پست ساخته شده است
$GLOBALS['bm_auto_job']['done']    = true;
$GLOBALS['bm_auto_job']['post_id'] = isset($post_id) ? (int) $post_id : 0;