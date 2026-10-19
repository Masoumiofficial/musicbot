<?php
/**
 * پست‌ساز آهنگ‌بان — پردازش فرم‌ها و نمایش گزارش
 *
 *   module = single | video | album | leech  (از فرم‌های index.php)
 *
 * ترتیب کار (مثل ربات): بررسی ورودی → پست پیش‌نویس → سئو، دسته‌ها، هنرمند، متاها، ACF → تصویر شاخص
 * → انتقال فایل‌ها به هاست دانلود → انتشار (فقط اگر همه چیز درست بود) → گزارش
 */

require_once __DIR__ . '/../bot/config.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../bot/auth.php';
bm_require_auth('AhangBan PostSaz');

require_once __DIR__ . '/../wp-load.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/ui.php';

@set_time_limit(0);
@ignore_user_abort(true);

/**
 * بررسی‌های قبل از اجرا؛ خروجی: متن خطا یا '' اگر همه چیز درست بود
 */
function ps_precheck() {
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return 'این صفحه فقط فرم‌های ارسالی از پست‌ساز را می‌پذیرد.';
    }

    // حجم ارسالی بیشتر از post_max_size: PHP کل $_POST و $_FILES را خالی می‌کند
    if (empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH'])) {
        return 'حجم فایل‌های ارسالی از حد مجاز سرور (post_max_size = ' . ini_get('post_max_size') . '، upload_max_filesize = ' . ini_get('upload_max_filesize') . ') بیشتر است. برای فایل‌های بزرگ لینک مستقیم بدهید.';
    }

    // فقط از خود سایت (جلوگیری از ارسال فرم از سایت‌های دیگر با مرورگرِ واردشده)
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        $from = strtolower((string) parse_url((string) $_SERVER[$header], PHP_URL_HOST));
        $port = parse_url((string) $_SERVER[$header], PHP_URL_PORT);
        if ($port) {
            $from .= ':' . $port;
        }
        if ($from !== '' && $host !== '' && $from !== $host && $from !== preg_replace('/:\d+$/', '', $host)) {
            return 'درخواست از دامنه‌ی دیگری ارسال شده است (' . $from . ').';
        }
    }

    $nonce = isset($_POST['ps_nonce']) ? (string) $_POST['ps_nonce'] : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'postsaz_submit')) {
        return 'اعتبار فرم تمام شده است (مثلاً صفحه مدت زیادی باز مانده یا ورود/خروج وردپرس عوض شده). به پنل برگردید و دوباره ارسال کنید.';
    }

    return '';
}

$module   = isset($_POST['module']) ? sanitize_key((string) $_POST['module']) : '';
$precheck = ps_precheck();

ps_ui_header('نتیجه‌ی ارسال');

if ($precheck !== '') {
    echo '<section class="ps-result"><div class="ps-alert">' . ps_h($precheck) . '</div>';
    echo '<p><a class="ps-btn ps-btn-light" href="index.php">بازگشت به پست‌ساز</a></p></section>';
    ps_ui_footer();
    exit;
}

// پیام «در حال انجام» (اگر سرور خروجی را بافر نکند، زودتر از پایان کار دیده می‌شود)
echo '<div id="ps-working" class="ps-working">در حال ساخت پست و انتقال فایل‌ها به هاست دانلود… لطفاً صبر کنید و صفحه را نبندید.</div>';
echo str_repeat(' ', 1024);
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
}
@flush();

$report = null;
try {
    ps_gc_temp();
    $report = ps_run($module);
} catch (Throwable $e) {
    $report = ps_report_new();
    ps_fail($report, 'خطای اجرا: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    // فایل‌های آپلودشده‌ی همین درخواست (هاست دانلود و وردپرس تا این‌جا نسخه‌ی خودشان را برداشته‌اند)
    ps_cleanup_temp();
}

$module_tab = in_array($module, ['single', 'video', 'album', 'leech'], true) ? $module : 'single';
?>
<section class="ps-result">
    <h1 class="<?php echo $report['ok'] ? 'is-ok' : 'is-error'; ?>">
        <?php
        if ($module === 'leech') {
            echo $report['ok'] ? 'فایل‌ها منتقل شدند' : 'انتقال فایل‌ها کامل نشد';
        } elseif (!empty($report['post_id'])) {
            echo $report['ok'] ? 'پست ساخته شد' : 'پست ساخته شد ولی بخشی از کار خطا داشت';
        } else {
            echo 'پست ساخته نشد';
        }
        ?>
    </h1>

    <?php if (!empty($report['errors'])) : ?>
        <div class="ps-alert">
            <?php foreach ($report['errors'] as $error) : ?>
                <p><?php echo ps_h($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['post_id'])) : ?>
        <p class="ps-result-title">
            <strong><?php echo ps_h($report['title']); ?></strong> — ID <?php echo (int) $report['post_id']; ?>
        </p>
        <p class="ps-result-actions">
            <?php if (!empty($report['link'])) : ?>
                <a class="ps-btn" href="<?php echo ps_h($report['link']); ?>" target="_blank" rel="noopener">مشاهده‌ی پست</a>
            <?php endif; ?>
            <?php if (!empty($report['edit_link'])) : ?>
                <a class="ps-btn ps-btn-light" href="<?php echo ps_h($report['edit_link']); ?>" target="_blank" rel="noopener">ویرایش در پیشخوان</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($report['rows'])) : ?>
        <table class="ps-report">
            <tbody>
            <?php foreach ($report['rows'] as $row) : ?>
                <tr class="is-<?php echo ps_h($row['status']); ?>">
                    <th><?php echo ps_h($row['label']); ?></th>
                    <td><?php echo ps_h($row['value']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($report['links'])) : ?>
        <h2>لینک‌های نهایی</h2>
        <ul class="ps-links">
            <?php foreach ($report['links'] as $link) : ?>
                <li>
                    <span class="ps-link-label"><?php echo ps_h($link['label']); ?></span>
                    <input type="text" readonly dir="ltr" value="<?php echo ps_h($link['url']); ?>">
                    <button type="button" class="ps-btn ps-btn-light" data-copy>کپی</button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="ps-result-actions">
        <a class="ps-btn ps-btn-light" href="index.php#<?php echo ps_h($module_tab); ?>">ارسال پست جدید</a>
    </p>
</section>
<script>document.getElementById('ps-working').hidden = true;</script>
<?php
ps_ui_footer();
