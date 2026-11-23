<?php
/**
 * تکمیل پست‌های قبلی: تاریخ انتشار واقعی + ترم‌های ژانر / حال‌وهوا / ترانه‌سرا / آهنگساز / تنظیم‌کننده
 *
 *   /bot/backfill.php                      فهرست پست‌هایی که هنوز بررسی نشده‌اند (جدیدترین اول) + وضعیت فعلی هر پست؛
 *                                          با تیک و دکمه‌ی «تکمیل تیک‌خورده‌ها» همان‌ها پردازش می‌شوند
 *   /bot/backfill.php?run=1&limit=20       پردازش دسته‌ی بعدی (۲۰ پست) و نمایش نتیجه + دکمه‌ی «ادامه»
 *   /bot/backfill.php?run=1&auto=1         همان، با ادامه‌ی خودکار دسته‌به‌دسته تا پایان (صفحه را باز بگذارید؛ «توقف» در بالای صفحه)
 *   /bot/backfill.php?run=1&dry=1          پیش‌نمایش: فقط می‌خواند و می‌گوید چه چیزی پر می‌شد (چیزی نوشته نمی‌شود)
 *   /bot/backfill.php?retry=1              پست‌هایی که قبلاً بررسی شده‌اند ولی هنوز چیزی کم دارند، دوباره
 *   /bot/backfill.php?status=any           پیش‌نویس‌ها هم (پیش‌فرض فقط منتشرشده)
 *   /bot/backfill.php?ids=123,456          فقط همین پست‌ها (با run=1 بی‌درنگ پردازش می‌شوند)
 *
 * برای هر پست (به ترتیب):
 *   1) ترم‌هایی که از متاهای خودِ پست درمی‌آیند، بدون شبکه: mood → حال‌وهوا، genre → ژانر، lyricist/composer/arranger → عوامل
 *   2) اگر تاریخ انتشار یا ترمی هنوز کم بود: صفحه‌ی مبدأ (mybia2music) خوانده می‌شود — لینک از متای _bm_source_url، جدول
 *      wp_subcheck (ارسال خودکار قدیمی)، لینکی که خودتان در ستون «لینک مبدأ» می‌نویسید، یا جست‌وجو در فهرست آهنگ‌های همان
 *      خواننده در سایت مبدأ (/artists/{slug}/musics و صفحه‌های بعدی) با تطبیق نام آهنگ. لینک پیداشده در _bm_source_url ذخیره می‌شود.
 *   3) تاریخ: فیلد قالب (bm_release_date_field: جعبه‌ی «تاریخ انتشار واقعی آهنگ» ترانه‌یار = متای _tara_release_date میلادی Y-m-d؛
 *      یا $bot_release_date_field در config.php) + متاهای _bm_release_date / _bm_release_date_jalali
 *      ?fixdate=1 : فقط پست‌هایی که تاریخ در متای ربات هست ولی فیلد قالب خالی است (بدون خواندن مبدأ پر می‌شود)
 *      ترم‌ها: فقط نقش‌هایی که پست هنوز ترمی در آن تاکسونومی ندارد (چیزی که از پیشخوان تنظیم شده دست نمی‌خورد)
 *      متاهای متنی خالی (genre/mood/lyricist/composer/arranger) هم از مبدأ پر می‌شوند
 *   نتیجه در متای _bm_backfill ذخیره می‌شود تا پست دوباره در فهرست نیاید (retry=1 = پست‌های ناقص دوباره).
 *
 * فقط پشت همان یوزر/رمز ربات. پست‌ها منتشر/پیش‌نویس نمی‌شوند، عنوان/متن/تاریخ وردپرس پست دست نمی‌خورد.
 */
include('config.php');
require_once __DIR__ . '/auth.php';
bm_require_auth();

require('../wp-load.php');

$bm_functions_only = true;
require_once __DIR__ . '/script.php';

@set_time_limit(0);
@ignore_user_abort(true);
header('Content-Type: text/html; charset=utf-8');

$subcheck_table = isset($subcheck_table) ? $subcheck_table : 'wp_subcheck';
$bf_limit  = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
$bf_before = isset($_GET['before']) ? max(0, (int) $_GET['before']) : 0;
$bf_status = (isset($_GET['status']) && $_GET['status'] === 'any') ? ['publish', 'draft', 'pending', 'future', 'private'] : ['publish'];
$bf_retry  = !empty($_GET['retry']);
$bf_fixdate = !empty($_GET['fixdate']); // فقط تاریخ: پست‌هایی که تاریخ در متای ربات (_bm_release_date) هست ولی فیلد قالب خالی است
$bf_dry    = !empty($_GET['dry']);
$bf_auto   = !empty($_GET['auto']);
$bf_run    = !empty($_GET['run']);
$bf_pages  = isset($_GET['pages']) ? max(1, min(10, (int) $_GET['pages'])) : 4;
$bf_ids    = [];
if (!empty($_GET['ids'])) {
    foreach (preg_split('/[\s,]+/', (string) $_GET['ids']) as $one) {
        if ((int) $one > 0) {
            $bf_ids[] = (int) $one;
        }
    }
}

function bf_h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function bf_flush() {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

/* -------------------------------------------------------
 |  لینک صفحه‌ی مبدأ
 * ------------------------------------------------------ */

/** لینک مبدأ ذخیره‌شده‌ی پست: ['url' => …, 'from' => توضیح] (متای _bm_source_url یا جدول wp_subcheck) */
function bf_saved_source($post_id) {
    global $wpdb, $subcheck_table;
    $url = (string) get_post_meta($post_id, '_bm_source_url', true);
    if ($url !== '') {
        return ['url' => $url, 'from' => 'متای _bm_source_url'];
    }
    static $has_table = null;
    if ($has_table === null) {
        $exists    = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($subcheck_table)));
        $has_table = false;
        if ($exists === $subcheck_table) {
            $columns   = (array) $wpdb->get_col("SHOW COLUMNS FROM {$subcheck_table}", 0);
            $has_table = in_array('post_id', $columns, true) && in_array('url', $columns, true);
        }
    }
    if ($has_table) {
        $url = (string) $wpdb->get_var($wpdb->prepare("SELECT url FROM {$subcheck_table} WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id));
        if ($url !== '') {
            return ['url' => $url, 'from' => 'جدول ' . $subcheck_table];
        }
    }
    return ['url' => '', 'from' => ''];
}

/** نام ساده‌شده‌ی آهنگ برای تطبیق: بدون پرانتز «(ورژن جدید)»، بدون «آهنگ»/«ریمیکس» ابتدای نام */
function bf_song_key($song) {
    $song = preg_replace('/[\(\[].*?[\)\]]/u', ' ', (string) $song);
    $song = preg_replace('/^\s*(?:آهنگ|ریمیکس|دانلود)\s+/u', '', (string) $song);
    return bm_normalize_name($song);
}

/** فهرست آهنگ‌های یک صفحه‌ی هنرمند مبدأ (کش ۶ ساعته در transient): آرایه یا null (خوانده نشد) */
function bf_listing($url) {
    $key    = 'bm_bf_' . md5($url);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }
    $response = bm_source_http_get($url, 40);
    if ($response['body'] === '' || $response['code'] >= 400) {
        return null;
    }
    $items = bm_source_artist_listing($response['body'], $response['url'] !== '' ? $response['url'] : $url);
    set_transient($key, $items, 6 * HOUR_IN_SECONDS);
    return $items;
}

/**
 * جست‌وجوی لینک آهنگ در فهرست آهنگ‌های خواننده در سایت مبدأ ($bot_source_artist_url + ?page=N)
 * خروجی: ['url' => …, 'note' => توضیح]
 */
function bf_search_source($post_id, $max_pages) {
    $song      = (string) get_post_meta($post_id, 'song', true);
    $artist    = (string) get_post_meta($post_id, 'artist', true);
    $artist_en = (string) get_post_meta($post_id, 'artist_en', true);
    if ($song === '') {
        return ['url' => '', 'note' => 'متای song (نام آهنگ) خالی است؛ جست‌وجو در مبدأ ممکن نیست'];
    }
    // نامک انگلیسی خواننده: از artist_en، وگرنه نامک ترم هنرمند پست
    $slugs = [];
    foreach (bm_split_artists($artist_en) as $one_en) {
        $s = bm_artist_term_slug($one_en);
        if ($s !== '') {
            $slugs[] = $s;
        }
    }
    $taxonomy = bm_artist_taxonomy(isset($GLOBALS['bot_artist_taxonomy']) ? $GLOBALS['bot_artist_taxonomy'] : '');
    if ($taxonomy !== '') {
        foreach ((array) wp_get_object_terms($post_id, $taxonomy) as $term) {
            if (is_object($term) && !empty($term->slug) && !preg_match('/[^a-z0-9\-]/', $term->slug)) {
                $slugs[] = $term->slug;
            }
        }
    }
    $slugs = array_values(array_unique($slugs));
    if (empty($slugs)) {
        return ['url' => '', 'note' => 'نامک انگلیسی خواننده معلوم نیست (artist_en / ترم هنرمند)؛ لینک مبدأ را دستی بدهید'];
    }
    $template = !empty($GLOBALS['bot_source_artist_url']) ? (string) $GLOBALS['bot_source_artist_url'] : 'https://www.mybia2music.com/artists/{artist}/musics';
    $want     = bf_song_key($song);
    $want_art = bm_normalize_name($artist);
    $looked   = 0;
    foreach ($slugs as $slug) {
        $base = str_replace('{artist}', rawurlencode($slug), $template);
        $seen = [];
        for ($page = 1; $page <= $max_pages; $page++) {
            $url   = $page === 1 ? $base : $base . (strpos($base, '?') === false ? '?' : '&') . 'page=' . $page;
            $items = bf_listing($url);
            if ($items === null) {
                if ($page === 1) {
                    break; // صفحه‌ی هنرمند با این نامک نیست
                }
                break;
            }
            if (empty($items)) {
                break;
            }
            $new = 0;
            foreach ($items as $item) {
                if (isset($seen[$item['url']])) {
                    continue;
                }
                $seen[$item['url']] = true;
                $new++;
                $looked++;
                $key = bf_song_key($item['song']);
                if ($key === '' || $key !== $want) {
                    continue;
                }
                $item_art = bm_normalize_name($item['artist']);
                if ($want_art !== '' && $item_art !== '' && $item_art !== $want_art && strpos($item_art, $want_art) === false && strpos($want_art, $item_art) === false) {
                    continue; // هم‌نام ولی خواننده‌ی دیگر
                }
                return ['url' => $item['url'], 'note' => 'از فهرست هنرمند مبدأ (' . $slug . '، صفحه‌ی ' . $page . ')'];
            }
            if ($new === 0 || count($items) < 10) {
                break; // صفحه‌ی آخر (یا سایت همان صفحه‌ی اول را برگرداند)
            }
        }
    }
    return ['url' => '', 'note' => 'در فهرست هنرمند مبدأ پیدا نشد (' . implode('، ', $slugs) . '؛ ' . $looked . ' آهنگ دیده شد)؛ لینک مبدأ را دستی بدهید'];
}

/* -------------------------------------------------------
 |  وضعیت فعلی پست
 * ------------------------------------------------------ */

/** وضعیت فعلی: تاریخ، ترم هر نقش، متاهای متنی */
function bf_state($post_id) {
    $field = bm_release_date_field($post_id);
    $state = [
        'field'       => $field,
        'date_field'  => bm_release_date_current($post_id, $field),
        'date_meta'   => (string) get_post_meta($post_id, '_bm_release_date', true),
        'date_jalali' => (string) get_post_meta($post_id, '_bm_release_date_jalali', true),
        'terms'       => [],
        'metas'       => [
            'mood'     => bm_clean_text((string) get_post_meta($post_id, 'mood', true)),
            'genre'    => bm_clean_text((string) get_post_meta($post_id, 'genre', true)),
            'lyricist' => bm_clean_text((string) get_post_meta($post_id, 'lyricist', true)),
            'composer' => bm_clean_text((string) get_post_meta($post_id, 'composer', true)),
            'arranger' => bm_clean_text((string) get_post_meta($post_id, 'arranger', true)),
        ],
    ];
    foreach (bm_term_roles() as $role) {
        $taxonomy = bm_role_taxonomy($role);
        $names    = [];
        if ($taxonomy !== '') {
            foreach ((array) wp_get_object_terms($post_id, $taxonomy) as $term) {
                if (is_object($term) && !empty($term->name)) {
                    $names[] = $term->name;
                }
            }
        }
        $state['terms'][$role] = ['taxonomy' => $taxonomy, 'names' => $names];
    }
    // تاریخ وقتی «هست» که فیلد قالب پر باشد؛ متای ربات (_bm_release_date) به‌تنهایی فقط وقتی کافی است که قالب فیلدی نداشته باشد
    $state['has_date'] = is_array($field) ? $state['date_field'] !== '' : $state['date_meta'] !== '';
    return $state;
}

/** نقش‌هایی که پست هنوز ترم ندارد (فقط نقش‌هایی که تاکسونومی‌شان در سایت هست) */
function bf_missing_roles(array $state) {
    $missing = [];
    foreach ($state['terms'] as $role => $t) {
        if ($t['taxonomy'] !== '' && empty($t['names'])) {
            $missing[] = $role;
        }
    }
    return $missing;
}

/** کلید متای متنی هر نقش */
function bf_role_meta_key($role) {
    $keys = ['mood' => 'mood', 'genre' => 'genre', 'songwriter' => 'lyricist', 'composer' => 'composer', 'arranger' => 'arranger'];
    return isset($keys[$role]) ? $keys[$role] : '';
}

/** کلید داده‌ی مبدأ (خروجی bm_fetch_source) هر نقش */
function bf_role_data_key($role) {
    $keys = ['mood' => 'mood', 'genre' => 'style', 'songwriter' => 'songwriter', 'composer' => 'composer', 'arranger' => 'regulator'];
    return isset($keys[$role]) ? $keys[$role] : '';
}

/** نام‌هایی که برای یک نقش ساخته/ست می‌شد (پیش‌نمایش بدون نوشتن) */
function bf_planned_names($role, array $people) {
    $names = [];
    foreach ($people as $person) {
        $name = bm_role_term_name($role, isset($person['name']) ? $person['name'] : '');
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

/* -------------------------------------------------------
 |  پردازش یک پست
 * ------------------------------------------------------ */

/**
 * خروجی: ['id', 'status' => ok|partial|error|skip, 'lines' => [...], 'filled' => [...], 'missing' => [...], 'source' => url, 'fetched' => bool]
 */
function bf_process($post_id, $manual_source, $dry, $max_pages) {
    $post = get_post($post_id);
    $out  = ['id' => (int) $post_id, 'status' => 'skip', 'lines' => [], 'filled' => [], 'missing' => [], 'source' => '', 'fetched' => false];
    if (!$post || $post->post_type !== 'post') {
        $out['status']  = 'error';
        $out['lines'][] = 'پست پیدا نشد';
        return $out;
    }
    $state   = bf_state($post_id);
    $missing = bf_missing_roles($state);
    $need    = ['date' => !$state['has_date'], 'roles' => $missing];
    if (!$need['date'] && empty($need['roles'])) {
        $out['status']  = 'ok';
        $out['lines'][] = 'چیزی کم نداشت' . ($state['date_field'] !== '' ? ' (تاریخ انتشار: ' . $state['date_field'] . ')' : '');
        if (!$dry) {
            bf_mark($post_id, $out);
        }
        return $out;
    }

    /* --- 1) ترم‌ها از متاهای خود پست --- */
    $from_meta = [];
    foreach ($need['roles'] as $role) {
        $value = $state['metas'][bf_role_meta_key($role)];
        if ($value !== '') {
            $from_meta[bf_role_data_key($role)] = $value;
        }
    }
    if (!empty($from_meta)) {
        if ($dry) {
            foreach (bm_role_people_from_data($from_meta) as $role => $people) {
                $names = bf_planned_names($role, $people);
                $out['lines'][]  = bm_role_label($role) . ' از متای پست: ' . implode('، ', $names) . ' (پیش‌نمایش)';
                $out['filled'][] = $role;
            }
        } else {
            $report = bm_apply_role_terms($post_id, $from_meta, true);
            foreach ($report as $role => $row) {
                if (!empty($row['terms'])) {
                    $out['filled'][] = $role;
                }
            }
            $out['lines'][] = 'از متای پست — ' . bm_role_terms_summary($report);
        }
        $need['roles'] = array_values(array_diff($need['roles'], $out['filled']));
    }

    /* --- 1-ب) تاریخ از متای خود ربات (پست‌هایی که ربات قبلاً _bm_release_date را نوشته ولی فیلد قالب آن موقع پیدا نشده بود) --- */
    if ($need['date'] && $state['date_meta'] !== '') {
        $pair = bm_release_date_pair($state['date_meta'], $state['date_jalali']);
        if ($pair['g'] !== '') {
            if ($dry) {
                $field = $state['field'];
                $out['lines'][] = 'تاریخ انتشار از متای ربات (_bm_release_date): ' . $pair['j'] . ' (' . $pair['g'] . ')' . (is_array($field) ? ' → فیلد ' . $field['name'] . ' = ' . bm_release_date_value($pair, bm_release_date_format($field)) : '') . ' (پیش‌نمایش)';
                $out['filled'][] = 'date';
                $need['date'] = false;
            } else {
                $r = bm_store_release_date($post_id, $pair['g'], $pair['j'], false);
                if ($r['ok']) {
                    $out['lines'][] = 'تاریخ انتشار از متای ربات (_bm_release_date): ' . bm_release_date_summary($r);
                    $out['filled'][] = 'date';
                    $need['date'] = false;
                }
            }
        }
    }

    /* --- 2) مبدأ --- */
    if ($need['date'] || !empty($need['roles'])) {
        $source = ['url' => '', 'from' => ''];
        if ($manual_source !== '') {
            $source = ['url' => $manual_source, 'from' => 'لینک دستی'];
        } else {
            $source = bf_saved_source($post_id);
            if ($source['url'] === '') {
                $found  = bf_search_source($post_id, $max_pages);
                $source = ['url' => $found['url'], 'from' => $found['note']];
            }
        }
        if ($source['url'] === '' || !preg_match('#^https?://#i', $source['url'])) {
            $out['lines'][] = 'لینک مبدأ معلوم نیست: ' . ($source['from'] !== '' ? $source['from'] : 'لینک نامعتبر «' . $source['url'] . '»');
        } else {
            $out['source'] = $source['url'];
            $fetch = bm_fetch_source($source['url']);
            $out['fetched'] = !empty($fetch['ok']);
            if (empty($fetch['ok']) || !is_array($fetch['result'])) {
                $out['lines'][] = 'صفحه‌ی مبدأ خوانده نشد (' . $source['from'] . '): ' . (string) $fetch['error'];
            } else {
                $data = $fetch['result'];
                $out['lines'][] = 'مبدأ: ' . $source['url'] . ' — ' . $source['from'] . ($fetch['from'] === 'api' ? ' (API قدیمی)' : '')
                    . (!empty($fetch['kind']) && $fetch['kind'] !== 'music' ? ' — صفحه ' . bm_source_kind_label($fetch['kind']) . ' است' : '');
                if (!$dry && $manual_source === '' && (string) get_post_meta($post_id, '_bm_source_url', true) === '') {
                    update_post_meta($post_id, '_bm_source_url', esc_url_raw($source['url']));
                } elseif (!$dry && $manual_source !== '' && (string) get_post_meta($post_id, '_bm_source_url', true) === '') {
                    update_post_meta($post_id, '_bm_source_url', esc_url_raw($manual_source));
                }

                // تاریخ
                if ($need['date']) {
                    $g = (string) ($data['date'] ?? '');
                    $j = (string) ($data['date_jalali'] ?? '');
                    if ($dry) {
                        $pair = bm_release_date_pair($g, $j);
                        if ($pair['g'] === '') {
                            $out['lines'][] = 'تاریخ انتشار: در صفحه‌ی مبدأ نبود';
                        } else {
                            $field = $state['field'];
                            $out['lines'][] = 'تاریخ انتشار: ' . $pair['j'] . ' (' . $pair['g'] . ')' . (is_array($field) ? ' → فیلد ' . $field['name'] . ' = ' . bm_release_date_value($pair, bm_release_date_format($field)) : ' → فقط متای _bm_release_date (فیلد قالب پیدا نشد)') . ' (پیش‌نمایش)';
                            $out['filled'][] = 'date';
                        }
                    } else {
                        $r = bm_store_release_date($post_id, $g, $j, false);
                        $out['lines'][] = 'تاریخ انتشار: ' . bm_release_date_summary($r);
                        if ($r['gregorian'] !== '') {
                            $out['filled'][] = 'date';
                        }
                    }
                }

                // ترم‌ها و متاهای خالی
                if (!empty($need['roles'])) {
                    $role_data = ['crew_links' => isset($data['crew_links']) ? $data['crew_links'] : [], 'mood_slug' => (string) ($data['mood_slug'] ?? ''), 'genre_slug' => (string) ($data['genre_slug'] ?? '')];
                    foreach ($need['roles'] as $role) {
                        $k = bf_role_data_key($role);
                        $role_data[$k] = bm_clean_text((string) ($data[$k] ?? ''));
                    }
                    if ($dry) {
                        $people_all = bm_role_people_from_data($role_data);
                        foreach ($need['roles'] as $role) {
                            if (!empty($people_all[$role])) {
                                $out['lines'][]  = bm_role_label($role) . ' از مبدأ: ' . implode('، ', bf_planned_names($role, $people_all[$role])) . ' (پیش‌نمایش)';
                                $out['filled'][] = $role;
                            }
                        }
                    } else {
                        $report = bm_apply_role_terms($post_id, $role_data, true);
                        foreach ($report as $role => $row) {
                            if (!empty($row['terms'])) {
                                $out['filled'][] = $role;
                            }
                        }
                        if (!empty($report)) {
                            $out['lines'][] = 'از مبدأ — ' . bm_role_terms_summary($report);
                        }
                        // متاهای متنی خالی (مثل ربات: «ریتمیک» در متا «شاد» ثبت می‌شود)
                        $meta_filled = [];
                        foreach ($need['roles'] as $role) {
                            $meta_key = bf_role_meta_key($role);
                            $value    = $role_data[bf_role_data_key($role)];
                            if ($role === 'mood' && $value === 'ریتمیک') {
                                $value = 'شاد';
                            }
                            if ($value !== '' && $state['metas'][$meta_key] === '') {
                                update_post_meta($post_id, $meta_key, $value);
                                $meta_filled[] = $meta_key . '=' . $value;
                            }
                        }
                        if (!empty($meta_filled)) {
                            $out['lines'][] = 'متاهای خالی پر شد: ' . implode('، ', $meta_filled);
                        }
                    }
                }
            }
        }
    }

    /* --- جمع‌بندی --- */
    $out['filled'] = array_values(array_unique($out['filled']));
    if ($need['date'] && !in_array('date', $out['filled'], true)) {
        $out['missing'][] = 'date';
    }
    foreach ($need['roles'] as $role) {
        if (!in_array($role, $out['filled'], true)) {
            $out['missing'][] = $role;
        }
    }
    if (empty($out['missing'])) {
        $out['status'] = 'ok';
    } elseif (!empty($out['filled'])) {
        $out['status'] = 'partial';
    } else {
        $out['status'] = 'error';
    }
    if (!$dry) {
        bf_mark($post_id, $out);
    }
    return $out;
}

/** ثبت نتیجه در متای _bm_backfill */
function bf_mark($post_id, array $out) {
    update_post_meta($post_id, '_bm_backfill', wp_json_encode([
        'time'    => current_time('mysql'),
        'status'  => $out['status'],
        'filled'  => $out['filled'],
        'missing' => $out['missing'],
        'source'  => $out['source'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** برچسب فارسی کلیدهای filled/missing */
function bf_item_label($item) {
    return $item === 'date' ? 'تاریخ انتشار' : bm_role_label($item);
}

/* -------------------------------------------------------
 |  انتخاب پست‌ها
 * ------------------------------------------------------ */

/** شناسه‌ی پست‌های نوبت بعد (جدیدترین اول؛ ID < $before) */
function bf_candidates(array $statuses, $limit, $before, $retry, $fixdate = false) {
    global $wpdb;
    $holders = implode(',', array_fill(0, count($statuses), '%s'));
    $args    = $statuses;
    $where   = "p.post_type = 'post' AND p.post_status IN ($holders)";
    if ($before > 0) {
        $where .= ' AND p.ID < %d';
        $args[] = $before;
    }
    if ($fixdate) {
        $field = bm_release_date_field();
        if (!is_array($field)) {
            return [];
        }
        // تاریخ در متای ربات هست ولی فیلد قالب خالی است (بدون توجه به _bm_backfill)
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_bm_release_date' AND m.meta_value <> '')"
            . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = %s AND f.meta_value <> '')";
        $args[] = $field['name'];
    } elseif ($retry) {
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_bm_backfill' AND m.meta_value LIKE %s)";
        $args[] = '%"missing":["%';
    } else {
        $where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_bm_backfill')";
    }
    $args[] = (int) $limit;
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE $where ORDER BY p.ID DESC LIMIT %d", $args)));
}

/** شمارش: [کل پست‌ها، بررسی‌نشده، ناقص] */
function bf_counts(array $statuses) {
    global $wpdb;
    $holders = implode(',', array_fill(0, count($statuses), '%s'));
    $total   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status IN ($holders)", $statuses));
    $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status IN ($holders) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_bm_backfill')", $statuses));
    $partial = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status IN ($holders) AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_bm_backfill' AND m.meta_value LIKE %s)", array_merge($statuses, ['%"missing":["%'])));
    $fixdate = 0;
    $field   = bm_release_date_field();
    if (is_array($field)) {
        $fixdate = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status IN ($holders)"
            . " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_bm_release_date' AND m.meta_value <> '')"
            . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = %s AND f.meta_value <> '')", array_merge($statuses, [$field['name']])));
    }
    return [$total, $pending, $partial, $fixdate];
}

$self      = esc_url(remove_query_arg(['run', 'auto', 'dry', 'before', 'ids']));
$base_args = [];
foreach (['limit', 'status', 'retry', 'fixdate', 'pages'] as $keep) {
    if (isset($_GET[$keep]) && $_GET[$keep] !== '') {
        $base_args[$keep] = (string) $_GET[$keep];
    }
}
$field  = bm_release_date_field();
$format = bm_release_date_format($field);
list($count_total, $count_pending, $count_partial, $count_fixdate) = bf_counts($bf_status);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>تکمیل پست‌های قبلی: تاریخ انتشار و ترم‌ها</title>
<style>
body{font-family:tahoma,sans-serif;font-size:13px;line-height:1.9;background:#f5f6fa;color:#1f2937;margin:0;padding:20px}
.box{background:#fff;border:1px solid #e3e6ee;border-radius:12px;padding:16px 18px;margin-bottom:16px}
h1{font-size:18px;margin:0 0 8px}h2{font-size:15px;margin:0 0 10px}
table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #eef0f4;padding:7px 8px;text-align:right;vertical-align:top}
th{background:#f8f9fc;font-weight:bold;font-size:12px}
.ok{color:#0b9b70;font-weight:bold}.bad{color:#dc2626;font-weight:bold}.warn{color:#b45309;font-weight:bold}.muted{color:#6b7280}
code{font-family:Consolas,monospace;font-size:12px;direction:ltr;unicode-bidi:embed}
input[type=text]{width:100%;box-sizing:border-box;border:1px solid #d7dbe4;border-radius:8px;padding:5px 8px;font-size:12px;direction:ltr}
button,.btn{border:0;border-radius:10px;padding:9px 18px;background:#635bff;color:#fff;cursor:pointer;font-family:inherit;font-size:13px;text-decoration:none;display:inline-block}
.btn.gray{background:#e5e7eb;color:#111}.btn.red{background:#dc2626}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
small{font-size:11px}
ul.report{margin:4px 0 0 0;padding-right:18px}
.tag{display:inline-block;background:#eef2ff;border-radius:6px;padding:0 6px;margin:1px 0 1px 3px;font-size:11px}
</style>
</head>
<body>
<div class="box">
  <h1>تکمیل پست‌های قبلی: تاریخ انتشار واقعی + ژانر / حال‌وهوا / عوامل</h1>
  <table style="margin-bottom:10px">
    <tr><th style="width:220px">فیلد تاریخ انتشار قالب</th><td><?php
        if (is_array($field)) {
            echo '<code>' . bf_h($field['name']) . '</code>';
            echo $field['label'] !== '' && $field['label'] !== $field['name'] ? ' («' . bf_h($field['label']) . '»)' : '';
            if ($field['source'] === 'theme') {
                echo ' — متای جعبه‌ی «تاریخ انتشار واقعی آهنگ» قالب ترانه‌یار (کنار ویرایشگر نوشته)' . (function_exists('tara_p1_date_meta_key') ? '' : (function_exists('tara_release_meta_keys') ? ' <small class="warn">قالب ۳.۱۶ (جعبه‌ی ویرایش از ۳.۱۷ اضافه شده؛ همین کلید خوانده می‌شود)</small>' : ' <small class="warn">توابع قالب در دسترس نبود؛ کلید استاندارد قالب نوشته می‌شود و بعد از به‌روزرسانی قالب دیده می‌شود</small>'));
            } else {
                echo ' — نوع <code>' . bf_h($field['type']) . '</code>';
                echo $field['key'] !== '' ? ' <small class="muted">' . bf_h($field['key']) . '</small>' : '';
                echo ' — ' . ($field['source'] === 'config' ? ($field['key'] !== '' ? 'فیلد ACF (از config.php)' : 'متای ساده (از config.php)') : 'تشخیص خودکار از فیلدهای ACF');
            }
            echo '<br>قالب ذخیره: <b>' . bf_h(bm_release_date_format_label($format)) . '</b> <small class="muted">($bot_release_date_format = ' . bf_h(isset($bot_release_date_format) ? $bot_release_date_format : 'auto') . ($field['source'] === 'theme' ? '؛ برای فیلد قالب همیشه میلادی Y-m-d — قالب فقط همین را می‌خواند' : '') . ')</small>';
            if ($count_fixdate > 0) {
                echo '<br><span class="warn">' . (int) $count_fixdate . ' پست</span> تاریخ را فقط در متای ربات دارند (فیلد قالب خالی است) → <a href="' . esc_url(add_query_arg(array_merge($base_args, ['fixdate' => 1, 'run' => 1, 'auto' => 1]), $self)) . '"><b>انتقال به فیلد قالب (بدون خواندن مبدأ)</b></a> <small class="muted">یا <a href="' . esc_url(add_query_arg(array_merge($base_args, ['fixdate' => 1]), $self)) . '">فهرست‌شان</a></small>';
            }
        } else {
            echo '<span class="bad">پیدا نشد</span> — تاریخ فقط در متاهای <code>_bm_release_date</code> / <code>_bm_release_date_jalali</code> ذخیره می‌شود. نام فیلد را در config.php بدهید: <code>$bot_release_date_field</code>.';
            $all_fields = bm_acf_post_fields();
            if (!empty($all_fields)) {
                $names = [];
                foreach ($all_fields as $f) {
                    $names[] = '<code>' . bf_h($f['name']) . '</code> (' . bf_h($f['label']) . '، ' . bf_h($f['type']) . ')';
                }
                echo '<br><small class="muted">فیلدهای ACF نوشته‌ها: ' . implode('، ', $names) . '</small>';
            } else {
                echo '<br><small class="muted">هیچ فیلد ACF برای نوشته‌ها پیدا نشد (ACF غیرفعال است یا فیلد تاریخ با ACF ساخته نشده).</small>';
            }
        }
    ?></td></tr>
    <tr><th>تاکسونومی‌ها</th><td><?php
        $parts = [];
        foreach (['mood', 'genre', 'songwriter', 'composer', 'arranger'] as $role) {
            $tax = bm_role_taxonomy($role);
            $on  = in_array($role, bm_term_roles(), true);
            $cnt = $tax !== '' ? wp_count_terms(['taxonomy' => $tax, 'hide_empty' => false]) : 0;
            $parts[] = bf_h(bm_role_label($role)) . ' → ' . ($tax !== '' ? '<code>' . bf_h($tax) . '</code> <small class="muted">(' . (is_wp_error($cnt) ? '?' : (int) $cnt) . ' ترم)</small>' : '<span class="bad">در سایت نیست</span>') . (!$on ? ' <small class="warn">(خاموش: $bot_credit_terms)</small>' : '');
        }
        echo implode(' | ', $parts);
        echo '<br><small class="muted">نام ترم حال‌وهوا: پیشوند «' . bf_h(isset($bot_mood_term_prefix) ? $bot_mood_term_prefix : 'آهنگ ') . '» + نگاشت ' . bf_h(wp_json_encode(isset($bot_mood_term_names) ? $bot_mood_term_names : ['ریتمیک' => 'شاد'], JSON_UNESCAPED_UNICODE)) . ' — ژانر: پیشوند «' . bf_h(isset($bot_genre_term_prefix) ? $bot_genre_term_prefix : '') . '»</small>';
    ?></td></tr>
    <tr><th>پست‌ها</th><td><?php echo (int) $count_total; ?> پست <?php echo count($bf_status) > 1 ? '(همه‌ی وضعیت‌ها)' : 'منتشرشده'; ?> — <b><?php echo (int) $count_pending; ?></b> بررسی‌نشده — <?php echo (int) $count_partial; ?> بررسی‌شده ولی ناقص
        <small class="muted">(<?php echo count($bf_status) > 1 ? '<a href="' . esc_url(remove_query_arg('status', $self)) . '">فقط منتشرشده</a>' : '<a href="' . esc_url(add_query_arg(['status' => 'any'], $self)) . '">پیش‌نویس‌ها هم</a>'; ?>)</small></td></tr>
  </table>
  <div class="toolbar">
    <a class="btn gray" href="<?php echo $self; ?>">فهرست</a>
    <a class="btn" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['run' => 1, 'auto' => 1]), $self)); ?>" onclick="return confirm('پست‌های بررسی‌نشده دسته‌به‌دسته (هر دسته <?php echo (int) $bf_limit; ?> پست) پردازش می‌شوند و صفحه خودش ادامه می‌دهد. برای توقف صفحه را ببندید یا «توقف» را بزنید. ادامه می‌دهید؟');">پردازش خودکار همه</a>
    <a class="btn gray" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['run' => 1]), $self)); ?>">پردازش یک دسته</a>
    <a class="btn gray" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['run' => 1, 'dry' => 1]), $self)); ?>">پیش‌نمایش یک دسته (بدون نوشتن)</a>
    <a class="btn gray" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['retry' => $bf_retry ? 0 : 1, 'fixdate' => 0]), $self)); ?>"><?php echo $bf_retry ? 'بررسی‌نشده‌ها' : 'ناقص‌های قبلی (retry)'; ?></a>
    <?php if ($bf_fixdate) : ?><a class="btn gray" href="<?php echo esc_url(remove_query_arg('fixdate', $self)); ?>">بررسی‌نشده‌ها</a><?php endif; ?>
    <a class="btn gray" href="meta-compare.php">متاها / فیلدهای ACF</a>
    <small class="muted">دسته: <?php echo (int) $bf_limit; ?> پست (<code>?limit=</code>۱ تا ۱۰۰) — جست‌وجوی مبدأ تا <?php echo (int) $bf_pages; ?> صفحه‌ی فهرست هنرمند (<code>?pages=</code>)</small>
  </div>
</div>
<?php
/* ---------- پردازش (GET run=1 یا POST فرم) ---------- */
$process  = [];
$sources  = [];
$from_post = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['fill']) && is_array($_POST['fill'])) {
    check_admin_referer('bm_backfill');
    $process   = array_values(array_unique(array_filter(array_map('intval', $_POST['fill']))));
    $sources   = isset($_POST['source']) && is_array($_POST['source']) ? $_POST['source'] : [];
    $bf_dry    = !empty($_POST['dry']);
    $from_post = true;
} elseif ($bf_run) {
    $process = !empty($bf_ids) ? $bf_ids : bf_candidates($bf_status, $bf_limit, $bf_before, $bf_retry, $bf_fixdate);
}

if ($from_post || $bf_run) {
    $next_before = 0;
    echo '<div class="box"><h2>' . ($bf_dry ? 'پیش‌نمایش (چیزی نوشته نشد)' : 'نتیجه') . ' — ' . count($process) . ' پست' . ($bf_before ? ' (ادامه از پست ' . (int) $bf_before . ')' : '') . '</h2>';
    if (empty($process)) {
        echo '<p class="ok">پستی برای پردازش نمانده است' . ($bf_fixdate ? ' (تاریخ همه‌ی پست‌هایی که ربات داشت، در فیلد قالب هست)' : ($bf_retry ? ' (ناقصی وجود ندارد)' : '')) . '.</p>';
    } else {
        echo '<table><tr><th style="width:70px">پست</th><th style="width:28%">عنوان</th><th style="width:90px">نتیجه</th><th>جزئیات</th></tr>';
        $stats = ['ok' => 0, 'partial' => 0, 'error' => 0, 'skip' => 0];
        foreach ($process as $id) {
            bf_flush();
            $manual = isset($sources[$id]) ? trim((string) $sources[$id]) : '';
            $res    = bf_process($id, $manual, $bf_dry, $bf_pages);
            $stats[$res['status']] = (isset($stats[$res['status']]) ? $stats[$res['status']] : 0) + 1;
            $next_before = $next_before === 0 ? $id : min($next_before, $id);
            $labels = ['ok' => '<span class="ok">کامل</span>', 'partial' => '<span class="warn">ناقص</span>', 'error' => '<span class="bad">پر نشد</span>', 'skip' => '<span class="muted">—</span>'];
            $tags   = '';
            foreach ($res['filled'] as $item) {
                $tags .= '<span class="tag">' . bf_h(bf_item_label($item)) . ' ✓</span>';
            }
            foreach ($res['missing'] as $item) {
                $tags .= '<span class="tag" style="background:#fee2e2">' . bf_h(bf_item_label($item)) . ' ✗</span>';
            }
            echo '<tr><td>' . (int) $id . '</td><td><a href="' . esc_url(get_permalink($id)) . '" target="_blank">' . bf_h(get_the_title($id)) . '</a>'
                . ' <small><a href="' . esc_url(get_edit_post_link($id, '')) . '" target="_blank" class="muted">ویرایش</a></small></td>'
                . '<td>' . $labels[$res['status']] . '<br>' . $tags . '</td>'
                . '<td><ul class="report"><li>' . implode('</li><li>', array_map('bf_h', $res['lines'])) . '</li></ul></td></tr>';
        }
        echo '</table>';
        echo '<p>کامل: <b>' . $stats['ok'] . '</b> — ناقص: <b>' . $stats['partial'] . '</b> — پر نشد: <b>' . $stats['error'] . '</b>'
            . ($bf_dry ? ' <span class="muted">(پیش‌نمایش؛ متای _bm_backfill هم نوشته نشد)</span>' : '') . '</p>';
    }
    if ($bf_run && empty($bf_ids) && !empty($process)) {
        $next_url = add_query_arg(array_merge($base_args, ['run' => 1, 'before' => (int) $next_before] + ($bf_auto ? ['auto' => 1] : []) + ($bf_dry ? ['dry' => 1] : [])), $self);
        echo '<div class="toolbar"><a class="btn" href="' . esc_url($next_url) . '">ادامه (پست‌های قدیمی‌تر از ' . (int) $next_before . ')</a>'
            . '<a class="btn red" href="' . $self . '">توقف</a>'
            . ($bf_auto ? '<small class="muted">ادامه‌ی خودکار تا ۲ ثانیه دیگر…</small>' : '') . '</div>';
        if ($bf_auto) {
            echo '<script>setTimeout(function(){location.href=' . wp_json_encode(esc_url_raw($next_url)) . ';},2000);</script>';
        }
    } elseif ($bf_run && empty($bf_ids) && empty($process) && $bf_auto) {
        echo '<p class="ok">پردازش خودکار تمام شد.</p>';
    }
    echo '</div>';
}

/* ---------- فهرست ---------- */
if (!$from_post && !$bf_run) :
    $ids  = !empty($bf_ids) ? $bf_ids : bf_candidates($bf_status, $bf_limit, $bf_before, $bf_retry, $bf_fixdate);
    $rows = [];
    foreach ($ids as $id) {
        $p = get_post($id);
        if (!$p) {
            continue;
        }
        $state  = bf_state($id);
        $saved  = bf_saved_source($id);
        $last   = json_decode((string) get_post_meta($id, '_bm_backfill', true), true);
        $rows[] = ['post' => $p, 'state' => $state, 'source' => $saved, 'last' => is_array($last) ? $last : null];
    }
    $min_id = !empty($ids) ? min($ids) : 0;
?>
<div class="box">
  <h2><?php echo $bf_fixdate ? 'پست‌هایی که تاریخ فقط در متای ربات دارند (فیلد قالب خالی)' : ($bf_retry ? 'پست‌های بررسی‌شده‌ی ناقص' : 'پست‌های بررسی‌نشده'); ?> (<?php echo count($rows); ?> پست<?php echo $bf_before ? '، قدیمی‌تر از ' . (int) $bf_before : ''; ?>)</h2>
  <form method="post" action="<?php echo esc_url(add_query_arg($base_args, $self)); ?>">
    <?php wp_nonce_field('bm_backfill'); ?>
    <div class="toolbar">
      <button type="submit">تکمیل تیک‌خورده‌ها</button>
      <button type="submit" name="dry" value="1" class="btn gray">پیش‌نمایش تیک‌خورده‌ها (بدون نوشتن)</button>
      <label><input type="checkbox" checked onclick="document.querySelectorAll('input[name=\'fill[]\']').forEach(c=>c.checked=this.checked)"> همه</label>
      <?php if ($min_id > 0 && count($rows) >= $bf_limit) : ?><a class="btn gray" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['before' => $min_id]), $self)); ?>">صفحه‌ی بعد (قدیمی‌تر)</a><?php endif; ?>
      <small class="muted">ستون «لینک مبدأ»: اگر خالی است و لازم شد، ربات در فهرست آهنگ‌های همان خواننده در سایت مبدأ می‌گردد؛ می‌توانید لینک صفحه‌ی آهنگ در mybia2music را خودتان بنویسید.</small>
    </div>
    <table>
      <tr><th></th><th>پست</th><th>تاریخ انتشار</th><th>حال‌وهوا</th><th>ژانر</th><th>عوامل</th><th style="width:240px">لینک مبدأ</th></tr>
      <?php foreach ($rows as $r) : $p = $r['post']; $s = $r['state']; ?>
      <tr>
        <td><input type="checkbox" name="fill[]" value="<?php echo (int) $p->ID; ?>" checked></td>
        <td><a href="<?php echo esc_url(get_permalink($p)); ?>" target="_blank"><?php echo bf_h($p->post_title); ?></a><br><small class="muted">ID <?php echo (int) $p->ID; ?> — <?php echo bf_h(get_date_from_gmt($p->post_date_gmt, 'Y-m-d')); ?><?php echo $p->post_status !== 'publish' ? ' — <span class="warn">' . bf_h($p->post_status) . '</span>' : ''; ?></small>
            <?php if ($r['last']) : ?><br><small class="muted">آخرین بررسی <?php echo bf_h($r['last']['time']); ?>: کم دارد <?php echo bf_h(implode('، ', array_map('bf_item_label', (array) $r['last']['missing']))); ?></small><?php endif; ?></td>
        <td><?php echo $s['date_field'] !== '' ? '<span class="ok">' . bf_h($s['date_field']) . '</span>' : ($s['date_meta'] !== '' ? '<span class="warn" title="فقط متای ربات؛ فیلد قالب خالی/پیدا نشده">' . bf_h($s['date_jalali'] !== '' ? $s['date_jalali'] : $s['date_meta']) . '</span>' : '<span class="muted">—</span>'); ?></td>
        <?php foreach (['mood', 'genre'] as $role) : ?>
        <td><?php
            $t = $s['terms'][$role] ?? ['taxonomy' => '', 'names' => []];
            if ($t['taxonomy'] === '') {
                echo '<span class="muted">تاکسونومی نیست</span>';
            } elseif (!empty($t['names'])) {
                echo '<span class="ok">' . bf_h(implode('، ', $t['names'])) . '</span>';
            } else {
                $m = $s['metas'][$role];
                echo $m !== '' ? '<span class="warn" title="از متای پست پر می‌شود">متا: ' . bf_h($m) . '</span>' : '<span class="muted">—</span>';
            }
        ?></td>
        <?php endforeach; ?>
        <td><small><?php
            $bits = [];
            foreach (['songwriter', 'composer', 'arranger'] as $role) {
                $t = $s['terms'][$role] ?? ['taxonomy' => '', 'names' => []];
                if ($t['taxonomy'] === '') {
                    continue;
                }
                $m = $s['metas'][bf_role_meta_key($role)];
                $bits[] = bf_h(bm_role_label($role)) . ': ' . (!empty($t['names']) ? '<span class="ok">' . bf_h(implode('، ', $t['names'])) . '</span>' : ($m !== '' ? '<span class="warn">متا: ' . bf_h($m) . '</span>' : '<span class="muted">—</span>'));
            }
            echo !empty($bits) ? implode('<br>', $bits) : '<span class="muted">تاکسونومی نیست</span>';
        ?></small></td>
        <td><input type="text" name="source[<?php echo (int) $p->ID; ?>]" value="<?php echo bf_h($r['source']['url']); ?>" placeholder="جست‌وجو در فهرست هنرمند مبدأ"><?php echo $r['source']['from'] !== '' ? '<small class="muted">' . bf_h($r['source']['from']) . '</small>' : ''; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)) : ?><tr><td colspan="7" class="ok">پستی نمانده است.</td></tr><?php endif; ?>
    </table>
  </form>
</div>
<?php endif; ?>
</body>
</html>
