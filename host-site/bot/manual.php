<?php
include('config.php');
require_once __DIR__ . '/auth.php';
bm_require_auth();

require('../wp-load.php');

set_time_limit(0);

if (!empty($_POST['link'])) {
    $postUrl = filter_var($_POST['link'], FILTER_SANITIZE_URL);


    if (filter_var($postUrl, FILTER_VALIDATE_URL)) {
        $bot_source = 'manual'; // ارسال دستی (robo.php و آرشیو هنرمندان) → دانلود آهنگ + آثار منتخب

        // same_title=1 → اگر پستی با همین عنوان (ولی لینک مبدأ دیگر) وجود داشت، به جای رد شدن با شماره ثبت شود
        // («ساز و آواز (2)»). همان لینک مبدأ در هر حالت رد می‌شود.
        $bm_same_title = !empty($_POST['same_title']) && $_POST['same_title'] !== '0' ? 'number' : 'skip';

        include('script.php');

        $permalink = rawurlencode(get_permalink($post_id));
        $my_title = rawurlencode(get_the_title($post_id));
        $redirect_url = "https://ahangban.ir/bot/robo.php/?q={$permalink}&t={$my_title}";
        
        exit("<script>document.title = 'Redirecting...'; window.location.replace('{$redirect_url}');</script>");
    } else {

        exit('<script>alert("لینک وارد شده معتبر نیست."); window.history.back();</script>');
    }
} else {

    exit('<script>alert("لطفاً یک لینک وارد کنید."); window.history.back();</script>');
}
?>