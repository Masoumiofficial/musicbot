<?php
/**
 * Plugin Name: AhangBan SEO
 * Description: noindex کردن صفحه‌های تگ (تگ در سایت استفاده نمی‌شود) + اسکیمای MusicRecording (JSON-LD) برای پست‌های آهنگ + کامل کردن لینک‌های داخلی بدون دامنه در خروجی
 * Version:     1.3.0
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

/** الگوی لینک مطلق به دامنه‌های قدیمی (AHANGBAN_OLD_HOSTS)؛ '' = تعریف نشده */
function ahangban_old_hosts_pattern() {
    static $pattern = null;
    if ($pattern !== null) {
        return $pattern;
    }
    $pattern = '';
    $hosts   = [];
    $current = strtolower((string) parse_url(ahangban_site_origin(), PHP_URL_HOST));
    foreach (preg_split('/[\s,]+/', strtolower((string) AHANGBAN_OLD_HOSTS)) as $host) {
        $host = trim((string) preg_replace('#^[a-z]+://#', '', $host), "/ \t");
        if ($host === '' || $host === $current) {
            continue;
        }
        $bare = preg_replace('#^www\.#', '', $host);
        $hosts[$bare]          = true;
        $hosts['www.' . $bare] = true;
    }
    unset($hosts[$current]);
    if (!empty($hosts)) {
        $pattern = '#^\s*(?:https?:)?//(?:' . implode('|', array_map(function ($h) {
            return preg_quote($h, '#');
        }, array_keys($hosts))) . ')(?::\d+)?(?=[/?\#]|\s*$)#i';
    }
    return $pattern;
}

/**
 * در صفت‌های href/src/poster/data-src:
 *   «/music/» (بدون دامنه)            → https://دامنه‌ی-فعلی/music/
 *   «https://دامنه‌ی-قدیمی/music/»    → https://دامنه‌ی-فعلی/music/   (فقط اگر AHANGBAN_OLD_HOSTS تعریف شده باشد)
 * بقیه‌ی لینک‌ها (خارجی، //cdn، mailto:، #) دست نمی‌خورند. متن ساده هم تغییر نمی‌کند.
 */
function ahangban_absolutize_links($html) {
    if (!AHANGBAN_ABSOLUTE_LINKS_OUTPUT || !is_string($html) || $html === '' || strpos($html, '=') === false) {
        return $html;
    }
    $origin = ahangban_site_origin();
    $old    = ahangban_old_hosts_pattern();
    if ($origin === '') {
        return $html;
    }
    return preg_replace_callback(
        '#(\b(?:href|src|poster|data-src)\s*=\s*)(["\'])(.*?)\2#is',
        function ($m) use ($origin, $old) {
            $url = $m[3];
            if (preg_match('#^/(?![/\\\\])#', $url)) {
                $url = $origin . $url;
            } elseif ($old !== '' && preg_match($old, $url)) {
                $rest = trim((string) preg_replace($old, '', $url));
                $url  = $origin . ($rest === '' || $rest[0] === '/' ? $rest : '/' . $rest);
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
