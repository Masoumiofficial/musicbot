<?php
/**
 * Plugin Name: AhangBan SEO
 * Description: جلوگیری از ایندکس شدن صفحه‌های تگ کم‌محتوا + اسکیمای MusicRecording (JSON-LD) برای پست‌های آهنگ
 * Version:     1.0.0
 * Author:      AhangBan
 *
 * محل قرارگیری:  wp-content/mu-plugins/ahangban-seo.php
 * (اگر پوشه mu-plugins وجود ندارد، آن را بسازید. افزونه‌های این پوشه همیشه فعال هستند و نیازی به فعال‌سازی ندارند.)
 *
 * 1) تگ‌هایی که کمتر از AHANGBAN_TAG_MIN_POSTS پست دارند noindex می‌شوند (و از نقشه سایت Rank Math حذف می‌شوند)
 *    تا صفحه‌های تک‌پستی و کم‌ارزش در گوگل ایندکس نشوند.
 * 2) برای هر پست آهنگ (که متاهای song و artist دارد) اسکیمای MusicRecording خروجی می‌دهد:
 *    نام آهنگ، خواننده، سبک، تصویر، فایل صوتی، تاریخ انتشار، آهنگساز/ترانه‌سرا/تنظیم و متن ترانه.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** حداقل تعداد پست برای اینکه صفحه‌ی یک تگ ایندکس شود */
if (!defined('AHANGBAN_TAG_MIN_POSTS')) {
    define('AHANGBAN_TAG_MIN_POSTS', 3);
}

/* -------------------------------------------------------
 |  1) noindex برای تگ‌های کم‌پست
 * ------------------------------------------------------ */

/**
 * آیا صفحه‌ی جاری، آرشیو یک تگ کم‌پست است؟
 */
function ahangban_is_thin_tag_archive() {
    if (!is_tag()) {
        return false;
    }

    $term = get_queried_object();

    return ($term instanceof WP_Term) && (int) $term->count < AHANGBAN_TAG_MIN_POSTS;
}

// Rank Math
add_filter('rank_math/frontend/robots', function ($robots) {
    if (ahangban_is_thin_tag_archive()) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
    }
    return $robots;
});

// حذف تگ‌های کم‌پست از نقشه سایت Rank Math
add_filter('rank_math/sitemap/entry', function ($url, $type, $object) {
    if ($type === 'term' && $object instanceof WP_Term && $object->taxonomy === 'post_tag'
        && (int) $object->count < AHANGBAN_TAG_MIN_POSTS) {
        return false;
    }
    return $url;
}, 10, 3);

// اگر Rank Math فعال نبود، متا روبوتس را خودمان چاپ می‌کنیم
add_action('wp_head', function () {
    if (!class_exists('RankMath') && ahangban_is_thin_tag_archive()) {
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

    // لینک آرشیو خواننده
    $artist_url = '';
    $singer_terms = get_the_terms($post_id, 'singer');
    if (is_array($singer_terms) && !empty($singer_terms)) {
        $link = get_term_link($singer_terms[0]);
        if (!is_wp_error($link)) {
            $artist_url = $link;
        }
    }

    $is_band   = preg_match('/\b(band|group|boys|brothers)\b/i', $artist_en) || preg_match('/گروه/u', $artist);
    $by_artist = ['@type' => $is_band ? 'MusicGroup' : 'Person', 'name' => $artist];
    if ($artist_en !== '' && $artist_en !== $artist) {
        $by_artist['alternateName'] = $artist_en;
    }
    if ($artist_url !== '') {
        $by_artist['url'] = $artist_url;
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
