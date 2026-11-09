<?php
/**
 * لینک‌های داخلی بدون دامنه + تغییر دامنه (ویرایش دسته‌ای پست‌های قدیمی)
 *
 *   /bot/domain-tool.php                 گزارش: چند پست لینک مطلق به دامنه‌ی سایت دارند، چند پست لینک بدون دامنه دارند،
 *                                        لینک‌های دانلود (متاهای music320/music128/online و …) روی کدام میزبان‌ها هستند
 *   «نسبی‌سازی لینک‌های داخلی»           در متن و خلاصه‌ی پست‌ها، لینک‌های مطلق به دامنه‌ی خود سایت (با/بی www، http/https)
 *                                        → بدون دامنه: https://ahangban.ir/music/ → /music/  (فقط صفت‌های href/src/poster/data-src؛
 *                                        متن ساده و لینک‌های خارجی دست نمی‌خورند). دسته‌دسته، ادامه‌پذیر، اول «فقط پیش‌نمایش».
 *                                        می‌توانید دامنه‌های قدیمی دیگر را هم اضافه کنید (مثلاً بعد از تغییر دامنه: ahangban.ir).
 *   «بازگشت به لینک کامل»                 عکس عمل بالا: /music/ → https://دامنه‌ی-فعلی/music/
 *   «تعویض دامنه در متن پست‌ها»           لینک‌های مطلق روی یک دامنه (مثلاً dl.ahangban.ir) → همان مسیر روی دامنه‌ی جدید
 *   «تعویض دامنه‌ی لینک‌های دانلود»       همان کار روی متاهای لینک دانلود (music320، music128، online، آلبوم، ویدیو) با یک
 *                                        دستور SQL برای هر کلید — در چند ثانیه، بدون اجرای هوک‌های ذخیره‌ی پست
 *
 * نوشتن در دیتابیس مستقیم ($wpdb) است و نه wp_update_post: هوک‌های save_post (ارسال تلگرام، کش، …) اجرا نمی‌شوند و
 * تاریخ ویرایش پست عوض نمی‌شود. فقط پشت همان یوزر/رمز ربات. پیش از اجرای واقعی یک پشتیبان از دیتابیس بگیرید.
 *
 * چرا بدون دامنه؟ مرورگر «/music/» را روی همان دامنه‌ای باز می‌کند که صفحه از آن آمده؛ پس با تغییر دامنه‌ی سایت لینک‌های
 * متن پست‌ها خودبه‌خود درست‌اند. برای فید RSS و افزونه‌هایی که متن را بیرون سایت می‌برند، افزونه‌ی
 * wp-content/mu-plugins/ahangban-seo.php همین لینک‌ها را در خروجی با دامنه‌ی فعلی کامل می‌کند.
 */
include('config.php');
require_once __DIR__ . '/auth.php';
bm_require_auth();

require('../wp-load.php');

$bm_functions_only = true;
require_once __DIR__ . '/script.php';

@set_time_limit(0);
@ignore_user_abort(true);

$dt_option   = 'bm_domain_tool_state';
$dt_self     = strtok($_SERVER['REQUEST_URI'], '?');
$dt_statuses = ['publish', 'draft', 'pending', 'future', 'private'];
$dt_types    = ['post', 'page'];

/** کلیدهای متای لینک دانلود (قالب + پست‌ساز). «%» = الگوی LIKE برای ردیف‌های تکرارشونده‌ی آلبوم */
$dt_dl_keys = [
    'music320', 'music128', 'online',
    'album320', 'album128', 'album_%_albumlink320', 'album_%_albumlink128',
    'video1080', 'video720', 'video480', 'video_low', 'video_medium',
];

function dt_h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** ورودی چندخطی/با کاما → فهرست میزبان‌ها (بدون پروتکل و مسیر) */
function dt_hosts_from_input($text) {
    $hosts = [];
    foreach (preg_split('/[\s,،]+/u', (string) $text) as $host) {
        $host = strtolower(trim($host));
        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = trim((string) preg_replace('#[/?\#].*$#', '', $host), "/ \t");
        if ($host !== '' && preg_match('/^[a-z0-9.\-]+(?::\d+)?$/', $host)) {
            $hosts[$host] = true;
        }
    }
    return array_keys($hosts);
}

/** «https://dl.ahangban.com» از ورودی کاربر (اگر پروتکل نداشت https) */
function dt_origin_from_input($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $text)) {
        $text = 'https://' . ltrim($text, '/');
    }
    return preg_match('#^(https?://[a-z0-9.\-]+(?::\d+)?)#i', $text, $m) ? strtolower($m[1]) : '';
}

function dt_types_sql(array $types) {
    global $wpdb;
    $types = array_values(array_filter(array_map('sanitize_key', $types)));
    if (empty($types)) {
        $types = ['post'];
    }
    return implode(',', array_map(function ($t) use ($wpdb) {
        return $wpdb->prepare('%s', $t);
    }, $types));
}

function dt_statuses_sql() {
    global $wpdb, $dt_statuses;
    return implode(',', array_map(function ($s) use ($wpdb) {
        return $wpdb->prepare('%s', $s);
    }, $dt_statuses));
}

/** شمار پست‌ها */
function dt_count_posts(array $types) {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (" . dt_types_sql($types) . ') AND post_status IN (' . dt_statuses_sql() . ')');
}

/** شمار پست‌هایی که در متن/خلاصه‌شان «://میزبان» یکی از این میزبان‌ها هست (تقریبی؛ شامل آدرس در متن ساده هم می‌شود) */
function dt_count_posts_with_hosts(array $types, array $hosts) {
    global $wpdb;
    $parts = [];
    foreach ($hosts as $host) {
        $like    = '%' . $wpdb->esc_like('//' . $host) . '%';
        $parts[] = $wpdb->prepare('post_content LIKE %s OR post_excerpt LIKE %s', $like, $like);
    }
    if (empty($parts)) {
        return 0;
    }
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (" . dt_types_sql($types) . ') AND post_status IN (' . dt_statuses_sql() . ') AND (' . implode(' OR ', $parts) . ')');
}

/** شمار پست‌هایی که لینک بدون دامنه دارند (href="/… یا src="/…) */
function dt_count_posts_relative(array $types) {
    global $wpdb;
    $parts = [];
    foreach (['href="/', "href='/", 'src="/', "src='/"] as $needle) {
        $parts[] = $wpdb->prepare('post_content LIKE %s', '%' . $wpdb->esc_like($needle) . '%');
    }
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (" . dt_types_sql($types) . ') AND post_status IN (' . dt_statuses_sql() . ') AND (' . implode(' OR ', $parts) . ')');
}

/** دسته‌ی بعدی پست‌ها (قدیمی‌ترین اول، از روی ID) */
function dt_next_posts($after, $limit, array $types) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts}
         WHERE ID > %d AND post_type IN (" . dt_types_sql($types) . ') AND post_status IN (' . dt_statuses_sql() . ')
         ORDER BY ID ASC LIMIT %d',
        (int) $after,
        (int) $limit
    ));
}

/** ذخیره‌ی مستقیم (بدون هوک‌های save_post و بدون تغییر تاریخ ویرایش) */
function dt_save_post($post_id, $content, $excerpt) {
    global $wpdb;
    $ok = $wpdb->update($wpdb->posts, ['post_content' => $content, 'post_excerpt' => $excerpt], ['ID' => (int) $post_id], ['%s', '%s'], ['%d']);
    clean_post_cache((int) $post_id);
    return $ok !== false;
}

/**
 * یک دسته از پست‌ها را با عمل خواسته‌شده پردازش می‌کند
 *   $op = relative | absolute | swap،  $hosts و $origin مثل bm_links_transform()
 * خروجی: ['scanned', 'changed_posts', 'changed_links', 'failed', 'last', 'more', 'rows' => [[id, title, links, pairs]], 'pairs']
 */
function dt_process_batch($op, array $hosts, $origin, $after, $limit, array $types, $dry, $max_pairs = 40) {
    $items = dt_next_posts($after, $limit, $types);
    $out   = ['scanned' => 0, 'changed_posts' => 0, 'changed_links' => 0, 'failed' => 0, 'last' => (int) $after, 'more' => false, 'rows' => [], 'pairs' => []];

    foreach ($items as $item) {
        $out['scanned']++;
        $out['last'] = (int) $item->ID;
        $content     = bm_links_transform((string) $item->post_content, $op, $hosts, $origin, 5);
        $excerpt     = bm_links_transform((string) $item->post_excerpt, $op, $hosts, $origin, 2);
        $links       = (int) $content['changed'] + (int) $excerpt['changed'];
        if ($links === 0) {
            continue;
        }
        $saved = true;
        if (!$dry) {
            $saved = dt_save_post($item->ID, $content['html'], $excerpt['html']);
        }
        if ($saved) {
            $out['changed_posts']++;
            $out['changed_links'] += $links;
        } else {
            $out['failed']++;
        }
        $pairs = array_merge($content['pairs'], $excerpt['pairs']);
        foreach ($pairs as $pair) {
            if (count($out['pairs']) < $max_pairs) {
                $out['pairs'][] = [(int) $item->ID, $pair[0], $pair[1]];
            }
        }
        if (count($out['rows']) < 60) {
            $out['rows'][] = ['id' => (int) $item->ID, 'title' => (string) $item->post_title, 'links' => $links, 'saved' => $saved, 'pairs' => $pairs];
        }
    }
    $out['more'] = count($items) >= $limit;
    return $out;
}

/* ---------- متاهای لینک دانلود ---------- */

/** شرط LIKE روی meta_key (کلید ساده یا الگوی % برای ردیف‌های تکرارشونده) */
function dt_meta_key_sql($key) {
    global $wpdb;
    if (strpos($key, '%') !== false) {
        return $wpdb->prepare('meta_key LIKE %s', $key);
    }
    return $wpdb->prepare('meta_key = %s', $key);
}

/** پیشوندهای یک میزبان در مقدار متا: https://host/  http://host/  //host/ (و همان‌ها بدون اسلش پایانی = خود دامنه) */
function dt_host_prefixes($host) {
    $host = strtolower(trim((string) $host, "/ \t"));
    if ($host === '') {
        return [];
    }
    return ['https://' . $host . '/', 'http://' . $host . '/', '//' . $host . '/'];
}

/** شمار ردیف‌های هر کلید که مقدارشان با یکی از پیشوندهای میزبان شروع می‌شود */
function dt_meta_count($key, $host) {
    global $wpdb;
    $parts = [];
    foreach (dt_host_prefixes($host) as $prefix) {
        $parts[] = $wpdb->prepare('meta_value LIKE %s', $wpdb->esc_like($prefix) . '%');
    }
    if (empty($parts)) {
        return 0;
    }
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE " . dt_meta_key_sql($key) . ' AND (' . implode(' OR ', $parts) . ')');
}

/** شمار ردیف‌های غیرخالی یک کلید */
function dt_meta_total($key) {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE " . dt_meta_key_sql($key) . " AND meta_value <> ''");
}

/** میزبان‌های دیده‌شده در مقدارهای این کلیدها (نمونه‌ی حداکثر $sample ردیف غیرخالی که روی $current_host نیستند) */
function dt_meta_other_hosts(array $keys, $current_host, $sample = 2000) {
    global $wpdb;
    $key_sql = [];
    foreach ($keys as $key) {
        $key_sql[] = dt_meta_key_sql($key);
    }
    $not = [];
    foreach (dt_host_prefixes($current_host) as $prefix) {
        $not[] = $wpdb->prepare('meta_value NOT LIKE %s', $wpdb->esc_like($prefix) . '%');
    }
    $values = $wpdb->get_col(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE (" . implode(' OR ', $key_sql) . ") AND meta_value <> ''"
        . (!empty($not) ? ' AND ' . implode(' AND ', $not) : '')
        . ' ORDER BY meta_id DESC LIMIT ' . (int) $sample
    );
    $hosts = [];
    foreach ((array) $values as $value) {
        $host = strtolower((string) parse_url(trim((string) $value), PHP_URL_HOST));
        $host = $host !== '' ? $host : '(بدون میزبان / لینک نسبی)';
        $hosts[$host] = isset($hosts[$host]) ? $hosts[$host] + 1 : 1;
    }
    arsort($hosts);
    return $hosts;
}

/**
 * تعویض میزبان مقدار متاها: هر مقدار که با https://old/ یا http://old/ یا //old/ شروع می‌شود → new_origin/…
 * یک UPDATE برای هر (کلید × پیشوند). خروجی: [key => تعداد ردیف‌های عوض‌شده]
 */
function dt_meta_swap(array $keys, $old_host, $new_origin, $dry) {
    global $wpdb;
    $result     = [];
    $new_origin = rtrim((string) $new_origin, '/');
    if ($new_origin === '') {
        return $result;
    }
    foreach ($keys as $key) {
        $n = 0;
        foreach (dt_host_prefixes($old_host) as $prefix) {
            $where = dt_meta_key_sql($key) . ' AND ' . $wpdb->prepare('meta_value LIKE %s', $wpdb->esc_like($prefix) . '%');
            if ($dry) {
                $n += (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE {$where}");
            } else {
                $done = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = CONCAT(%s, SUBSTRING(meta_value, %d)) WHERE {$where}",
                    $new_origin . '/',
                    strlen($prefix) + 1
                ));
                $n += $done === false ? 0 : (int) $done;
            }
        }
        $result[$key] = $n;
    }
    if (!$dry) {
        wp_cache_flush();
    }
    return $result;
}

/* ---------- وضعیت و ورودی‌ها ---------- */
$dt_state = get_option($dt_option);
if (!is_array($dt_state)) {
    $dt_state = [];
}
$dt_origin     = bm_site_origin();
$dt_home_host  = strtolower((string) parse_url($dt_origin, PHP_URL_HOST));
$dt_home_hosts = bm_internal_hosts();
$dt_dl_origin  = bm_dl_origin(); // config.php یا AHANGBAN_DL_HOST یا خودکار (dl. + دامنه‌ی سایت)
$dt_dl_host    = strtolower((string) parse_url($dt_dl_origin, PHP_URL_HOST));
$dt_dl_sources = ['config' => 'config.php ($urldlhost)', 'constant' => 'AHANGBAN_DL_HOST در wp-config.php', 'auto' => 'خودکار: dl. + دامنه‌ی سایت', 'none' => 'تنظیم نشده'];
$dt_dl_source  = isset($GLOBALS['bm_dl_source'], $dt_dl_sources[$GLOBALS['bm_dl_source']]) ? $dt_dl_sources[$GLOBALS['bm_dl_source']] : '';
// هاست دانلود از دید افزونه‌ی خروجی (ahangban-seo) — باید با ربات یکی باشد
$dt_plugin_dl  = function_exists('ahangban_dl_origin') ? (string) ahangban_dl_origin() : '';
// هاست‌های دانلود قدیمی (AHANGBAN_OLD_DL_HOSTS در wp-config.php): افزونه در لحظه‌ی خواندن آن‌ها را به هاست فعلی می‌برد
$dt_old_dl     = defined('AHANGBAN_OLD_DL_HOSTS') ? array_values(array_diff(dt_hosts_from_input(AHANGBAN_OLD_DL_HOSTS), [$dt_dl_host])) : [];
$dt_old_site   = defined('AHANGBAN_OLD_HOSTS') ? array_values(array_diff(dt_hosts_from_input(AHANGBAN_OLD_HOSTS), $dt_home_hosts)) : [];
$dt_labels     = ['relative' => 'نسبی‌سازی لینک‌های داخلی', 'absolute' => 'بازگشت به لینک کامل', 'swap' => 'تعویض دامنه در متن پست‌ها'];

header('Content-Type: text/html; charset=utf-8');

$dt_action  = '';
$dt_batch   = null;
$dt_meta    = null;
$dt_error   = '';
$dt_limit   = 200;
$dt_dry     = true;
$dt_more    = false;
$dt_hosts   = [];
$dt_target  = '';
$dt_types_r = $dt_types;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    check_admin_referer('bm_domain_tool');
    $dt_action  = (string) $_POST['action'];
    $dt_limit   = isset($_POST['n']) ? max(10, min(1000, (int) $_POST['n'])) : 200;
    $dt_dry     = !empty($_POST['dry']);
    $dt_types_r = isset($_POST['types']) && is_array($_POST['types']) ? array_values(array_intersect($dt_types, array_map('sanitize_key', $_POST['types']))) : $dt_types;
    if (empty($dt_types_r)) {
        $dt_types_r = ['post'];
    }

    if ($dt_action === 'reset') {
        delete_option($dt_option);
        $dt_state  = [];
        $dt_action = '';
    } elseif (in_array($dt_action, ['relative', 'absolute', 'swap'], true)) {
        // میزبان‌ها/مقصد
        if ($dt_action === 'relative') {
            $dt_hosts  = bm_internal_hosts(dt_hosts_from_input(isset($_POST['hosts']) ? $_POST['hosts'] : ''));
            $dt_target = '';
        } elseif ($dt_action === 'absolute') {
            $dt_hosts  = [];
            $dt_target = dt_origin_from_input(isset($_POST['target']) && trim((string) $_POST['target']) !== '' ? $_POST['target'] : $dt_origin);
        } else {
            $dt_hosts  = dt_hosts_from_input(isset($_POST['hosts']) ? $_POST['hosts'] : '');
            $dt_target = dt_origin_from_input(isset($_POST['target']) ? $_POST['target'] : '');
        }

        if ($dt_action === 'relative' && empty($dt_hosts)) {
            $dt_error = 'میزبانی برای نسبی‌سازی مشخص نیست.';
        } elseif ($dt_action === 'absolute' && $dt_target === '') {
            $dt_error = 'دامنه‌ی مقصد معتبر نیست.';
        } elseif ($dt_action === 'swap' && (empty($dt_hosts) || $dt_target === '')) {
            $dt_error = 'دامنه‌ی قدیمی و دامنه‌ی جدید هر دو لازم‌اند.';
        } elseif ($dt_action === 'swap' && in_array(strtolower((string) parse_url($dt_target, PHP_URL_HOST)), $dt_hosts, true)) {
            $dt_error = 'دامنه‌ی جدید با دامنه‌ی قدیمی یکی است.';
        }

        if ($dt_error === '') {
            $fresh = !empty($_POST['fresh']) || empty($dt_state['op']) || $dt_state['op'] !== $dt_action
                || (bool) $dt_state['dry'] !== $dt_dry || $dt_state['hosts'] !== $dt_hosts || $dt_state['target'] !== $dt_target || $dt_state['types'] !== $dt_types_r;
            if ($fresh) {
                $dt_state = [
                    'op' => $dt_action, 'dry' => $dt_dry, 'hosts' => $dt_hosts, 'target' => $dt_target, 'types' => $dt_types_r,
                    'cursor' => 0, 'scanned' => 0, 'changed_posts' => 0, 'changed_links' => 0, 'failed' => 0,
                    'pairs' => [], 'started' => time(), 'finished' => false,
                ];
            }
            $after    = isset($_POST['after']) && (int) $_POST['after'] > 0 ? (int) $_POST['after'] : (int) $dt_state['cursor'];
            $dt_batch = dt_process_batch($dt_action, $dt_hosts, $dt_target, $after, $dt_limit, $dt_types_r, $dt_dry, 40);
            $dt_more  = $dt_batch['more'];

            foreach (['scanned', 'changed_posts', 'changed_links', 'failed'] as $k) {
                $dt_state[$k] = (int) $dt_state[$k] + (int) $dt_batch[$k];
            }
            foreach ($dt_batch['pairs'] as $pair) {
                if (count($dt_state['pairs']) < 40) {
                    $dt_state['pairs'][] = $pair;
                }
            }
            $dt_state['cursor']   = $dt_more ? (int) $dt_batch['last'] : 0;
            $dt_state['finished'] = !$dt_more;
            $dt_state['updated']  = time();
            $dt_state['n']        = $dt_limit;
            update_option($dt_option, $dt_state, false);
            if (!empty($_POST['stop'])) {
                $dt_more = false;
            }
        } else {
            $dt_action = 'error';
        }
    } elseif ($dt_action === 'meta_swap') {
        $old_host = dt_hosts_from_input(isset($_POST['old_host']) ? $_POST['old_host'] : '');
        $old_host = !empty($old_host) ? $old_host[0] : '';
        $target   = dt_origin_from_input(isset($_POST['target']) ? $_POST['target'] : '');
        $keys     = [];
        foreach (preg_split('/[\s,،]+/u', (string) (isset($_POST['keys']) ? $_POST['keys'] : '')) as $key) {
            $key = trim($key);
            if ($key !== '' && preg_match('/^[A-Za-z0-9_%\-]+$/', $key)) {
                $keys[] = $key;
            }
        }
        if ($old_host === '' || $target === '') {
            $dt_error  = 'دامنه‌ی قدیمی و دامنه‌ی جدید هر دو لازم‌اند.';
            $dt_action = 'error';
        } elseif (strtolower((string) parse_url($target, PHP_URL_HOST)) === $old_host) {
            $dt_error  = 'دامنه‌ی جدید با دامنه‌ی قدیمی یکی است.';
            $dt_action = 'error';
        } elseif (empty($keys)) {
            $dt_error  = 'کلید متایی مشخص نیست.';
            $dt_action = 'error';
        } else {
            $dt_meta = ['old' => $old_host, 'target' => $target, 'dry' => $dt_dry, 'result' => dt_meta_swap($keys, $old_host, $target, $dt_dry)];
        }
    }
}

/* ---------- شمارش‌ها (گزارش) ---------- */
$dt_running = in_array($dt_action, ['relative', 'absolute', 'swap'], true) && $dt_batch !== null;
$dt_report  = null;
if (!$dt_running || !$dt_more) {
    $dt_report = [
        'posts'          => dt_count_posts($dt_types),
        'abs_home'       => dt_count_posts_with_hosts($dt_types, $dt_home_hosts),
        'relative'       => dt_count_posts_relative($dt_types),
        'abs_dl'         => $dt_dl_host !== '' ? dt_count_posts_with_hosts($dt_types, [$dt_dl_host]) : 0,
        'options_home'   => (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_value LIKE %s", '%' . $GLOBALS['wpdb']->esc_like('//' . $dt_home_host) . '%')),
        'menu_home'      => (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_menu_item_url' AND meta_value LIKE %s", '%' . $GLOBALS['wpdb']->esc_like('//' . $dt_home_host) . '%')),
        'meta'           => [],
        'meta_other'     => $dt_dl_host !== '' ? dt_meta_other_hosts($dt_dl_keys, $dt_dl_host) : [],
    ];
    foreach ($dt_dl_keys as $key) {
        $total = dt_meta_total($key);
        if ($total === 0) {
            continue;
        }
        $old_n = 0;
        foreach ($dt_old_dl as $old_host) {
            $old_n += dt_meta_count($key, $old_host);
        }
        $dt_report['meta'][$key] = ['total' => $total, 'current' => $dt_dl_host !== '' ? dt_meta_count($key, $dt_dl_host) : 0, 'old' => $old_n];
    }
}
$dt_processed = (int) (isset($dt_state['scanned']) ? $dt_state['scanned'] : 0);
$dt_total_r   = dt_count_posts(isset($dt_state['types']) && is_array($dt_state['types']) ? $dt_state['types'] : $dt_types);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>لینک‌های داخلی و تغییر دامنه</title>
<style>
body{font-family:tahoma,sans-serif;font-size:13px;line-height:1.9;background:#f5f6fa;color:#1f2937;margin:0;padding:20px}
.box{background:#fff;border:1px solid #e3e6ee;border-radius:12px;padding:16px 18px;margin-bottom:16px}
h1{font-size:18px;margin:0 0 8px}h2{font-size:15px;margin:0 0 10px}h3{font-size:13px;margin:12px 0 6px}
table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #eef0f4;padding:7px 8px;text-align:right;vertical-align:top}
th{background:#f8f9fc;font-weight:bold;font-size:12px}
.ok{color:#0b9b70;font-weight:bold}.bad{color:#dc2626;font-weight:bold}.warn{color:#b45309;font-weight:bold}.muted{color:#6b7280}
code,.ltr{font-family:Consolas,monospace;font-size:12px;direction:ltr;unicode-bidi:embed;display:inline-block}
pre{font-family:Consolas,monospace;font-size:12px;direction:ltr;text-align:left;background:#f8f9fc;border:1px solid #eef0f4;border-radius:8px;padding:10px;overflow:auto}
button,.btn{border:0;border-radius:10px;padding:9px 18px;background:#635bff;color:#fff;cursor:pointer;font-family:inherit;font-size:13px;text-decoration:none;display:inline-block}
.btn.gray,button.gray{background:#e5e7eb;color:#111}.btn.red,button.red{background:#dc2626}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px}
.card{background:#f8f9fc;border:1px solid #eef0f4;border-radius:12px;padding:12px 14px}
input[type=text],textarea,select{border:1px solid #d7dbe4;border-radius:8px;padding:6px 8px;font-size:12px;font-family:inherit;width:100%;box-sizing:border-box}
textarea{min-height:54px;direction:ltr}
input.ltr{direction:ltr}
label{display:block;margin:6px 0 2px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.row label{display:inline-flex;align-items:center;gap:6px;margin:0}
.bar{height:10px;background:#e5e7eb;border-radius:6px;overflow:hidden;margin:6px 0}.bar i{display:block;height:100%;background:#635bff}
small{font-size:11px}
.num{font-weight:bold;font-size:15px}
ol li{margin-bottom:6px}
</style>
</head>
<body>
<div class="box">
  <h1>لینک‌های داخلی بدون دامنه و تغییر دامنه</h1>
  <div class="toolbar">
    <a class="btn gray" href="<?php echo dt_h($dt_self); ?>">گزارش</a>
    <a class="btn gray" href="dl-repair.php">ترمیم هاست دانلود</a>
    <a class="btn gray" href="webp-batch.php">WebP کاورها</a>
    <a class="btn gray" href="watermark-batch.php">لوگو روی کاورها</a>
    <a class="btn gray" href="meta-compare.php">مقایسه‌ی متاها</a>
  </div>
  <p class="muted" style="margin:0"><small>
    دامنه‌ی فعلی سایت: <code><?php echo dt_h($dt_origin); ?></code> — هاست دانلود: <code><?php echo $dt_dl_origin !== '' ? dt_h($dt_dl_origin) : '—'; ?></code><?php echo $dt_dl_source !== '' ? ' (' . dt_h($dt_dl_source) . ')' : ''; ?>
    <?php if ($dt_plugin_dl !== '' && strtolower($dt_plugin_dl) !== strtolower($dt_dl_origin)) : ?>
    — <span class="bad">افزونه‌ی ahangban-seo هاست دانلود را <code><?php echo dt_h($dt_plugin_dl); ?></code> می‌داند؛ با ربات یکی نیست. <code>$urldlhost</code> را در config.php خالی بگذارید و اگر قاعده‌ی «dl. + دامنه» درست نیست، <code>AHANGBAN_DL_HOST</code> را در wp-config.php تعریف کنید.</span>
    <?php endif; ?>
    <?php if (!empty($dt_old_dl)) : ?>
    — هاست‌های دانلود قدیمی (AHANGBAN_OLD_DL_HOSTS): <code><?php echo dt_h(implode('، ', $dt_old_dl)); ?></code> <span class="ok">→ در لحظه‌ی خواندن متا و در خروجی متن به هاست فعلی می‌روند؛ برای دائمی‌شدن مورد ۴ را اجرا کنید</span>
    <?php endif; ?>
    <?php if (!empty($dt_old_site)) : ?>
    — دامنه‌های قدیمی سایت (AHANGBAN_OLD_HOSTS): <code><?php echo dt_h(implode('، ', $dt_old_site)); ?></code>
    <?php endif; ?>
    — لینک‌های داخلی پست‌های تازه: <?php echo bm_relative_links_enabled() ? '<span class="ok">بدون دامنه ($bot_relative_links = true)</span>' : '<span class="warn">با دامنه ($bot_relative_links = false)</span>'; ?>
    — تغییرها مستقیم در دیتابیس نوشته می‌شود (بدون هوک save_post و بدون تغییر تاریخ ویرایش). پیش از اجرای واقعی پشتیبان بگیرید.
  </small></p>
</div>

<?php if ($dt_action === 'error') : ?>
<div class="box"><span class="bad"><?php echo dt_h($dt_error); ?></span></div>
<?php endif; ?>

<?php if ($dt_meta !== null) : ?>
<div class="box">
  <h2>تعویض دامنه‌ی لینک‌های دانلود — <?php echo $dt_meta['dry'] ? '<span class="warn">فقط پیش‌نمایش</span>' : '<span class="ok">انجام شد</span>'; ?></h2>
  <p><code><?php echo dt_h($dt_meta['old']); ?></code> ← <code><?php echo dt_h($dt_meta['target']); ?></code></p>
  <table>
    <tr><th>کلید متا</th><th><?php echo $dt_meta['dry'] ? 'ردیف‌هایی که عوض می‌شوند' : 'ردیف‌های عوض‌شده'; ?></th></tr>
    <?php $sum = 0; foreach ($dt_meta['result'] as $key => $n) : $sum += $n; ?>
    <tr><td><code><?php echo dt_h($key); ?></code></td><td class="<?php echo $n > 0 ? 'ok' : 'muted'; ?>"><?php echo (int) $n; ?></td></tr>
    <?php endforeach; ?>
    <tr><th>جمع</th><th><?php echo (int) $sum; ?></th></tr>
  </table>
  <?php if ($dt_meta['dry'] && $sum > 0) : ?>
  <form method="post" style="margin-top:10px">
    <?php wp_nonce_field('bm_domain_tool'); ?>
    <input type="hidden" name="action" value="meta_swap">
    <input type="hidden" name="old_host" value="<?php echo dt_h($dt_meta['old']); ?>">
    <input type="hidden" name="target" value="<?php echo dt_h($dt_meta['target']); ?>">
    <input type="hidden" name="keys" value="<?php echo dt_h(implode(' ', array_keys($dt_meta['result']))); ?>">
    <button type="submit" class="red" onclick="return confirm('<?php echo (int) $sum; ?> ردیف متا به دامنه‌ی جدید عوض می‌شود. پشتیبان گرفته‌اید؟');">اجرای واقعی روی <?php echo (int) $sum; ?> ردیف</button>
  </form>
  <?php elseif (!$dt_meta['dry']) : ?>
  <p class="muted">کش شیء پاک شد. اگر افزونه‌ی کش صفحه دارید، کش را هم پاک کنید.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($dt_running) : ?>
<div class="box">
  <h2><?php echo dt_h($dt_labels[$dt_action]); ?> — <?php echo $dt_dry ? '<span class="warn">فقط پیش‌نمایش (چیزی ذخیره نمی‌شود)</span>' : '<span class="ok">اجرای واقعی</span>'; ?> — این دسته: <?php echo (int) $dt_batch['scanned']; ?> پست</h2>
  <?php $percent = $dt_total_r > 0 ? min(100, (int) round($dt_processed * 100 / $dt_total_r)) : 100; ?>
  <div>
    <?php if ($dt_action === 'relative') : ?>میزبان‌ها: <code><?php echo dt_h(implode(' ', $dt_hosts)); ?></code> → بدون دامنه<?php elseif ($dt_action === 'absolute') : ?>/… → <code><?php echo dt_h($dt_target); ?></code>/…<?php else : ?><code><?php echo dt_h(implode(' ', $dt_hosts)); ?></code> → <code><?php echo dt_h($dt_target); ?></code><?php endif; ?>
    | تا این‌جا: <?php echo (int) $dt_state['scanned']; ?> پست بررسی، <span class="ok"><?php echo (int) $dt_state['changed_posts']; ?> پست</span> با <span class="ok"><?php echo (int) $dt_state['changed_links']; ?> لینک</span> <?php echo $dt_dry ? 'قابل تغییر' : 'تغییر کرد'; ?><?php if ((int) $dt_state['failed'] > 0) : ?> | <span class="bad"><?php echo (int) $dt_state['failed']; ?> خطا در ذخیره</span><?php endif; ?>
    — <?php echo $dt_processed; ?> از <?php echo (int) $dt_total_r; ?>
  </div>
  <div class="bar"><i style="width:<?php echo $percent; ?>%"></i></div>
  <table>
    <tr><th style="width:70px">پست</th><th>عنوان</th><th style="width:60px">لینک</th><th>نمونه‌ی تغییر</th></tr>
    <?php foreach ($dt_batch['rows'] as $row) : ?>
    <tr>
      <td><?php echo (int) $row['id']; ?></td>
      <td><a href="<?php echo dt_h(get_permalink((int) $row['id'])); ?>" target="_blank" rel="noopener"><?php echo dt_h($row['title'] !== '' ? $row['title'] : '(بدون عنوان)'); ?></a><?php echo $row['saved'] ? '' : ' <span class="bad">ذخیره نشد</span>'; ?></td>
      <td><?php echo (int) $row['links']; ?></td>
      <td><?php foreach ($row['pairs'] as $pair) : ?><div><code><?php echo dt_h($pair[0]); ?></code> ← <code><?php echo dt_h($pair[1]); ?></code></div><?php endforeach; ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($dt_batch['rows'])) : ?><tr><td colspan="4" class="muted">در این دسته لینکی برای تغییر نبود.</td></tr><?php endif; ?>
  </table>
  <?php if ($dt_more) : ?>
  <form method="post" id="nextForm" style="margin-top:12px">
    <?php wp_nonce_field('bm_domain_tool'); ?>
    <input type="hidden" name="action" value="<?php echo dt_h($dt_action); ?>">
    <input type="hidden" name="after" value="<?php echo (int) $dt_batch['last']; ?>">
    <input type="hidden" name="n" value="<?php echo (int) $dt_limit; ?>">
    <input type="hidden" name="hosts" value="<?php echo dt_h(implode(' ', $dt_hosts)); ?>">
    <input type="hidden" name="target" value="<?php echo dt_h($dt_target); ?>">
    <?php foreach ($dt_types_r as $type) : ?><input type="hidden" name="types[]" value="<?php echo dt_h($type); ?>"><?php endforeach; ?>
    <?php if ($dt_dry) : ?><input type="hidden" name="dry" value="1"><?php endif; ?>
    <button type="submit">دسته‌ی بعد (خودکار تا <span id="cnt">2</span> ثانیه دیگر)</button>
    <a class="btn gray" href="<?php echo dt_h($dt_self); ?>" onclick="window.bmStop=true;clearInterval(window.bmTimer);">توقف</a>
    <small class="muted">هر وقت توقف بزنید، بعداً با همان دکمه از همین‌جا ادامه می‌یابد.</small>
  </form>
  <script>
  (function(){var n=2,el=document.getElementById('cnt');window.bmTimer=setInterval(function(){n--;if(el)el.textContent=n;if(n<=0){clearInterval(window.bmTimer);if(!window.bmStop)document.getElementById('nextForm').submit();}},1000);})();
  </script>
  <?php else : ?>
  <p class="ok">تمام شد: <?php echo (int) $dt_state['changed_posts']; ?> پست با <?php echo (int) $dt_state['changed_links']; ?> لینک <?php echo $dt_dry ? 'قابل تغییر است' : 'تغییر کرد'; ?>.</p>
  <?php if ($dt_dry && (int) $dt_state['changed_links'] > 0) : ?>
  <form method="post" style="display:inline">
    <?php wp_nonce_field('bm_domain_tool'); ?>
    <input type="hidden" name="action" value="<?php echo dt_h($dt_action); ?>">
    <input type="hidden" name="n" value="<?php echo (int) $dt_limit; ?>">
    <input type="hidden" name="hosts" value="<?php echo dt_h(implode(' ', $dt_hosts)); ?>">
    <input type="hidden" name="target" value="<?php echo dt_h($dt_target); ?>">
    <?php foreach ($dt_types_r as $type) : ?><input type="hidden" name="types[]" value="<?php echo dt_h($type); ?>"><?php endforeach; ?>
    <input type="hidden" name="fresh" value="1">
    <button type="submit" class="red" onclick="return confirm('اجرای واقعی: <?php echo (int) $dt_state['changed_links']; ?> لینک در <?php echo (int) $dt_state['changed_posts']; ?> پست تغییر می‌کند. پشتیبان گرفته‌اید؟');">اجرای واقعی همین کار</button>
  </form>
  <?php endif; ?>
  <a class="btn gray" href="<?php echo dt_h($dt_self); ?>">بازگشت به گزارش</a>
  <?php endif; ?>
  <?php if (!empty($dt_state['pairs'])) : ?>
  <h3>نمونه‌هایی از کل اجرا</h3>
  <table>
    <tr><th style="width:70px">پست</th><th>قبل</th><th>بعد</th></tr>
    <?php foreach (array_slice($dt_state['pairs'], 0, 15) as $pair) : ?>
    <tr><td><?php echo (int) $pair[0]; ?></td><td><code><?php echo dt_h($pair[1]); ?></code></td><td><code><?php echo dt_h($pair[2]); ?></code></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($dt_report !== null) : ?>
<div class="box">
  <h2>گزارش</h2>
  <div class="grid">
    <div class="card">
      <h3 style="margin-top:0">متن پست‌ها و برگه‌ها (<?php echo (int) $dt_report['posts']; ?> مورد)</h3>
      <table>
        <tr><td>لینک مطلق به دامنه‌ی خود سایت (<code><?php echo dt_h(implode(' / ', $dt_home_hosts)); ?></code>)</td><td class="num <?php echo $dt_report['abs_home'] > 0 ? 'warn' : 'ok'; ?>"><?php echo (int) $dt_report['abs_home']; ?></td></tr>
        <tr><td>لینک بدون دامنه (<code>href="/…"</code>)</td><td class="num"><?php echo (int) $dt_report['relative']; ?></td></tr>
        <?php if ($dt_dl_host !== '') : ?><tr><td>لینک مستقیم به هاست دانلود (<code><?php echo dt_h($dt_dl_host); ?></code>) داخل متن</td><td class="num"><?php echo (int) $dt_report['abs_dl']; ?></td></tr><?php endif; ?>
      </table>
      <p class="muted"><small>شمارش با LIKE است و آدرس‌هایی که در متن ساده نوشته شده‌اند را هم می‌شمارد؛ ابزار فقط صفت‌های href/src/poster/data-src را عوض می‌کند. «پیش‌نمایش» عدد دقیق را می‌دهد.</small></p>
    </div>
    <div class="card">
      <h3 style="margin-top:0">لینک‌های دانلود (متاها)</h3>
      <table>
        <tr><th>کلید</th><th>ردیف‌های غیرخالی</th><th>روی <?php echo dt_h($dt_dl_host !== '' ? $dt_dl_host : 'هاست دانلود'); ?></th><?php if (!empty($dt_old_dl)) : ?><th>روی هاست قدیمی (<?php echo dt_h(implode('، ', $dt_old_dl)); ?>)</th><?php endif; ?></tr>
        <?php foreach ($dt_report['meta'] as $key => $m) : ?>
        <tr><td><code><?php echo dt_h($key); ?></code></td><td><?php echo (int) $m['total']; ?></td><td class="<?php echo $m['current'] === $m['total'] ? 'ok' : 'warn'; ?>"><?php echo (int) $m['current']; ?></td><?php if (!empty($dt_old_dl)) : ?><td class="<?php echo $m['old'] > 0 ? 'warn' : 'ok'; ?>"><?php echo (int) $m['old']; ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <?php if (empty($dt_report['meta'])) : ?><tr><td colspan="<?php echo !empty($dt_old_dl) ? 4 : 3; ?>" class="muted">متایی پیدا نشد.</td></tr><?php endif; ?>
      </table>
      <?php if (!empty($dt_report['meta_other'])) : ?>
      <p><small>میزبان‌های دیگر در این متاها (نمونه‌ی ۲۰۰۰ ردیف آخر): <?php $parts = []; foreach ($dt_report['meta_other'] as $h => $n) { $parts[] = '<code>' . dt_h($h) . '</code> ' . (int) $n; } echo implode('، ', $parts); ?></small></p>
      <?php elseif ($dt_dl_host !== '') : ?>
      <p class="ok"><small>همه‌ی لینک‌های دانلود روی هاست دانلود فعلی‌اند.</small></p>
      <?php endif; ?>
      <?php if (!empty($dt_old_dl)) : ?>
      <p class="muted"><small>ستون «هاست قدیمی» مقدار واقعی دیتابیس است؛ بازدیدکننده‌ها همین الان (به لطف AHANGBAN_OLD_DL_HOSTS) لینک را روی <code><?php echo dt_h($dt_dl_host); ?></code> می‌بینند. مورد ۴ آن را دائمی می‌کند.</small></p>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3 style="margin-top:0">جاهای دیگر که روز تغییر دامنه باید جایگزینی شوند</h3>
      <table>
        <tr><td>ردیف‌های <code>wp_options</code> که <code>//<?php echo dt_h($dt_home_host); ?></code> دارند (تنظیمات قالب، ابزارک‌ها، Rank Math، …)</td><td class="num"><?php echo (int) $dt_report['options_home']; ?></td></tr>
        <tr><td>لینک‌های دلخواه فهرست‌ها (<code>_menu_item_url</code>) با دامنه‌ی سایت</td><td class="num"><?php echo (int) $dt_report['menu_home']; ?></td></tr>
      </table>
      <p class="muted"><small>این‌ها اغلب سریالایز شده‌اند؛ با Better Search Replace یا <code>wp search-replace</code> (بخش راهنما) عوض می‌شوند، نه با این ابزار.</small></p>
    </div>
  </div>
</div>

<div class="box">
  <h2>ویرایش دسته‌ای</h2>
  <?php if (!empty($dt_state['op']) && empty($dt_state['finished'])) : ?>
  <p class="warn">یک اجرای ناتمام هست: <?php echo dt_h($dt_labels[$dt_state['op']]); ?><?php echo !empty($dt_state['dry']) ? ' (پیش‌نمایش)' : ''; ?> — <?php echo (int) $dt_state['scanned']; ?> پست بررسی شده.
    <form method="post" style="display:inline"><?php wp_nonce_field('bm_domain_tool'); ?><input type="hidden" name="action" value="<?php echo dt_h($dt_state['op']); ?>"><input type="hidden" name="hosts" value="<?php echo dt_h(implode(' ', (array) $dt_state['hosts'])); ?>"><input type="hidden" name="target" value="<?php echo dt_h($dt_state['target']); ?>"><input type="hidden" name="n" value="<?php echo (int) (isset($dt_state['n']) ? $dt_state['n'] : 200); ?>"><?php foreach ((array) $dt_state['types'] as $type) : ?><input type="hidden" name="types[]" value="<?php echo dt_h($type); ?>"><?php endforeach; ?><?php if (!empty($dt_state['dry'])) : ?><input type="hidden" name="dry" value="1"><?php endif; ?><button type="submit">ادامه</button></form>
    <form method="post" style="display:inline"><?php wp_nonce_field('bm_domain_tool'); ?><input type="hidden" name="action" value="reset"><button type="submit" class="gray">از اول</button></form>
  </p>
  <?php endif; ?>
  <div class="grid">
    <div class="card">
      <h3 style="margin-top:0">۱) نسبی‌سازی لینک‌های داخلی</h3>
      <p class="muted"><small><code>https://<?php echo dt_h($dt_home_host); ?>/music/</code> → <code>/music/</code> در متن و خلاصه‌ی پست‌ها. دامنه‌های قدیمی‌تر را هم می‌توانید اضافه کنید.</small></p>
      <form method="post">
        <?php wp_nonce_field('bm_domain_tool'); ?>
        <input type="hidden" name="action" value="relative">
        <input type="hidden" name="fresh" value="1">
        <label>میزبان‌های داخلی (هر خط یکی؛ با/بی www خودکار اضافه می‌شود)</label>
        <textarea name="hosts"><?php echo dt_h($dt_home_host); ?></textarea>
        <div class="row" style="margin-top:8px">
          <label><input type="checkbox" name="types[]" value="post" checked> نوشته‌ها</label>
          <label><input type="checkbox" name="types[]" value="page" checked> برگه‌ها</label>
          <label>دسته: <select name="n"><option>100</option><option selected>200</option><option>500</option><option>1000</option></select></label>
          <label><input type="checkbox" name="dry" value="1" checked> فقط پیش‌نمایش</label>
        </div>
        <p><button type="submit">شروع</button></p>
      </form>
    </div>
    <div class="card">
      <h3 style="margin-top:0">۲) بازگشت به لینک کامل</h3>
      <p class="muted"><small>عکس مورد ۱: <code>/music/</code> → <code><?php echo dt_h($dt_origin); ?>/music/</code>. (لینک‌های <code>//cdn…</code> دست نمی‌خورند.)</small></p>
      <form method="post">
        <?php wp_nonce_field('bm_domain_tool'); ?>
        <input type="hidden" name="action" value="absolute">
        <input type="hidden" name="fresh" value="1">
        <label>دامنه‌ی مقصد</label>
        <input type="text" class="ltr" name="target" value="<?php echo dt_h($dt_origin); ?>">
        <div class="row" style="margin-top:8px">
          <label><input type="checkbox" name="types[]" value="post" checked> نوشته‌ها</label>
          <label><input type="checkbox" name="types[]" value="page" checked> برگه‌ها</label>
          <label>دسته: <select name="n"><option>100</option><option selected>200</option><option>500</option><option>1000</option></select></label>
          <label><input type="checkbox" name="dry" value="1" checked> فقط پیش‌نمایش</label>
        </div>
        <p><button type="submit">شروع</button></p>
      </form>
    </div>
    <div class="card">
      <h3 style="margin-top:0">۳) تعویض دامنه در متن پست‌ها</h3>
      <p class="muted"><small>لینک‌های مطلق روی یک دامنه (مثلاً هاست دانلود قدیمی یا دامنه‌ی قدیمی سایت) → همان مسیر روی دامنه‌ی جدید. برای لینک‌های خود سایت، مورد ۱ بهتر است.</small></p>
      <form method="post">
        <?php wp_nonce_field('bm_domain_tool'); ?>
        <input type="hidden" name="action" value="swap">
        <input type="hidden" name="fresh" value="1">
        <label>دامنه‌ی قدیمی (هر خط یکی)</label>
        <textarea name="hosts"><?php echo dt_h(!empty($dt_old_dl) ? implode("\n", $dt_old_dl) : $dt_dl_host); ?></textarea>
        <label>دامنه‌ی جدید (با https://)</label>
        <input type="text" class="ltr" name="target" value="<?php echo !empty($dt_old_dl) ? dt_h($dt_dl_origin) : ''; ?>" placeholder="https://dl.ahangban.com">
        <div class="row" style="margin-top:8px">
          <label><input type="checkbox" name="types[]" value="post" checked> نوشته‌ها</label>
          <label><input type="checkbox" name="types[]" value="page" checked> برگه‌ها</label>
          <label>دسته: <select name="n"><option>100</option><option selected>200</option><option>500</option><option>1000</option></select></label>
          <label><input type="checkbox" name="dry" value="1" checked> فقط پیش‌نمایش</label>
        </div>
        <p><button type="submit">شروع</button></p>
      </form>
    </div>
    <div class="card">
      <h3 style="margin-top:0">۴) تعویض دامنه‌ی لینک‌های دانلود (متاها)</h3>
      <p class="muted"><small>مقدار متاهایی که با <code>https://دامنه‌ی-قدیمی/</code> (یا http، //) شروع می‌شوند → <code>دامنه‌ی-جدید/…</code>؛ یک UPDATE برای هر کلید، در چند ثانیه. اول فایل‌ها باید روی هاست جدید در دسترس باشند (هاست دانلود ربات الان: <code><?php echo dt_h($dt_dl_origin !== '' ? $dt_dl_origin : '—'); ?></code>؛ با <code>dl-repair.php?ping=1</code> امتحان کنید).</small></p>
      <form method="post">
        <?php wp_nonce_field('bm_domain_tool'); ?>
        <input type="hidden" name="action" value="meta_swap">
        <label>دامنه‌ی قدیمی</label>
        <input type="text" class="ltr" name="old_host" value="<?php echo dt_h(!empty($dt_old_dl) ? $dt_old_dl[0] : $dt_dl_host); ?>">
        <label>دامنه‌ی جدید (با https://)</label>
        <input type="text" class="ltr" name="target" value="<?php echo dt_h($dt_dl_origin); ?>">
        <label>کلیدهای متا (با فاصله؛ % = الگوی ردیف‌های آلبوم)</label>
        <textarea name="keys" style="min-height:40px"><?php echo dt_h(implode(' ', $dt_dl_keys)); ?></textarea>
        <div class="row" style="margin-top:8px">
          <label><input type="checkbox" name="dry" value="1" checked> فقط پیش‌نمایش (فقط شمارش)</label>
        </div>
        <p><button type="submit">شروع</button></p>
      </form>
    </div>
  </div>
</div>

<div class="box">
  <h2>راهنمای روز تغییر دامنه (مثلاً ahangban.ir → ahangban.com)</h2>
  <ol>
    <li><b>پیش از همه، همین الان:</b> مورد ۱ را روی همه‌ی پست‌ها اجرا کنید تا لینک‌های داخلی متن پست‌ها بدون دامنه شوند؛ از آن به بعد ربات و پست‌ساز هم همین‌طور می‌نویسند. در روز تغییر دامنه دیگر هیچ کاری برای متن پست‌ها لازم نیست.</li>
    <li>دامنه‌ی جدید را به همین هاست وصل کنید (DNS + SSL)؛ دامنه‌ی قدیمی را هم دست‌کم یک سال نگه دارید.</li>
    <li>در <code>wp-config.php</code>: <code>define('WP_HOME', 'https://ahangban.com'); define('WP_SITEURL', 'https://ahangban.com');</code> و <code>define('AHANGBAN_OLD_HOSTS', 'ahangban.ir');</code> (افزونه‌ی ahangban-seo لینک‌های مطلقِ جامانده‌ی دامنه‌ی قدیمی را در خروجی به دامنه‌ی جدید برمی‌گرداند) و اگر هاست دانلود هم عوض شده <code>define('AHANGBAN_OLD_DL_HOSTS', 'dl.ahangban.ir');</code> (مورد ۵).</li>
    <li>تنظیمات قالب، ابزارک‌ها، فهرست‌ها، Rank Math، المنتور و … (سریالایزشده در <code>wp_options</code>/<code>wp_postmeta</code>): با افزونه‌ی <b>Better Search Replace</b> (اول Dry run) یا <code>wp search-replace 'https://ahangban.ir' 'https://ahangban.com' --all-tables --precise</code>؛ همین را برای <code>http://ahangban.ir</code> و حالت JSON <code>https:\/\/ahangban.ir</code> (المنتور) هم بزنید. این ابزار این جدول‌ها را دست نمی‌زند.</li>
    <li><b>هاست دانلود</b> (<code>dl.ahangban.ir → dl.ahangban.com</code>): فایل‌های روی هاست دانلود (<code>/music/…</code>، <code>/album/…</code>، <code>hostdl/*.php</code>) هیچ دامنه‌ای در خود ندارند — کافی است در DirectAdmin هاست دانلود، دامنه‌ی <code>dl.ahangban.com</code> را با همان پوشه‌ی <code>public_html</code> بسازید (یا پوشه را منتقل کنید) + SSL. ربات خودش هاست دانلود را «dl. + دامنه‌ی سایت» می‌گیرد (<code>$urldlhost</code> در config.php خالی بماند؛ اگر هاست جدید این قاعده را ندارد <code>define('AHANGBAN_DL_HOST', 'https://…')</code> در wp-config). سپس در wp-config: <code>define('AHANGBAN_OLD_DL_HOSTS', 'dl.ahangban.ir');</code> → از همان لحظه لینک‌های دانلود همه‌ی پست‌های قدیمی روی هاست جدید نمایش داده می‌شوند. بعد با خیال راحت مورد ۴ (متاها) و اگر لینک دانلود داخل متن هست مورد ۳ را اجرا کنید تا دائمی شود؛ <code>dl-repair.php?ping=1</code> هم اتصال ربات به هاست جدید را نشان می‌دهد. اگر هاست دانلود همان می‌ماند، هیچ‌کدام لازم نیست.</li>
    <li>ریدایرکت ۳۰۱ دامنه‌ی قدیمی به جدید با حفظ مسیر — برای سایت و همین‌طور روی هاست دانلود قدیمی (لینک‌های قدیمی تلگرام/گوگل زنده می‌مانند):
<pre>RewriteEngine On
RewriteCond %{HTTP_HOST} ^(www\.)?ahangban\.ir$ [NC]
RewriteRule ^(.*)$ https://ahangban.com/$1 [R=301,L]

# .htaccess هاست دانلود قدیمی
RewriteEngine On
RewriteCond %{HTTP_HOST} ^dl\.ahangban\.ir$ [NC]
RewriteRule ^(.*)$ https://dl.ahangban.com/$1 [R=301,L]</pre></li>
    <li>Search Console: «تغییر آدرس» (Change of Address) از دامنه‌ی قدیمی به جدید، ارسال نقشه‌ی سایت جدید؛ پیوندهای یکتا را یک‌بار ذخیره کنید؛ کش صفحه/CDN را پاک کنید.</li>
    <li>کران‌جاب <code>auto.php</code> را با آدرس جدید تنظیم کنید؛ <code>$urlsite</code> در config.php خالی (خودکار) بماند.</li>
    <li>در پایان همین صفحه را باز کنید: «لینک مطلق به دامنه‌ی خود سایت» باید صفر باشد؛ اگر نبود مورد ۱ را با دامنه‌ی قدیمی در فهرست میزبان‌ها اجرا کنید.</li>
  </ol>
</div>
<?php endif; ?>
</body>
</html>
