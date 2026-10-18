<?php

$urlsite = "https://ahangban.ir";
$urldlhost = "https://dl.ahangban.ir";
$functions_dl_music = "https://dl.ahangban.ir/music.php";

/* -------------------------------------------------------
 |  ورود به پنل ربات (HTTP Basic Auth)
 |  رمز فقط به صورت هش bcrypt ذخیره می‌شود و خود رمز در هیچ فایلی نیست.
 |
 |  تغییر رمز: هش جدید را با این دستور بسازید و جایگزین کنید
 |    php -r "echo password_hash('رمز-جدید', PASSWORD_BCRYPT), PHP_EOL;"
 |  (یا یک‌بار در مرورگر از https://bcrypt-generator.com استفاده کنید)
 |
 |  همین دو مقدار باید در hostdl/config.php روی هاست دانلود هم باشد.
 |  اگر auto.php را با «php auto.php» از کران‌جاب اجرا می‌کنید، رمز را
 |  در wp-config.php تعریف کنید:  define('BM_AUTH_PASS', 'رمز');
 * ------------------------------------------------------ */
$bot_auth_user = 'sajad';
$bot_auth_hash = '$2y$10$r2pNbsZs6jdQcajpFLF3Yu7/y31ykm.RvlqMrK.XASoItSB.K94ya';

/* -------------------------------------------------------
 |  دسته‌بندی پست‌ها (آیدی عددی دسته در وردپرس)
 |  $cat_music    : به همه‌ی پست‌ها داده می‌شود (خودکار و دستی)
 |  $cat_featured : فقط به پست‌هایی که دستی ارسال می‌شوند (robo.php و آرشیو هنرمندان)
 |  برای غیرفعال کردن دسته‌ی منتخب مقدارش را 0 بگذارید.
 * ------------------------------------------------------ */
$cat_music    = 3;    // دانلود آهنگ
$cat_featured = 249;  // آثار منتخب
