<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
bm_require_auth();

// لینک پست تازه ثبت‌شده (از manual.php برمی‌گردد)
$done_link  = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$done_title = isset($_GET['t']) ? trim((string) $_GET['t']) : '';
if ($done_link !== '' && !filter_var($done_link, FILTER_VALIDATE_URL)) {
    $done_link = '';
}
?>
<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرم ارسال پست</title>
    <link rel="stylesheet" href="https://ahangban.ir/bot/files/styles.css">
</head>
<body>
    <div class="background"></div>
    <div class="form-container">
	 <?php if ($done_link !== '') echo '<div class="alert-box"><p><a href="' . htmlspecialchars($done_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($done_title !== '' ? $done_title : $done_link, ENT_QUOTES, 'UTF-8') . '</a></p></div>'; ?> 
        <form action="https://ahangban.ir/bot/manual.php" method="post">
            <label for="link">: لینک پست </label>
            <input type="url" id="link" name="link" placeholder="https://example.com" required>
            <button type="submit">ارسال</button>
        </form>
    </div>
</body>
</html>
