#!/usr/bin/env python3
"""RastChin product cover (2100×1040) for Hamrang, composed procedurally from real engine output.

Usage:  python3 make_cover.py [cover1.jpg cover2.jpg cover3.jpg]
        (three watermarked sample covers, square, produced by the plugin engine on assets/samples/*.jpg
        with assets/brand/sample-logo-white.png; defaults to cover/demo-{1,2,3}.jpg)
Writes: cover/rastchin-cover-2100x1040.jpg  (+ a half-size preview)
"""
import os
import sys

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from make_brand import NAVY, STOPS, font, gradient, reshape, text_image, gradient_text  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'cover')
W, H = 2100, 1040
SAFE_X, SAFE_Y = 210, 104  # 10 % safe padding required by RastChin
MARK = os.path.join(HERE, 'logo', 'hamrang-mark-1000.png')


def glow(size, center, radius, color, alpha):
    """Soft radial glow as an RGBA layer."""
    w, h = size
    ys, xs = np.mgrid[0:h, 0:w].astype(np.float32)
    d = np.sqrt((xs - center[0]) ** 2 + (ys - center[1]) ** 2) / radius
    a = np.clip(1.0 - d, 0, 1) ** 2 * alpha * 255
    layer = Image.new('RGBA', size, color + (0,))
    layer.putalpha(Image.fromarray(a.astype(np.uint8), 'L'))
    return layer


def background():
    bg = gradient((W, H), [(0.0, (30, 34, 66)), (1.0, (20, 23, 46))], (0, 0), (W, H)).convert('RGBA')
    bg.alpha_composite(glow((W, H), (330, 820), 820, (106, 90, 224), 0.42))
    bg.alpha_composite(glow((W, H), (1750, 120), 700, (255, 138, 91), 0.22))
    bg.alpha_composite(glow((W, H), (1150, 980), 500, (240, 80, 140), 0.16))
    # fine dot grid for texture
    dots = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(dots)
    for y in range(40, H, 40):
        for x in range(40, W, 40):
            d.ellipse((x - 1.2, y - 1.2, x + 1.2, y + 1.2), fill=(255, 255, 255, 18))
    bg.alpha_composite(dots)
    return bg


def card(path, size, radius, angle):
    """Rounded cover card with a soft drop shadow, rotated; returns RGBA layer and its anchor offset."""
    im = Image.open(path).convert('RGB').resize((size, size), Image.LANCZOS)
    mask = Image.new('L', (size * 2, size * 2), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, size * 2 - 1, size * 2 - 1), radius * 2, fill=255)
    mask = mask.resize((size, size), Image.LANCZOS)
    pad = 90
    layer = Image.new('RGBA', (size + 2 * pad, size + 2 * pad), (0, 0, 0, 0))
    shadow = Image.new('RGBA', layer.size, (0, 0, 0, 0))
    sh_mask = Image.new('L', layer.size, 0)
    sh_mask.paste(mask, (pad, pad + 26))
    sh_mask = sh_mask.filter(ImageFilter.GaussianBlur(26))
    shadow.putalpha(sh_mask.point(lambda v: int(v * 0.62)))
    layer.alpha_composite(shadow)
    rgba = im.convert('RGBA')
    rgba.putalpha(mask)
    # thin light rim
    rim = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    ImageDraw.Draw(rim).rounded_rectangle((0, 0, size - 1, size - 1), radius, outline=(255, 255, 255, 60), width=2)
    rgba.alpha_composite(rim)
    layer.alpha_composite(rgba, (pad, pad))
    if angle:
        layer = layer.rotate(angle, resample=Image.BICUBIC, expand=True)
    return layer


def paste_center(base, layer, cx, cy):
    base.alpha_composite(layer, (int(cx - layer.width / 2), int(cy - layer.height / 2)))


def main(covers):
    os.makedirs(OUT, exist_ok=True)
    bg = background()

    # ------------------------------------------------------------------ left: stacked real covers
    size = 400
    # front card top-left, the others step down-right so every plate (bottom-right) stays visible;
    # all cards stay inside the 10 % safe area (only their shadows may leave it)
    specs = [  # (path, cx, cy, angle) — drawn back to front
        (covers[2], 770, 705, 3),
        (covers[1], 590, 560, -2),
        (covers[0], 412, 412, 2),
    ]
    for path, cx, cy, ang in specs:
        paste_center(bg, card(path, size, 34, ang), cx, cy)

    # ------------------------------------------------------------------ right: copy (RTL, right-aligned)
    right = W - SAFE_X
    y = SAFE_Y + 10

    # mark + small label
    mark = Image.open(MARK).convert('RGBA').resize((104, 104), Image.LANCZOS)
    bg.alpha_composite(mark, (right - 104, y))
    lbl, _ = text_image('افزونه‌ی وردپرس', font('Vazirmatn-Medium.ttf', 36), (201, 204, 227))
    bg.alpha_composite(lbl, (right - 104 - 26 - lbl.width, y + 52 - lbl.height // 2))
    y += 104 + 26

    # title (gradient) + subtitle
    title = gradient_text('هم‌رنگ', font('Vazirmatn-Black.ttf', 236), STOPS)
    bg.alpha_composite(title, (right - title.width, y - 30))
    y += title.height - 40
    sub, _ = text_image('واترمارک هوشمند با پلاک هم‌رنگ کاور', font('Vazirmatn-Bold.ttf', 66), (255, 255, 255))
    bg.alpha_composite(sub, (right - sub.width, y))
    y += sub.height + 34

    # feature lines with gradient bullets
    feats = [
        'رنگ پلاک از خودِ هر تصویر گرفته می‌شود',
        'نگه‌داشتن نسخه‌ی اصلی و بازگردانی آسان',
        'پردازش دسته‌ای کل آرشیو با نوار پیشرفت',
    ]
    f_font = font('Vazirmatn-Medium.ttf', 40)
    bullet = gradient((22, 22), STOPS, (0, 22), (22, 0)).convert('RGBA')
    bmask = Image.new('L', (88, 88), 0)
    ImageDraw.Draw(bmask).ellipse((0, 0, 87, 87), fill=255)
    bullet.putalpha(bmask.resize((22, 22), Image.LANCZOS))
    for t in feats:
        img, _ = text_image(t, f_font, (222, 224, 240))
        bg.alpha_composite(bullet, (right - 22, y + img.height // 2 - 11))
        bg.alpha_composite(img, (right - 22 - 24 - img.width, y))
        y += img.height + 16

    # footer line
    foot, _ = text_image('سازگار با هر قالب  ·  PHP 7.4 تا 8.4  ·  وردپرس 5.8 به بالا', font('Vazirmatn-Regular.ttf', 32), (150, 155, 190))
    bg.alpha_composite(foot, (right - foot.width, H - SAFE_Y - foot.height - 6))

    out = bg.convert('RGB')
    out.save(os.path.join(OUT, 'rastchin-cover-2100x1040.jpg'), quality=93, subsampling=0, optimize=True)
    out.resize((1050, 520), Image.LANCZOS).save(os.path.join(OUT, 'rastchin-cover-preview.jpg'), quality=88)
    print('bottom of text block:', y, '/ safe bottom', H - SAFE_Y)
    print('written', OUT)


if __name__ == '__main__':
    args = sys.argv[1:4]
    if len(args) != 3:
        args = [os.path.join(OUT, 'demo-%d.jpg' % i) for i in (1, 2, 3)]
    main(args)
