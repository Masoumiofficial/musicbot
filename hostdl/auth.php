<?php
/**
 * احراز هویت HTTP Basic برای اسکریپت‌های ربات (پنل سایت و هاست دانلود)
 *
 * نحوه استفاده در ابتدای هر فایلِ قابل دسترسی از وب:
 *   require_once __DIR__ . '/config.php';   // $bot_auth_user و $bot_auth_hash
 *   require_once __DIR__ . '/auth.php';
 *   bm_require_auth();
 *
 * - رمز به صورت هش bcrypt در config.php ذخیره می‌شود (خود رمز هیچ‌جا در کد نیست).
 * - اجرای خط فرمان (php auto.php از کران‌جاب) نیازی به ورود ندارد.
 * - تماس‌های سرور-به-سرور (سایت -> هاست دانلود، آرشیو -> manual.php) با همان
 *   یوزر/رمزی انجام می‌شود که کاربر با آن وارد شده است (bm_auth_userpwd).
 *
 * اگر هاست شما PHP را به صورت CGI/FastCGI اجرا می‌کند و هدر Authorization به PHP
 * نمی‌رسد، این خط‌ها را به .htaccess همان پوشه اضافه کنید:
 *   SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
 *   RewriteEngine On
 *   RewriteCond %{HTTP:Authorization} .
 *   RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
 */

if (!function_exists('bm_auth_request_credentials')) {

    /**
     * یوزر/رمز ارسالی در درخواست جاری
     * خروجی: [user, pass] — اگر هیچ اطلاعاتی ارسال نشده باشد user برابر null است
     */
    function bm_auth_request_credentials() {
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
        }

        // هاست‌هایی که PHP_AUTH_USER را پر نمی‌کنند: هدر Authorization را خودمان می‌خوانیم
        $header = '';
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                $header = (string) $_SERVER[$key];
                break;
            }
        }

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                $headers = array_change_key_case($headers, CASE_LOWER);
                $header  = (string) ($headers['authorization'] ?? '');
            }
        }

        if ($header !== '' && stripos($header, 'basic ') === 0) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                list($user, $pass) = explode(':', $decoded, 2);
                return [$user, $pass];
            }
        }

        return [null, ''];
    }

    /**
     * بررسی ورود؛ در صورت نامعتبر بودن، پاسخ 401 می‌دهد و اجرا متوقف می‌شود
     */
    function bm_require_auth($realm = 'AhangBan Bot') {
        global $bot_auth_user, $bot_auth_hash;

        // اجرای خط فرمان (کران‌جاب با php) نیازی به ورود ندارد
        if (PHP_SAPI === 'cli' && empty($_SERVER['REQUEST_METHOD'])) {
            return true;
        }

        if (empty($bot_auth_user) || empty($bot_auth_hash)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Auth is not configured: set $bot_auth_user and $bot_auth_hash in config.php');
        }

        list($user, $pass) = bm_auth_request_credentials();

        $valid = ($user !== null)
            && hash_equals((string) $bot_auth_user, (string) $user)
            && password_verify((string) $pass, (string) $bot_auth_hash);

        if ($valid) {
            $GLOBALS['bm_auth_verified'] = [(string) $user, (string) $pass];
            return true;
        }

        if ($user !== null) {
            // کند کردن حملات حدس رمز
            usleep(500000);
        }

        $hint = ($user === null)
            ? 'هیچ اطلاعات ورودی دریافت نشد. اگر یوزر و رمز را فرستاده‌اید، هاست شما هدر Authorization را به PHP نمی‌دهد؛ قوانین .htaccess ذکرشده در auth.php را اضافه کنید.'
            : 'یوزر یا رمز اشتباه است.';

        header('WWW-Authenticate: Basic realm="' . str_replace('"', '', $realm) . '", charset="UTF-8"');
        http_response_code(401);
        header('Content-Type: text/html; charset=UTF-8');
        exit(
            '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>401 - دسترسی غیرمجاز</title></head>'
            . '<body style="font-family:tahoma,sans-serif;text-align:center;padding:40px">'
            . '<h2>دسترسی غیرمجاز</h2><p>' . $hint . '</p></body></html>'
        );
    }

    /**
     * «user:pass» برای تماس‌های cURL به بخش‌های دیگر (CURLOPT_USERPWD)
     * 1) همان اطلاعاتی که درخواست جاری با آن وارد شده
     * 2) در اجرای خط فرمان: ثابت BM_AUTH_PASS در wp-config.php یا متغیر محیطی BM_AUTH_PASS
     * خروجی خالی = رمزی در دسترس نیست
     */
    function bm_auth_userpwd() {
        global $bot_auth_user;

        if (!empty($GLOBALS['bm_auth_verified'])) {
            return implode(':', $GLOBALS['bm_auth_verified']);
        }

        $pass = defined('BM_AUTH_PASS') ? (string) BM_AUTH_PASS : (string) getenv('BM_AUTH_PASS');
        if ($pass !== '' && !empty($bot_auth_user)) {
            return $bot_auth_user . ':' . $pass;
        }

        return '';
    }
}
