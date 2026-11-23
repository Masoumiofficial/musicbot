<?php
/**
 * ترم‌های ژانر / حال‌وهوا / عوامل + فیلد «تاریخ انتشار واقعی» قالب (bot/terms.php)
 *
 * قالب برای پست‌ها این تاکسونومی‌ها را دارد: ژانر (tara_genre)، حال‌وهوا (tara_mood)، ترانه‌سرا (tara_songwriter)،
 * آهنگساز (tara_composer)، تنظیم‌کننده (tara_arranger). ربات قبلاً سبک/حال‌وهوا را فقط در متای genre/mood می‌نوشت
 * (و ترم را روی تاکسونومی‌های «mood»/«genre» که در سایت نیستند می‌فرستاد → چیزی ثبت نمی‌شد).
 *
 *   bm_apply_role_terms($post_id, $data)      ترم‌های همه‌ی نقش‌ها از داده‌ی مبدأ (خروجی bm_fetch_source) یا متاهای پست
 *   bm_store_release_date($post_id, $g, $j)   تاریخ انتشار واقعی آهنگ در فیلد قالب (+ متاهای _bm_release_date / _bm_release_date_jalali)
 *   bm_release_date_field()                   فیلد تاریخ انتشار قالب: $bot_release_date_field در config.php، وگرنه جست‌وجوی خودکار
 *                                             بین فیلدهای ACF نوشته‌ها (نام/برچسب شامل «تاریخ انتشار» یا release/publish date)
 *   bm_release_date_format($field)            قالب ذخیره: $bot_release_date_format = auto | jalali | gregorian | acf
 *                                             auto = فیلد تاریخ ACF → Ymd میلادی (همان قالب داخلی ACF)، فیلد متنی → شمسی 1405/08/30
 *
 * نام ترم‌ها (config.php):
 *   $bot_mood_term_names  = ['ریتمیک' => 'شاد'];   نگاشت نام مبدأ → نام سایت (قبل از پیشوند)
 *   $bot_mood_term_prefix = 'آهنگ ';               «شاد» → «آهنگ شاد» (مثل ترم موجود «آهنگ عاشقانه»)
 *   $bot_genre_term_prefix = '';                   «پاپ» همان «پاپ» می‌ماند
 *   $bot_credit_terms = true;                      ترانه‌سرا/آهنگساز/تنظیم‌کننده هم ترم بگیرند
 *   $bot_mood_taxonomy / $bot_genre_taxonomy / $bot_songwriter_taxonomy / $bot_composer_taxonomy / $bot_arranger_taxonomy
 *                                                  نام داخلی تاکسونومی اگر با پیش‌فرض‌های قالب فرق داشت
 * ترم موجود با همان نام (یا املای نزدیک / همان نامک انگلیسی) دوباره ساخته نمی‌شود؛ ترم تازه با نامک انگلیسی مبدأ
 * (pop ، happy ، mehdi-hariji) ساخته می‌شود تا آدرس آرشیو انگلیسی باشد.
 *
 * پست‌های قبلی: bot/backfill.php
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------
 |  تاکسونومی‌ها
 * ------------------------------------------------------ */

/** نقش‌ها → نامزدهای نام داخلی تاکسونومی در قالب (اولین موردی که در سایت ثبت باشد انتخاب می‌شود) */
function bm_role_taxonomy_candidates() {
    return [
        'mood'       => ['tara_mood', 'mood', 'moods', 'music_mood'],
        'genre'      => ['tara_genre', 'genre', 'genres', 'music_genre', 'style'],
        'songwriter' => ['tara_songwriter', 'songwriter', 'lyricist', 'poet'],
        'composer'   => ['tara_composer', 'composer'],
        'arranger'   => ['tara_arranger', 'arranger', 'regulator'],
    ];
}

/** برچسب فارسی نقش برای گزارش‌ها */
function bm_role_label($role) {
    $labels = ['mood' => 'حال‌وهوا', 'genre' => 'ژانر', 'songwriter' => 'ترانه‌سرا', 'composer' => 'آهنگساز', 'arranger' => 'تنظیم‌کننده'];
    return isset($labels[$role]) ? $labels[$role] : (string) $role;
}

/**
 * نام داخلی تاکسونومی یک نقش: $bot_{role}_taxonomy از config.php اگر در سایت ثبت باشد، وگرنه نامزدهای قالب
 * خروجی: نام تاکسونومی یا '' (در سایت نیست)
 */
function bm_role_taxonomy($role) {
    static $cache = [];
    $role = (string) $role;
    if (array_key_exists($role, $cache)) {
        return $cache[$role];
    }
    $all = bm_role_taxonomy_candidates();
    if (!isset($all[$role])) {
        return $cache[$role] = '';
    }
    $configured = isset($GLOBALS['bot_' . $role . '_taxonomy']) ? sanitize_key((string) $GLOBALS['bot_' . $role . '_taxonomy']) : '';
    foreach (array_unique(array_filter(array_merge([$configured], $all[$role]))) as $taxonomy) {
        if (taxonomy_exists($taxonomy)) {
            return $cache[$role] = $taxonomy;
        }
    }
    return $cache[$role] = '';
}

/** نقش‌هایی که باید ترم بگیرند (عوامل با $bot_credit_terms = false خاموش می‌شوند) */
function bm_term_roles() {
    $roles = ['mood', 'genre'];
    if (!isset($GLOBALS['bot_credit_terms']) || $GLOBALS['bot_credit_terms']) {
        $roles[] = 'songwriter';
        $roles[] = 'composer';
        $roles[] = 'arranger';
    }
    return $roles;
}

/**
 * نام ترم سایت از روی نام مبدأ: نگاشت ($bot_mood_term_names / $bot_genre_term_names) + پیشوند ($bot_*_term_prefix)
 * «ریتمیک» → «شاد» → «آهنگ شاد»؛ «آهنگ عاشقانه» دوباره پیشوند نمی‌گیرد
 */
function bm_role_term_name($role, $name) {
    $name = bm_clean_text($name);
    if ($name === '') {
        return '';
    }
    $map = [];
    if ($role === 'mood') {
        $map = ['ریتمیک' => 'شاد'];
    }
    if (isset($GLOBALS['bot_' . $role . '_term_names']) && is_array($GLOBALS['bot_' . $role . '_term_names'])) {
        $map = array_merge($map, $GLOBALS['bot_' . $role . '_term_names']);
    }
    $key = bm_normalize_name($name);
    foreach ($map as $from => $to) {
        if (bm_normalize_name($from) === $key && bm_clean_text($to) !== '') {
            $name = bm_clean_text($to);
            break;
        }
    }
    $prefix = '';
    if ($role === 'mood') {
        $prefix = isset($GLOBALS['bot_mood_term_prefix']) ? (string) $GLOBALS['bot_mood_term_prefix'] : 'آهنگ ';
    } elseif ($role === 'genre') {
        $prefix = isset($GLOBALS['bot_genre_term_prefix']) ? (string) $GLOBALS['bot_genre_term_prefix'] : '';
    }
    $prefix = trim(preg_replace('/\s+/u', ' ', $prefix));
    if ($prefix !== '' && strpos(bm_normalize_name($name), bm_normalize_name($prefix)) !== 0) {
        $name = $prefix . ' ' . $name;
    }
    return $name;
}

/**
 * نامک انگلیسی شناخته‌شده برای ترم ژانر/حال‌وهوا وقتی مبدأ نامکی نداده (مثلاً ترم از متای پست قدیمی ساخته می‌شود):
 * «شاد»/«ریتمیک» → happy ، «غمگین» → sad ، «پاپ» → pop … ($bot_mood_slugs / $bot_genre_slugs در config.php اضافه/بازنویسی می‌کند)
 * پیشوند نام («آهنگ شاد») نادیده گرفته می‌شود. ناشناخته → '' (وردپرس خودش نامک می‌سازد)
 */
function bm_role_known_slug($role, $name) {
    static $maps = null;
    if ($maps === null) {
        $maps = [
            'mood' => [
                'شاد' => 'happy', 'ریتمیک' => 'happy', 'غمگین' => 'sad', 'عاشقانه' => 'love', 'آرام' => 'calm',
                'احساسی' => 'emotional', 'انرژی' => 'energetic', 'پرانرژی' => 'energetic', 'عرفانی' => 'mystic',
                'دلتنگی' => 'nostalgic', 'نوستالژی' => 'nostalgic', 'حماسی' => 'epic', 'انگیزشی' => 'motivational',
            ],
            'genre' => [
                'پاپ' => 'pop', 'رپ' => 'rap', 'هیپ هاپ' => 'hip-hop', 'راک' => 'rock', 'سنتی' => 'traditional',
                'محلی' => 'folk', 'بی کلام' => 'instrumental', 'الکترونیک' => 'electronic', 'جاز' => 'jazz',
                'بلوز' => 'blues', 'کلاسیک' => 'classical', 'متال' => 'metal', 'تلفیقی' => 'fusion', 'مذهبی' => 'religious',
                'کودکانه' => 'kids', 'ریمیکس' => 'remix', 'هوش مصنوعی' => 'ai', 'دی جی' => 'dj', 'پادکست' => 'podcast',
            ],
        ];
        foreach (['mood', 'genre'] as $r) {
            if (isset($GLOBALS['bot_' . $r . '_slugs']) && is_array($GLOBALS['bot_' . $r . '_slugs'])) {
                $maps[$r] = array_merge($maps[$r], $GLOBALS['bot_' . $r . '_slugs']);
            }
        }
    }
    if (!isset($maps[$role])) {
        return '';
    }
    $name = bm_clean_text($name);
    $prefix = '';
    if ($role === 'mood') {
        $prefix = isset($GLOBALS['bot_mood_term_prefix']) ? (string) $GLOBALS['bot_mood_term_prefix'] : 'آهنگ ';
    } elseif ($role === 'genre') {
        $prefix = isset($GLOBALS['bot_genre_term_prefix']) ? (string) $GLOBALS['bot_genre_term_prefix'] : '';
    }
    $prefix = trim(preg_replace('/\s+/u', ' ', $prefix));
    $key = bm_normalize_name($name); // بدون فاصله: «آهنگ شاد» → «آهنگشاد»
    if ($prefix !== '') {
        $pk = bm_normalize_name($prefix);
        if ($pk !== '' && strpos($key, $pk) === 0 && strlen($key) > strlen($pk)) {
            $key = substr($key, strlen($pk));
        }
    }
    foreach ($maps[$role] as $fa => $slug) {
        if (bm_normalize_name($fa) === $key) {
            $slug = strtolower(trim((string) $slug));
            return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) ? $slug : '';
        }
    }
    return '';
}

/**
 * چند نام در یک رشته («پاپ، سنتی»، «الف / ب») → [['name' => …, 'slug' => …], …]
 * برای ژانر/حال‌وهوا فقط «،» «,» «/» «|» جدا می‌کند (« و » می‌تواند جزء نام باشد: «رپ و هیپ‌هاپ»)؛
 * برای عوامل همان جداکننده‌های نام خواننده‌ها (bm_split_artists: « و »، «&»، «فیت» …)
 * نامک مبدأ فقط وقتی به کار می‌رود که یک نام باشد.
 */
function bm_role_people($role, $text, $slug = '') {
    $text = bm_clean_text($text);
    if ($text === '') {
        return [];
    }
    if (in_array($role, ['mood', 'genre'], true)) {
        $parts = preg_split('/\s*[،,\/|]\s*/u', $text);
    } else {
        $parts = bm_split_artists($text);
    }
    $people = [];
    foreach ((array) $parts as $part) {
        $part = bm_clean_text((string) $part);
        if ($part !== '') {
            $people[] = ['name' => $part, 'slug' => ''];
        }
    }
    if (count($people) === 1 && $slug !== '') {
        $people[0]['slug'] = (string) $slug;
    }
    return $people;
}

/**
 * ترم‌های یک نقش روی پست (ترم نبود → ساخته می‌شود)
 * $people: [['name' => 'مهدی هریجی', 'slug' => 'mehdi-hariji'], …]   slug = نامک انگلیسی مبدأ (اختیاری)
 * $append = false → ترم‌های قبلی این تاکسونومی جایگزین می‌شوند
 * خروجی: ['role', 'taxonomy', 'terms' => [['id', 'name', 'slug']], 'note']
 */
function bm_set_role_terms($post_id, $role, array $people, $append = false) {
    $out = ['role' => $role, 'taxonomy' => '', 'terms' => [], 'note' => ''];
    $taxonomy = bm_role_taxonomy($role);
    if ($taxonomy === '') {
        $out['note'] = 'تاکسونومی ' . bm_role_label($role) . ' در سایت نیست';
        return $out;
    }
    $out['taxonomy'] = $taxonomy;

    $ids = [];
    foreach ($people as $person) {
        $name = bm_role_term_name($role, isset($person['name']) ? $person['name'] : '');
        if ($name === '') {
            continue;
        }
        // نامک انگلیسی: mehdi-hariji → «Mehdi Hariji» → bm_get_singer_term خودش sanitize می‌کند؛ نامک غیرانگلیسی نادیده
        $slug = !empty($person['slug']) ? (string) $person['slug'] : bm_role_known_slug($role, $name);
        $slug_words = $slug !== '' ? str_replace(['-', '_'], ' ', $slug) : '';
        $term = bm_get_singer_term($name, $slug_words, $taxonomy);
        if (empty($term['id'])) {
            $out['note'] = trim($out['note'] . ' ترم «' . $name . '» ساخته نشد؛');
            continue;
        }
        $term_id = (int) $term['id'];
        if (!isset($ids[$term_id])) {
            $term_obj = get_term($term_id, $taxonomy);
            $ids[$term_id] = [
                'id'   => $term_id,
                'name' => ($term_obj && !is_wp_error($term_obj)) ? $term_obj->name : $name,
                'slug' => ($term_obj && !is_wp_error($term_obj)) ? $term_obj->slug : '',
            ];
        }
    }
    if (!empty($ids)) {
        $set = wp_set_object_terms($post_id, array_keys($ids), $taxonomy, (bool) $append);
        if (is_wp_error($set)) {
            $out['note'] = trim($out['note'] . ' ' . $set->get_error_message());
        } else {
            $out['terms'] = array_values($ids);
        }
    }
    $out['note'] = trim($out['note'], ' ؛');
    return $out;
}

/**
 * افراد/مقدارهای هر نقش از داده‌ی مبدأ (خروجی bm_fetch_source) یا متاهای پست
 * کلیدهای شناخته‌شده: mood + mood_slug ، style|genre + genre_slug ، songwriter|lyricist ، composer ، regulator|arranger ،
 * crew_links (لینک‌های صفحه‌ی مبدأ: چند نفر با نامک انگلیسی هر کدام)
 * خروجی: [role => [['name', 'slug'], …]] فقط نقش‌هایی که مقداری دارند
 */
function bm_role_people_from_data(array $data) {
    $links = isset($data['crew_links']) && is_array($data['crew_links']) ? $data['crew_links'] : [];
    $first = function (array $keys) use ($data) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && bm_clean_text($data[$key]) !== '') {
                return bm_clean_text($data[$key]);
            }
        }
        return '';
    };
    $out = [];
    $out['mood']  = bm_role_people('mood', $first(['mood']), isset($data['mood_slug']) ? (string) $data['mood_slug'] : '');
    $out['genre'] = bm_role_people('genre', $first(['style', 'genre']), isset($data['genre_slug']) ? (string) $data['genre_slug'] : '');
    foreach (['songwriter' => ['songwriter', 'lyricist'], 'composer' => ['composer'], 'arranger' => ['regulator', 'arranger']] as $role => $keys) {
        $link_key = $role === 'arranger' ? 'regulator' : $role;
        $people   = [];
        if (!empty($links[$link_key]) && is_array($links[$link_key])) {
            foreach ($links[$link_key] as $person) {
                if (is_array($person) && !empty($person['name'])) {
                    $people[] = ['name' => (string) $person['name'], 'slug' => isset($person['slug']) ? (string) $person['slug'] : ''];
                }
            }
        }
        if (empty($people)) {
            $people = bm_role_people($role, $first($keys));
        }
        $out[$role] = $people;
    }
    return array_filter($out);
}

/**
 * ترم‌های همه‌ی نقش‌ها روی پست
 * $only_missing = true → نقشی که از قبل ترم دارد دست نمی‌خورد (پست‌های قبلی که دستی تنظیم شده‌اند)
 * خروجی: [role => نتیجه‌ی bm_set_role_terms] فقط برای نقش‌های فعال (bm_term_roles) که مقداری داشتند
 */
function bm_apply_role_terms($post_id, array $data, $only_missing = false) {
    $report = [];
    $people = bm_role_people_from_data($data);
    foreach (bm_term_roles() as $role) {
        if (empty($people[$role])) {
            continue;
        }
        if ($only_missing) {
            $taxonomy = bm_role_taxonomy($role);
            if ($taxonomy !== '') {
                $current = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
                if (is_array($current) && !empty($current)) {
                    $report[$role] = ['role' => $role, 'taxonomy' => $taxonomy, 'terms' => [], 'note' => 'قبلاً ترم داشت', 'kept' => true];
                    continue;
                }
            }
        }
        $report[$role] = bm_set_role_terms($post_id, $role, $people[$role], false);
    }
    return $report;
}

/** متن یک‌خطی گزارش ترم‌ها برای خروجی ربات */
function bm_role_terms_summary(array $report) {
    $parts = [];
    foreach ($report as $role => $row) {
        $names = [];
        foreach ((array) $row['terms'] as $term) {
            $names[] = $term['name'] . ' (ترم ' . (int) $term['id'] . ')';
        }
        $text = bm_role_label($role) . ': ' . (!empty($names) ? implode('، ', $names) : '—');
        if (!empty($row['taxonomy'])) {
            $text .= ' [' . $row['taxonomy'] . ']';
        }
        if (!empty($row['note'])) {
            $text .= ' — ' . $row['note'];
        }
        $parts[] = $text;
    }
    return implode(' | ', $parts);
}

/* -------------------------------------------------------
 |  تاریخ انتشار واقعی آهنگ
 * ------------------------------------------------------ */

/** فیلدهای ACF نوشته‌ها (همه‌ی گروه‌هایی که برای نوع «نوشته» نمایش داده می‌شوند) */
function bm_acf_post_fields($post_id = 0) {
    static $cache = [];
    $post_id = (int) $post_id;
    if (isset($cache[$post_id])) {
        return $cache[$post_id];
    }
    $fields = [];
    if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
        $args = ['post_type' => 'post'];
        if ($post_id > 0) {
            $args['post_id'] = $post_id;
        }
        foreach ((array) acf_get_field_groups($args) as $group) {
            foreach ((array) acf_get_fields($group) as $field) {
                if (is_array($field) && !empty($field['name']) && !empty($field['key'])) {
                    $fields[] = $field;
                }
            }
        }
    }
    return $cache[$post_id] = $fields;
}

/**
 * فیلد «تاریخ انتشار واقعی» قالب
 *   $bot_release_date_field (config.php) = نام فیلد (meta key): اگر فیلد ACF با این نام باشد با همان کلید/نوع، وگرنه متای ساده‌ی متنی
 *   خالی = جست‌وجوی خودکار بین فیلدهای ACF نوشته‌ها: نام/برچسب شامل «تاریخ انتشار» یا release/publish date (امتیاز ۳)،
 *          «انتشار»/release (۲)، فیلد تاریخ (date_picker) یا نام شامل date/تاریخ (۱)؛ فیلد تاریخ نیم‌امتیاز اضافه می‌گیرد.
 * خروجی: ['name', 'key', 'type', 'label', 'source' => 'config' | 'acf' | 'meta'] یا null (پیدا نشد)
 */
function bm_release_date_field($post_id = 0) {
    static $cache = [];
    $post_id = (int) $post_id;
    if (array_key_exists($post_id, $cache)) {
        return $cache[$post_id];
    }
    $configured = isset($GLOBALS['bot_release_date_field']) ? trim((string) $GLOBALS['bot_release_date_field']) : '';
    $best       = null;
    $best_score = 0;
    foreach (bm_acf_post_fields($post_id) as $field) {
        $name  = (string) $field['name'];
        $type  = isset($field['type']) ? (string) $field['type'] : '';
        $label = isset($field['label']) ? (string) $field['label'] : '';
        if ($configured !== '') {
            if ($name === $configured) {
                $best = ['name' => $name, 'key' => (string) $field['key'], 'type' => $type, 'label' => $label, 'source' => 'config'];
                break;
            }
            continue;
        }
        if (in_array($type, ['repeater', 'group', 'flexible_content', 'clone', 'tab', 'accordion', 'message', 'taxonomy', 'image', 'file', 'url', 'true_false', 'relationship', 'post_object', 'gallery'], true)) {
            continue;
        }
        $hay   = mb_strtolower($name . ' ' . $label, 'UTF-8');
        $score = 0;
        if (preg_match('/تاریخ[\s\x{200C}_\-]*انتشار|release[\s_\-]*date|date[\s_\-]*(?:of[\s_\-]*)?release|publish(?:ed)?[\s_\-]*date|date[\s_\-]*publish|enteshar|tarikh/u', $hay)) {
            $score = 3;
        } elseif (preg_match('/انتشار|release/u', $hay)) {
            $score = 2;
        } elseif (in_array($type, ['date_picker', 'date_time_picker'], true) || preg_match('/(?:^|[\s_\-])date(?:$|[\s_\-])|تاریخ/u', $hay)) {
            $score = 1;
        }
        if ($score > 0 && in_array($type, ['date_picker', 'date_time_picker'], true)) {
            $score += 0.5;
        }
        if ($score > $best_score) {
            $best_score = $score;
            $best       = ['name' => $name, 'key' => (string) $field['key'], 'type' => $type, 'label' => $label, 'source' => 'acf'];
        }
    }
    if ($configured === '' && $best !== null && $best_score < 1.5) {
        $best = null; // فقط «date» ساده بدون نشانه‌ی انتشار: مطمئن نیستیم
    }
    if ($best === null && $configured !== '') {
        $best = ['name' => $configured, 'key' => '', 'type' => 'text', 'label' => $configured, 'source' => 'meta'];
    }
    return $cache[$post_id] = $best;
}

/**
 * قالب ذخیره‌ی تاریخ در فیلد: 'Ymd' | 'Y-m-d H:i:s' | 'gregorian' (Y-m-d) | 'jalali' (1405/08/30)
 * $bot_release_date_format (config.php): auto | jalali | gregorian | acf
 */
function bm_release_date_format($field = null) {
    $cfg = isset($GLOBALS['bot_release_date_format']) ? strtolower(trim((string) $GLOBALS['bot_release_date_format'])) : 'auto';
    if ($cfg === 'jalali' || $cfg === 'gregorian') {
        return $cfg;
    }
    $type = is_array($field) && isset($field['type']) ? (string) $field['type'] : '';
    if ($type === 'date_time_picker') {
        return 'Y-m-d H:i:s';
    }
    if ($type === 'date_picker' || $cfg === 'acf') {
        return 'Ymd';
    }
    return 'jalali';
}

/** برچسب فارسی قالب برای گزارش */
function bm_release_date_format_label($format) {
    $labels = ['Ymd' => 'میلادی Ymd (قالب داخلی ACF)', 'Y-m-d H:i:s' => 'میلادی با ساعت', 'gregorian' => 'میلادی Y-m-d', 'jalali' => 'شمسی 1405/08/30'];
    return isset($labels[$format]) ? $labels[$format] : (string) $format;
}

/** میلادی → شمسی: [سال، ماه، روز] */
function bm_gregorian_to_jalali($gy, $gm, $gd) {
    $gy = (int) $gy; $gm = (int) $gm; $gd = (int) $gd;
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
    $days  = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy    = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy   += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy  += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/** شمسی → میلادی: [سال، ماه، روز] */
function bm_jalali_to_gregorian($jy, $jm, $jd) {
    $jy = (int) $jy + 1595; $jm = (int) $jm; $jd = (int) $jd;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy   = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy  += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy   += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy  += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd    = $days + 1;
    $leap  = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28;
    $sal_a = [0, 31, $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) {
        $gd -= $sal_a[$gm];
    }
    return [$gy, $gm, $gd];
}

/** آیا تاریخ شمسی معتبر است؟ (اسفند ۳۰ فقط در سال کبیسه) */
function bm_jalali_checkdate($jy, $jm, $jd) {
    $jy = (int) $jy; $jm = (int) $jm; $jd = (int) $jd;
    if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1) {
        return false;
    }
    if ($jm <= 6) {
        return $jd <= 31;
    }
    if ($jm <= 11) {
        return $jd <= 30;
    }
    if ($jd <= 29) {
        return true;
    }
    if ($jd > 30) {
        return false;
    }
    // ۳۰ اسفند: رفت‌وبرگشت از میلادی باید همان روز را بدهد
    list($gy, $gm, $gd) = bm_jalali_to_gregorian($jy, 12, 30);
    return bm_gregorian_to_jalali($gy, $gm, $gd) === [$jy, 12, 30];
}

/**
 * هر شکلی از تاریخ → ['kind' => 'g' | 'j', 'y', 'm', 'd'] یا null
 * میلادی: 2026-11-21T00:00:00+03:30 ، 2026-11-21 ، 2026/11/21 ، 20261121   |   شمسی: 1405/08/30 ، 1405-08-30 ، 14050830 (ارقام فارسی هم)
 * سال ۱۲۰۰ تا ۱۶۰۰ شمسی، ۱۹۰۰ تا ۲۲۰۰ میلادی
 */
function bm_parse_any_date($text) {
    $text = function_exists('bm_source_latin_digits') ? bm_source_latin_digits((string) $text) : (string) $text;
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^(\d{4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})(?:$|[T\s])/', $text, $m) || preg_match('/^(\d{4})(\d{2})(\d{2})$/', $text, $m)) {
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
    } elseif (preg_match('/^(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{4})$/', $text, $m)) {
        $y = (int) $m[3]; $mo = (int) $m[2]; $d = (int) $m[1]; // 30/08/1405 یا 21/11/2026
    } else {
        return null;
    }
    if ($y >= 1200 && $y <= 1600) {
        return bm_jalali_checkdate($y, $mo, $d) ? ['kind' => 'j', 'y' => $y, 'm' => $mo, 'd' => $d] : null;
    }
    if ($y >= 1900 && $y <= 2200) {
        return checkdate($mo, $d, $y) ? ['kind' => 'g', 'y' => $y, 'm' => $mo, 'd' => $d] : null;
    }
    return null;
}

/**
 * جفت میلادی/شمسی از هر چه در دست است: ['g' => 'Y-m-d', 'j' => 'Y/m/d'] (هر دو خالی = تاریخی نبود)
 * $gregorian = datePublished مبدأ (میلادی)، $jalali = «تاریخ انتشار: 1405/08/30» صفحه
 * تاریخ شمسی صفحه (همان که سایت مبدأ نشان می‌دهد) مرجع است و میلادی از روی آن حساب می‌شود؛ datePublished (که ممکن است
 * به وقت UTC یک روز عقب باشد) فقط وقتی به کار می‌رود که تاریخ شمسی در صفحه نباشد. هر کدام از ورودی‌ها می‌تواند شمسی یا میلادی باشد.
 */
function bm_release_date_pair($gregorian, $jalali = '') {
    $g = null;
    $j = null;
    foreach ([$jalali, $gregorian] as $raw) {
        $p = bm_parse_any_date($raw);
        if ($p === null) {
            continue;
        }
        if ($p['kind'] === 'j') {
            $j = [$p['y'], $p['m'], $p['d']];
            $g = bm_jalali_to_gregorian($j[0], $j[1], $j[2]);
            break; // شمسی صریح: مرجع
        }
        if ($g === null) {
            $g = [$p['y'], $p['m'], $p['d']];
        }
    }
    if ($j === null && $g !== null) {
        $j = bm_gregorian_to_jalali($g[0], $g[1], $g[2]);
    }
    return [
        'g' => $g !== null ? sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]) : '',
        'j' => $j !== null ? sprintf('%04d/%02d/%02d', $j[0], $j[1], $j[2]) : '',
    ];
}

/** مقدار قابل ذخیره در فیلد با قالب انتخابی */
function bm_release_date_value(array $pair, $format) {
    if ($pair['g'] === '') {
        return '';
    }
    switch ($format) {
        case 'jalali':
            return $pair['j'];
        case 'gregorian':
            return $pair['g'];
        case 'Y-m-d H:i:s':
            return $pair['g'] . ' 00:00:00';
        default:
            return str_replace('-', '', $pair['g']); // Ymd
    }
}

/** مقدار فعلی فیلد تاریخ انتشار پست ('' = خالی) */
function bm_release_date_current($post_id, $field = null) {
    if ($field === null) {
        $field = bm_release_date_field($post_id);
    }
    if (!is_array($field)) {
        return '';
    }
    $value = get_post_meta($post_id, $field['name'], true);
    return is_scalar($value) ? trim((string) $value) : '';
}

/**
 * ذخیره‌ی تاریخ انتشار واقعی آهنگ روی پست
 * همیشه متاهای _bm_release_date (میلادی Y-m-d) و _bm_release_date_jalali (شمسی) نوشته می‌شود؛ فیلد قالب
 * (bm_release_date_field) با قالب bm_release_date_format پر می‌شود. $overwrite = false → فیلد پرشده دست نمی‌خورد.
 * خروجی: ['ok', 'field', 'value', 'format', 'gregorian', 'jalali', 'note']
 */
function bm_store_release_date($post_id, $gregorian, $jalali = '', $overwrite = true) {
    $pair = bm_release_date_pair($gregorian, $jalali);
    $out  = ['ok' => false, 'field' => '', 'value' => '', 'format' => '', 'gregorian' => $pair['g'], 'jalali' => $pair['j'], 'note' => ''];
    if ($pair['g'] === '') {
        $out['note'] = 'تاریخ انتشار در مبدأ پیدا نشد';
        return $out;
    }
    update_post_meta($post_id, '_bm_release_date', $pair['g']);
    update_post_meta($post_id, '_bm_release_date_jalali', $pair['j']);

    $field = bm_release_date_field($post_id);
    if (!is_array($field)) {
        $out['note'] = 'فیلد تاریخ انتشار در قالب پیدا نشد؛ فقط در متای _bm_release_date ذخیره شد (نام فیلد را در config.php بدهید: $bot_release_date_field)';
        return $out;
    }
    $out['field']  = $field['name'];
    $out['format'] = bm_release_date_format($field);
    if (!$overwrite) {
        $current = bm_release_date_current($post_id, $field);
        if ($current !== '') {
            $out['ok']    = true;
            $out['value'] = $current;
            $out['note']  = 'فیلد از قبل پر بود';
            return $out;
        }
    }
    $value = bm_release_date_value($pair, $out['format']);
    if ($field['key'] !== '' && function_exists('update_field')) {
        update_field($field['key'], $value, $post_id);
    } else {
        update_post_meta($post_id, $field['name'], $value);
    }
    $out['ok']    = true;
    $out['value'] = $value;
    return $out;
}

/** متن یک‌خطی گزارش تاریخ انتشار برای خروجی ربات */
function bm_release_date_summary(array $r) {
    if ($r['gregorian'] === '') {
        return $r['note'];
    }
    $text = $r['jalali'] . ' (' . $r['gregorian'] . ')';
    if ($r['field'] !== '') {
        $text .= ' → فیلد ' . $r['field'] . ' = ' . $r['value'] . ' [' . bm_release_date_format_label($r['format']) . ']';
    }
    if ($r['note'] !== '') {
        $text .= ' — ' . $r['note'];
    }
    return $text;
}
