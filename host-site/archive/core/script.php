<?php
/**
 * واسط آرشیو هنرمندان ← ربات (manual.php)
 * ورودی (POST): p = لینک آهنگ در سایت مبدأ، artist، song، same_title (1 = اگر عنوان تکراری بود با شماره ثبت شود)
 * خروجی: JSON
 *   status  : ok (پست ساخته شد) | exists (قبلاً ثبت شده — همان لینک یا همان عنوان) | error (ثبت نشد)
 *   reason  : created | source | title | api | insert | auth | http | curl | input | unknown
 *   message : توضیح کوتاه فارسی برای نمایش در ردیف
 *   post_id, link, title, note, html (گزارش کامل ربات برای پنل نتیجه)
 * کد HTTP همیشه 200 است تا جاوااسکریپت وضعیت را از status بخواند (نه از کد HTTP).
 */

// ورود با یوزر/رمز پنل ربات (config.php و auth.php در پوشه bot کنار پوشه archive)
$bm_bot_dir = is_file(dirname(__DIR__, 2) . '/bot/auth.php')
    ? dirname(__DIR__, 2) . '/bot'
    : rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/bot';
if (!is_file($bm_bot_dir . '/auth.php')) {
    http_response_code(500);
    exit('auth.php not found: the archive folder must be deployed next to the bot folder.');
}
require_once $bm_bot_dir . '/config.php';
require_once $bm_bot_dir . '/auth.php';
require_once __DIR__ . '/archive.php';
bm_require_auth();

set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bm_archive_json($status, $reason, $message, array $extra = []) {
    $data = array_merge([
        'status'  => $status,
        'reason'  => $reason,
        'message' => $message,
        'post_id' => 0,
        'link'    => '',
        'title'   => '',
        'note'    => '',
        'html'    => '',
    ], $extra);
    $data['status']  = $status;
    $data['reason']  = $reason;
    $data['message'] = $message;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$p          = trim((string) ($_POST['p'] ?? ''));
$artist     = trim((string) ($_POST['artist'] ?? ''));
$song       = trim((string) ($_POST['song'] ?? ''));
$same_title = (!empty($_POST['same_title']) && $_POST['same_title'] !== '0') ? '1' : '0';

if ($p === '' || !filter_var($p, FILTER_VALIDATE_URL)) {
    bm_archive_json('error', 'input', 'لینک مبدأ معتبر نیست.');
}

// آدرس manual.php از config.php ربات ($urlsite) — همان سایت
$manual_url = (isset($urlsite) && is_string($urlsite) && $urlsite !== '')
    ? rtrim($urlsite, '/') . '/bot/manual.php'
    : 'https://ahangban.ir/bot/manual.php';

// ارسال به manual.php با همان یوزر/رمزی که کاربر با آن وارد شده است
$ch = curl_init($manual_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['link' => $p, 'same_title' => $same_title]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, bm_auth_userpwd());
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_TIMEOUT, 900);
$body  = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (PHP_VERSION_ID < 80000) {
    curl_close($ch);
}

if ($errno !== 0) {
    bm_archive_json('error', 'curl', 'ارتباط با manual.php برقرار نشد: ' . $error);
}
if ($code === 401) {
    bm_archive_json('error', 'auth', 'یوزر یا رمز پنل ربات پذیرفته نشد (401).');
}

$parsed = bm_archive_parse_bot_response($body);

if ($code < 200 || $code >= 300) {
    // خطای PHP/سرور در manual.php (500 و ...)؛ اگر نشانگر نتیجه در پاسخ بود همان معتبر است
    if ($parsed['status'] === 'ok' || $parsed['status'] === 'exists') {
        bm_archive_json($parsed['status'], $parsed['reason'], $parsed['message'] . ' (HTTP ' . $code . ')', $parsed);
    }
    bm_archive_json('error', 'http', 'manual.php با خطای HTTP ' . $code . ' پاسخ داد' . ($parsed['message'] !== '' ? ': ' . $parsed['message'] : ''), ['html' => '']);
}

if ($parsed['title'] === '' && ($artist !== '' || $song !== '')) {
    $parsed['title'] = trim("دانلود آهنگ {$artist} {$song}");
}

bm_archive_json($parsed['status'], $parsed['reason'], $parsed['message'], $parsed);
