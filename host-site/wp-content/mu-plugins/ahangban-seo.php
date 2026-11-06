<?php
/**
 * Plugin Name: AhangBan SEO
 * Description: noindex کردن صفحه‌های تگ (تگ در سایت استفاده نمی‌شود) + اسکیمای MusicRecording (JSON-LD) برای پست‌های آهنگ + کامل کردن لینک‌های داخلی بدون دامنه در خروجی + هاست دانلود همراه دامنه‌ی سایت
 * Version:     1.4.0
 * Author:      AhangBan
 *
 * محل قرارگیری:  wp-content/mu-plugins/ahangban-seo.php
 * (اگر پوشه mu-plugins وجود ندارد، آن را بسازید. افزونه‌های این پوشه همیشه فعال هستند و نیازی به فعال‌سازی ندارند.)
 *
 * 1) ربات دیگر به پست‌ها تگ نمی‌دهد (آرشیو خواننده = taxonomy هنرمند «artist» (قبلاً «singer»)، «دانلود آهنگ» = دسته‌بندی).
 *    تگ‌های قدیمی («دانلود آهنگ»، «آهنگ X» و ...) هنوز در سایت هستند؛ این افزونه همه‌ی صفحه‌های تگ را
 *    noindex می‌کند و از نقشه سایت Rank Math حذف می‌کند تا صفحه‌های تکراری/تک‌پستی در گوگل ایندکس نشوند.
 *    (اگر روزی خواستید دوباره از تگ استفاده کنید، AHANGBAN_NOINDEX_ALL_TAGS را false کنید؛ در آن حالت
 *    فقط تگ‌هایی که کمتر از AHANGBAN_TAG_MIN_POSTS پست دارند noindex می‌شوند.)
 * 2) برای هر پست آهنگ (که متاهای song و artist دارد) اسکیمای MusicRecording خروجی می‌دهد:
 *    نام آهنگ، خواننده (اگر چند هنرمند داشته باشد، هر کدام با لینک صفحه‌ی خودش)، سبک، تصویر،
 *    فایل صوتی، تاریخ انتشار، آهنگساز/ترانه‌سرا/تنظیم و متن ترانه.
 * 3) لینک‌های داخلی متن پست‌ها بدون دامنه ذخیره می‌شوند (/music/، /artist/… — ربات و پست‌ساز، و پست‌های قدیمی با
 *    bot/domain-tool.php) تا تغییر دامنه‌ی سایت نیازی به ویرایش دیتابیس نداشته باشد. این افزونه در خروجی
 *    (متن پست، خلاصه، فید RSS، REST) آن‌ها را با دامنه‌ی فعلی کامل می‌کند؛ پس خواننده‌ی فید و کپی‌کننده‌های متن
 *    همیشه لینک کامل می‌بینند. بعد از تغییر دامنه، لینک‌های مطلقِ دامنه‌ی قدیمی (AHANGBAN_OLD_HOSTS) هم در خروجی
 *    به دامنه‌ی فعلی برمی‌گردند تا پیش از اجرای ابزار، چیزی خراب نباشد.
 * 4) هاست دانلود همراه دامنه‌ی سایت: لینک‌های دانلود (متاهای music320/music128/online، آلبوم، ویدیو) کامل و روی
 *    هاست دانلود (dl.ahangban.ir) ذخیره می‌شوند. وقتی دامنه عوض شد و هاست دانلود هم همراهش (dl.ahangban.com)،
 *    با define('AHANGBAN_OLD_DL_HOSTS', 'dl.ahangban.ir') این افزونه در لحظه‌ی خواندن متا (قالب، پلیر، ACF، اسکیما،
 *    REST) و در لینک‌های داخل متن، هاست قدیمی را با هاست دانلود فعلی عوض می‌کند — یعنی از همان لحظه همه‌ی دانلودهای
 *    پست‌های قدیمی کار می‌کنند؛ تغییر دائمی دیتابیس را بعداً bot/domain-tool.php (بخش ۴) انجام می‌دهد.
 *    هاست دانلود فعلی = AHANGBAN_DL_HOST، و اگر تعریف نشده «dl.» + دامنه‌ی سایت (همان قاعده‌ی bot/config.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** true = همه‌ی صفحه‌های تگ noindex شوند (تگ در سایت استفاده نمی‌شود) */
if (!defined('AHANGBAN_NOINDEX_ALL_TAGS')) {
    define('AHANGBAN_NOINDEX_ALL_TAGS', true);
}

/** فقط وقتی AHANGBAN_NOINDEX_ALL_TAGS برابر false است: حداقل تعداد پست برای ایندکس شدن صفحه‌ی یک تگ */
if (!defined('AHANGBAN_TAG_MIN_POSTS')) {
    define('AHANGBAN_TAG_MIN_POSTS', 3);
}

/** true = لینک‌های بدون دامنه‌ی متن پست‌ها (/music/ …) در خروجی با دامنه‌ی فعلی کامل شوند (بخش ۳) */
if (!defined('AHANGBAN_ABSOLUTE_LINKS_OUTPUT')) {
    define('AHANGBAN_ABSOLUTE_LINKS_OUTPUT', true);
}

/**
 * دامنه‌های قدیمی سایت، با کاما (بعد از تغییر دامنه در wp-config.php تعریف کنید):
 *   define('AHANGBAN_OLD_HOSTS', 'ahangban.ir, www.ahangban.ir');
 * لینک‌های مطلق به این دامنه‌ها در خروجی متن/خلاصه/فید به دامنه‌ی فعلی برمی‌گردند (دیتابیس را bot/domain-tool.php اصلاح می‌کند).
 */
if (!defined('AHANGBAN_OLD_HOSTS')) {
    define('AHANGBAN_OLD_HOSTS', '');
}

/**
 * هاست دانلود فعلی (scheme + host). خالی = خودکار: «dl.» + دامنه‌ی سایت بدون www — همان قاعده‌ی bot/config.php
 * ($urldlhost خالی). فقط اگر هاست دانلود این قاعده را ندارد در wp-config.php تعریف کنید:
 *   define('AHANGBAN_DL_HOST', 'https://files.example.com');
 */
if (!defined('AHANGBAN_DL_HOST')) {
    define('AHANGBAN_DL_HOST', '');
}

/**
 * هاست‌های دانلود قدیمی، با کاما (بعد از تغییر دامنه‌ی هاست دانلود در wp-config.php تعریف کنید):
 *   define('AHANGBAN_OLD_DL_HOSTS', 'dl.ahangban.ir');
 * لینک‌های دانلود روی این هاست‌ها در لحظه‌ی خواندن متا و در خروجی متن به هاست دانلود فعلی می‌روند (بخش ۴).
 */
if (!defined('AHANGBAN_OLD_DL_HOSTS')) {
    define('AHANGBAN_OLD_DL_HOSTS', '');
}

/* -------------------------------------------------------
 |  1) noindex صفحه‌های تگ
 * ------------------------------------------------------ */

/**
 * آیا این تگ باید noindex شود؟
 */
function ahangban_tag_is_noindex($term) {
    if (!($term instanceof WP_Term) || $term->taxonomy !== 'post_tag') {
        return false;
    }

    return AHANGBAN_NOINDEX_ALL_TAGS || (int) $term->count < AHANGBAN_TAG_MIN_POSTS;
}

/**
 * آیا صفحه‌ی جاری، آرشیو یک تگِ noindex است؟
 */
function ahangban_is_noindex_tag_archive() {
    return is_tag() && ahangban_tag_is_noindex(get_queried_object());
}

// Rank Math
add_filter('rank_math/frontend/robots', function ($robots) {
    if (ahangban_is_noindex_tag_archive()) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
    }
    return $robots;
});

// حذف تگ‌ها از نقشه سایت Rank Math
add_filter('rank_math/sitemap/entry', function ($url, $type, $object) {
    if ($type === 'term' && ahangban_tag_is_noindex($object)) {
        return false;
    }
    return $url;
}, 10, 3);

// اگر Rank Math فعال نبود، متا روبوتس را خودمان چاپ می‌کنیم
add_action('wp_head', function () {
    if (!class_exists('RankMath') && ahangban_is_noindex_tag_archive()) {
        echo '<meta name="robots" content="noindex, follow">' . "\n";
    }
}, 1);

/* -------------------------------------------------------
 |  2) اسکیمای MusicRecording برای پست‌های آهنگ
 * ------------------------------------------------------ */

/**
 * جدا کردن چند نام از یک فیلد («علی و رضا»، «A & B»، «A، B») و تبدیل به Person
 */
function ahangban_schema_persons($value) {
    $value = trim(wp_strip_all_tags((string) $value));
    if ($value === '') {
        return null;
    }

    $persons = [];
    foreach (preg_split('/\s*(?:[،,\/&|؛;]|\s+و\s+|\s+and\s+)\s*/iu', $value) as $name) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name), " \t-–_:.");
        if ($name === '' || mb_strlen($name, 'UTF-8') < 3) {
            continue;
        }
        $persons[] = ['@type' => 'Person', 'name' => $name];
    }

    if (empty($persons)) {
        return null;
    }

    return count($persons) === 1 ? $persons[0] : $persons;
}

/**
 * ساخت آرایه‌ی اسکیما از متاهای پست؛ اگر پست آهنگ نباشد null برمی‌گردد
 */
function ahangban_build_music_schema($post_id) {
    $meta = function ($key) use ($post_id) {
        return trim(wp_strip_all_tags((string) get_post_meta($post_id, $key, true)));
    };

    $song   = $meta('song');
    $artist = $meta('artist');

    if ($song === '' || $artist === '') {
        return null;
    }

    $song_en   = $meta('song_en');
    $artist_en = $meta('artist_en');
    $genre     = $meta('genre');
    $mood      = $meta('mood');
    $composer  = $meta('composer');
    $arranger  = $meta('arranger');
    $lyricist  = $meta('lyricist');
    $lyrics    = trim(wp_strip_all_tags((string) get_post_meta($post_id, 'music_text', true)));

    $permalink = get_permalink($post_id);
    $image     = get_the_post_thumbnail_url($post_id, 'full');

    // هنرمند(ان) از taxonomy هنرمند («artist»؛ روی سایت‌های قدیمی «singer») — هر ترم با لینک صفحه‌ی خودش
    $term_artists = [];
    foreach (['artist', 'singer'] as $artist_taxonomy) {
        if (taxonomy_exists($artist_taxonomy)) {
            $singer_terms = get_the_terms($post_id, $artist_taxonomy);
            if (is_array($singer_terms)) {
                foreach ($singer_terms as $singer_term) {
                    $entry = [
                        '@type' => preg_match('/گروه/u', $singer_term->name) ? 'MusicGroup' : 'Person',
                        'name'  => $singer_term->name,
                    ];
                    $link = get_term_link($singer_term);
                    if (!is_wp_error($link)) {
                        $entry['url'] = $link;
                    }
                    $term_artists[] = $entry;
                }
            }
            break;
        }
    }

    if (count($term_artists) > 1) {
        // چند هنرمند (مثلاً «همایون شجریان و تهمورس پورناظری»): هر کدام جدا
        $by_artist = $term_artists;
    } else {
        $is_band   = preg_match('/\b(band|group|boys|brothers)\b/i', $artist_en) || preg_match('/گروه/u', $artist);
        $by_artist = ['@type' => $is_band ? 'MusicGroup' : 'Person', 'name' => $artist];
        if ($artist_en !== '' && $artist_en !== $artist) {
            $by_artist['alternateName'] = $artist_en;
        }
        if (!empty($term_artists[0]['url'])) {
            $by_artist['url'] = $term_artists[0]['url'];
        }
    }

    $schema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'MusicRecording',
        '@id'              => $permalink . '#music',
        'name'             => $song,
        'url'              => $permalink,
        'mainEntityOfPage' => $permalink,
        'inLanguage'       => 'fa',
        'byArtist'         => $by_artist,
        'datePublished'    => get_the_date('c', $post_id),
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => get_bloginfo('name'),
            'url'   => home_url('/'),
        ],
    ];

    if ($song_en !== '' && $song_en !== $song) {
        $schema['alternateName'] = $song_en;
    }
    if ($genre !== '') {
        $schema['genre'] = $genre;
    }
    if ($image) {
        $schema['image'] = $image;
    }

    $keywords = array_values(array_unique(array_filter([$artist, $song, $artist_en, $song_en, $genre, $mood])));
    if (!empty($keywords)) {
        $schema['keywords'] = implode(', ', $keywords);
    }

    // فایل صوتی (ترجیحاً 128 برای پخش آنلاین)
    $audio_url = '';
    foreach (['music128', 'online', 'music320'] as $key) {
        // نام فایل‌ها فاصله و [ ] دارند؛ فاصله را کدگذاری می‌کنیم و فقط آدرس http(s) می‌پذیریم
        $candidate = str_replace(' ', '%20', $meta($key));
        if ($candidate !== '' && preg_match('#^https?://\S+$#i', $candidate)) {
            $audio_url = $candidate;
            break;
        }
    }
    if ($audio_url !== '') {
        $schema['audio'] = [
            '@type'          => 'AudioObject',
            'name'           => "{$artist} - {$song}",
            'contentUrl'     => $audio_url,
            'encodingFormat' => 'audio/mpeg',
        ];
    }

    // اثر موسیقایی: آهنگساز، ترانه‌سرا، متن ترانه
    $composition = ['@type' => 'MusicComposition', 'name' => $song];
    if ($composer !== '' && ($persons = ahangban_schema_persons($composer))) {
        $composition['composer'] = $persons;
    }
    if ($lyricist !== '' && ($persons = ahangban_schema_persons($lyricist))) {
        $composition['lyricist'] = $persons;
    }
    if ($lyrics !== '') {
        $composition['lyrics'] = [
            '@type'      => 'CreativeWork',
            'inLanguage' => 'fa',
            'text'       => mb_substr($lyrics, 0, 5000, 'UTF-8'),
        ];
    }
    if (count($composition) > 2) {
        $schema['recordingOf'] = $composition;
    }

    if ($arranger !== '' && ($persons = ahangban_schema_persons($arranger))) {
        $schema['contributor'] = $persons;
    }

    return apply_filters('ahangban_music_schema', $schema, $post_id);
}

add_action('wp_head', function () {
    if (!is_singular('post')) {
        return;
    }

    $schema = ahangban_build_music_schema(get_queried_object_id());
    if (empty($schema)) {
        return;
    }

    echo '<script type="application/ld+json">'
        . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
        . '</script>' . "\n";
}, 20);

/* -------------------------------------------------------
 |  3) لینک‌های داخلی بدون دامنه → کامل با دامنه‌ی فعلی (در خروجی)
 * ------------------------------------------------------ */

/** scheme + host سایت، بدون مسیر (https://ahangban.ir) */
function ahangban_site_origin() {
    static $origin = null;
    if ($origin === null) {
        $origin = preg_match('#^(https?://[^/]+)#i', (string) home_url('/'), $m) ? $m[1] : rtrim((string) home_url(), '/');
    }
    return $origin;
}

/**
 * الگوی «آدرس مطلق روی یکی از این میزبان‌ها» (http/https/بدون پروتکل، با یا بی‌پورت) از یک فهرست با کاما؛
 * میزبان فعلی ($current) حذف می‌شود تا چیزی به خودش عوض نشود. با $with_www هر میزبان با و بی www می‌آید. '' = خالی
 */
function ahangban_hosts_pattern($list, $current, $with_www = true) {
    $hosts   = [];
    $current = strtolower((string) $current);
    foreach (preg_split('/[\s,]+/', strtolower((string) $list)) as $host) {
        $host = trim((string) preg_replace('#^[a-z]+://#', '', $host), "/ \t");
        $host = (string) preg_replace('#[/?\#].*$#', '', $host);
        if ($host === '') {
            continue;
        }
        if ($with_www) {
            $bare = preg_replace('#^www\.#', '', $host);
            $hosts[$bare]          = true;
            $hosts['www.' . $bare] = true;
        } else {
            $hosts[$host] = true;
        }
    }
    unset($hosts[$current]);
    if (empty($hosts)) {
        return '';
    }
    return '#^\s*(?:https?:)?//(?:' . implode('|', array_map(function ($h) {
        return preg_quote($h, '#');
    }, array_keys($hosts))) . ')(?::\d+)?(?=[/?\#]|\s*$)#i';
}

/** الگوی لینک مطلق به دامنه‌های قدیمی سایت (AHANGBAN_OLD_HOSTS)؛ '' = تعریف نشده */
function ahangban_old_hosts_pattern() {
    static $pattern = null;
    if ($pattern === null) {
        $pattern = ahangban_hosts_pattern(AHANGBAN_OLD_HOSTS, parse_url(ahangban_site_origin(), PHP_URL_HOST), true);
    }
    return $pattern;
}

/** مسیر بعد از میزبان را روی $origin می‌گذارد: «https://old/music/x» + https://new → https://new/music/x */
function ahangban_url_reorigin($url, $pattern, $origin) {
    $rest = trim((string) preg_replace($pattern, '', (string) $url));
    return $origin . ($rest === '' || $rest[0] === '/' ? $rest : '/' . $rest);
}

/**
 * در صفت‌های href/src/poster/data-src:
 *   «/music/» (بدون دامنه)                 → https://دامنه‌ی-فعلی/music/
 *   «https://دامنه‌ی-قدیمی/music/»         → https://دامنه‌ی-فعلی/music/        (فقط اگر AHANGBAN_OLD_HOSTS تعریف شده باشد)
 *   «https://هاست-دانلود-قدیمی/music/x»    → https://هاست-دانلود-فعلی/music/x  (فقط اگر AHANGBAN_OLD_DL_HOSTS تعریف شده باشد)
 * بقیه‌ی لینک‌ها (خارجی، //cdn، mailto:، #) دست نمی‌خورند. متن ساده هم تغییر نمی‌کند.
 */
function ahangban_absolutize_links($html) {
    if (!AHANGBAN_ABSOLUTE_LINKS_OUTPUT || !is_string($html) || $html === '' || strpos($html, '=') === false) {
        return $html;
    }
    $origin = ahangban_site_origin();
    $old    = ahangban_old_hosts_pattern();
    $old_dl = ahangban_old_dl_hosts_pattern();
    $dl     = $old_dl !== '' ? ahangban_dl_origin() : '';
    if ($origin === '') {
        return $html;
    }
    return preg_replace_callback(
        '#(\b(?:href|src|poster|data-src)\s*=\s*)(["\'])(.*?)\2#is',
        function ($m) use ($origin, $old, $old_dl, $dl) {
            $url = $m[3];
            if (preg_match('#^/(?![/\\\\])#', $url)) {
                $url = $origin . $url;
            } elseif ($old !== '' && preg_match($old, $url)) {
                $url = ahangban_url_reorigin($url, $old, $origin);
            } elseif ($dl !== '' && preg_match($old_dl, $url)) {
                $url = ahangban_url_reorigin($url, $old_dl, $dl);
            }
            return $m[1] . $m[2] . $url . $m[2];
        },
        $html
    );
}

// اولویت ۱: قبل از wpautop و بقیه‌ی فیلترها، تا همه‌ی افزونه‌ها (فید، تلگرام، REST) لینک کامل ببینند — مثل وقتی که
// در دیتابیس لینک کامل بود
foreach (['the_content', 'the_excerpt', 'the_content_feed', 'the_excerpt_rss', 'widget_text_content', 'widget_block_content'] as $ahangban_links_filter) {
    add_filter($ahangban_links_filter, 'ahangban_absolutize_links', 1);
}
unset($ahangban_links_filter);

/* -------------------------------------------------------
 |  4) هاست دانلود همراه دامنه‌ی سایت (AHANGBAN_DL_HOST / AHANGBAN_OLD_DL_HOSTS)
 * ------------------------------------------------------ */

/** هاست دانلود فعلی (scheme + host، بدون / آخر): AHANGBAN_DL_HOST یا https://dl.<دامنه‌ی سایت بدون www> */
function ahangban_dl_origin() {
    static $origin = null;
    if ($origin !== null) {
        return $origin;
    }
    $origin = trim((string) AHANGBAN_DL_HOST);
    if ($origin !== '') {
        if (!preg_match('#^https?://#i', $origin)) {
            $origin = 'https://' . ltrim($origin, '/');
        }
        $origin = rtrim($origin, '/');
        return $origin;
    }
    $site = ahangban_site_origin();
    $host = strtolower((string) parse_url($site, PHP_URL_HOST));
    if ($host === '') {
        return $origin;
    }
    $scheme = strtolower((string) parse_url($site, PHP_URL_SCHEME)) === 'http' ? 'http' : 'https';
    $origin = rtrim((string) apply_filters('ahangban_dl_origin', $scheme . '://dl.' . preg_replace('#^www\.#', '', $host)), '/');
    return $origin;
}

/** الگوی لینک روی هاست‌های دانلود قدیمی (AHANGBAN_OLD_DL_HOSTS)؛ '' = تعریف نشده یا همان هاست فعلی */
function ahangban_old_dl_hosts_pattern() {
    static $pattern = null;
    if ($pattern === null) {
        $pattern = ahangban_hosts_pattern(AHANGBAN_OLD_DL_HOSTS, parse_url(ahangban_dl_origin(), PHP_URL_HOST), false);
    }
    return $pattern;
}

/** یک مقدار متا: اگر رشته و روی هاست دانلود قدیمی بود → همان مسیر روی هاست دانلود فعلی؛ وگرنه دست‌نخورده */
function ahangban_swap_dl_host($value) {
    if (!is_string($value) || $value === '' || strpos($value, '//') === false) {
        return $value;
    }
    $old_dl = ahangban_old_dl_hosts_pattern();
    if ($old_dl === '' || !preg_match($old_dl, $value)) {
        return $value;
    }
    return ahangban_url_reorigin($value, $old_dl, ahangban_dl_origin());
}

/** کلیدهای متایی که لینک دانلود دارند (قالب + پست‌ساز)؛ فیلتر ahangban_dl_meta_keys برای افزودن */
function ahangban_is_dl_meta_key($key) {
    static $pattern = null;
    if ($pattern === null) {
        $keys = apply_filters('ahangban_dl_meta_keys', [
            'music320', 'music128', 'online', 'album320', 'album128',
            'video1080', 'video720', 'video480', 'video_low', 'video_medium',
        ]);
        $pattern = '/^(?:' . implode('|', array_map(function ($k) {
            return preg_quote((string) $k, '/');
        }, (array) $keys)) . '|album_\d+_albumlink(?:320|128))$/';
    }
    return $key !== '' && preg_match($pattern, (string) $key) === 1;
}

/**
 * فیلتر get_post_metadata: مقدار متاهای لینک دانلود را از کش متای وردپرس می‌خواند و اگر روی هاست دانلود قدیمی بود،
 * روی هاست فعلی برمی‌گرداند — همه‌ی خواننده‌ها (قالب، پلیر، ACF get_field، اسکیما، REST) همین را می‌بینند.
 * فقط وقتی AHANGBAN_OLD_DL_HOSTS تعریف شده کاری می‌کند؛ وگرنه هزینه‌ای ندارد. دیتابیس تغییر نمی‌کند.
 */
function ahangban_dl_meta_filter($value, $object_id, $meta_key, $single) {
    if (ahangban_old_dl_hosts_pattern() === '') {
        return $value;
    }
    $meta_key = (string) $meta_key;
    if ($meta_key !== '' && !ahangban_is_dl_meta_key($meta_key)) {
        return $value;
    }
    if ($value !== null) { // افزونه‌ی دیگری مقدار را داده؛ فقط هاست را عوض می‌کنیم
        return is_array($value) ? array_map('ahangban_swap_dl_host', $value) : ahangban_swap_dl_host($value);
    }
    $object_id = (int) $object_id;
    $cache     = wp_cache_get($object_id, 'post_meta');
    if (!$cache) {
        $cache = update_meta_cache('post', [$object_id]);
        $cache = isset($cache[$object_id]) ? $cache[$object_id] : null;
    }
    if (!is_array($cache)) {
        return $value;
    }
    if ($meta_key === '') { // get_post_meta($id) / get_post_custom(): همه‌ی متاها (مقدارهای خام)
        if ($single) {
            return $value;
        }
        foreach ($cache as $key => $values) {
            if (ahangban_is_dl_meta_key((string) $key) && is_array($values)) {
                $cache[$key] = array_map('ahangban_swap_dl_host', $values);
            }
        }
        return $cache;
    }
    if (!isset($cache[$meta_key]) || !is_array($cache[$meta_key])) {
        return $value; // متا وجود ندارد → پیش‌فرض خود وردپرس ('' یا [])
    }
    $values = array_map(function ($v) {
        return ahangban_swap_dl_host(maybe_unserialize($v));
    }, $cache[$meta_key]);
    return $single ? [reset($values)] : $values;
}
add_filter('get_post_metadata', 'ahangban_dl_meta_filter', 9999, 4);
