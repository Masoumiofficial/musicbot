<?php
/**
 * قالب و اجزای فرم پنل پست‌ساز (index.php و submit.php)
 */

if (!defined('ABSPATH')) {
    exit;
}

function ps_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ps_ui_header($title) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    $version = @filemtime(__DIR__ . '/assets/postsaz.css') ?: '1';
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo ps_h($title); ?> — پست‌ساز آهنگ‌بان</title>
<link rel="stylesheet" href="assets/postsaz.css?v=<?php echo ps_h($version); ?>">
</head>
<body>
<header class="ps-header">
    <div class="ps-wrap">
        <a class="ps-brand" href="index.php">پست‌ساز <span>آهنگ‌بان</span></a>
        <nav class="ps-nav">
            <a href="index.php">پست جدید</a>
            <a href="../bot/robo.php">ربات</a>
            <a href="../archive/">آرشیو خواننده</a>
            <a href="<?php echo ps_h(admin_url('edit.php')); ?>" target="_blank" rel="noopener">پیشخوان</a>
        </nav>
    </div>
</header>
<main class="ps-wrap">
    <?php
}

function ps_ui_footer() {
    $version = @filemtime(__DIR__ . '/assets/postsaz.js') ?: '1';
    ?>
</main>
<footer class="ps-footer">پست‌ساز آهنگ‌بان — فایل‌ها با همان قاعده‌های ربات روی هاست دانلود ذخیره می‌شوند.</footer>
<script src="assets/postsaz.js?v=<?php echo ps_h($version); ?>"></script>
</body>
</html>
    <?php
}

/** فیلد متنی یک‌خطی */
function ps_ui_text($name, $label, array $opts = []) {
    $type        = isset($opts['type']) ? $opts['type'] : 'text';
    $required    = !empty($opts['required']);
    $dir         = isset($opts['dir']) ? $opts['dir'] : '';
    $placeholder = isset($opts['placeholder']) ? $opts['placeholder'] : '';
    $hint        = isset($opts['hint']) ? $opts['hint'] : '';
    $value       = isset($opts['value']) ? $opts['value'] : '';
    $class       = isset($opts['class']) ? $opts['class'] : '';
    echo '<label class="ps-field ' . ps_h($class) . '">';
    echo '<span class="ps-label">' . ps_h($label) . ($required ? ' <b>*</b>' : '') . '</span>';
    echo '<input type="' . ps_h($type) . '" name="' . ps_h($name) . '" value="' . ps_h($value) . '"'
        . ($dir !== '' ? ' dir="' . ps_h($dir) . '"' : '')
        . ($placeholder !== '' ? ' placeholder="' . ps_h($placeholder) . '"' : '')
        . ($required ? ' required' : '')
        . (!empty($opts['attrs']) ? ' ' . $opts['attrs'] : '')
        . '>';
    if ($hint !== '') {
        echo '<small class="ps-hint">' . ps_h($hint) . '</small>';
    }
    echo '</label>';
}

/** فیلد چندخطی */
function ps_ui_textarea($name, $label, array $opts = []) {
    $placeholder = isset($opts['placeholder']) ? $opts['placeholder'] : '';
    $rows        = isset($opts['rows']) ? (int) $opts['rows'] : 6;
    $hint        = isset($opts['hint']) ? $opts['hint'] : '';
    echo '<label class="ps-field ps-field-wide">';
    echo '<span class="ps-label">' . ps_h($label) . '</span>';
    echo '<textarea name="' . ps_h($name) . '" rows="' . $rows . '"' . ($placeholder !== '' ? ' placeholder="' . ps_h($placeholder) . '"' : '') . '></textarea>';
    if ($hint !== '') {
        echo '<small class="ps-hint">' . ps_h($hint) . '</small>';
    }
    echo '</label>';
}

/** فیلد انتخابی؛ $options = [value => label] */
function ps_ui_select($name, $label, array $options, $selected = '', array $opts = []) {
    $hint = isset($opts['hint']) ? $opts['hint'] : '';
    echo '<label class="ps-field' . (!empty($opts['class']) ? ' ' . ps_h($opts['class']) : '') . '">';
    echo '<span class="ps-label">' . ps_h($label) . '</span>';
    echo '<select name="' . ps_h($name) . '"' . (!empty($opts['attrs']) ? ' ' . $opts['attrs'] : '') . '>';
    foreach ($options as $value => $text) {
        echo '<option value="' . ps_h($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . ps_h($text) . '</option>';
    }
    echo '</select>';
    if ($hint !== '') {
        echo '<small class="ps-hint">' . ps_h($hint) . '</small>';
    }
    echo '</label>';
}

/**
 * فایل رسانه‌ای: لینک مستقیم یا آپلود فایل (اگر هر دو پر شود، فایل آپلودشده استفاده می‌شود)
 * نام فیلدها: {$base}_url و {$base}_file
 */
function ps_ui_media($base, $label, $accept, array $opts = []) {
    $required = !empty($opts['required']);
    $hint     = isset($opts['hint']) ? $opts['hint'] : '';
    echo '<div class="ps-field ps-field-wide ps-media" data-media="' . ps_h($base) . '">';
    echo '<span class="ps-label">' . ps_h($label) . ($required ? ' <b>*</b>' : '') . '</span>';
    echo '<div class="ps-media-row">';
    echo '<input type="url" name="' . ps_h($base) . '_url" dir="ltr" placeholder="https://..." class="ps-media-url">';
    echo '<label class="ps-file-btn"><input type="file" name="' . ps_h($base) . '_file" accept="' . ps_h($accept) . '"><span>انتخاب فایل</span></label>';
    echo '<span class="ps-file-name" aria-live="polite"></span>';
    echo '</div>';
    if ($hint !== '') {
        echo '<small class="ps-hint">' . ps_h($hint) . '</small>';
    }
    echo '</div>';
}

/** وضعیت انتشار */
function ps_ui_status($selected = 'publish') {
    ps_ui_select('post_status', 'وضعیت پست', ['publish' => 'منتشر شود', 'draft' => 'پیش‌نویس بماند'], $selected);
}

/** گزینه‌های عمومی پست */
function ps_ui_post_options() {
    echo '<label class="ps-check"><input type="checkbox" name="allow_duplicate" value="1"> اجازه‌ی عنوان تکراری (اگر پستی با همین عنوان هست باز هم ثبت شود)</label>';
}

/**
 * دسته‌ها به‌صورت چک‌باکس؛ data-defaults روی هر فرم = دسته‌های پیش‌فرض هر نوع (برای تغییر خودکار با انتخاب نوع)
 */
function ps_ui_categories(array $categories, array $checked) {
    echo '<fieldset class="ps-field ps-field-wide ps-cats"><legend class="ps-label">دسته‌ها <small>(اولین دسته‌ی پیش‌فرضِ نوع، دسته‌ی اصلی می‌شود)</small></legend>';
    echo '<div class="ps-cats-grid">';
    foreach ($categories as $category) {
        $id = (int) $category->term_id;
        echo '<label class="ps-check"><input type="checkbox" name="categories[]" value="' . $id . '"' . (in_array($id, $checked, true) ? ' checked' : '') . '> '
            . ps_h($category->name) . ' <small>(' . $id . ')</small></label>';
    }
    echo '</div></fieldset>';
}

/** جمله‌ی راهنمای حجم آپلود */
function ps_ui_upload_limit_text() {
    $max = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
    if ($max <= 0) {
        return '';
    }
    return 'حداکثر حجم هر فایل آپلودی روی این سرور: ' . size_format($max) . ' — برای فایل‌های بزرگ‌تر لینک مستقیم بدهید.';
}
