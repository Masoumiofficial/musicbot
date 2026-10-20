<?php
// ورود با یوزر/رمز پنل ربات (config.php و auth.php در پوشه bot کنار پوشه archive)
$bm_bot_dir = is_file(dirname(__DIR__) . '/bot/auth.php')
    ? dirname(__DIR__) . '/bot'
    : rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/bot';
if (!is_file($bm_bot_dir . '/auth.php')) {
    http_response_code(500);
    exit('auth.php not found: the archive folder must be deployed next to the bot folder.');
}
require_once $bm_bot_dir . '/config.php';
require_once $bm_bot_dir . '/auth.php';
require_once __DIR__ . '/core/archive.php';
bm_require_auth();

// وردپرس (برای $wpdb و توابع کمکی) — پوشه‌ی archive کنار wp-load.php است
$bm_wp_load = is_file(dirname(__DIR__) . '/wp-load.php')
    ? dirname(__DIR__) . '/wp-load.php'
    : rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/wp-load.php';
require_once $bm_wp_load;
set_time_limit(0);

// فهرست آهنگ‌های هنرمند از سایت مبدأ + وضعیت هر کدام در سایت
$archive_items  = [];
$archive_query  = '';
$archive_error  = '';
if (!empty($_GET['artist'])) {
    $archive_query = sanitize_text_field(wp_unslash($_GET['artist']));
    $b2m_url = 'https://www.mybia2music.com/artists/' . str_replace(' ', '-', $archive_query) . '?more=musics';
    $source  = @file_get_contents($b2m_url);
    if ($source === false || $source === '') {
        $archive_error = 'صفحه‌ی هنرمند از سایت مبدأ دریافت نشد؛ نام انگلیسی هنرمند را بررسی کنید.';
    } else {
        $archive_items = bm_archive_classify_items(bm_archive_parse_listing($source), $wpdb);
    }
}

$archive_stats = ['total' => count($archive_items), 'published' => 0, 'legacy' => 0, 'dup_title' => 0, 'pending' => 0];
foreach ($archive_items as $item) {
    $archive_stats[$item['state']]++;
}
$archive_stats['ready'] = $archive_stats['pending'] + $archive_stats['dup_title'];
?>
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
// ارسال یک مطلب به ربات و به‌روزرسانی ردیف بر اساس نتیجه‌ی واقعی (ok / exists / error)
window.bmArchive = (function ($) {
    "use strict";

    const SEND_TIMEOUT = 600000; // ۱۰ دقیقه: دانلود فایل‌های بزرگ روی هاست دانلود ممکن است طول بکشد

    function esc(text) {
        return $('<div>').text(text == null ? '' : String(text)).html();
    }

    function messageBlock(kind, title, small) {
        const icon = kind === 'ok' ? '✓' : (kind === 'exists' ? '=' : '!');
        return '<div class="result-message ' + kind + '">' +
            '<span class="result-icon">' + icon + '</span>' +
            '<div><strong>' + esc(title) + '</strong><small>' + esc(small || '') + '</small></div>' +
            '</div>';
    }

    function showResult(res) {
        const $result = $(".MTForumBlock");
        let html = '';
        if (res.status === 'ok') {
            html = messageBlock('ok', 'ثبت شد: ' + (res.title || ''), res.message || '');
            if (res.html) html += '<div class="bot-report">' + res.html + '</div>';
            if (res.link) html += '<div class="result-link"><a href="' + esc(res.link) + '" target="_blank" rel="noopener">مشاهده پست ↗</a></div>';
        } else if (res.status === 'exists') {
            html = messageBlock('exists', 'قبلاً ثبت شده بود: ' + (res.title || ''), res.message || '');
            if (res.link) html += '<div class="result-link"><a href="' + esc(res.link) + '" target="_blank" rel="noopener">مشاهده پست موجود ↗</a></div>';
        } else {
            html = messageBlock('error', 'ثبت نشد: ' + (res.title || ''), res.message || 'خطای نامشخص');
        }
        $result.stop(true, true).css({opacity: 0}).html(html).animate({opacity: 1}, 220);
    }

    function applyResult($item, res) {
        const $status = $item.find(".item-status");
        const $note   = $item.find(".item-note");
        const $action = $item.find(".item-action");

        $item.removeClass("failed");
        if (res.status === 'ok') {
            $item.removeClass("dup-title").addClass("published just-sent");
            $status.attr("class", "item-status ok").text("ثبت شد");
            $note.text(res.message || 'پست ساخته شد.');
            $action.replaceWith($('<div class="item-action done"></div>')
                .html(res.link ? '<a href="' + esc(res.link) + '" target="_blank" rel="noopener">✓ ثبت شد ↗</a>' : '✓ ثبت شد'));
        } else if (res.status === 'exists') {
            $item.removeClass("dup-title").addClass("published exists");
            $status.attr("class", "item-status exists").text("قبلاً ثبت شده");
            $note.text(res.message || 'این آهنگ قبلاً در سایت ثبت شده است.');
            $action.replaceWith($('<div class="item-action done exists"></div>')
                .html(res.link ? '<a href="' + esc(res.link) + '" target="_blank" rel="noopener">= تکراری ↗</a>' : '= تکراری'));
        } else {
            $item.addClass("failed");
            $status.attr("class", "item-status error").text("خطا");
            $note.text(res.message || 'ثبت نشد.');
            $action.addClass("failed").html('ارسال دوباره <b>↻</b>');
        }
        $item.attr("data-last-message", res.message || '');
    }

    // ارسال یک ردیف؛ خروجی Promise با نتیجه‌ی {status, reason, message, post_id, link, title, html}
    function sendItem($item) {
        const $btn = $item.find(".send-post").first();
        const title = $item.find("h3").text().trim();
        const payload = {
            req: "LastTopics",
            p: $btn.attr("links"),
            song: $btn.attr("song"),
            artist: $btn.attr("artist"),
            same_title: $btn.attr("same_title") || "0"
        };

        return new Promise(function (resolve) {
            $.ajax({
                type: "POST",
                url: "core/script.php",
                data: payload,
                dataType: "json",
                timeout: SEND_TIMEOUT
            }).done(function (res) {
                if (!res || typeof res !== 'object' || !res.status) {
                    res = {status: 'error', reason: 'unknown', message: 'پاسخ نامعتبر از سرور'};
                }
                if (!res.title) res.title = title;
                resolve(res);
            }).fail(function (xhr, textStatus) {
                let message;
                if (textStatus === 'timeout') {
                    message = 'زمان انتظار تمام شد؛ ممکن است پست در حال ساخته شدن باشد — با «ارسال دوباره» وضعیت مشخص می‌شود.';
                } else if (textStatus === 'parsererror') {
                    message = 'پاسخ سرور JSON نبود (خطای PHP یا صفحه‌ی ورود): ' + String(xhr.responseText || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
                } else {
                    message = 'ارتباط با سرور برقرار نشد' + (xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '') + '.';
                }
                resolve({status: 'error', reason: textStatus || 'network', message: message, title: title});
            });
        });
    }

    function updateCount() {
        const $items = $(".music-item");
        const pending = $items.filter(function () { return $(this).find(".send-post").length > 0; }).length;
        $(".remaining-count").text(pending);
        $(".published-count").text($items.filter(".published").length);
        $(".failed-count").text($items.filter(".failed").length);
        $(".dup-count").text($items.filter(".dup-title").length);
        $("#sendFailedPosts").prop("hidden", $items.filter(".failed").length === 0);
    }

    return {esc: esc, showResult: showResult, applyResult: applyResult, sendItem: sendItem, updateCount: updateCount};
})(jQuery);

$(function () {
    const $body = $("body");
    const $loader = $(".MTFloader");
    const bm = window.bmArchive;

    $(".movielinks").on("click", ".send-post", function (e) {
        e.preventDefault();
        const $item = $(this).closest(".music-item");
        if ($item.hasClass("is-sending")) return;

        $item.addClass("is-sending");
        $body.addClass("is-loading");
        $loader.fadeIn(180);

        bm.sendItem($item).then(function (res) {
            bm.showResult(res);
            bm.applyResult($item, res);
            bm.updateCount();
            $("html, body").animate({scrollTop: 0}, 450);
        }).finally(function () {
            $item.removeClass("is-sending");
            $loader.fadeOut(180);
            $body.removeClass("is-loading");
        });
    });

    bm.updateCount();

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

    <section class="dashboard-grid stats-4">
        <div class="stat-card">
            <span class="stat-icon">♪</span>
            <div>
                <small>آماده‌ی ارسال</small>
                <strong class="remaining-count">0</strong>
            </div>
        </div>
        <div class="stat-card accent">
            <span class="stat-icon">✓</span>
            <div>
                <small>ثبت‌شده در سایت</small>
                <strong class="published-count"><?php echo (int) ($archive_stats['published'] + $archive_stats['legacy']); ?></strong>
            </div>
        </div>
        <div class="stat-card warn">
            <span class="stat-icon">≈</span>
            <div>
                <small>هم‌نام (با شماره ثبت می‌شود)</small>
                <strong class="dup-count"><?php echo (int) $archive_stats['dup_title']; ?></strong>
            </div>
        </div>
        <div class="stat-card danger">
            <span class="stat-icon">!</span>
            <div>
                <small>خطا در این نشست</small>
                <strong class="failed-count">0</strong>
            </div>
        </div>
    </section>
    <?php if ($archive_query !== ''): ?>
    <div class="info-card archive-summary">
        <span class="info-dot"></span>
        <div>
            <strong><?php echo esc_html($archive_query); ?> — <?php echo (int) $archive_stats['total']; ?> آهنگ در سایت مبدأ</strong>
            <small>
                <?php echo (int) $archive_stats['published']; ?> ثبت‌شده با همین لینک،
                <?php echo (int) $archive_stats['legacy']; ?> ثبت‌شده‌ی قدیمی (فقط با عنوان تطبیق داده شد)،
                <?php echo (int) $archive_stats['dup_title']; ?> هم‌نام با قطعه‌ی دیگر،
                <?php echo (int) $archive_stats['pending']; ?> جدید.
                نتیجه‌ی هر ارسال از پاسخ واقعی ربات خوانده می‌شود (ثبت شد / تکراری / خطا).
            </small>
        </div>
    </div>
    <?php endif; ?>

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
                    <h2>لیست مطالب <small style="font-size:.6em;font-weight:normal;opacity:.75">(قدیمی‌ترین → جدیدترین؛ «ارسال همه» به همین ترتیب)</small></h2>
                </div>
                <span class="count-badge"><b class="remaining-count">0</b> مطلب</span>
            </div>
            <div class="list-actions">
                <button type="button" class="send-all-posts secondary" id="sendFailedPosts" hidden>
                    <span class="send-all-icon">↻</span>
                    <span>ارسال دوباره‌ی ناموفق‌ها</span>
                </button>
                <button type="button" class="send-all-posts" id="sendAllPosts">
                    <span class="send-all-icon">⇧</span>
                    <span>ارسال همه</span>
                </button>
            </div>
        </div>
        <div class="music-list">
        <?php
        $x = 0;
        foreach ($archive_items as $item) {
            $x++;
            $safeTitle = esc_html($item['title']);
            $index     = sprintf('%02d', $x);
            $post_link = $item['post_id'] ? get_permalink($item['post_id']) : '';
            $note      = esc_html($item['note']);

            if ($item['state'] === 'published' || $item['state'] === 'legacy') {
                $label = $item['state'] === 'legacy' ? 'ثبت شده (قدیمی)' : 'ثبت شده';
                echo '<article class="music-item published ' . esc_attr($item['state']) . ' Page_' . (int) $x . '" data-url="' . esc_attr($item['url']) . '">';
                echo '<div class="item-index">' . $index . '</div>';
                echo '<div class="item-main"><span class="item-status ok">' . $label . '</span><h3>' . $safeTitle . '</h3><small class="item-note">' . $note . '</small></div>';
                echo '<div class="item-action done">' . ($post_link ? '<a href="' . esc_url($post_link) . '" target="_blank" rel="noopener">✓ منتشر شده ↗</a>' : '✓ منتشر شده') . '</div>';
                echo '</article>';
                continue;
            }

            $is_dup  = ($item['state'] === 'dup_title');
            $label   = $is_dup ? 'هم‌نام — با شماره ثبت می‌شود' : 'آماده ارسال';
            $note_html = $note;
            if ($is_dup && $post_link) {
                $note_html .= ' <a href="' . esc_url($post_link) . '" target="_blank" rel="noopener">(پست موجود ↗)</a>';
            }
            echo '<article class="music-item' . ($is_dup ? ' dup-title' : '') . ' Page_' . (int) $x . '" data-url="' . esc_attr($item['url']) . '">';
            echo '<div class="item-index">' . $index . '</div>';
            echo '<div class="item-main"><span class="item-status ' . ($is_dup ? 'dup' : 'ready') . '">' . $label . '</span><h3>' . $safeTitle . '</h3><small class="item-note">' . $note_html . '</small></div>';
            echo '<a class="item-action send-post" data-id="' . (int) $x . '" links="' . esc_attr($item['url']) . '" artist="' . esc_attr($item['artist']) . '" song="' . esc_attr($item['song']) . '" same_title="' . ($is_dup ? '1' : '0') . '" href="#">ارسال پست <b>←</b></a>';
            echo '</article>';
        }

        if ($x === 0) {
            if ($archive_error !== '') {
                echo '<div class="no-results"><span>!</span><strong>' . esc_html($archive_error) . '</strong><small>آدرس بررسی‌شده: ' . esc_html(isset($b2m_url) ? $b2m_url : '') . '</small></div>';
            } else {
                echo '<div class="no-results"><span>♪</span><strong>مطلبی پیدا نشد</strong><small>ابتدا نام صحیح هنرمند را جستجو کنید.</small></div>';
            }
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

        <div class="bulk-result bulk-result-3" id="bulkResult">
            <span class="ok"><b id="bulkSuccessCount">0</b> ثبت شد</span>
            <span class="exists"><b id="bulkExistsCount">0</b> تکراری</span>
            <span class="error"><b id="bulkFailCount">0</b> خطا</span>
        </div>

        <div class="bulk-failed" id="bulkFailed" hidden>
            <strong>ثبت‌نشده‌ها و دلیل:</strong>
            <ol id="bulkFailedList"></ol>
        </div>

        <button type="button" class="bulk-stop" id="bulkStop">توقف بعد از مطلب جاری</button>
        <div class="bulk-buttons">
            <button type="button" class="bulk-retry" id="bulkRetry" hidden>ارسال دوباره‌ی ناموفق‌ها</button>
            <button type="button" class="bulk-finish" id="bulkFinish" hidden>بستن و پایان</button>
        </div>
    </div>
</div>


<script>
(function($){
    "use strict";

    const bm = window.bmArchive;
    const modal = $("#bulkSendModal");
    const progressBar = $("#bulkProgressBar");
    const percent = $("#bulkProgressPercent");
    const currentEl = $("#bulkProgressCurrent");
    const totalEl = $("#bulkProgressTotal");
    const currentPost = $("#bulkCurrentPost");
    const statusEl = $("#bulkSendStatus");
    const successEl = $("#bulkSuccessCount");
    const existsEl = $("#bulkExistsCount");
    const failEl = $("#bulkFailCount");
    const failedBox = $("#bulkFailed");
    const failedList = $("#bulkFailedList");
    const finishBtn = $("#bulkFinish");
    const retryBtn = $("#bulkRetry");
    const stopBtn = $("#bulkStop");
    const closeBtn = $("#bulkSendClose");

    let bulkRunning = false;
    let stopRequested = false;

    // ردیف‌های قابل ارسال: دکمه‌ی ارسال دارند (جدید، هم‌نام، یا خطادار)
    function getPendingItems(){
        return $(".music-item").filter(function(){
            return $(this).find(".send-post").length > 0;
        });
    }

    function getFailedItems(){
        return $(".music-item.failed").filter(function(){
            return $(this).find(".send-post").length > 0;
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
        stopRequested = false;
        modal.addClass("is-open").attr("aria-hidden","false");
        $("body").addClass("modal-open");
        finishBtn.prop("hidden", true);
        retryBtn.prop("hidden", true);
        stopBtn.prop("hidden", false).prop("disabled", false).text("توقف بعد از مطلب جاری");
        closeBtn.hide();
        successEl.text("0");
        existsEl.text("0");
        failEl.text("0");
        failedList.empty();
        failedBox.prop("hidden", true);
        setProgress(0, total);
        statusEl.text("مطالب تک‌به‌تک و پشت‌سرهم، از قدیمی‌ترین به جدیدترین ارسال می‌شوند. نتیجه‌ی هر مطلب از پاسخ واقعی ربات خوانده می‌شود.");
        currentPost.html('<span class="bulk-current-dot"></span><span>آماده‌سازی ارسال...</span>');
    }

    function finishBulk(done, total, counts, stopped){
        bulkRunning = false;
        setProgress(done, total);
        statusEl.text(stopped
            ? "ارسال متوقف شد. " + done + " از " + total + " مطلب پردازش شد."
            : "عملیات ارسال همه مطالب به پایان رسید.");
        currentPost.html('<span class="bulk-current-check">✓</span><span>' +
            counts.ok + ' ثبت شد، ' + counts.exists + ' تکراری، ' + counts.error + ' خطا' +
            (stopped ? ' (ناتمام)' : '') + '</span>');
        successEl.text(counts.ok);
        existsEl.text(counts.exists);
        failEl.text(counts.error);
        stopBtn.prop("hidden", true);
        finishBtn.prop("hidden", false);
        retryBtn.prop("hidden", counts.error === 0);
        closeBtn.show();
    }

    function closeBulkModal(){
        if (bulkRunning) return;
        modal.removeClass("is-open").attr("aria-hidden","true");
        $("body").removeClass("modal-open");
    }

    function addFailed(index, title, message){
        failedBox.prop("hidden", false);
        failedList.append(
            '<li><b>' + bm.esc(index) + '.</b> ' + bm.esc(title) +
            '<small>' + bm.esc(message || 'خطای نامشخص') + '</small></li>'
        );
    }

    async function runBulk(items, label){
        const total = items.length;
        if (!total) {
            alert(label === 'failed' ? "مطلب ناموفقی برای ارسال دوباره وجود ندارد." : "مطلب آماده ارسالی وجود ندارد.");
            return;
        }
        if (!confirm("تعداد " + total + " مطلب به‌صورت تکی ارسال می‌شود. ادامه می‌دهید؟")) {
            return;
        }

        openBulkModal(total);
        const counts = {ok: 0, exists: 0, error: 0};
        let done = 0;
        let stopped = false;

        for (let i = 0; i < items.length; i++) {
            const $item = $(items[i]);
            const title = $item.find("h3").text().trim();
            const index = $item.find(".item-index").text().trim();

            currentPost.html('<span class="bulk-current-dot"></span><span>' + bm.esc(title || "در حال ارسال مطلب") + '</span>');

            const res = await bm.sendItem($item);
            bm.showResult(res);
            bm.applyResult($item, res);

            if (res.status === 'ok') counts.ok++;
            else if (res.status === 'exists') counts.exists++;
            else { counts.error++; addFailed(index, title, res.message); }

            done = i + 1;
            successEl.text(counts.ok);
            existsEl.text(counts.exists);
            failEl.text(counts.error);
            setProgress(done, total);
            bm.updateCount();

            if (stopRequested && i < items.length - 1) { stopped = true; break; }
        }

        finishBulk(done, total, counts, stopped);
        bm.updateCount();
    }

    function startBulkSend(){
        if (bulkRunning) return;
        runBulk(getPendingItems(), 'all');
    }

    function startFailedSend(){
        if (bulkRunning) return;
        runBulk(getFailedItems(), 'failed');
    }

    $("#sendAllPosts").on("click", startBulkSend);
    $("#sendFailedPosts").on("click", startFailedSend);
    retryBtn.on("click", function(){
        if (bulkRunning) return;
        closeBulkModal();
        startFailedSend();
    });
    stopBtn.on("click", function(){
        if (!bulkRunning) return;
        stopRequested = true;
        stopBtn.prop("disabled", true).text("بعد از مطلب جاری متوقف می‌شود...");
    });
    finishBtn.on("click", closeBulkModal);
    closeBtn.on("click", closeBulkModal);
    modal.find(".bulk-modal-backdrop").on("click", function(){
        if (!bulkRunning) closeBulkModal();
    });

    $(document).on("keydown", function(e){
        if (e.key === "Escape" && !bulkRunning) closeBulkModal();
    });

    window.addEventListener("beforeunload", function(e){
        if (bulkRunning) { e.preventDefault(); e.returnValue = ""; }
    });
})(jQuery);
</script>

</body>
</html>
