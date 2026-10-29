<p align="center"><img src="assets/brand/hamrang-logo-fa.png" width="300" alt="هم‌رنگ"></p>

# هم‌رنگ — واترمارک هوشمند تصاویر | Hamrang – Smart Cover Watermark

افزونه‌ی وردپرس برای گذاشتن لوگو روی کاورها، داخل یک **پلاک شیشه‌ای هم‌رنگ هر تصویر**. اصل تصویر نگه داشته می‌شود و هر وقت بخواهید قابل بازگردانی است.

A WordPress plugin that puts your logo on covers inside a **frosted-glass plate coloured by each image's dominant colour**. Originals are kept and can be restored at any time.

| | |
|---|---|
| نسخه / Version | 1.0.0 |
| وردپرس / WordPress | 5.8 – 7.1 |
| PHP | 7.4 – 8.5 (GD required, Imagick not needed) |
| دامنه‌ی متنی / Text domain | `hamrang-watermark` (fa_IR included) |

## امکانات

- **پلاک رنگی تطبیقی:** رنگ پلاک از پررنگ‌ترین رنگ غالب همان تصویر گرفته می‌شود (یا یک رنگ ثابت). شیشه‌ای یا پُر؛ مربع، مستطیل یا بدون پلاک.
- **لوگوی تیره‌ی خودکار** روی پلاک‌ها و گوشه‌های روشن.
- **اعمال خودکار** روی تصاویر شاخص (ویرایشگر، REST API، درون‌ریزها، ربات‌ها)، روی هر آپلود، یا فقط دستی.
- **نگه‌داشتن نسخه‌ی اصلی** کنار هر تصویر و بازگردانی/تغییر ظاهر در آینده؛ بازسازی همه‌ی بندانگشتی‌ها.
- **پردازش دسته‌ای** با نوار پیشرفت برای آرشیو موجود؛ تصاویر به‌روز رد می‌شوند، توقف/ادامه‌ی امن.
- **کتابخانه‌ی رسانه:** ستون وضعیت، لینک‌های ردیف، اقدام گروهی و دکمه‌ها در پنجره‌ی جزئیات.
- **پیش‌نمایش زنده** روی تصاویر نمونه یا تصاویر خودتان.
- مستقل از قالب؛ بدون هیچ ارتباط خارجی؛ رابط فارسی + راهنمای کامل داخل پیشخوان.

## نصب

1. پوشه‌ی `hamrang-watermark` را در `wp-content/plugins/` بگذارید (یا زیپ را از *افزونه‌ها ← افزودن ← بارگذاری* نصب کنید).
2. فعال کنید؛ منوی **هم‌رنگ** به پیشخوان اضافه می‌شود.
3. **هم‌رنگ ← تنظیمات:** لوگوی PNG شفاف را انتخاب کنید، حالت اعمال را بگذارید، ذخیره.
4. **هم‌رنگ ← پردازش دسته‌ای:** آرشیو موجود را یک‌جا واترمارک کنید.

راهنمای کامل: **هم‌رنگ ← راهنما** در پیشخوان (`admin/views/guide-fa.php`).

## برای توسعه‌دهندگان / Developers

```php
hamrang_apply( $attachment_id, $force = false ); // ['status' => applied|skipped|error, 'code', 'message']
hamrang_restore( $attachment_id );               // ['status' => restored|skipped|error, ...]
hamrang_settings();                              // current settings array

add_filter( 'hamrang_should_apply', function ( $apply, $attachment_id, $context ) {
    // $context: upload | featured | batch | manual | api
    return $apply;
}, 10, 3 );
```

- Request parameter `hamrang_skip=1` bypasses stamping in "every upload" mode.
- Attachment meta: `_hamrang_wm` (stamp, time, dimensions, plate info), `_hamrang_original` (stored original, relative to the uploads dir).
- Stored originals use the suffix `-hamrang-orig` and are removed with the attachment.

## ساختار پوشه

```
hamrang-watermark/
├─ hamrang-watermark.php        bootstrap, activation check, public API
├─ uninstall.php                removes settings (and data/originals when opted in)
├─ includes/
│  ├─ engine.php                GD rendering engine (dominant colour, glass plate, shadow, EXIF, WebP/AVIF)
│  ├─ class-hamrang-settings.php
│  ├─ class-hamrang-media.php   upload / featured hooks, apply, restore, backups
│  ├─ class-hamrang-batch.php   AJAX batch endpoints
│  └─ class-hamrang-admin.php   menu, settings page, preview, media library integration
├─ admin/{views,css,js}/
├─ assets/{brand,samples}/
└─ languages/                   .pot + fa_IR .po/.mo
```

Development helpers (brand generator, sample generator, i18n builder) live in `../hamrang-dev/` and are not shipped.

## License

GPL-2.0-or-later.
