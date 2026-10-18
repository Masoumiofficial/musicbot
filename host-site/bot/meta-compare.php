<?php
/**
 * ابزار عیب‌یابی: مقایسه‌ی متاهای دو پست + اطلاعات قالب، افزونه‌ها، کاربرها و فیلدهای ACF
 *
 *   /bot/meta-compare.php?a=ID_پست_ربات&b=ID_پست_ویرایش‌شده
 *
 *   a = پستی که ربات ساخته و هنوز در پیشخوان ذخیره نشده
 *   b = پستی که بعد از ساخت، در پیشخوان «به‌روزرسانی» شده (اختیاری)
 *
 * فقط می‌خواند؛ چیزی را تغییر نمی‌دهد. پشت همان یوزر/رمز ربات است.
 */
include('config.php');
require_once __DIR__ . '/auth.php';
bm_require_auth();

require('../wp-load.php');

header('Content-Type: text/html; charset=utf-8');

$a = isset($_GET['a']) ? (int) $_GET['a'] : 0;
$b = isset($_GET['b']) ? (int) $_GET['b'] : 0;

function bmc_h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function bmc_val($v) {
    if (is_array($v)) {
        $v = count($v) === 1 ? reset($v) : wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $v = (string) $v;
    if (is_serialized($v)) {
        $v = wp_json_encode(maybe_unserialize($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return mb_strlen($v) > 160 ? mb_substr($v, 0, 160) . ' …' : $v;
}

/**
 * نام داخلی taxonomy هنرمند: $bot_artist_taxonomy از config.php اگر در سایت باشد، وگرنه artist / singer
 */
function bmc_artist_taxonomy() {
    $configured = isset($GLOBALS['bot_artist_taxonomy']) ? sanitize_key((string) $GLOBALS['bot_artist_taxonomy']) : '';
    foreach (array_unique(array_filter([$configured, 'artist', 'singer'])) as $taxonomy) {
        if (taxonomy_exists($taxonomy)) {
            return $taxonomy;
        }
    }
    return '';
}

function bmc_post_info($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return null;
    }
    $author = get_userdata($post->post_author);
    return [
        'post'     => $post,
        'author'   => $author ? $author->user_login . ' (' . $author->display_name . ')' : '— کاربری با این آیدی وجود ندارد —',
        'thumb'    => (int) get_post_thumbnail_id($post_id),
        'cats'     => wp_get_post_categories($post_id),
        'singer'   => bmc_artist_taxonomy() !== '' ? wp_get_object_terms($post_id, bmc_artist_taxonomy(), ['fields' => 'names']) : [],
        'meta'     => get_post_meta($post_id),
    ];
}

$info_a = $a ? bmc_post_info($a) : null;
$info_b = $b ? bmc_post_info($b) : null;

$theme = wp_get_theme();
$user1 = get_userdata(1);
$admins = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'fields' => ['ID', 'user_login', 'display_name']]);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>مقایسه‌ی متاها</title>
<style>
    body{font-family:tahoma,sans-serif;font-size:13px;line-height:1.9;background:#f5f5f5;color:#222;margin:0;padding:20px}
    h2{font-size:16px;margin:25px 0 8px}
    table{border-collapse:collapse;background:#fff;width:100%;margin-bottom:10px}
    th,td{border:1px solid #ddd;padding:5px 8px;text-align:right;vertical-align:top;word-break:break-all}
    th{background:#eee}
    td.ltr{direction:ltr;text-align:left;font-family:monospace}
    tr.diff td{background:#fff3cd}
    tr.only-b td{background:#f8d7da}
    tr.only-a td{background:#d1e7dd}
    .box{background:#fff;border:1px solid #ddd;padding:10px 15px;margin-bottom:10px}
    .muted{color:#777}
    form input{padding:4px 6px;font-family:tahoma}
</style>
</head>
<body>

<form method="get" class="box">
    شناسه‌ی پست ربات (a): <input type="number" name="a" value="<?php echo $a ?: ''; ?>">
    &nbsp; شناسه‌ی پست ویرایش‌شده (b): <input type="number" name="b" value="<?php echo $b ?: ''; ?>">
    <button type="submit">مقایسه</button>
    <div class="muted">راهنما: a = پستی که ربات ساخته و دست نخورده؛ b = پستی که بعد از ساخت در پیشخوان «به‌روزرسانی» شده. ردیف‌های قرمز = فقط در b (یعنی ویرایش از پیشخوان آن را اضافه کرده)، زرد = مقدار متفاوت.</div>
</form>

<h2>سایت</h2>
<table>
    <tr><th style="width:220px">قالب فعال</th><td><?php echo bmc_h($theme->get('Name') . ' ' . $theme->get('Version')); ?> <span class="muted">(<?php echo bmc_h($theme->get_stylesheet()); ?><?php echo $theme->parent() ? ' — والد: ' . bmc_h($theme->parent()->get('Name')) : ''; ?>)</span></td></tr>
    <tr><th>افزونه‌های فعال</th><td><?php
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option('active_plugins', []);
        $names  = [];
        foreach ($active as $plugin_file) {
            $path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if (file_exists($path)) {
                $data    = get_plugin_data($path, false, false);
                $names[] = $data['Name'] . ' ' . $data['Version'];
            } else {
                $names[] = $plugin_file;
            }
        }
        echo bmc_h(implode('، ', $names));
    ?></td></tr>
    <tr><th>ACF</th><td><?php echo function_exists('acf_get_field_groups') ? 'فعال' . (defined('ACF_VERSION') ? ' ' . bmc_h(ACF_VERSION) : '') : 'غیرفعال'; ?></td></tr>
    <tr><th>کش شیء / صفحه</th><td><?php
        $flags = [];
        if (wp_using_ext_object_cache()) $flags[] = 'Object cache خارجی';
        if (defined('WP_CACHE') && WP_CACHE) $flags[] = 'WP_CACHE=true (افزونه‌ی کش صفحه)';
        if (defined('LSCWP_V')) $flags[] = 'LiteSpeed Cache';
        if (defined('WP_ROCKET_VERSION')) $flags[] = 'WP Rocket';
        if (defined('W3TC')) $flags[] = 'W3 Total Cache';
        if (defined('WPCACHEHOME')) $flags[] = 'WP Super Cache';
        echo $flags ? bmc_h(implode('، ', $flags)) : 'چیزی تشخیص داده نشد';
    ?></td></tr>
    <tr><th>کاربر شماره‌ی 1</th><td><?php echo $user1 ? bmc_h($user1->user_login . ' (' . $user1->display_name . ')') : '<strong>وجود ندارد</strong> — به همین خاطر پست‌های قبلی ربات (post_author = 1) بدون نویسنده بودند'; ?></td></tr>
    <tr><th>مدیرها</th><td><?php
        $list = [];
        foreach ($admins as $u) {
            $list[] = 'ID ' . $u->ID . ' = ' . $u->user_login . ' (' . $u->display_name . ')';
        }
        echo bmc_h(implode('، ', $list));
    ?></td></tr>
    <tr><th>نویسنده‌ای که ربات انتخاب می‌کند</th><td><?php
        $cfg = isset($bot_post_author) ? $bot_post_author : '';
        $u = null;
        if ($cfg !== '') {
            $u = ctype_digit((string) $cfg) ? get_user_by('id', (int) $cfg) : (get_user_by('login', $cfg) ?: get_user_by('email', $cfg));
        }
        if (!$u && !empty($bot_auth_user)) {
            $u = get_user_by('login', $bot_auth_user);
        }
        if (!$u && $admins) {
            $u = get_userdata($admins[0]->ID);
        }
        echo $u ? bmc_h('ID ' . $u->ID . ' = ' . $u->user_login . ' (' . $u->display_name . ')') : 'هیچ کاربر مناسبی پیدا نشد';
        echo ' <span class="muted">($bot_post_author = ' . bmc_h($cfg === '' ? 'خالی' : $cfg) . ')</span>';
    ?></td></tr>
</table>

<?php if ($info_a || $info_b) : ?>
<h2>پست‌ها</h2>
<table>
    <tr><th style="width:220px"></th><th>a = <?php echo $a; ?></th><th>b = <?php echo $b; ?></th></tr>
    <?php
    $rows = [
        'عنوان'        => function ($i) { return $i['post']->post_title; },
        'وضعیت'        => function ($i) { return $i['post']->post_status; },
        'نویسنده'      => function ($i) { return $i['post']->post_author . ' → ' . $i['author']; },
        'تاریخ'        => function ($i) { return $i['post']->post_date . ' (ویرایش: ' . $i['post']->post_modified . ')'; },
        'تصویر شاخص'   => function ($i) { return $i['thumb'] ? 'attachment ' . $i['thumb'] : '— ندارد —'; },
        'دسته‌ها'      => function ($i) { return implode(', ', $i['cats']); },
        'هنرمند'       => function ($i) { return (is_array($i['singer']) ? implode(', ', $i['singer']) : '') . ' (taxonomy: ' . (bmc_artist_taxonomy() !== '' ? bmc_artist_taxonomy() : 'پیدا نشد') . ')'; },
        'تعداد متاها'  => function ($i) { return count($i['meta']); },
    ];
    foreach ($rows as $label => $fn) {
        echo '<tr><th>' . bmc_h($label) . '</th>';
        echo '<td>' . ($info_a ? bmc_h($fn($info_a)) : '<span class="muted">—</span>') . '</td>';
        echo '<td>' . ($info_b ? bmc_h($fn($info_b)) : '<span class="muted">—</span>') . '</td></tr>';
    }
    ?>
</table>

<h2>متاها</h2>
<table>
    <tr><th style="width:220px">کلید</th><th>a = <?php echo $a; ?></th><th>b = <?php echo $b; ?></th></tr>
    <?php
    $meta_a = $info_a ? $info_a['meta'] : [];
    $meta_b = $info_b ? $info_b['meta'] : [];
    $keys   = array_unique(array_merge(array_keys($meta_a), array_keys($meta_b)));
    sort($keys);
    foreach ($keys as $key) {
        $has_a = array_key_exists($key, $meta_a);
        $has_b = array_key_exists($key, $meta_b);
        $va    = $has_a ? bmc_val($meta_a[$key]) : null;
        $vb    = $has_b ? bmc_val($meta_b[$key]) : null;
        $class = '';
        if ($info_a && $info_b) {
            if ($has_a && !$has_b)      $class = 'only-a';
            elseif (!$has_a && $has_b)  $class = 'only-b';
            elseif ($va !== $vb && !in_array($key, ['_edit_lock', '_edit_last', '_bm_download_response', '_bm_featured_image_result', '_thumbnail_id'], true)) $class = 'diff';
        }
        echo '<tr class="' . $class . '"><td class="ltr">' . bmc_h($key) . '</td>';
        echo '<td class="ltr">' . ($has_a ? bmc_h($va) : '<span class="muted">—</span>') . '</td>';
        echo '<td class="ltr">' . ($has_b ? bmc_h($vb) : '<span class="muted">—</span>') . '</td></tr>';
    }
    ?>
</table>
<?php endif; ?>

<?php if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) : ?>
<h2>گروه‌های فیلد ACF که برای پست‌ها نمایش داده می‌شوند</h2>
<?php
$acf_args = ['post_type' => 'post'];
if ($a) $acf_args['post_id'] = $a;
$groups = acf_get_field_groups($acf_args);
if (empty($groups)) {
    echo '<div class="box">هیچ گروه فیلدی برای نوع پست «نوشته» پیدا نشد.</div>';
}
foreach ((array) $groups as $group) :
    $fields = acf_get_fields($group);
?>
<table>
    <tr><th colspan="5"><?php echo bmc_h($group['title']); ?> <span class="muted">(<?php echo bmc_h($group['key']); ?>)</span></th></tr>
    <tr><th style="width:170px">نام فیلد (meta key)</th><th style="width:190px">کلید</th><th style="width:100px">نوع</th><th style="width:120px">مقدار پیش‌فرض</th><th style="width:260px">گزینه‌ها (مقدار = برچسب)</th><th style="width:130px">مقداری که ربات می‌نویسد</th><th>برچسب</th></tr>
    <?php foreach ((array) $fields as $field) :
        $choices = (!empty($field['choices']) && is_array($field['choices'])) ? $field['choices'] : [];
        $choice_text = [];
        foreach (array_slice($choices, 0, 8, true) as $cv => $cl) {
            $choice_text[] = $cv . ' = ' . $cl;
        }
        if (count($choices) > 8) {
            $choice_text[] = '…';
        }
        // همان منطق ربات: مقدار config → پیش‌فرض → گزینه‌ی اول فیلد انتخابی بدون گزینه‌ی خالی → ''
        $bot_value = '(مقدار خودِ ربات)';
        if (isset($bot_acf_values) && is_array($bot_acf_values) && array_key_exists($field['name'], $bot_acf_values)) {
            $bot_value = bmc_val($bot_acf_values[$field['name']]) . ' (config)';
        } elseif (!in_array($field['name'], ['music320', 'music128', 'online', 'video_low', 'video_medium', 'artist', 'song', 'artist_en', 'song_en', 'music_text', 'composer', 'arranger', 'lyricist', 'mixmaster', 'guitar', 'publisher', 'genre', 'mood'], true)) {
            if (isset($field['default_value']) && $field['default_value'] !== '' && $field['default_value'] !== []) {
                $bot_value = bmc_val($field['default_value']) . ' (پیش‌فرض)';
            } elseif (in_array($field['type'], ['select', 'radio', 'button_group'], true) && empty($field['allow_null']) && empty($field['multiple']) && $choices) {
                reset($choices);
                $bot_value = key($choices) . ' (گزینه‌ی اول)';
            } elseif (in_array($field['type'], ['repeater', 'group', 'flexible_content', 'clone', 'tab', 'accordion', 'message', 'taxonomy'], true)) {
                $bot_value = '— دست نمی‌خورد —';
            } else {
                $bot_value = "'' (خالی)";
            }
        }
    ?>
    <tr>
        <td class="ltr"><?php echo bmc_h($field['name']); ?></td>
        <td class="ltr"><?php echo bmc_h($field['key']); ?></td>
        <td class="ltr"><?php echo bmc_h($field['type'] . (isset($field['allow_null']) && $field['allow_null'] ? ' (allow_null)' : '') . (!empty($field['multiple']) ? ' (multiple)' : '')); ?></td>
        <td class="ltr"><?php echo bmc_h(isset($field['default_value']) ? bmc_val($field['default_value']) : ''); ?></td>
        <td class="ltr"><?php echo bmc_h(implode(' | ', $choice_text)); ?></td>
        <td class="ltr"><?php echo bmc_h($bot_value); ?></td>
        <td><?php echo bmc_h($field['label']); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endforeach; ?>
<?php endif; ?>

</body>
</html>
