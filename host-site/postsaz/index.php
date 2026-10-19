<?php
/**
 * پست‌ساز آهنگ‌بان — پنل ثبت دستی
 *   تک‌آهنگ (آهنگ / ریمیکس / مداحی / پادکست) ، موزیک ویدیو ، آلبوم ، آپلود فایل روی هاست دانلود
 *
 * ورود با همان یوزر/رمز ربات (HTTP Basic Auth). فرم‌ها به submit.php ارسال می‌شوند.
 */

require_once __DIR__ . '/../bot/config.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../bot/auth.php';
bm_require_auth('AhangBan PostSaz');

require_once __DIR__ . '/../wp-load.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/ui.php';

$types_single = ps_types('single');
$type_video   = ps_module_type('video');
$type_album   = ps_module_type('album');
$extra_cats   = array_map('intval', (array) ps_cfg('extra_categories', []));
$categories   = get_categories(['hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
$limit_text   = ps_ui_upload_limit_text();
$max_tracks   = max(1, (int) ps_cfg('album.max_tracks', 30));
$dl_folders   = ps_dl_folders();

// دسته‌های پیش‌فرض هر نوع (برای جاوااسکریپت)
$defaults_map = [];
foreach (ps_types() as $key => $type) {
    $defaults_map[$key] = array_values(array_unique(array_merge(
        array_map('intval', (array) (isset($type['categories']) ? $type['categories'] : [])),
        $extra_cats
    )));
}

$first_single = !empty($types_single) ? reset($types_single) : null;
$single_options = [];
foreach ($types_single as $key => $type) {
    $single_options[$key] = $type['label'];
}

// آخرین پست‌های ساخته‌شده با پست‌ساز
$recent = get_posts([
    'post_type'      => 'post',
    'post_status'    => ['publish', 'draft', 'pending', 'future'],
    'posts_per_page' => 8,
    'meta_key'       => '_postsaz_type',
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

ps_ui_header('پست جدید');
?>

<nav class="ps-tabs" role="tablist">
    <a href="#single" class="ps-tab is-active" data-tab="single">تک‌آهنگ</a>
    <a href="#video" class="ps-tab" data-tab="video">موزیک ویدیو</a>
    <a href="#album" class="ps-tab" data-tab="album">آلبوم</a>
    <a href="#leech" class="ps-tab" data-tab="leech">آپلود فایل</a>
</nav>

<?php if ($limit_text !== '') : ?>
<p class="ps-note"><?php echo ps_h($limit_text); ?></p>
<?php endif; ?>

<!-- ============================ تک‌آهنگ ============================ -->
<section id="panel-single" class="ps-panel is-active" data-panel="single">
<?php if (!$first_single) : ?>
    <p class="ps-alert">هیچ نوعی با module = single در postsaz/config.php تعریف نشده است.</p>
<?php else : ?>
    <form method="post" action="submit.php" enctype="multipart/form-data" class="ps-form" data-defaults='<?php echo ps_h(wp_json_encode($defaults_map)); ?>'>
        <input type="hidden" name="module" value="single">
        <?php wp_nonce_field('postsaz_submit', 'ps_nonce'); ?>

        <div class="ps-grid">
            <?php ps_ui_select('type', 'نوع اثر', $single_options, $first_single['key'], ['attrs' => 'data-type-select']); ?>
            <?php ps_ui_status('publish'); ?>
            <?php ps_ui_text('artist', 'نام خواننده (فارسی)', ['required' => true, 'placeholder' => 'محسن یگانه — برای چند نفر: «الف و ب»']); ?>
            <?php ps_ui_text('song', 'نام اثر (فارسی)', ['required' => true, 'placeholder' => 'سرنوشت']); ?>
            <?php ps_ui_text('artist_en', 'نام خواننده (انگلیسی)', ['required' => true, 'dir' => 'ltr', 'placeholder' => 'Mohsen Yeganeh', 'hint' => 'پوشه‌ی هاست دانلود و نام فایل‌ها از همین نام ساخته می‌شود']); ?>
            <?php ps_ui_text('song_en', 'نام اثر (انگلیسی)', ['required' => true, 'dir' => 'ltr', 'placeholder' => 'Sarnevesht']); ?>
        </div>

        <div class="ps-grid">
            <?php ps_ui_media('cover', 'کاور', 'image/jpeg,image/png,image/webp', ['hint' => 'ترجیحاً مربعی و دست‌کم 500×500؛ به مربع حداکثر ' . (int) (isset($bot_cover_size) ? $bot_cover_size : 1080) . ' برش می‌خورد']); ?>
            <?php ps_ui_media('music320', 'فایل MP3 کیفیت 320', 'audio/mpeg,.mp3'); ?>
            <?php ps_ui_media('music128', 'فایل MP3 کیفیت 128', 'audio/mpeg,.mp3', ['hint' => 'اگر فقط 320 بدهید، لینک 128 و پخش آنلاین هم به همان فایل اشاره می‌کند']); ?>
        </div>

        <details class="ps-more">
            <summary>اطلاعات تکمیلی (عوامل، سبک، متن ترانه)</summary>
            <div class="ps-grid">
                <?php ps_ui_text('composer', 'آهنگساز'); ?>
                <?php ps_ui_text('arranger', 'تنظیم'); ?>
                <?php ps_ui_text('lyricist', 'ترانه‌سرا'); ?>
                <?php ps_ui_text('mixmaster', 'میکس و مستر'); ?>
                <?php ps_ui_text('guitar', 'گیتار'); ?>
                <?php ps_ui_text('publisher', 'ناشر'); ?>
                <?php ps_ui_text('genre', 'سبک', ['placeholder' => 'پاپ']); ?>
                <?php ps_ui_text('mood', 'حال‌وهوا', ['placeholder' => 'شاد / غمگین']); ?>
                <?php ps_ui_text('info', 'یک خط توضیح زیر متن پست', ['class' => 'ps-field-wide', 'placeholder' => 'مثلاً: تولید موسسه فرهنگی هنری آوای هنر']); ?>
                <?php ps_ui_textarea('music_text', 'متن ترانه', ['rows' => 8, 'placeholder' => 'هر مصرع در یک خط']); ?>
            </div>
        </details>

        <?php ps_ui_categories($categories, $defaults_map[$first_single['key']]); ?>
        <?php ps_ui_post_options(); ?>

        <div class="ps-actions">
            <button type="submit" class="ps-btn">ثبت تک‌آهنگ</button>
            <span class="ps-progress" hidden>در حال ارسال و انتقال فایل‌ها… این صفحه را نبندید.</span>
        </div>
    </form>
<?php endif; ?>
</section>

<!-- ============================ موزیک ویدیو ============================ -->
<section id="panel-video" class="ps-panel" data-panel="video">
<?php if (!$type_video) : ?>
    <p class="ps-alert">نوع «video» در postsaz/config.php تعریف نشده است.</p>
<?php else : ?>
    <form method="post" action="submit.php" enctype="multipart/form-data" class="ps-form">
        <input type="hidden" name="module" value="video">
        <?php wp_nonce_field('postsaz_submit', 'ps_nonce'); ?>

        <div class="ps-grid">
            <?php ps_ui_status('publish'); ?>
            <div class="ps-field"></div>
            <?php ps_ui_text('artist', 'نام خواننده (فارسی)', ['required' => true]); ?>
            <?php ps_ui_text('song', 'نام اثر (فارسی)', ['required' => true, 'placeholder' => 'بهت قول میدم (اجرای زنده)']); ?>
            <?php ps_ui_text('artist_en', 'نام خواننده (انگلیسی)', ['required' => true, 'dir' => 'ltr']); ?>
            <?php ps_ui_text('song_en', 'نام اثر (انگلیسی)', ['required' => true, 'dir' => 'ltr', 'placeholder' => 'Behet Ghol Midam Live Video']); ?>
        </div>

        <div class="ps-grid">
            <?php ps_ui_media('cover', 'کاور', 'image/jpeg,image/png,image/webp'); ?>
            <?php foreach (array_map('strval', (array) ps_cfg('video.qualities', ['1080', '720', '480'])) as $quality) : ?>
                <?php ps_ui_media('video' . $quality, "ویدیو کیفیت {$quality}p", 'video/mp4,.mp4'); ?>
            <?php endforeach; ?>
        </div>

        <details class="ps-more">
            <summary>اطلاعات تکمیلی (عوامل، متن ترانه)</summary>
            <div class="ps-grid">
                <?php ps_ui_text('composer', 'آهنگساز'); ?>
                <?php ps_ui_text('arranger', 'تنظیم'); ?>
                <?php ps_ui_text('lyricist', 'ترانه‌سرا'); ?>
                <?php ps_ui_text('mixmaster', 'میکس و مستر'); ?>
                <?php ps_ui_text('publisher', 'ناشر'); ?>
                <?php ps_ui_text('info', 'یک خط توضیح زیر متن پست'); ?>
                <?php ps_ui_textarea('music_text', 'متن ترانه', ['rows' => 6]); ?>
            </div>
        </details>

        <?php ps_ui_categories($categories, $defaults_map[$type_video['key']]); ?>
        <?php ps_ui_post_options(); ?>

        <div class="ps-actions">
            <button type="submit" class="ps-btn">ثبت موزیک ویدیو</button>
            <span class="ps-progress" hidden>در حال ارسال و انتقال فایل‌ها… ویدیو ممکن است چند دقیقه طول بکشد؛ این صفحه را نبندید.</span>
        </div>
    </form>
<?php endif; ?>
</section>

<!-- ============================ آلبوم ============================ -->
<section id="panel-album" class="ps-panel" data-panel="album">
<?php if (!$type_album) : ?>
    <p class="ps-alert">نوع «album» در postsaz/config.php تعریف نشده است.</p>
<?php else : ?>
    <form method="post" action="submit.php" enctype="multipart/form-data" class="ps-form" data-max-tracks="<?php echo $max_tracks; ?>">
        <input type="hidden" name="module" value="album">
        <?php wp_nonce_field('postsaz_submit', 'ps_nonce'); ?>

        <div class="ps-grid">
            <?php ps_ui_status('publish'); ?>
            <div class="ps-field"></div>
            <?php ps_ui_text('artist', 'نام خواننده (فارسی)', ['required' => true]); ?>
            <?php ps_ui_text('song', 'نام آلبوم (فارسی)', ['required' => true, 'placeholder' => 'رگ خواب']); ?>
            <?php ps_ui_text('artist_en', 'نام خواننده (انگلیسی)', ['required' => true, 'dir' => 'ltr']); ?>
            <?php ps_ui_text('song_en', 'نام آلبوم (انگلیسی)', ['required' => true, 'dir' => 'ltr', 'placeholder' => 'Rage Khab Album']); ?>
            <?php ps_ui_media('cover', 'کاور آلبوم', 'image/jpeg,image/png,image/webp', ['hint' => 'تصویر شاخص پست و کاور داخل فایل‌های MP3']); ?>
            <?php ps_ui_text('info', 'یک خط توضیح زیر متن پست', ['class' => 'ps-field-wide', 'placeholder' => 'مثلاً: تولید موسسه فرهنگی هنری آوای هنر']); ?>
        </div>

        <fieldset class="ps-tracks">
            <legend class="ps-label">قطعه‌ها <small>(لینک مستقیم هر قطعه؛ ردیف‌های خالی نادیده گرفته می‌شوند — حداکثر <?php echo $max_tracks; ?> قطعه)</small></legend>
            <div class="ps-tracks-head">
                <span>#</span><span>نام فارسی</span><span>نام انگلیسی</span><span>لینک 320</span><span>لینک 128</span><span></span>
            </div>
            <div class="ps-tracks-body">
                <?php for ($i = 0; $i < min(5, $max_tracks); $i++) : ?>
                <div class="ps-track">
                    <span class="ps-track-no"><?php echo $i + 1; ?></span>
                    <input type="text" name="track_fa[]" placeholder="سکوت">
                    <input type="text" name="track_en[]" dir="ltr" placeholder="Sokoot">
                    <input type="url" name="track_320[]" dir="ltr" placeholder="https://... 320">
                    <input type="url" name="track_128[]" dir="ltr" placeholder="https://... 128">
                    <button type="button" class="ps-track-remove" title="حذف ردیف">×</button>
                </div>
                <?php endfor; ?>
            </div>
            <div class="ps-tracks-actions">
                <button type="button" class="ps-btn ps-btn-light" data-add-track>+ افزودن قطعه</button>
                <label class="ps-check"><input type="hidden" name="make_zip" value="0"><input type="checkbox" name="make_zip" value="1"<?php echo ps_cfg('album.zip', true) ? ' checked' : ''; ?>> ساخت فایل ZIP هر کیفیت روی هاست دانلود</label>
            </div>
        </fieldset>

        <?php ps_ui_categories($categories, $defaults_map[$type_album['key']]); ?>
        <?php ps_ui_post_options(); ?>

        <div class="ps-actions">
            <button type="submit" class="ps-btn">ثبت آلبوم</button>
            <span class="ps-progress" hidden>در حال انتقال قطعه‌ها به هاست دانلود… آلبوم‌های بزرگ چند دقیقه طول می‌کشند؛ این صفحه را نبندید.</span>
        </div>
    </form>
<?php endif; ?>
</section>

<!-- ============================ آپلود فایل ============================ -->
<section id="panel-leech" class="ps-panel" data-panel="leech">
    <p class="ps-note">فقط انتقال فایل به هاست دانلود (با تگ ID3 و کاور) بدون ساخت پست — برای وقتی که لینک نهایی را جای دیگری لازم دارید.</p>
    <form method="post" action="submit.php" enctype="multipart/form-data" class="ps-form">
        <input type="hidden" name="module" value="leech">
        <?php wp_nonce_field('postsaz_submit', 'ps_nonce'); ?>

        <div class="ps-grid">
            <?php ps_ui_select('folder', 'پوشه‌ی مقصد', $dl_folders, 'music', ['hint' => 'مسیر نهایی: {پوشه}/{حرف اول}/{نامک خواننده}/']); ?>
            <div class="ps-field"></div>
            <?php ps_ui_text('artist_en', 'نام خواننده (انگلیسی)', ['required' => true, 'dir' => 'ltr']); ?>
            <?php ps_ui_text('song_en', 'نام اثر (انگلیسی)', ['required' => true, 'dir' => 'ltr']); ?>
            <?php ps_ui_text('album', 'نام آلبوم برای تگ (اختیاری)', ['dir' => 'ltr', 'class' => 'ps-field-wide']); ?>
            <?php ps_ui_media('cover', 'کاور (برای تگ MP3)', 'image/jpeg,image/png,image/webp'); ?>
            <?php ps_ui_media('mp3', 'فایل MP3', 'audio/mpeg,.mp3'); ?>
            <?php ps_ui_select('mp3_quality', 'کیفیت MP3', ['320' => '320', '128' => '128'], '320'); ?>
            <?php ps_ui_media('mp4', 'فایل MP4', 'video/mp4,.mp4'); ?>
            <?php ps_ui_select('mp4_quality', 'کیفیت MP4', ['1080' => '1080p', '720' => '720p', '480' => '480p'], '1080'); ?>
        </div>

        <div class="ps-actions">
            <button type="submit" class="ps-btn">انتقال به هاست دانلود</button>
            <span class="ps-progress" hidden>در حال انتقال… این صفحه را نبندید.</span>
        </div>
    </form>
</section>

<?php if (!empty($recent)) : ?>
<section class="ps-recent">
    <h2>آخرین پست‌های پست‌ساز</h2>
    <table>
        <thead><tr><th>ID</th><th>عنوان</th><th>نوع</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $post) :
            $type_key = get_post_meta($post->ID, '_postsaz_type', true);
            $type_def = ps_type($type_key);
            ?>
            <tr>
                <td><?php echo (int) $post->ID; ?></td>
                <td><?php echo ps_h(get_the_title($post)); ?></td>
                <td><?php echo ps_h($type_def ? $type_def['label'] : $type_key); ?></td>
                <td><?php echo ps_h($post->post_status === 'publish' ? 'منتشر شده' : ($post->post_status === 'draft' ? 'پیش‌نویس' : $post->post_status)); ?></td>
                <td dir="ltr"><?php echo ps_h(get_the_date('Y-m-d H:i', $post)); ?></td>
                <td>
                    <a href="<?php echo ps_h(get_permalink($post)); ?>" target="_blank" rel="noopener">مشاهده</a>
                    <a href="<?php echo ps_h(admin_url('post.php?post=' . (int) $post->ID . '&action=edit')); ?>" target="_blank" rel="noopener">ویرایش</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php ps_ui_footer(); ?>
