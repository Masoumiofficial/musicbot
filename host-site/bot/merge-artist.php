<?php
/**
 * ادغام دو ترم هنرمند — ابزار یک‌باره
 *
 * مثال: ترم 315 «محمد رضا شجریان» (با فاصله) باید در ترم 314 «محمدرضا شجریان» ادغام شود:
 *   https://ahangban.ir/bot/merge-artist.php?from=315&to=314
 * صفحه ابتدا پیش‌نمایش را نشان می‌دهد (دو ترم و فهرست پست‌هایی که جابه‌جا می‌شوند)؛
 * با دکمه‌ی تأیید (POST + nonce) انجام می‌شود:
 *   - همه‌ی پست‌های ترم مبدأ به ترم مقصد اضافه و از ترم مبدأ جدا می‌شوند
 *   - (اختیاری، پیش‌فرض فعال) نام مبدأ در عنوان، متای artist، کلیدواژه‌ی رنک‌مث و متن پست به نام مقصد
 *     اصلاح می‌شود و لینک آرشیو مبدأ در متن پست به آرشیو مقصد تغییر می‌کند (نامک/آدرس پست عوض نمی‌شود)
 *   - (اختیاری، پیش‌فرض فعال) ترم مبدأ اگر خالی شد حذف می‌شود
 * ورود با همان یوزر/رمز پنل ربات.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
bm_require_auth();

require_once dirname(__DIR__) . '/wp-load.php';
set_time_limit(0);

header('Content-Type: text/html; charset=utf-8');

function bmm_h($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function bmm_taxonomy($configured) {
    foreach (array_unique(array_filter([sanitize_key((string) $configured), 'artist', 'singer'])) as $tax) {
        if (taxonomy_exists($tax)) {
            return $tax;
        }
    }
    return '';
}

/** بررسی مبدأ درخواست (Origin/Referer باید همین سایت باشد) */
function bmm_same_origin() {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        $from = strtolower((string) parse_url((string) $_SERVER[$header], PHP_URL_HOST));
        if ($from !== '' && $host !== '' && $from !== preg_replace('/:\d+$/', '', $host)) {
            return false;
        }
    }
    return true;
}

$taxonomy = bmm_taxonomy(isset($bot_artist_taxonomy) ? $bot_artist_taxonomy : '');
$from_id  = isset($_REQUEST['from']) ? (int) $_REQUEST['from'] : 0;
$to_id    = isset($_REQUEST['to']) ? (int) $_REQUEST['to'] : 0;
$is_post  = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ادغام ترم هنرمند</title>';
echo '<style>body{font-family:tahoma,sans-serif;font-size:13px;line-height:1.9;background:#f4f5f9;color:#1f2937;margin:0;padding:24px}';
echo '.box{max-width:900px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:20px 24px}';
echo 'h1{font-size:18px;margin:0 0 12px}h2{font-size:14px;margin:18px 0 8px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #e5e7eb;padding:6px 8px;text-align:right;font-size:12px}th{background:#f9fafb}';
echo '.ok{color:#047857}.err{color:#b91c1c}.warn{color:#b45309}.btn{display:inline-block;background:#635bff;color:#fff;border:0;border-radius:10px;padding:10px 18px;font-size:13px;cursor:pointer}';
echo '.btn.danger{background:#dc2626}label{display:block;margin:6px 0}code{background:#f3f4f6;padding:1px 5px;border-radius:5px}small{color:#6b7280}</style></head><body><div class="box">';
echo '<h1>ادغام ترم هنرمند</h1>';

if ($taxonomy === '') {
    echo '<p class="err">taxonomy هنرمند (artist/singer) در سایت پیدا نشد.</p></div></body></html>';
    exit;
}

if ($from_id <= 0 || $to_id <= 0) {
    echo '<p>شناسه‌ی ترم مبدأ و مقصد را در آدرس بدهید: <code>merge-artist.php?from=315&amp;to=314</code></p>';
    echo '<p><small>from = ترمی که باید خالی و حذف شود (مثلاً «محمد رضا شجریان»)، to = ترمی که باید بماند (مثلاً «محمدرضا شجریان»). شناسه‌ها را از پیشخوان → هنرمندان (ستون آدرس، tag_ID) بردارید.</small></p>';
    echo '</div></body></html>';
    exit;
}

$from = get_term($from_id, $taxonomy);
$to   = get_term($to_id, $taxonomy);
if (!$from || is_wp_error($from) || !$to || is_wp_error($to)) {
    echo '<p class="err">یکی از ترم‌ها در taxonomy «' . bmm_h($taxonomy) . '» پیدا نشد (from=' . $from_id . '، to=' . $to_id . ').</p></div></body></html>';
    exit;
}
if ($from_id === $to_id) {
    echo '<p class="err">مبدأ و مقصد یکی هستند.</p></div></body></html>';
    exit;
}

$from_link = get_term_link($from, $taxonomy);
$to_link   = get_term_link($to, $taxonomy);
$from_link = is_wp_error($from_link) ? '' : $from_link;
$to_link   = is_wp_error($to_link) ? '' : $to_link;

$object_ids = get_objects_in_term($from_id, $taxonomy);
$object_ids = is_wp_error($object_ids) ? [] : array_values(array_unique(array_map('intval', (array) $object_ids)));

echo '<table><tr><th></th><th>شناسه</th><th>نام</th><th>نامک</th><th>تعداد</th><th>آرشیو</th></tr>';
foreach (['مبدأ (خالی و حذف می‌شود)' => $from, 'مقصد (می‌ماند)' => $to] as $label => $term) {
    $link = ($term->term_id === $from_id) ? $from_link : $to_link;
    echo '<tr><td>' . bmm_h($label) . '</td><td>' . (int) $term->term_id . '</td><td>' . bmm_h($term->name) . '</td><td><code>' . bmm_h($term->slug) . '</code></td><td>' . (int) $term->count . '</td><td>' . ($link ? '<a href="' . bmm_h($link) . '" target="_blank" rel="noopener">' . bmm_h($link) . '</a>' : '—') . '</td></tr>';
}
echo '</table>';

$do_merge = $is_post && !empty($_POST['confirm']);
if ($do_merge) {
    $nonce = isset($_POST['bm_nonce']) ? (string) $_POST['bm_nonce'] : '';
    if (!bmm_same_origin() || $nonce === '' || !wp_verify_nonce($nonce, 'bm_merge_' . $from_id . '_' . $to_id)) {
        echo '<p class="err">اعتبار فرم تأیید نشد؛ صفحه را دوباره باز کنید و تأیید را بزنید.</p></div></body></html>';
        exit;
    }
    $fix_text    = !empty($_POST['fix_text']);
    $delete_term = !empty($_POST['delete_term']);

    echo '<h2>نتیجه</h2><table><tr><th>پست</th><th>عنوان</th><th>ترم</th><th>متن/متاها</th></tr>';
    $moved = 0;
    foreach ($object_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            continue;
        }
        $set = wp_set_object_terms($post_id, [$to_id], $taxonomy, true);
        $rem = wp_remove_object_terms($post_id, [$from_id], $taxonomy);
        $term_ok = !is_wp_error($set) && !is_wp_error($rem) && $rem === true;
        if ($term_ok) {
            $moved++;
        }

        $text_note = '—';
        if ($fix_text && $term_ok) {
            $changed = [];
            $new_title = str_replace($from->name, $to->name, $post->post_title);
            $new_content = $post->post_content;
            if ($from_link !== '' && $to_link !== '') {
                $new_content = str_replace($from_link, $to_link, $new_content);
            }
            $new_content = str_replace($from->name, $to->name, $new_content);
            if ($new_title !== $post->post_title || $new_content !== $post->post_content) {
                $upd = wp_update_post(['ID' => $post_id, 'post_title' => $new_title, 'post_content' => $new_content], true);
                $changed[] = is_wp_error($upd) ? 'عنوان/متن: ' . $upd->get_error_message() : 'عنوان/متن';
            }
            foreach (['artist', 'rank_math_focus_keyword', 'rank_math_title', 'rank_math_description', '_yoast_wpseo_focuskw', '_yoast_wpseo_title', '_yoast_wpseo_metadesc'] as $meta_key) {
                $value = get_post_meta($post_id, $meta_key, true);
                if (is_string($value) && $value !== '' && strpos($value, $from->name) !== false) {
                    update_post_meta($post_id, $meta_key, str_replace($from->name, $to->name, $value));
                    $changed[] = $meta_key;
                }
            }
            $text_note = $changed ? implode('، ', $changed) : 'نیازی به تغییر نبود';
        }

        echo '<tr><td>' . (int) $post_id . '</td><td><a href="' . bmm_h(get_permalink($post_id)) . '" target="_blank" rel="noopener">' . bmm_h(get_the_title($post_id)) . '</a> <small>(' . bmm_h($post->post_status) . ')</small></td>';
        echo '<td class="' . ($term_ok ? 'ok' : 'err') . '">' . ($term_ok ? 'به مقصد منتقل شد' : 'خطا: ' . bmm_h(is_wp_error($set) ? $set->get_error_message() : (is_wp_error($rem) ? $rem->get_error_message() : 'جدا نشد'))) . '</td>';
        echo '<td>' . bmm_h($text_note) . '</td></tr>';
    }
    echo '</table>';

    if (function_exists('wp_update_term_count_now')) {
        wp_update_term_count_now([$from_id, $to_id], $taxonomy);
    }
    clean_term_cache([$from_id, $to_id], $taxonomy);
    $from_after = get_term($from_id, $taxonomy);
    $to_after   = get_term($to_id, $taxonomy);
    $from_count = ($from_after && !is_wp_error($from_after)) ? (int) $from_after->count : 0;
    $to_count   = ($to_after && !is_wp_error($to_after)) ? (int) $to_after->count : 0;

    echo '<p class="ok">' . $moved . ' پست از ' . count($object_ids) . ' پست منتقل شد. تعداد مقصد اکنون ' . $to_count . ' و مبدأ ' . $from_count . ' است.</p>';

    if ($delete_term) {
        $remaining = get_objects_in_term($from_id, $taxonomy);
        $remaining = is_wp_error($remaining) ? [] : (array) $remaining;
        if (empty($remaining)) {
            $del = wp_delete_term($from_id, $taxonomy);
            if ($del === true) {
                echo '<p class="ok">ترم مبدأ (' . $from_id . ' — ' . bmm_h($from->name) . ') حذف شد. آدرس قدیمی آرشیو آن (' . bmm_h($from_link) . ') دیگر وجود ندارد؛ اگر در گوگل ایندکس شده بود، در رنک‌مث یک ریدایرکت 301 به ' . bmm_h($to_link) . ' بگذارید.</p>';
            } else {
                echo '<p class="err">ترم مبدأ حذف نشد: ' . bmm_h(is_wp_error($del) ? $del->get_error_message() : 'خطای نامشخص') . '</p>';
            }
        } else {
            echo '<p class="warn">ترم مبدأ هنوز ' . count($remaining) . ' آیتم دارد و حذف نشد.</p>';
        }
    }

    echo '<p><a class="btn" href="merge-artist.php?from=' . $from_id . '&amp;to=' . $to_id . '">بررسی دوباره</a></p>';
    echo '</div></body></html>';
    exit;
}

// ---- پیش‌نمایش
echo '<h2>پست‌هایی که از مبدأ به مقصد منتقل می‌شوند (' . count($object_ids) . ')</h2>';
if (empty($object_ids)) {
    echo '<p class="warn">ترم مبدأ هیچ پستی ندارد؛ فقط حذف ترم انجام می‌شود.</p>';
} else {
    echo '<table><tr><th>شناسه</th><th>عنوان</th><th>وضعیت</th><th>عنوان بعد از اصلاح</th></tr>';
    foreach ($object_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            continue;
        }
        $new_title = str_replace($from->name, $to->name, $post->post_title);
        echo '<tr><td>' . (int) $post_id . '</td><td><a href="' . bmm_h(get_permalink($post_id)) . '" target="_blank" rel="noopener">' . bmm_h($post->post_title) . '</a></td><td>' . bmm_h($post->post_status) . '</td><td>' . ($new_title !== $post->post_title ? bmm_h($new_title) : '<small>بدون تغییر</small>') . '</td></tr>';
    }
    echo '</table>';
}

echo '<form method="post" action="merge-artist.php" style="margin-top:16px">';
echo '<input type="hidden" name="from" value="' . $from_id . '"><input type="hidden" name="to" value="' . $to_id . '">';
echo '<input type="hidden" name="bm_nonce" value="' . bmm_h(wp_create_nonce('bm_merge_' . $from_id . '_' . $to_id)) . '">';
echo '<label><input type="checkbox" name="fix_text" value="1" checked> نام «' . bmm_h($from->name) . '» در عنوان، متن، متای artist و کلیدواژه‌ی سئوی این پست‌ها به «' . bmm_h($to->name) . '» اصلاح شود (آدرس پست‌ها عوض نمی‌شود)</label>';
echo '<label><input type="checkbox" name="delete_term" value="1" checked> ترم مبدأ بعد از خالی شدن حذف شود</label>';
echo '<p><button class="btn danger" type="submit" name="confirm" value="1" onclick="return confirm(\'ادغام انجام شود؟ این کار برگشت‌پذیر نیست.\')">تأیید و ادغام</button></p>';
echo '</form>';
echo '<p><small>بعد از ادغام، ربات خودش برای این هنرمند ترم جدید نمی‌سازد: نام‌ها با نادیده گرفتن فاصله/نیم‌فاصله و ي/ك عربی مقایسه می‌شوند و نامک انگلیسی هم بررسی می‌شود.</small></p>';
echo '</div></body></html>';
