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
