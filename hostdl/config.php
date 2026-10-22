<?php

/* -------------------------------------------------------
 |  دسترسی به music.php روی هاست دانلود (HTTP Basic Auth)
 |  این دو مقدار باید دقیقاً با host-site/bot/config.php روی هاست سایت یکی باشد؛
 |  اسکریپت سایت با همین یوزر/رمز به این‌جا متصل می‌شود.
 |  رمز فقط به صورت هش bcrypt ذخیره می‌شود؛ برای ساخت هش جدید:
 |    php -r "echo password_hash('رمز-جدید', PASSWORD_BCRYPT), PHP_EOL;"
 * ------------------------------------------------------ */
$bot_auth_user = 'sajad';
$bot_auth_hash = '$2y$10$r2pNbsZs6jdQcajpFLF3Yu7/y31ykm.RvlqMrK.XASoItSB.K94ya';

/* -------------------------------------------------------
 |  لوگوی سایت روی کاور داخل فایل‌های MP3 (تگ ID3)
 |  کاوری که از مبدأ گرفته می‌شود قبل از تگ‌نویسی با GD لوگو می‌گیرد (watermark.php — همان فایل bot/watermark.php سایت).
 |  فایل لوگو: PNG شفاف سفید در hostdl/assets/logo.png (همان لوگوی سایت)؛ نسخه‌ی تیره‌ی اختیاری: assets/logo-dark.png
 |  اگر فایل لوگو نباشد فایل‌ها بدون لوگو تگ می‌شوند و در پاسخ JSON (cover.watermark) و گزارش ربات گفته می‌شود.
 |  کاور داخل فایل معمولاً ۵۰۰ پیکسل است و در پلیرها کوچک دیده می‌شود؛ برای همین عرض کمی بیشتر از سایت است.
 * ------------------------------------------------------ */
$dl_watermark           = true;
$dl_watermark_file      = __DIR__ . '/assets/logo.png';
$dl_watermark_dark      = __DIR__ . '/assets/logo-dark.png';
$dl_watermark_position  = 'bottom-right';
$dl_watermark_width     = 18;   // درصد عرض کاور
$dl_watermark_margin    = 3.5;
$dl_watermark_opacity   = 85;
$dl_watermark_shadow    = true;
$dl_watermark_min_cover = 240;
// پلاک رنگی پشت لوگو (سبک رادیوجوان؛ رنگ = رنگ غالب همان کاور) — همان معنی تنظیمات $bot_watermark_plate_* سایت
$dl_watermark_plate         = 'auto';   // auto | square | rect | none
$dl_watermark_plate_color   = 'auto';   // auto | '#1e1e1e'
$dl_watermark_plate_size    = 14;       // ضلع پلاک مربع، درصد عرض کاور
$dl_watermark_plate_radius  = 10;
$dl_watermark_plate_opacity = 100;      // پُری پلاک، درصد (100 = کاملاً پر، حداقل 10)
