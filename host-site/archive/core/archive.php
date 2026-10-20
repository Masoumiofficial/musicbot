<?php
/**
 * توابع آرشیو هنرمندان
 *  - خواندن فهرست آهنگ‌های یک هنرمند از صفحه‌ی سایت مبدأ
 *  - تعیین وضعیت هر آهنگ در سایت (ثبت شده / قدیمی / هم‌نام / آماده‌ی ارسال)
 *  - خواندن نتیجه‌ی واقعی manual.php (نشانگر BM_RESULT که ربات در خروجی می‌گذارد)
 *
 * این فایل بدون وردپرس هم قابل include است؛ فقط bm_archive_classify_items به $wpdb نیاز دارد.
 */

if (!function_exists('bm_archive_norm')) {

    /** فاصله‌های تکراری/نیم‌فاصله‌های اضافه را یکی می‌کند تا «ساز و آواز  1» و «ساز و آواز 1» یکی شوند */
    function bm_archive_norm($text) {
        $text = (string) $text;
        $text = str_replace(["\xC2\xA0", "\xE2\x80\x8C"], ' ', $text); // nbsp و نیم‌فاصله
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    /**
     * کلید مقایسه‌ی عنوان: بدون هیچ فاصله/نیم‌فاصله‌ای، تا «محمد رضا شجریان» و «محمدرضا شجریان» و
     * «ساز و آواز  1» و «ساز و آواز 1» یکی شوند (همان کاری که در SQL با REPLACE انجام می‌شود)
     */
    function bm_archive_title_key($text) {
        return str_replace([' ', "\xC2\xA0", "\xE2\x80\x8C", "\t", "\n", "\r"], '', (string) $text);
    }

    /** عنوان پستی که ربات برای این آهنگ می‌سازد (مثل بخش 4 اسکریپت ربات) */
    function bm_archive_title($artist, $song) {
        return trim("دانلود آهنگ {$artist} {$song}");
    }

    /**
     * فهرست آهنگ‌ها از HTML صفحه‌ی هنرمند در سایت مبدأ
     * خروجی: آرایه‌ای از ['url', 'artist', 'song', 'title'] از قدیمی‌ترین به جدیدترین
     * (سایت مبدأ جدیدترین را اول نشان می‌دهد؛ «ارسال همه» باید از قدیمی‌ترین شروع کند)
     */
    function bm_archive_parse_listing($html) {
        $items = [];
        if (!is_string($html) || $html === '') {
            return $items;
        }

        preg_match_all('/(<article class="item ">.*<\/article>)/isU', $html, $blocks);
        $seen = [];
        foreach ($blocks[0] as $block) {
            preg_match('/<a[^>]*href="([^"]*)"[^>]*>/i', $block, $m);
            $url = isset($m[1]) ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : '';

            preg_match('/(<span class="txt">.*<\/span>)/isU', $block, $a);
            $artist = isset($a[0]) ? bm_archive_norm(html_entity_decode(strip_tags($a[0]), ENT_QUOTES, 'UTF-8')) : '';

            preg_match('/(<span class="songname">.*<\/span>)/isU', $block, $s);
            $song = isset($s[0]) ? bm_archive_norm(html_entity_decode(strip_tags($s[0]), ENT_QUOTES, 'UTF-8')) : '';

            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $items[] = [
                'url'    => $url,
                'artist' => $artist,
                'song'   => $song,
                'title'  => bm_archive_title($artist, $song),
            ];
        }

        return array_reverse($items);
    }

    /**
     * وضعیت هر آهنگ در سایت. به هر آیتم این کلیدها اضافه می‌شود:
     *   state      : published | legacy | dup_title | pending
     *   post_id    : شناسه‌ی پست مرتبط (یا پست هم‌عنوان برای dup_title) یا 0
     *   same_title : 1 اگر باید با گزینه‌ی «ثبت با شماره» فرستاده شود
     *   note       : توضیح کوتاه برای نمایش زیر عنوان
     *
     * منطق:
     *   - پستی که متای _bm_source_url آن همین لینک است → published (قطعی)
     *   - وگرنه اگر پستی با همین عنوان هست:
     *       · پست قدیمی بدون لینک مبدأ که هنوز به آیتمی نسبت داده نشده → legacy (قدیمی‌ترین آیتم هم‌عنوان
     *         صاحب آن می‌شود؛ ارسال‌های قبلی هم از قدیمی به جدید بوده‌اند)
     *       · در غیر این صورت → dup_title: قطعه‌ی دیگری با همین نام (مثل «ساز و آواز» در چند آلبوم)؛
     *         قابل ارسال است و ربات آن را با شماره‌ی تمایز «(2)» ثبت می‌کند
     *   - وگرنه → pending
     */
    function bm_archive_classify_items(array $items, $wpdb) {
        $statuses = "'publish', 'draft', 'pending', 'future'";

        // ---- 1) پست‌هایی که از همین لینک‌ها ساخته شده‌اند (_bm_source_url)
        $url_keys = [];
        foreach ($items as $item) {
            $url_keys[$item['url']] = true;
            if (function_exists('esc_url_raw')) {
                $url_keys[esc_url_raw($item['url'])] = true;
            }
        }
        $by_url = [];
        foreach (array_chunk(array_keys($url_keys), 200) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT m.post_id, m.meta_value, p.post_status
                 FROM {$wpdb->postmeta} m
                 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = '_bm_source_url' AND m.meta_value IN ($holders)
                 AND p.post_type = 'post' AND p.post_status IN ($statuses)
                 ORDER BY m.post_id ASC",
                $chunk
            ));
            foreach ((array) $rows as $row) {
                if (!isset($by_url[$row->meta_value])) {
                    $by_url[$row->meta_value] = ['id' => (int) $row->post_id, 'status' => (string) $row->post_status];
                }
            }
        }

        // ---- 2) پست‌های هم‌عنوان (فاصله/نیم‌فاصله در مقایسه‌ی عنوان نادیده گرفته می‌شود)
        $title_keys = [];
        foreach ($items as $item) {
            $title_keys[bm_archive_title_key($item['title'])] = true;
        }
        $by_title = [];
        foreach (array_chunk(array_keys($title_keys), 200) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_status
                 FROM {$wpdb->posts}
                 WHERE post_type = 'post' AND post_status IN ($statuses)
                 AND REPLACE(REPLACE(REPLACE(post_title, ' ', ''), %s, ''), %s, '') IN ($holders)
                 ORDER BY ID ASC",
                array_merge(["\xE2\x80\x8C", "\xC2\xA0"], $chunk)
            ));
            foreach ((array) $rows as $row) {
                $by_title[bm_archive_title_key($row->post_title)][] = [
                    'id'     => (int) $row->ID,
                    'status' => (string) $row->post_status,
                    'source' => '',
                ];
            }
        }

        // لینک مبدأ پست‌های هم‌عنوان (برای تشخیص پست قدیمی از پست قطعه‌ی دیگر)
        $ids = [];
        foreach ($by_title as $group) {
            foreach ($group as $p) {
                $ids[] = $p['id'];
            }
        }
        $source_of = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_bm_source_url' AND post_id IN ($holders)",
                $chunk
            ));
            foreach ((array) $rows as $row) {
                $source_of[(int) $row->post_id] = (string) $row->meta_value;
            }
        }
        foreach ($by_title as $title => $group) {
            foreach ($group as $i => $p) {
                $by_title[$title][$i]['source'] = isset($source_of[$p['id']]) ? $source_of[$p['id']] : '';
            }
        }

        // ---- 3) تعیین وضعیت
        $claimed        = [];
        $pending_titles = []; // عنوان‌هایی که در همین فهرست آماده‌ی ارسال‌اند (برای هم‌نام‌های داخل خود فهرست)
        foreach ($items as $i => $item) {
            $items[$i] += ['state' => 'pending', 'post_id' => 0, 'post_status' => '', 'same_title' => 0, 'note' => ''];

            $hit = null;
            if (isset($by_url[$item['url']])) {
                $hit = $by_url[$item['url']];
            } elseif (function_exists('esc_url_raw') && isset($by_url[esc_url_raw($item['url'])])) {
                $hit = $by_url[esc_url_raw($item['url'])];
            }
            if ($hit) {
                $claimed[$hit['id']] = true;
                $items[$i]['state']       = 'published';
                $items[$i]['post_id']     = $hit['id'];
                $items[$i]['post_status'] = $hit['status'];
                $items[$i]['note']        = 'همین لینک قبلاً ثبت شده است (پست ' . $hit['id'] . ($hit['status'] !== 'publish' ? ' — ' . $hit['status'] : '') . ').';
                continue;
            }

            $title_key = bm_archive_title_key($item['title']);
            $group     = isset($by_title[$title_key]) ? $by_title[$title_key] : [];
            if (empty($group)) {
                if (isset($pending_titles[$title_key])) {
                    // قطعه‌ی دیگری با همین نام چند ردیف بالاتر در همین فهرست آماده‌ی ارسال است؛ این یکی بعد از آن
                    // عنوان تکراری می‌شود و باید با شماره ثبت شود
                    $items[$i]['state']      = 'dup_title';
                    $items[$i]['same_title'] = 1;
                    $items[$i]['note']       = 'هم‌نام با ردیف ' . $pending_titles[$title_key] . ' همین فهرست؛ با شماره‌ی تمایز مثل «(2)» ثبت می‌شود.';
                } else {
                    $pending_titles[$title_key] = $i + 1;
                    $items[$i]['note'] = 'برای ثبت این مطلب روی دکمه ارسال کلیک کنید.';
                }
                continue;
            }

            $legacy = null;
            foreach ($group as $p) {
                if ($p['source'] === '' && empty($claimed[$p['id']])) {
                    $legacy = $p;
                    break;
                }
            }
            if ($legacy) {
                $claimed[$legacy['id']] = true;
                $items[$i]['state']       = 'legacy';
                $items[$i]['post_id']     = $legacy['id'];
                $items[$i]['post_status'] = $legacy['status'];
                $items[$i]['note']        = 'پستی با همین عنوان از قبل هست (پست ' . $legacy['id'] . '، بدون لینک مبدأ)؛ همین قطعه در نظر گرفته شد.';
                continue;
            }

            $items[$i]['state']      = 'dup_title';
            $items[$i]['same_title'] = 1;
            $items[$i]['post_id']    = $group[0]['id'];
            $items[$i]['note']       = 'قطعه‌ی دیگری با همین نام قبلاً ثبت شده (پست ' . $group[0]['id'] . ')؛ این یکی با شماره‌ی تمایز مثل «(2)» ثبت می‌شود.';
        }

        return $items;
    }

    /**
     * خواندن نتیجه‌ی واقعی از خروجی manual.php
     * ربات در انتهای خروجی نشانگر  <!--BM_RESULT {...} -->  می‌گذارد (status: ok | exists | error).
     * اگر نبود (نسخه‌ی قدیمی ربات یا خطای PHP) از متن تشخیص داده می‌شود.
     * خروجی: ['status', 'reason', 'message', 'post_id', 'link', 'title', 'note', 'html']
     */
    function bm_archive_parse_bot_response($body) {
        $body   = is_string($body) ? $body : '';
        $result = ['status' => 'error', 'reason' => 'unknown', 'message' => '', 'post_id' => 0, 'link' => '', 'title' => '', 'note' => '', 'html' => ''];

        if (preg_match_all('/<!--BM_RESULT\s+(\{.*?\})\s*-->/su', $body, $m) && !empty($m[1])) {
            $data = json_decode(end($m[1]), true);
            if (is_array($data) && !empty($data['status'])) {
                $result['status']  = in_array($data['status'], ['ok', 'exists', 'error'], true) ? $data['status'] : 'error';
                $result['reason']  = isset($data['reason']) ? (string) $data['reason'] : ($result['status'] === 'ok' ? 'created' : 'unknown');
                $result['message'] = isset($data['message']) ? (string) $data['message'] : '';
                $result['post_id'] = isset($data['post_id']) ? (int) $data['post_id'] : 0;
                $result['link']    = isset($data['link']) && is_string($data['link']) ? $data['link'] : '';
                $result['title']   = isset($data['title']) ? (string) $data['title'] : '';
                $result['note']    = isset($data['note']) ? (string) $data['note'] : '';
                if ($result['status'] === 'ok') {
                    $parts = ['پست ' . $result['post_id'] . ' ساخته شد'];
                    if (isset($data['published']) && !$data['published']) {
                        $parts[] = 'ولی منتشر نشد (پیش‌نویس ماند)';
                    }
                    if (array_key_exists('download', $data) && $data['download'] === false) {
                        $parts[] = 'دانلود فایل‌ها روی هاست دانلود ناموفق بود';
                    }
                    if ($result['note'] !== '') {
                        $parts[] = $result['note'];
                    }
                    $result['message'] = implode('؛ ', $parts);
                }
            }
        }

        // متن خوانا برای پنل نتیجه (بدون اسکریپت ریدایرکت و نشانگر)
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body);
        $html = preg_replace('/<!--BM_RESULT\s+\{.*?\}\s*-->/su', '', $html);
        $html = trim((string) $html);

        if ($result['message'] === '' || ($result['status'] === 'error' && $result['reason'] === 'unknown')) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
            if (strpos($text, 'پست با موفقیت ساخته شد') !== false) {
                $result['status'] = 'ok';
                $result['reason'] = 'created';
                if (preg_match('/ID پست:\s*(\d+)/u', $text, $pm)) {
                    $result['post_id'] = (int) $pm[1];
                }
                $result['message'] = 'پست ساخته شد' . ($result['post_id'] ? ' (ID ' . $result['post_id'] . ')' : '');
            } elseif (strpos($text, 'قبلاً ثبت شده') !== false) {
                $result['status'] = 'exists';
                $result['reason'] = 'title';
                if (preg_match('/ID:\s*(\d+)/u', $text, $pm)) {
                    $result['post_id'] = (int) $pm[1];
                }
                $result['message'] = 'قبلاً ثبت شده' . ($result['post_id'] ? ' (ID ' . $result['post_id'] . ')' : '');
            } else {
                $result['status']  = 'error';
                $result['message'] = $text !== '' ? mb_substr($text, 0, 300) : 'پاسخ خالی از سایت';
            }
        }

        // فقط گزارش خود ربات (div گزارش) نمایش داده می‌شود؛ خطاهای خام PHP به شکل متن
        if ($result['status'] === 'ok' && preg_match('#<div[^>]*>\s*<strong>پست با موفقیت ساخته شد\.</strong>.*?</div>#su', $html, $dm)) {
            $result['html'] = $dm[0];
        } else {
            $result['html'] = '';
        }

        return $result;
    }
}
