<?php
/**
 * سایت مبدأ (بیاتوموزیک) — خواندن مستقیم صفحه‌ی آهنگ و فهرست آیتم‌های جدید، بدون API
 *
 * بیاتوموزیک آذر ۱۴۰۵ از وردپرس به Next.js منتقل شد:
 *   - فید /feed دیگر وجود ندارد → آیتم‌های جدید از نقشه‌ی سایت «آهنگ‌های تازه» (new-sitemap.xml) خوانده می‌شوند
 *     (bm_source_feed_items: RSS، Atom و sitemap را می‌فهمد؛ فهرست آدرس‌ها در config.php: $bot_feed_urls)
 *   - آدرس پست‌ها /{id}/{slug} شد و API قدیمی (api.wintheme.ir/b2m.php) صفحه‌های جدید را نمی‌خواند
 *     (نام خواننده خالی، لینک‌های 320/128 خالی) → bm_source_read خودِ صفحه را می‌خواند:
 *       JSON-LD صفحه (schema.org MusicRecording: نام آهنگ/خواننده، نام انگلیسی، کاور، ژانر، تاریخ)
 *       + لینک‌های «کیفیت ۳۲۰ / ۱۲۸» + ترانه‌سرا/آهنگساز/تنظیم/میکس + «حس و حال» + متن ترانه
 *     خروجی همان شکل پاسخ API است تا script.php بدون تغییر ادامه دهد.
 *
 * script.php: $bot_source_parser در config.php — 'auto' (صفحه، اگر نشد API) | 'page' | 'api'
 * آزمایش: bot/source-test.php?url=…  (و ?feed=1 برای فهرست آیتم‌های جدید)
 */

if (!defined('ABSPATH')) {
    exit;
}

/** User-Agent مرورگر (بدون کلمه‌ی bot؛ CDN سایت مبدأ درخواست‌های غیرمرورگر را گاهی رد می‌کند) */
function bm_source_user_agent() {
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
}

/**
 * GET با cURL؛ خروجی: ['body', 'code', 'error', 'url' (آدرس نهایی بعد از ریدایرکت)]
 */
function bm_source_http_get($url, $timeout = 40) {
    $out = ['body' => '', 'code' => 0, 'error' => '', 'url' => (string) $url];
    if (!function_exists('curl_init')) {
        $out['error'] = 'cURL روی سرور فعال نیست';
        return $out;
    }
    $ch = curl_init((string) $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => (int) $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => bm_source_user_agent(),
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: fa-IR,fa;q=0.9,en;q=0.7',
        ],
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $out['error'] = $errno ? (string) curl_error($ch) : '';
    $out['code']  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final        = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (is_string($final) && $final !== '') {
        $out['url'] = $final;
    }
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch); // از PHP 8 بی‌اثر و در 8.5 منسوخ است
    }
    $out['body'] = is_string($body) ? $body : '';
    return $out;
}

/* -------------------------------------------------------
 |  فهرست آیتم‌های جدید (فید RSS / Atom یا نقشه‌ی سایت)
 * ------------------------------------------------------ */

/**
 * اولین منبعی از فهرست که آیتم داشت
 * خروجی: ['items' => [url => title], 'source' => آدرس منبع, 'errors' => [متن خطای منبع‌های قبلی]]
 */
function bm_source_feed_items(array $urls, $max = 30) {
    $errors = [];
    foreach ($urls as $url) {
        $url = trim((string) $url);
        if ($url === '') {
            continue;
        }
        $response = bm_source_http_get($url, 30);
        if ($response['body'] === '' || $response['code'] >= 400) {
            $errors[] = $url . ' → ' . ($response['error'] !== '' ? 'cURL: ' . $response['error'] : 'HTTP ' . $response['code']);
            continue;
        }
        $items = bm_source_parse_feed_xml($response['body'], $max);
        if ($items === null) {
            $errors[] = $url . ' → XML معتبر نیست (نه RSS/Atom است نه نقشه‌ی سایت)';
            continue;
        }
        if (empty($items)) {
            $errors[] = $url . ' → آیتمی نداشت';
            continue;
        }
        return ['items' => $items, 'source' => $url, 'errors' => $errors];
    }
    return ['items' => [], 'source' => '', 'errors' => $errors];
}

/**
 * RSS (channel/item)، Atom (feed/entry) یا sitemap (urlset/url) → [url => title] به ترتیب خود سند (جدیدترین اول)
 * آیتم‌های نقشه‌ی سایت عنوان ندارند؛ عنوان از نامک لینک ساخته می‌شود (فقط برای گزارش)
 * خروجی null = XML نبود / ساختار ناشناخته
 */
function bm_source_parse_feed_xml($xml, $max = 30) {
    $xml = trim((string) $xml);
    if ($xml === '' || $xml[0] !== '<') {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $doc      = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($doc === false) {
        return null;
    }

    $items = [];
    $add   = function ($url, $title) use (&$items, $max) {
        if (count($items) >= $max) {
            return;
        }
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url) || isset($items[$url])) {
            return;
        }
        $title = trim(html_entity_decode(strip_tags((string) $title), ENT_QUOTES, 'UTF-8'));
        $items[$url] = $title !== '' ? $title : bm_source_title_from_url($url);
    };

    $root = strtolower($doc->getName());
    if ($root === 'rss' && isset($doc->channel)) {
        foreach ($doc->channel->item as $item) {
            $add((string) $item->link, (string) $item->title);
        }
    } elseif ($root === 'feed') {
        foreach ($doc->entry as $entry) {
            $href = '';
            foreach ($entry->link as $link) {
                $attributes = $link->attributes();
                $rel        = isset($attributes['rel']) ? (string) $attributes['rel'] : 'alternate';
                if ($rel === 'alternate' || $href === '') {
                    $href = isset($attributes['href']) ? (string) $attributes['href'] : '';
                }
            }
            $add($href, (string) $entry->title);
        }
    } elseif ($root === 'urlset') {
        foreach ($doc->url as $entry) {
            $add((string) $entry->loc, '');
        }
    } else {
        return null;
    }
    return $items;
}

/** عنوان نمایشی از نامک لینک: /114816530/shahin-banan-aroosi → «shahin banan aroosi» */
function bm_source_title_from_url($url) {
    $path = trim((string) parse_url((string) $url, PHP_URL_PATH), '/');
    $slug = $path !== '' ? basename($path) : '';
    $slug = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $slug);
    $text = trim(preg_replace('/[-_]+/', ' ', rawurldecode((string) $slug)));
    return $text !== '' ? $text : (string) $url;
}

/* -------------------------------------------------------
 |  خواندن صفحه‌ی آهنگ
 * ------------------------------------------------------ */

/**
 * خواندن و تجزیه‌ی صفحه‌ی آهنگ
 * خروجی: ['result' => آرایه‌ای مثل پاسخ API یا null, 'kind' => music|album|video|playlist|'', 'error' => '', 'notes' => []]
 */
function bm_source_read($url) {
    $response = bm_source_http_get($url, 40);
    if ($response['body'] === '' || $response['code'] >= 400) {
        return [
            'result' => null,
            'kind'   => '',
            'error'  => 'صفحه‌ی مبدأ خوانده نشد (' . ($response['error'] !== '' ? 'cURL: ' . $response['error'] : 'HTTP ' . $response['code']) . ')',
            'notes'  => [],
        ];
    }
    return bm_source_parse($response['body'], $response['url'] !== '' ? $response['url'] : (string) $url);
}

/**
 * تجزیه‌ی HTML صفحه‌ی آهنگ (بدون شبکه) — برای آزمایش هم قابل استفاده است
 */
function bm_source_parse($html, $url = '') {
    $out  = ['result' => null, 'kind' => '', 'error' => '', 'notes' => []];
    $html = (string) $html;
    if (trim($html) === '') {
        $out['error'] = 'صفحه خالی بود';
        return $out;
    }
    if (!preg_match('//u', $html)) {
        // UTF-8 خراب: بایت‌های نامعتبر حذف می‌شوند تا regexهای /u کار کنند
        $html = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $html);
    }

    $ld    = bm_source_jsonld($html);
    $body  = bm_source_body($html);
    $title = preg_match('#<title[^>]*>(.*?)</title>#isu', $html, $m) ? bm_source_text($m[1]) : '';
    $desc  = bm_source_meta($html, 'name', 'description');
    $og    = bm_source_meta($html, 'property', 'og:image');
    $main  = is_array($ld['main']) ? $ld['main'] : [];

    /* --- نوع صفحه --- */
    $links = bm_source_media_links($body, $url);
    $kind  = 'music';
    if ($ld['type'] === 'MusicAlbum' || !empty($main['inAlbum'])) {
        $kind = 'album';
    } elseif (in_array($ld['type'], ['VideoObject', 'MusicVideoObject', 'Movie'], true)) {
        $kind = 'video';
    } elseif (in_array($ld['type'], ['MusicPlaylist', 'ItemList'], true)) {
        $kind = 'playlist';
    } elseif (preg_match('/^\s*آلبوم(?:\s|$)/u', $title) || in_array('آلبوم', $ld['crumbs'], true) || preg_match('/ترک[\s\x{200C}]*های\s+آلبوم/u', $body)) {
        $kind = 'album';
    } elseif (preg_match('/^\s*(?:موزیک[\s\x{200C}]*)?وید[یئ]و/u', $title) || ($links['320'] === '' && $links['128'] === '' && !empty($links['video']))) {
        $kind = 'video';
    } elseif (preg_match('/پلی[\s\x{200C}]*لیست/u', $title)) {
        $kind = 'playlist';
    }
    $out['kind'] = $kind;

    /* --- نام فارسی آهنگ و خواننده --- */
    $song   = isset($main['name']) && is_string($main['name']) ? bm_source_text($main['name']) : '';
    $artist = bm_source_ld_artist($main);
    if ($song === '' || $artist === '') {
        // توضیحات متا: «دانلود آهنگ عروسی از شاهین بنان با دو کیفیت»
        if ($desc !== '' && preg_match('/^دانلود\s+(?:آهنگ|ریمیکس|مداحی|پادکست|موزیک[\s\x{200C}]*وید[یئ]و|آلبوم)\s+(.+?)\s+از\s+(.+?)(?:\s+با\s+|\s*[\-|–]|$)/u', $desc, $m)) {
            $song   = $song !== '' ? $song : bm_source_text($m[1]);
            $artist = $artist !== '' ? $artist : bm_source_text($m[2]);
        }
    }
    if (($song === '' || $artist === '') && count($ld['crumbs']) >= 3) {
        // مسیر صفحه: بیاتوموزیک › شاهین بنان › عروسی
        $crumbs = array_values($ld['crumbs']);
        $artist = $artist !== '' ? $artist : bm_source_text($crumbs[count($crumbs) - 2]);
        $song   = $song !== '' ? $song : bm_source_text($crumbs[count($crumbs) - 1]);
    }
    if (($song === '' || $artist === '') && preg_match('#<h1\b[^>]*>(.*?)</h1>#isu', $body, $m)) {
        $pieces = array_values(array_filter(array_map('bm_source_text', preg_split('#<[^>]+>#', $m[1]))));
        if (count($pieces) >= 2) {
            $song   = $song !== '' ? $song : $pieces[0];
            $artist = $artist !== '' ? $artist : $pieces[1];
        }
    }

    /* --- نام انگلیسی: نام فایل «Artist - Song.mp3»؛ وگرنه JSON-LD / نامک خواننده / عنوان صفحه --- */
    $file      = bm_source_names_from_file($links['320'] !== '' ? $links['320'] : $links['128']);
    $song_en   = '';
    $artist_en = '';
    if ($file['artist_en'] !== '' && $file['song_en'] !== '') {
        $artist_en = $file['artist_en'];
        $song_en   = $file['song_en'];
    }
    if ($song_en === '' && isset($main['alternateName']) && is_string($main['alternateName']) && preg_match('/[A-Za-z]/', $main['alternateName'])) {
        $song_en = bm_source_text($main['alternateName']);
    }
    $artist_slug = '';
    if (isset($main['byArtist'])) {
        $by = $main['byArtist'];
        if (isset($by[0]) && is_array($by[0])) {
            $by = $by[0];
        }
        if (is_array($by) && !empty($by['url'])) {
            $artist_slug = bm_source_last_segment($by['url']);
        }
    }
    $en_title = '';
    if ($title !== '' && strpos($title, '|') !== false) {
        $tail = trim((string) substr($title, strrpos($title, '|') + 1));
        if ($tail !== '' && preg_match('/^[\x20-\x7E]+$/', $tail)) {
            $en_title = $tail; // «Shahin Banan Aroosi»
        }
    }
    if ($artist_en === '') {
        if ($artist_slug !== '') {
            $artist_en = bm_source_slug_words($artist_slug);
        } elseif ($en_title !== '' && $song_en !== '' && bm_source_ends_with_ci($en_title, $song_en)) {
            $artist_en = trim((string) preg_replace('/^[\s\-–|]+|[\s\-–|]+$/u', '', (string) substr($en_title, 0, strlen($en_title) - strlen($song_en))));
        }
    }
    if ($song_en === '') {
        if ($file['song_en'] !== '' && preg_match('/[A-Za-z]/', $file['song_en'])) {
            $song_en = $file['song_en'];
        } elseif ($en_title !== '' && $artist_en !== '' && stripos($en_title, $artist_en) === 0) {
            $song_en = trim((string) preg_replace('/^[\s\-–|]+|[\s\-–|]+$/u', '', (string) substr($en_title, strlen($artist_en))));
        } elseif ($url !== '') {
            $slug = bm_source_last_segment($url);
            if ($artist_slug !== '' && strpos($slug, $artist_slug . '-') === 0) {
                $slug = substr($slug, strlen($artist_slug) + 1);
            }
            $song_en = bm_source_slug_words($slug);
        }
    }
    if ($artist_en === '' && $en_title !== '') {
        $artist_en = $en_title;
    }

    /* --- کاور --- */
    $image = '';
    if (isset($main['image'])) {
        $img = $main['image'];
        if (is_array($img)) {
            $img = isset($img['url']) ? $img['url'] : (isset($img[0]) ? (is_array($img[0]) && isset($img[0]['url']) ? $img[0]['url'] : $img[0]) : '');
        }
        $image = is_string($img) ? $img : '';
    }
    if ($image === '' && $og !== '') {
        $image = $og;
    }
    if ($image === '' && preg_match('#<img\b[^>]*\balt=["\'][^"\']*کاور[^"\']*["\'][^>]*\bsrc=["\']([^"\']+)["\']#iu', $body, $m)) {
        $image = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    } elseif ($image === '' && preg_match('#<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*\balt=["\'][^"\']*کاور[^"\']*["\']#iu', $body, $m)) {
        $image = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    $image = bm_source_image_url($image, $url);

    /* --- ژانر، حس و حال، عوامل --- */
    $genre = isset($main['genre']) ? (is_array($main['genre']) ? bm_source_text((string) reset($main['genre'])) : bm_source_text((string) $main['genre'])) : '';
    if ($genre === '') {
        $genre = bm_source_after_label($body, ['ژانر', 'سبک']);
    }
    $mood = bm_source_after_label($body, ['حس و حال', 'حس‌و‌حال', 'حال و هوا']);
    $crew = [
        'songwriter' => bm_source_labelled($body, ['ترانه سرا', 'ترانه‌سرا', 'شاعر']),
        'composer'   => bm_source_labelled($body, ['آهنگساز']),
        'regulator'  => bm_source_labelled($body, ['تنظیم کننده', 'تنظیم‌کننده', 'تنظیم']),
        'mix'        => bm_source_labelled($body, ['میکس و مستر', 'میکس و مسترینگ', 'میکس']),
        'guitar'     => bm_source_labelled($body, ['نوازنده گیتار', 'گیتار']),
        'publisher'  => bm_source_labelled($body, ['ناشر', 'انتشارات']),
    ];
    // نامک‌های ژانر/حال‌وهوا (/genre/pop ، /mood/happy) و لینک صفحه‌ی هر یک از عوامل (/artists/{slug}) — برای ترم‌های سایت
    $genre_slug = bm_source_taxonomy_slug($body, 'genre');
    $mood_slug  = bm_source_taxonomy_slug($body, 'mood');
    $crew_links = [
        'songwriter' => bm_source_labelled_links($body, ['ترانه سرا', 'ترانه‌سرا', 'شاعر']),
        'composer'   => bm_source_labelled_links($body, ['آهنگساز']),
        'regulator'  => bm_source_labelled_links($body, ['تنظیم کننده', 'تنظیم‌کننده', 'تنظیم']),
        'mix'        => bm_source_labelled_links($body, ['میکس و مستر', 'میکس و مسترینگ', 'میکس']),
    ];

    /* --- متن ترانه --- */
    $lyrics = $kind === 'music' ? bm_source_lyrics($body, $song) : '';

    /* --- تاریخ انتشار: JSON-LD (میلادی) + «تاریخ انتشار: 1405/08/30» صفحه (شمسی) --- */
    $date = isset($main['datePublished']) && is_string($main['datePublished']) ? trim($main['datePublished']) : '';
    if ($date === '') {
        $date = bm_source_meta($html, 'property', 'article:published_time');
    }
    $date_jalali = bm_source_jalali_date($body);

    if ($song === '' || $artist === '') {
        $out['error'] = 'نام آهنگ یا خواننده در صفحه پیدا نشد'
            . ($title !== '' ? ' (عنوان صفحه: «' . $title . '»)' : '')
            . ($ld['type'] === '' ? ' — صفحه JSON-LD ندارد' : '');
        return $out;
    }

    $out['result'] = [
        'song'         => $song,
        'artist'       => $artist,
        'song_en'      => $song_en,
        'artist_en'    => $artist_en,
        'image'        => $image,
        'music128'     => $links['128'],
        'music320'     => $links['320'],
        'video_low'    => '',
        'video_medium' => !empty($links['video']) ? $links['video'][0] : '',
        'mood'         => $mood,
        'style'        => $genre,
        'publisher'    => $crew['publisher'],
        'guitar'       => $crew['guitar'],
        'mix'          => $crew['mix'],
        'songwriter'   => $crew['songwriter'],
        'regulator'    => $crew['regulator'],
        'composer'     => $crew['composer'],
        'matnmusic'    => $lyrics,
        'kind'         => $kind,
        'date'         => $date,
        'date_jalali'  => $date_jalali,
        'genre_slug'   => $genre_slug,
        'mood_slug'    => $mood_slug,
        'crew_links'   => $crew_links,
        'source'       => 'page',
    ];
    if ($ld['type'] === '') {
        $out['notes'][] = 'JSON-LD پیدا نشد؛ نام‌ها از متا/مسیر صفحه خوانده شد';
    }
    if ($links['320'] === '' && $links['128'] === '' && $kind === 'music') {
        $out['notes'][] = 'لینک mp3 در صفحه پیدا نشد';
    }
    return $out;
}

/* -------------------------------------------------------
 |  ابزارهای تجزیه
 * ------------------------------------------------------ */

/** JSON-LD صفحه: ['main' => گره‌ی اصلی (MusicRecording/MusicAlbum/…), 'type' => نوع آن, 'crumbs' => نام‌های مسیر صفحه] */
function bm_source_jsonld($html) {
    $found = ['main' => null, 'type' => '', 'crumbs' => []];
    if (!preg_match_all('#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#isu', $html, $blocks)) {
        return $found;
    }
    $wanted = ['MusicRecording', 'MusicAlbum', 'VideoObject', 'MusicVideoObject', 'Movie', 'MusicPlaylist', 'PodcastEpisode', 'AudioObject', 'ItemList'];
    foreach ($blocks[1] as $json) {
        $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (!is_array($data)) {
            $data = json_decode(trim($json), true);
        }
        if (!is_array($data)) {
            continue;
        }
        $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (isset($data[0]) ? $data : [$data]);
        foreach ($nodes as $node) {
            if (!is_array($node) || empty($node['@type'])) {
                continue;
            }
            $type = is_array($node['@type']) ? (string) reset($node['@type']) : (string) $node['@type'];
            if ($type === 'BreadcrumbList' && !empty($node['itemListElement']) && is_array($node['itemListElement'])) {
                $crumbs = [];
                foreach ($node['itemListElement'] as $element) {
                    if (is_array($element) && isset($element['name'])) {
                        $position          = isset($element['position']) ? (int) $element['position'] : count($crumbs) + 1;
                        $crumbs[$position] = (string) $element['name'];
                    }
                }
                ksort($crumbs);
                $found['crumbs'] = $crumbs;
            } elseif ($found['main'] === null && in_array($type, $wanted, true)) {
                $found['main'] = $node;
                $found['type'] = $type;
            }
        }
    }
    return $found;
}

/** نام خواننده از JSON-LD (byArtist یک شیء یا آرایه‌ای از شیءها؛ چند نفر با « و ») */
function bm_source_ld_artist(array $main) {
    if (empty($main['byArtist'])) {
        return '';
    }
    $by = $main['byArtist'];
    if (is_string($by)) {
        return bm_source_text($by);
    }
    if (!is_array($by)) {
        return '';
    }
    $list  = isset($by['name']) || isset($by['@type']) ? [$by] : $by;
    $names = [];
    foreach ($list as $one) {
        $name = is_array($one) ? (isset($one['name']) ? bm_source_text((string) $one['name']) : '') : bm_source_text((string) $one);
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    return implode(' و ', $names);
}

/** HTML بدون script / style / noscript / کامنت (داده‌ی Next.js داخل script همه‌ی متن صفحه را تکرار می‌کند) */
function bm_source_body($html) {
    $body = preg_replace('#<script\b[^>]*>.*?</script>#isu', ' ', $html);
    $body = preg_replace('#<style\b[^>]*>.*?</style>#isu', ' ', (string) $body);
    $body = preg_replace('#<noscript\b[^>]*>.*?</noscript>#isu', ' ', (string) $body);
    $body = preg_replace('#<!--.*?-->#su', ' ', (string) $body);
    return (string) $body;
}

/** مقدار یک تگ meta (name یا property) */
function bm_source_meta($html, $attribute, $value) {
    $q = preg_quote($value, '#');
    if (preg_match('#<meta\b[^>]*\b' . $attribute . '=["\']' . $q . '["\'][^>]*\bcontent=["\']([^"\']*)["\']#iu', $html, $m)
        || preg_match('#<meta\b[^>]*\bcontent=["\']([^"\']*)["\'][^>]*\b' . $attribute . '=["\']' . $q . '["\']#iu', $html, $m)) {
        return bm_source_text(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

/** متن ساده: بدون تگ، موجودیت‌ها باز، فاصله‌های تکراری یکی (نیم‌فاصله می‌ماند) */
function bm_source_text($fragment) {
    $text = html_entity_decode(strip_tags((string) $fragment), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/** ارقام فارسی/عربی → انگلیسی */
function bm_source_latin_digits($text) {
    return str_replace(
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        (string) $text
    );
}

/** آخرین بخش مسیر یک آدرس (نامک) */
function bm_source_last_segment($url) {
    $path = trim((string) parse_url((string) $url, PHP_URL_PATH), '/');
    return $path !== '' ? rawurldecode(basename($path)) : '';
}

/** shahin-banan → Shahin Banan */
function bm_source_slug_words($slug) {
    $words = trim(preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', (string) $slug)));
    return $words !== '' ? ucwords($words) : '';
}

function bm_source_ends_with_ci($haystack, $needle) {
    $needle = (string) $needle;
    return $needle !== '' && strlen($haystack) >= strlen($needle) && strcasecmp(substr($haystack, -strlen($needle)), $needle) === 0;
}

/** آدرس نسبی → مطلق */
function bm_source_abs_url($href, $base) {
    $href = trim((string) $href);
    if ($href === '' || preg_match('#^https?://#i', $href)) {
        return $href;
    }
    if (strpos($href, '//') === 0) {
        return 'https:' . $href;
    }
    $parts = parse_url((string) $base);
    if (empty($parts['host'])) {
        return $href;
    }
    $origin = (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if ($href[0] === '/') {
        return $origin . $href;
    }
    $dir = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
    return $origin . $dir . $href;
}

/**
 * آدرس کاور: اگر از بهینه‌ساز تصویر Next.js (/_next/image?url=…) باشد آدرس اصلی بیرون کشیده می‌شود؛
 * فاصله‌ها %20 می‌شوند (JSON-LD آدرس را با فاصله می‌نویسد)
 */
function bm_source_image_url($image, $base) {
    $image = trim((string) $image);
    if ($image === '') {
        return '';
    }
    if (preg_match('#/_next/image\?#', $image)) {
        $query = (string) parse_url($image, PHP_URL_QUERY);
        parse_str($query, $params);
        if (!empty($params['url'])) {
            $image = (string) $params['url'];
        }
    }
    $image = bm_source_abs_url($image, $base);
    return str_replace(' ', '%20', $image);
}

/**
 * لینک‌های فایل: ['320' => url, '128' => url, 'video' => [url…]]
 * اول لینک‌های <a> با متن «کیفیت ۳۲۰ / ۱۲۸» (یا 320/128 در نام فایل)؛ اگر نبود هر آدرس mp3 داخل صفحه (مثلاً src پخش‌کننده)
 */
function bm_source_media_links($body, $base = '') {
    $links = ['320' => '', '128' => '', 'video' => []];

    if (preg_match_all('#<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>#isu', $body, $anchors, PREG_SET_ORDER)) {
        foreach ($anchors as $anchor) {
            $href = html_entity_decode($anchor[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('#\.mp3(?:$|[?\#])#i', $href)) {
                continue;
            }
            $href    = bm_source_abs_url($href, $base);
            $quality = bm_source_quality_of($href, bm_source_text($anchor[3]));
            if ($quality === '') {
                $quality = $links['320'] === '' ? '320' : '128';
            }
            if ($links[$quality] === '') {
                $links[$quality] = $href;
            }
        }
    }
    if ($links['320'] === '' && $links['128'] === '' && preg_match_all('#https?://[^\s"\'<>\\\\]+?\.mp3(?=$|["\'<>\s?\#])#iu', $body, $m)) {
        foreach (array_unique($m[0]) as $href) {
            $href    = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $quality = bm_source_quality_of($href, '');
            if ($quality === '') {
                $quality = $links['320'] === '' ? '320' : '128';
            }
            if ($links[$quality] === '') {
                $links[$quality] = $href;
            }
        }
    }
    if ($links['128'] !== '' && $links['128'] === $links['320']) {
        $links['128'] = ''; // قطعه‌ی آلبوم: یک فایل برای هر دو دکمه
    }
    if (preg_match_all('#https?://[^\s"\'<>\\\\]+?\.(?:mp4|mkv|webm)(?=$|["\'<>\s?\#])#iu', $body, $m)) {
        foreach (array_unique($m[0]) as $href) {
            $links['video'][] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $links;
}

/** کیفیت یک لینک از متن دکمه («کیفیت ۳۲۰»، «128kbps») یا نام فایل («… [128].mp3»)؛ '' = نامشخص */
function bm_source_quality_of($href, $text) {
    $text = bm_source_latin_digits($text);
    if (preg_match('/(?<!\d)320(?!\d)/', $text)) {
        return '320';
    }
    if (preg_match('/(?<!\d)128(?!\d)/', $text)) {
        return '128';
    }
    $name = rawurldecode((string) basename((string) parse_url($href, PHP_URL_PATH)));
    if (preg_match('/(?<!\d)128(?!\d)/', $name)) {
        return '128';
    }
    if (preg_match('/(?<!\d)320(?!\d)/', $name)) {
        return '320';
    }
    return '';
}

/** نام انگلیسی خواننده و آهنگ از نام فایل «Artist - Song [128].mp3» */
function bm_source_names_from_file($href) {
    $out  = ['artist_en' => '', 'song_en' => '', 'file' => ''];
    $href = trim((string) $href);
    if ($href === '') {
        return $out;
    }
    $name = (string) basename((string) parse_url($href, PHP_URL_PATH));
    for ($i = 0; $i < 2 && preg_match('/%[0-9A-Fa-f]{2}/', $name); $i++) {
        $name = rawurldecode($name); // بعضی لینک‌های قدیمی دو بار کدگذاری شده‌اند (%2520)
    }
    $name = preg_replace('/\.(mp3|m4a|ogg|wav)$/i', '', $name);
    $name = preg_replace('/\s*[\[\(](?:128|320|64|192)(?:\s*kbps)?[\]\)]\s*$/i', '', $name);
    $name = preg_replace('/\s*[\[\(]?(?:www\.)?[a-z0-9-]+\.(?:com|ir|net|org)[\]\)]?\s*$/i', '', $name); // «(Bia2Music.com)»
    $name = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', (string) $name)));
    if ($name === '' || !preg_match('/[A-Za-z]/', $name)) {
        return $out;
    }
    $out['file'] = $name;
    if (strpos($name, ' - ') !== false) {
        list($artist, $song) = explode(' - ', $name, 2);
        $out['artist_en'] = trim($artist);
        $out['song_en']   = trim($song);
    } else {
        $out['song_en'] = $name;
    }
    return $out;
}

/** regex یک برچسب فارسی: فاصله و نیم‌فاصله‌ی داخلش اختیاری */
function bm_source_label_regex($label) {
    $parts = preg_split('/[\s\x{200C}]+/u', trim((string) $label));
    return implode('[\s\x{200C}]*', array_map(function ($part) {
        return preg_quote($part, '#');
    }, $parts));
}

/**
 * مقدار بعد از یک برچسب: <span>ترانه سرا</span><a>مهدی هریجی</a> یا <li>ترانه سرا: مهدی هریجی</li>
 * برچسب باید با فاصله، دونقطه یا تگ تمام شود («آهنگساز» داخل «آهنگسازی» گرفته نمی‌شود)؛ اولین مقدار معنی‌دار برمی‌گردد
 */
function bm_source_labelled($body, array $labels) {
    foreach ($labels as $label) {
        // بعد از برچسب: هر ترکیبی از تگ‌های باز/بسته، دونقطه و فاصله (<span>:</span> هم) تا رسیدن به متن مقدار
        $pattern = '#>\s*' . bm_source_label_regex($label) . '(?=[\s:<])(?:\s*(?:</?[a-z][^>]*>|[:،]))*\s*([^<>]{1,80}?)\s*<#iu';
        if (!preg_match_all($pattern, $body, $all)) {
            continue;
        }
        foreach ($all[1] as $raw) {
            $value = bm_source_text($raw);
            if ($value === '' || preg_match('/^[\s:،,\-–]+$/u', $value) || preg_match('/^(?:نامشخص|ندارد|-)$/u', $value)) {
                continue;
            }
            if (preg_match('/^(?:و|کننده|سرا)(?:\s|$)/u', $value)) {
                continue; // برچسب کوتاه وسط برچسب بلندتر افتاده («میکس» در «میکس و مستر»)
            }
            return $value;
        }
    }
    return '';
}

/** مقدار بعد از برچسب با دونقطه در همان متن: «حس و حال: <a>ریتمیک</a>»، «ژانر: پاپ» */
function bm_source_after_label($body, array $labels) {
    foreach ($labels as $label) {
        $pattern = '#' . bm_source_label_regex($label) . '\s*:\s*(?:<[^>]*>\s*)*([^<>]{1,60}?)\s*<#iu';
        if (!preg_match_all($pattern, $body, $all)) {
            continue;
        }
        foreach ($all[1] as $raw) {
            $value = bm_source_text($raw);
            if ($value !== '' && !preg_match('/^[\s:،,\-–]+$/u', $value)) {
                return $value;
            }
        }
    }
    return bm_source_labelled($body, $labels);
}

/**
 * همه‌ی لینک‌های عوامل بعد از یک برچسب: <a href="/artists/mehdi-hariji">ترانه سرا مهدی هریجی</a>
 * (در سایت مبدأ برچسب و نام داخل یک لینک‌اند؛ اگر چند نفر باشند هر کدام لینک خودش را دارد)
 * خروجی: [['name' => 'مهدی هریجی', 'slug' => 'mehdi-hariji'], …] بدون تکرار؛ اگر لینکی نبود آرایه‌ی خالی
 */
function bm_source_labelled_links($body, array $labels) {
    static $anchors = null, $anchors_for = null;
    if ($anchors_for !== $body) {
        $anchors_for = $body;
        $anchors     = [];
        if (preg_match_all('#<a\b[^>]*\bhref=(["\'])([^"\']*?/artists?/([^"\'/?\#]+))/?\1[^>]*>(.*?)</a>#isu', $body, $all, PREG_SET_ORDER)) {
            foreach ($all as $a) {
                // تگ‌ها → فاصله تا «<span>ترانه سرا</span><span>مهدی هریجی</span>» به «ترانه سرا مهدی هریجی» تبدیل شود
                $anchors[] = ['slug' => rawurldecode($a[3]), 'text' => bm_source_text(preg_replace('#<[^>]+>#', ' ', $a[4]))];
            }
        }
    }
    $out = [];
    foreach ($labels as $label) {
        $pattern = '#^' . bm_source_label_regex($label) . '(?![\p{L}])\s*[:،]?\s*(.{1,80})$#iu';
        foreach ($anchors as $anchor) {
            if ($anchor['text'] === '' || !preg_match($pattern, $anchor['text'], $m)) {
                continue;
            }
            $name = trim((string) preg_replace('/^[\s:،,\-–]+|[\s:،,\-–]+$/u', '', $m[1]));
            if ($name === '' || preg_match('/^(?:و|کننده|سرا)(?:\s|$)/u', $name)) {
                continue; // برچسب کوتاه وسط برچسب بلندتر («میکس» در «میکس و مستر»)
            }
            $key = $anchor['slug'] !== '' ? strtolower($anchor['slug']) : $name;
            if (!isset($out[$key])) {
                $out[$key] = ['name' => $name, 'slug' => strtolower($anchor['slug'])];
            }
        }
        if (!empty($out)) {
            break; // نام‌های همین برچسب کافی است («تنظیم کننده» پیدا شد → سراغ «تنظیم» نمی‌رویم)
        }
    }
    return array_values($out);
}

/** نامک ژانر/حال‌وهوا از لینک صفحه (/genre/pop ، /mood/happy) — برای نامک انگلیسی ترم‌های سایت */
function bm_source_taxonomy_slug($body, $kind) {
    $kind = preg_quote((string) $kind, '#');
    if (preg_match('#\bhref=(["\'])[^"\']*?/' . $kind . '/([a-z0-9][a-z0-9_\-]*)/?(?:[?\#][^"\']*)?\1#iu', $body, $m)) {
        return strtolower($m[2]);
    }
    return '';
}

/**
 * «تاریخ انتشار: 1405/08/30» صفحه → «1405/08/30» (ارقام فارسی هم پذیرفته می‌شود)؛ اگر نبود رشته‌ی خالی
 * فقط تاریخ شمسی برمی‌گردد؛ تاریخ میلادی JSON-LD جداگانه در «date» است.
 */
function bm_source_jalali_date($body) {
    if (!preg_match_all('/تاریخ[\s\x{200C}]*انتشار\s*(?:<[^>]*>\s*)*[:：]?\s*(?:<[^>]*>\s*)*([0-9۰-۹٠-٩]{4}\s*[\/\-.]\s*[0-9۰-۹٠-٩]{1,2}\s*[\/\-.]\s*[0-9۰-۹٠-٩]{1,2})/u', $body, $all)) {
        return '';
    }
    foreach ($all[1] as $raw) {
        $digits = bm_source_latin_digits($raw);
        if (preg_match('/^(\d{4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})$/', $digits, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if ($y >= 1300 && $y <= 1499 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
                return sprintf('%04d/%02d/%02d', $y, $mo, $d);
            }
        }
    }
    return '';
}

/** خط‌های متنی صفحه (عنصرهای بلوکی و <br> = خط جدید؛ خط‌های خالی حذف) */
function bm_source_lines($fragment) {
    $text = preg_replace('#<head\b[^>]*>.*?</head>#isu', ' ', (string) $fragment);
    $text = preg_replace('#<br\s*/?>#iu', "\n", (string) $text);
    $text = preg_replace('#</?(?:p|div|li|ul|ol|h[1-6]|tr|td|th|table|section|article|header|footer|nav|aside|main|blockquote|figure|figcaption|button|pre|form|label|dt|dd)\b[^>]*>#iu', "\n", (string) $text);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    $lines = [];
    foreach (preg_split("/\r\n|\r|\n/", $text) as $line) {
        $line = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
}

/** خطی که شروع بخش بعدی صفحه را نشان می‌دهد = پایان متن ترانه */
function bm_source_is_stop_line($line) {
    return (bool) preg_match(
        '/^(?:(?:جدیدترین|محبوب[\s\x{200C}]*ترین|پربازدیدترین|برترین|دیگر|سایر|بقیه[\s\x{200C}]*ی?)\s+(?:آهنگ|ریمیکس|آلبوم|موزیک)|آهنگ[\s\x{200C}]*های\s+(?:دیگر|مشابه|بیشتر|پیشنهادی|ویژه|جدید)|(?:ریمیکس|تیزر|ویدیو|آلبوم)[\s\x{200C}]*های\s|نظرات?(?:\s|$)|دیدگاه|بعدی\s+در\s+صف|صف\s+پخش|ثبت\s+نظر|ارسال\s+نظر|پلی[\s\x{200C}]*لیست|تبلیغات|درباره\s+ما|تماس\s+با\s+ما|قوانین|(?:کلیه|تمامی|همه)[\s\x{200C}]*ی?\s+حقوق|©|دانلود\s+(?:آهنگ|ریمیکس|آلبوم|موزیک)|پیشنهاد\s+(?:ما|ویژه)|مطالب\s+مرتبط|جست\s*‌?و\s*‌?جو|ورود(?:\s|$)|عضویت)/u',
        (string) $line
    );
}

/** خط دکمه‌ای میان متن (کپی، بیشتر، اشتراک‌گذاری) که باید نادیده گرفته شود */
function bm_source_is_button_line($line) {
    return (bool) preg_match('/^(?:کپی(?:\s+متن)?(?:\s+ترانه|\s+آهنگ)?|کپی\s+شد|بیشتر|کمتر|نمایش\s+(?:بیشتر|کمتر|کامل)|متن\s+کامل|ادامه(?:\s+مطلب)?|اشتراک[\s\x{200C}]*گذاری|گزارش(?:\s+خطا)?|بستن)$/u', (string) $line);
}

/**
 * متن ترانه: از خط عنوان «متن آهنگ {نام آهنگ} …» / «متن ترانه …» تا اولین خط بخش بعدی («آهنگ‌های مشابه»، «نظرات»، پانوشت …)
 * اگر عنوان چند بار آمده باشد (فهرست مطالب + خود بخش) آخرین آن مبنا است؛ تب/دکمه‌ی «متن ترانه» (بدون نام آهنگ) نادیده گرفته می‌شود
 * خروجی: خط‌ها با \n (مثل فیلد متن ترانه‌ی پست‌ساز)
 */
function bm_source_lyrics($body, $song = '') {
    $lines = bm_source_lines($body);
    $count = count($lines);
    $start = -1;
    foreach ($lines as $i => $line) {
        if (mb_strlen($line, 'UTF-8') > 120 || !preg_match('/^متن\s+(?:آهنگ|ترانه|موزیک|ریمیکس)(?:\s+(.*))?$/u', $line, $m)) {
            continue;
        }
        $rest = isset($m[1]) ? trim((string) preg_replace('/^[\s:،\-–]+|[\s:،\-–]+$/u', '', $m[1])) : '';
        if ($rest === '' || preg_match('/^(?:ها|های)(?:\s|$)/u', $rest)) {
            continue; // دکمه/تب «متن ترانه» یا منوی «متن آهنگ‌ها»
        }
        $start = $i;
    }
    if ($start < 0) {
        return '';
    }
    $out = [];
    for ($i = $start + 1; $i < $count; $i++) {
        $line = $lines[$i];
        if (bm_source_is_button_line($line)) {
            continue;
        }
        if (bm_source_is_stop_line($line) || mb_strlen($line, 'UTF-8') > 300) {
            break;
        }
        $out[] = $line;
        if (count($out) >= 400) {
            break;
        }
    }
    return implode("\n", $out);
}

/* -------------------------------------------------------
 |  فهرست آهنگ‌های یک هنرمند (آرشیو هنرمندان)
 * ------------------------------------------------------ */

/**
 * فهرست آهنگ‌های یک هنرمند از صفحه‌ی هنرمند سایت مبدأ (/artists/{slug}/musics)
 * هر آیتم: لینک /{id}/{slug} + «<strong>نام آهنگ</strong> نام خواننده» (یا alt تصویر «نام خواننده نام آهنگ»)
 * خروجی: [['url' => …, 'artist' => …, 'song' => …], …] به ترتیب صفحه (جدیدترین اول)، بدون تکرار
 */
function bm_source_artist_listing($html, $base = '') {
    $html = (string) $html;
    if (trim($html) === '') {
        return [];
    }
    if (!preg_match('//u', $html)) {
        $html = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $html);
    }
    $body = bm_source_body($html);

    // نام هنرمند از عنوان بخش/صفحه: «جدیدترین آهنگ‌های شاهین بنان»
    $artist = '';
    foreach ([bm_source_meta($html, 'property', 'og:title'), preg_match('#<title[^>]*>(.*?)</title>#isu', $html, $m) ? $m[1] : '', $body] as $hay) {
        if (preg_match('/آهنگ[\s\x{200C}]*های\s+([^<>|«»]{2,80}?)\s*(?:[|<«»]|$)/u', (string) $hay, $m)) {
            $artist = bm_source_text($m[1]);
            if ($artist !== '') {
                break;
            }
        }
    }

    $items = [];
    if (!preg_match_all('#<a\b[^>]*\bhref=(["\'])([^"\']*?/\d{4,}/[^"\'/?\#]+/?)\1[^>]*>(.*?)</a>#isu', $body, $anchors, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($anchors as $anchor) {
        $url   = bm_source_abs_url(html_entity_decode($anchor[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $base);
        $url   = rtrim($url, '/');
        $inner = $anchor[3];
        $song  = '';
        $who   = '';
        $sure  = false;
        if (preg_match('#<(strong|b|h[1-6])\b[^>]*>(.*?)</\1>#isu', $inner, $m)) {
            $song = bm_source_text($m[2]);
            $who  = bm_source_text(preg_replace('#<(strong|b|h[1-6])\b[^>]*>.*?</\1>#isu', ' ', $inner, 1));
            $sure = $song !== '';
        }
        if ($song === '') {
            // فقط تصویر: alt = «نام خواننده نام آهنگ»
            $alt = preg_match('#\balt=(["\'])(.*?)\1#isu', $inner, $m) ? bm_source_text(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $txt = $alt !== '' ? $alt : bm_source_text($inner);
            if ($txt !== '' && $artist !== '' && strpos($txt, $artist) === 0) {
                $song = trim((string) preg_replace('/^[\s\-–:]+|[\s\-–:]+$/u', '', (string) substr($txt, strlen($artist))));
                $who  = $artist;
            } elseif ($txt !== '' && $alt === '') {
                $song = $txt;
            }
        }
        if ($song === '') {
            continue;
        }
        if ($who === '') {
            $who = $artist;
        }
        // کلید یکتا: شناسه‌ی عددی پست (/114757513/…) تا لینک نسبی و مطلق یک آیتم دو بار نیاید
        $key = preg_match('#/(\d{4,})/[^/]+$#', $url, $m) ? $m[1] : $url;
        if (!isset($items[$key]) || ($sure && empty($items[$key]['sure']))) {
            $items[$key] = ['url' => $url, 'artist' => $who, 'song' => $song, 'sure' => $sure];
        }
    }
    $out = [];
    foreach ($items as $item) {
        unset($item['sure']);
        $out[] = $item;
    }
    return $out;
}

/** برچسب فارسی نوع صفحه برای گزارش */
function bm_source_kind_label($kind) {
    $labels = ['album' => 'آلبوم (یا قطعه‌ای از آلبوم)', 'video' => 'موزیک ویدیو', 'playlist' => 'پلی‌لیست', 'music' => 'تک‌آهنگ'];
    return isset($labels[$kind]) ? $labels[$kind] : '';
}
