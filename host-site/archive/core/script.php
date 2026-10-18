<?php
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
bm_require_auth();

$p = $_POST['p'] ?? '';
$artist = htmlspecialchars((string) ($_POST['artist'] ?? ''), ENT_QUOTES, 'UTF-8');
$song = htmlspecialchars((string) ($_POST['song'] ?? ''), ENT_QUOTES, 'UTF-8');

// ارسال به manual.php با همان یوزر/رمزی که کاربر با آن وارد شده است
$ch = curl_init("https://ahangban.ir/bot/manual.php");
$data = array('link' => $p );
curl_setopt($ch, CURLOPT_POST,1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, bm_auth_userpwd());
$body  = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0 || $code < 200 || $code >= 300) {
    $reason = ($code === 401)
        ? 'یوزر یا رمز پنل ربات پذیرفته نشد (401).'
        : ('خطا در ارتباط با manual.php — HTTP ' . (int) $code . ($error ? ' | ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : ''));
    http_response_code(502);
    echo "
<h1 style=\"text-align: center;\"><span style=\"color: #808080;\">دانلود آهنگ جدید {$artist} به نام {$song}</span></h1>
<h2 style=\"text-align: center;\"><span style=\"color: #c0392b;\">ثبت نشد: {$reason}</span></h2>
";
    exit;
}

// حذف اسکریپت ریدایرکت manual.php؛ بقیه‌ی پاسخ (شناسه پست، وضعیت دانلود و ...) نمایش داده می‌شود
$report  = is_string($body) ? trim(preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body)) : '';
$created = ($report !== '' && strpos($report, 'پست با موفقیت ساخته شد') !== false);

if ($created) {
    $status = '<span style="color: #008000;">با موفقیت در سایت ثبت شد</span>';
} else {
    $status = '<span style="color: #d35400;">پاسخ سایت: ' . htmlspecialchars(mb_substr(strip_tags($report), 0, 300), ENT_QUOTES, 'UTF-8') . '</span>';
    $report = '';
}

$my_post_content = "
<h1 style=\"text-align: center;\"><span style=\"color: #808080;\">دانلود آهنگ جدید {$artist} به نام {$song}</span></h1>
<h2 style=\"text-align: center;\">{$status}</h2>
";
print_r($my_post_content);

if ($report !== '') {
    echo $report;
}

?>