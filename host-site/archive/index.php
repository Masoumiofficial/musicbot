<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f172a">
<title>اسکریپت آرشیو هنرمندان</title>
<link href="assets/style.css" rel="stylesheet" type="text/css">
<script src="assets/jquery.js"></script>
<script>
$(function () {
    const $body = $("body");
    const $loader = $(".MTFloader");
    const $result = $(".MTForumBlock");

    $(".movielinks").on("click", ".send-post", function (e) {
        e.preventDefault();

        const $item = $(this).closest(".music-item");
        const id = $(this).data("id");
        const links = $(this).attr("links");
        const artist = $(this).attr("artist");
        const song = $(this).attr("song");

        $body.addClass("is-loading");
        $loader.fadeIn(180);

        $.ajax({
            type: "POST",
            url: "core/script.php",
            data: {
                req: "LastTopics",
                p: links,
                song: song,
                artist: artist
            }
        }).done(function (responseText) {
            $result
                .stop(true, true)
                .css({opacity: 0})
                .html(responseText)
                .animate({opacity: 1}, 280);

            $item.slideUp(220, function () {
                $(this).remove();
                updateCount();
            });

            $("html, body").animate({scrollTop: 0}, 450);
        }).fail(function () {
            $result.html(
                '<div class="result-message error">' +
                '<span class="result-icon">!</span>' +
                '<div><strong>عملیات ناموفق بود</strong><small>ارتباط با سرور برقرار نشد. دوباره تلاش کنید.</small></div>' +
                '</div>'
            );
        }).always(function () {
            $loader.fadeOut(180);
            $body.removeClass("is-loading");
        });
    });

    function updateCount() {
        $(".remaining-count").text($(".music-item").length);
    }
    updateCount();

    $(".clear-search").on("click", function () {
        $("input[name=artist]").val("").focus();
    });
});
</script>
</head>
<body>

<header class="app-header">
    <div class="header-inner">
        <a class="brand" href="" aria-label="صفحه اصلی">
            <span class="brand-mark">♫</span>
            <span>
                <strong>آرشیو هنرمندان</strong>
                <small>مدیریت و ارسال مطالب</small>
            </span>
        </a>

        <nav class="top-nav">
            <a class="active" href="https://tsmelody.com/archive/">داشبورد</a>
            <a href="https://wintheme.ir/" target="_blank" rel="noopener">وین‌تم</a>
        </nav>
    </div>
</header>

<main class="app-shell">

    <section class="hero">
        <div class="hero-content">
            <span class="eyebrow">MUSIC ARCHIVE</span>
            <h1>مدیریت آرشیو هنرمندان</h1>
            <p>نام هنرمند را وارد کنید تا مطالب مربوط به او دریافت و برای ارسال آماده شوند.</p>
        </div>
        <div class="hero-decoration"><span>♪</span><span>♫</span><span>♬</span></div>
    </section>

    <section class="search-card">
        <div class="search-title">
            <span class="search-icon">⌕</span>
            <div>
                <strong>جستجوی هنرمند</strong>
                <small>نام انگلیسی و صحیح هنرمند را وارد کنید</small>
            </div>
        </div>

        <form method="get" action="" class="artist-search">
            <div class="input-wrap">
                <input
                    name="artist"
                    value="<?php echo isset($_GET['artist']) ? htmlspecialchars($_GET['artist'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                    placeholder="مثلاً: Mohsen Yeganeh"
                    type="text"
                    required
                    autocomplete="off"
                >
                <button type="button" class="clear-search" aria-label="پاک کردن">×</button>
            </div>
            <button class="search-submit" type="submit">
                <span>جستجو و دریافت</span>
                <i class="icon-search"></i>
            </button>
        </form>
    </section>

    <section class="dashboard-grid">
        <div class="stat-card">
            <span class="stat-icon">♪</span>
            <div>
                <small>مطالب باقی‌مانده</small>
                <strong class="remaining-count">0</strong>
            </div>
        </div>
        <div class="stat-card accent">
            <span class="stat-icon">✓</span>
            <div>
                <small>وضعیت سیستم</small>
                <strong>آماده ارسال</strong>
            </div>
        </div>
        <div class="info-card">
            <span class="info-dot"></span>
            <div>
                <strong>ارسال AJAX</strong>
                <small>بدون رفرش صفحه و به‌صورت تک‌به‌تک</small>
            </div>
        </div>
    </section>

    <section class="result-panel">
        <div class="panel-head">
            <div>
                <span class="panel-kicker">RESULT</span>
                <h2>نتیجه عملیات</h2>
            </div>
            <span class="panel-badge">Live</span>
        </div>
        <div class="MTForumBlock">
            <div class="empty-result">
                <span>⌁</span>
                <strong>هنوز عملیاتی انجام نشده است</strong>
                <small>پس از ارسال هر مطلب، نتیجه اینجا نمایش داده می‌شود.</small>
            </div>
        </div>
    </section>

    <section class="movielinks">
        <div class="panel-head list-head">
            <div class="list-head-title">
                <div>
                    <span class="panel-kicker">ARCHIVE</span>
                    <h2>لیست مطالب</h2>
                </div>
                <span class="count-badge"><b class="remaining-count">0</b> مطلب</span>
            </div>
            <button type="button" class="send-all-posts" id="sendAllPosts">
                <span class="send-all-icon">⇧</span>
                <span>ارسال همه</span>
            </button>
        <div class="music-list">
        <?php
        include $_SERVER['DOCUMENT_ROOT'].'/wp-config.php';
        set_time_limit(0);

        $source = '';
        if (!empty($_GET['artist'])) {
            $artistQuery = sanitize_text_field(wp_unslash($_GET['artist']));
            $b2m_url = "https://www.mybia2music.com/artists/" . str_replace(' ', '-', $artistQuery) . "?more=musics";
            $source = @file_get_contents($b2m_url);
        }

        preg_match_all('/(<article class="item ">.*<\/article>)/isU', $source, $posts);
        $x = 0;

        foreach ($posts[0] as $vale) {
            preg_match('/<a[^>]*href="([^"]*)"[^>]*>/i', $vale, $Output2);
            $url = isset($Output2[1]) ? trim($Output2[1]) : '';

            preg_match('/(<span class="txt">.*<\/span>)/isU', $vale, $artists);
            $artistName = isset($artists[0]) ? trim(strip_tags($artists[0])) : '';

            preg_match('/(<span class="songname">.*<\/span>)/isU', $vale, $songname);
            $song = isset($songname[0]) ? trim(strip_tags($songname[0])) : '';

            $title = "دانلود آهنگ {$artistName} {$song}";
            $safeTitle = esc_html($title);

            $mytitle = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_title FROM $wpdb->posts WHERE post_title = %s LIMIT 1",
                    $title
                )
            );

            if (!empty($mytitle)) {
                echo '<article class="music-item published Page_' . (int)$x . '">';
                echo '<div class="item-index">' . sprintf('%02d', $x + 1) . '</div>';
                echo '<div class="item-main"><span class="item-status">ثبت شده</span><h3>' . $safeTitle . '</h3><small>این مطلب قبلاً در سایت منتشر شده است.</small></div>';
                echo '<div class="item-action done">✓ منتشر شده</div>';
                echo '</article>';
            } else {
                echo '<article class="music-item Page_' . (int)$x . '">';
                echo '<div class="item-index">' . sprintf('%02d', $x + 1) . '</div>';
                echo '<div class="item-main"><span class="item-status ready">آماده ارسال</span><h3>' . $safeTitle . '</h3><small>برای ثبت این مطلب روی دکمه ارسال کلیک کنید.</small></div>';
                echo '<a class="item-action send-post" data-id="' . (int)$x . '" links="' . esc_attr($url) . '" artist="' . esc_attr($artistName) . '" song="' . esc_attr($song) . '" href="#">ارسال پست <b>←</b></a>';
                echo '</article>';
            }
            $x++;
        }

        if ($x === 0) {
            echo '<div class="no-results"><span>♪</span><strong>مطلبی پیدا نشد</strong><small>ابتدا نام صحیح هنرمند را جستجو کنید.</small></div>';
        }
        ?>
        </div>
    </section>

</main>

<footer class="app-footer">
    <div>ساخته شده با <i class="icon-heart"></i> توسط <a href="https://wintheme.ir/" target="_blank" rel="noopener">وین‌تم</a></div>
    <span>Music Archive Manager</span>
</footer>

<div class="MTFloader" aria-live="polite">
    <div class="loader-card">
        <div class="loader-ring"></div>
        <strong>در حال ارسال مطلب</strong>
        <span>لطفاً تا پایان عملیات صفحه را نبندید...</span>
    </div>
</div>


<div class="bulk-modal" id="bulkSendModal" aria-hidden="true">
    <div class="bulk-modal-backdrop"></div>
    <div class="bulk-modal-card" role="dialog" aria-modal="true" aria-labelledby="bulkSendTitle">
        <button type="button" class="bulk-modal-close" id="bulkSendClose" aria-label="بستن">×</button>
        <div class="bulk-modal-icon">⇧</div>
        <div class="bulk-modal-kicker">BULK SEND</div>
        <h3 id="bulkSendTitle">در حال ارسال مطالب</h3>
        <p id="bulkSendStatus">لطفاً تا پایان عملیات این پنجره را نبندید.</p>

        <div class="bulk-progress">
            <div class="bulk-progress-bar" id="bulkProgressBar"></div>
        </div>

        <div class="bulk-progress-meta">
            <strong id="bulkProgressPercent">0%</strong>
            <span><b id="bulkProgressCurrent">0</b> از <b id="bulkProgressTotal">0</b> مطلب</span>
        </div>

        <div class="bulk-current" id="bulkCurrentPost">
            <span class="bulk-current-dot"></span>
            <span>آماده‌سازی...</span>
        </div>

        <div class="bulk-result" id="bulkResult">
            <span><b id="bulkSuccessCount">0</b> موفق</span>
            <span><b id="bulkFailCount">0</b> ناموفق</span>
        </div>

        <button type="button" class="bulk-finish" id="bulkFinish" hidden>بستن و پایان</button>
    </div>
</div>


<script>
(function($){
    "use strict";

    const modal = $("#bulkSendModal");
    const progressBar = $("#bulkProgressBar");
    const percent = $("#bulkProgressPercent");
    const currentEl = $("#bulkProgressCurrent");
    const totalEl = $("#bulkProgressTotal");
    const currentPost = $("#bulkCurrentPost");
    const statusEl = $("#bulkSendStatus");
    const successEl = $("#bulkSuccessCount");
    const failEl = $("#bulkFailCount");
    const finishBtn = $("#bulkFinish");
    const closeBtn = $("#bulkSendClose");

    let bulkRunning = false;
    let bulkClosed = false;

    function getPendingItems(){
        return $(".music-item").filter(function(){
            return $(this).find(".send-post").length > 0 && !$(this).hasClass("published");
        });
    }

    function setProgress(done, total){
        const value = total ? Math.round((done / total) * 100) : 0;
        progressBar.css("width", value + "%");
        percent.text(value + "%");
        currentEl.text(done);
        totalEl.text(total);
    }

    function openBulkModal(total){
        bulkRunning = true;
        bulkClosed = false;
        modal.addClass("is-open").attr("aria-hidden","false");
        $("body").addClass("modal-open");
        finishBtn.prop("hidden", true);
        closeBtn.hide();
        successEl.text("0");
        failEl.text("0");
        setProgress(0, total);
        statusEl.text("مطالب به‌صورت تکی و پشت‌سرهم ارسال می‌شوند.");
        currentPost.html('<span class="bulk-current-dot"></span><span>آماده‌سازی ارسال...</span>');
    }

    function finishBulk(total, success, fail){
        bulkRunning = false;
        setProgress(total, total);
        statusEl.text("عملیات ارسال همه مطالب به پایان رسید.");
        currentPost.html('<span class="bulk-current-check">✓</span><span>تمام مطالب پردازش شدند.</span>');
        successEl.text(success);
        failEl.text(fail);
        finishBtn.prop("hidden", false);
        closeBtn.show();
    }

    function closeBulkModal(){
        if (bulkRunning) return;
        modal.removeClass("is-open").attr("aria-hidden","true");
        $("body").removeClass("modal-open");
    }

    function sendOne($item){
        return new Promise(function(resolve){
            const $btn = $item.find(".send-post").first();
            const id = $btn.data("id");
            const links = $btn.attr("links");
            const artist = $btn.attr("artist");
            const song = $btn.attr("song");
            const title = $item.find("h3").text().trim();

            currentPost.html(
                '<span class="bulk-current-dot"></span><span>' +
                $('<div>').text(title || "در حال ارسال مطلب").html() +
                '</span>'
            );

            $.ajax({
                type: "POST",
                url: "core/script.php",
                data: {
                    req: "LastTopics",
                    p: links,
                    song: song,
                    artist: artist
                },
                timeout: 120000
            }).done(function(responseText){
                // Show the latest server response in the existing result panel.
                $(".MTForumBlock")
                    .stop(true,true)
                    .css({opacity:0})
                    .html(responseText)
                    .animate({opacity:1},180);

                $item.removeClass("ready").addClass("published");
                $item.find(".item-status").removeClass("ready").text("ثبت شده");
                $item.find(".item-action")
                    .removeClass("send-post")
                    .addClass("done")
                    .removeAttr("data-id links artist song href")
                    .text("✓ منتشر شد")
                    .off("click");

                resolve(true);
            }).fail(function(){
                resolve(false);
            });
        });
    }

    async function startBulkSend(){
        if (bulkRunning) return;

        const items = getPendingItems();
        const total = items.length;

        if (!total) {
            alert("مطلب آماده ارسالی وجود ندارد.");
            return;
        }

        if (!confirm("تعداد " + total + " مطلب به‌صورت تکی ارسال می‌شود. ادامه می‌دهید؟")) {
            return;
        }

        openBulkModal(total);

        let success = 0;
        let fail = 0;

        for (let i = 0; i < items.length; i++) {
            const ok = await sendOne($(items[i]));
            if (ok) success++;
            else fail++;

            successEl.text(success);
            failEl.text(fail);
            setProgress(i + 1, total);
        }

        finishBulk(total, success, fail);
        if (typeof updateCount === "function") updateCount();
    }

    $("#sendAllPosts").on("click", startBulkSend);
    finishBtn.on("click", closeBulkModal);
    closeBtn.on("click", closeBulkModal);
    modal.find(".bulk-modal-backdrop").on("click", function(){
        if (!bulkRunning) closeBulkModal();
    });

    $(document).on("keydown", function(e){
        if (e.key === "Escape" && !bulkRunning) closeBulkModal();
    });
})(jQuery);
</script>

</body>
</html>
