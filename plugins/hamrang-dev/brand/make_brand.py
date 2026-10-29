#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Brand asset generator for «هم‌رنگ» (Hamrang) — the smart cover-watermark plugin.

Everything is drawn procedurally (Pillow + numpy), so the files can be regenerated
or re-coloured at any time:

    python3 make_brand.py            # writes logo/, icon/ and ../hamrang-watermark/assets/brand/

Fonts: Vazirmatn (SIL OFL 1.1) — see fonts/OFL.txt.
"""
import math
import os
import sys

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

try:
    import arabic_reshaper
    from bidi.algorithm import get_display
except ImportError:  # pragma: no cover
    print('pip install arabic_reshaper python-bidi', file=sys.stderr)
    raise

HERE = os.path.dirname(os.path.abspath(__file__))
FONT_DIR = os.path.join(HERE, 'fonts')
OUT_LOGO = os.path.join(HERE, 'logo')
OUT_ICON = os.path.join(HERE, 'icon')
PLUGIN_BRAND = os.path.normpath(os.path.join(HERE, '..', '..', 'hamrang-watermark', 'assets', 'brand'))

# ----------------------------------------------------------------------------- palette
NAVY = (27, 31, 59)          # #1B1F3B  text / dark surfaces
INK = (84, 90, 120)          # #545A78  secondary text
STOPS = [                    # tile gradient, top-left → bottom-right
    (0.00, (255, 138, 91)),  # #FF8A5B coral
    (0.52, (240, 80, 140)),  # #F0508C pink
    (1.00, (106, 90, 224)),  # #6A5AE0 violet
]
S = 4                        # supersampling factor for raster output

# ----------------------------------------------------------------------------- helpers

def reshape(text):
    cfg = arabic_reshaper.ArabicReshaper({'support_zwj': True, 'delete_harakat': False})
    shaped = cfg.reshape(text)
    shaped = shaped.replace('\u200c', '')  # joining already resolved; drop the ZWNJ glyph gap
    return get_display(shaped)


def font(name, size):
    return ImageFont.truetype(os.path.join(FONT_DIR, name), int(size))


def gradient(size, stops, p0, p1):
    """Linear RGB gradient over `size` from point p0 to p1 (in pixels)."""
    w, h = size
    ys, xs = np.mgrid[0:h, 0:w].astype(np.float32)
    dx, dy = p1[0] - p0[0], p1[1] - p0[1]
    length = dx * dx + dy * dy
    t = ((xs - p0[0]) * dx + (ys - p0[1]) * dy) / length
    t = np.clip(t, 0.0, 1.0)
    out = np.zeros((h, w, 3), dtype=np.float32)
    for (ta, ca), (tb, cb) in zip(stops[:-1], stops[1:]):
        seg = np.clip((t - ta) / (tb - ta), 0.0, 1.0)[..., None]
        mask = ((t >= ta) & (t <= tb))[..., None]
        col = np.array(ca, np.float32) * (1 - seg) + np.array(cb, np.float32) * seg
        out = np.where(mask, col, out)
    return Image.fromarray(out.clip(0, 255).astype(np.uint8), 'RGB')


def rounded_mask(size, box, radius):
    m = Image.new('L', size, 0)
    ImageDraw.Draw(m).rounded_rectangle(box, radius=radius, fill=255)
    return m


def ellipse_mask(size, box):
    m = Image.new('L', size, 0)
    ImageDraw.Draw(m).ellipse(box, fill=255)
    return m


def droplet_mask(size, cx, cy, r, tip_y):
    """Teardrop: circle (cx, cy, r) + tangent cone to the tip (cx, tip_y)."""
    m = Image.new('L', size, 0)
    d = ImageDraw.Draw(m)
    d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=255)
    dist = cy - tip_y
    ang = math.acos(r / dist)           # angle between CP and the tangent points
    base = -math.pi / 2                 # straight up
    pts = [(cx, tip_y)]
    a1, a2 = base + ang, base - ang
    # arc from a1 down around the bottom to a2 (going clockwise through +90°)
    steps = 64
    for i in range(steps + 1):
        a = a1 + (2 * math.pi - 2 * ang) * i / steps
        pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    d.polygon(pts, fill=255)
    return m


def composite_color(base, mask, color, alpha=1.0):
    """Paint `color` through `mask` (L) with global alpha onto RGBA `base`."""
    layer = Image.new('RGBA', base.size, color + (0,))
    a = mask.point(lambda v: int(v * alpha))
    layer.putalpha(a)
    return Image.alpha_composite(base, layer)


def vertical_alpha(mask, top, bottom):
    """Scale mask alpha linearly from `top` (y=0) to `bottom` (y=h)."""
    arr = np.asarray(mask, dtype=np.float32) / 255.0
    h = arr.shape[0]
    ramp = np.linspace(top, bottom, h, dtype=np.float32)[:, None]
    return Image.fromarray((arr * ramp * 255).clip(0, 255).astype(np.uint8), 'L')


# ----------------------------------------------------------------------------- the mark

def draw_mark(px=1000, mono=False):
    """Returns RGBA image of the Hamrang mark on a transparent canvas (px × px)."""
    W = px * S
    k = W / 1000.0

    def g(v):
        return v * k

    canvas = Image.new('RGBA', (W, W), (0, 0, 0, 0))

    tile_box = (g(110), g(110), g(890), g(890))
    tile_r = g(200)
    tile_mask = rounded_mask((W, W), tile_box, tile_r)

    plate_box = (g(545), g(545), g(835), g(835))
    plate_r = g(70)
    plate_mask = rounded_mask((W, W), plate_box, plate_r)

    drop = droplet_mask((W, W), g(690), g(716), g(54), g(602))

    if mono:
        # flat black: tile with the plate punched out, droplet solid
        tile = Image.new('RGBA', (W, W), (0, 0, 0, 255))
        hole = Image.fromarray(np.maximum(np.asarray(tile_mask, dtype=np.int16) - np.asarray(plate_mask, dtype=np.int16), 0).astype(np.uint8), 'L')
        canvas = composite_color(canvas, hole, (0, 0, 0))
        canvas = composite_color(canvas, drop, (0, 0, 0))
        return canvas.resize((px, px), Image.LANCZOS)

    # gradient tile
    grad = gradient((W, W), STOPS, (g(110), g(110)), (g(890), g(890))).convert('RGBA')
    grad.putalpha(tile_mask)
    canvas = Image.alpha_composite(canvas, grad)

    # soft bokeh highlights (reads as a picture)
    bokeh = ellipse_mask((W, W), (g(390 - 250), g(400 - 250), g(390 + 250), g(400 + 250)))
    bokeh = Image.fromarray(np.minimum(np.asarray(bokeh), np.asarray(tile_mask)), 'L')
    canvas = composite_color(canvas, bokeh.filter(ImageFilter.GaussianBlur(g(6))), (255, 255, 255), 0.14)
    bokeh2 = ellipse_mask((W, W), (g(610 - 80), g(290 - 80), g(610 + 80), g(290 + 80)))
    canvas = composite_color(canvas, bokeh2, (255, 255, 255), 0.12)

    # plate shadow (clipped to the tile)
    shadow = Image.new('L', (W, W), 0)
    shadow.paste(plate_mask, (0, int(g(12))))
    shadow = shadow.filter(ImageFilter.GaussianBlur(g(16)))
    shadow = Image.fromarray(np.minimum(np.asarray(shadow), np.asarray(tile_mask)), 'L')
    canvas = composite_color(canvas, shadow, (20, 10, 40), 0.28)

    # frosted glass: blur what is under the plate, then tint white
    blurred = canvas.filter(ImageFilter.GaussianBlur(g(14)))
    frosted = Image.composite(blurred, canvas, plate_mask)
    canvas = frosted
    canvas = composite_color(canvas, plate_mask, (255, 255, 255), 0.30)

    # glass edge: 1 px inner rim, brighter at the top
    inset = g(7)
    inner = rounded_mask((W, W), (plate_box[0] + inset, plate_box[1] + inset, plate_box[2] - inset, plate_box[3] - inset), plate_r - inset)
    rim = Image.fromarray(np.maximum(np.asarray(plate_mask, dtype=np.int16) - np.asarray(inner, dtype=np.int16), 0).astype(np.uint8), 'L')
    rim = vertical_alpha(rim, 1.0, 0.45)
    canvas = composite_color(canvas, rim, (255, 255, 255), 0.75)
    sheen = vertical_alpha(inner, 0.16, 0.0)
    canvas = composite_color(canvas, sheen, (255, 255, 255), 1.0)

    # droplet with a whisper of shadow
    dshadow = Image.new('L', (W, W), 0)
    dshadow.paste(drop, (0, int(g(5))))
    dshadow = dshadow.filter(ImageFilter.GaussianBlur(g(6)))
    canvas = composite_color(canvas, dshadow, (60, 20, 90), 0.25)
    canvas = composite_color(canvas, drop, (255, 255, 255), 1.0)

    return canvas.resize((px, px), Image.LANCZOS)


# ----------------------------------------------------------------------------- SVG twins

SVG_COLOR = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" width="1000" height="1000">
  <defs>
    <linearGradient id="hamrang-g" x1="110" y1="110" x2="890" y2="890" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#FF8A5B"/>
      <stop offset="0.52" stop-color="#F0508C"/>
      <stop offset="1" stop-color="#6A5AE0"/>
    </linearGradient>
    <linearGradient id="hamrang-rim" x1="0" y1="545" x2="0" y2="835" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#FFFFFF" stop-opacity="0.85"/>
      <stop offset="1" stop-color="#FFFFFF" stop-opacity="0.35"/>
    </linearGradient>
    <clipPath id="hamrang-clip"><rect x="110" y="110" width="780" height="780" rx="200"/></clipPath>
  </defs>
  <rect x="110" y="110" width="780" height="780" rx="200" fill="url(#hamrang-g)"/>
  <g clip-path="url(#hamrang-clip)">
    <circle cx="390" cy="400" r="250" fill="#FFFFFF" fill-opacity="0.18"/>
    <circle cx="610" cy="290" r="80" fill="#FFFFFF" fill-opacity="0.12"/>
    <rect x="545" y="557" width="290" height="290" rx="70" fill="#140A28" fill-opacity="0.22"/>
  </g>
  <rect x="545" y="545" width="290" height="290" rx="70" fill="#FFFFFF" fill-opacity="0.30"/>
  <rect x="548.5" y="548.5" width="283" height="283" rx="66.5" fill="none" stroke="url(#hamrang-rim)" stroke-width="7"/>
  <path d="{DROP}" fill="#FFFFFF"/>
</svg>
'''

SVG_MENU = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" width="20" height="20">
  <path fill-rule="evenodd" fill="#000000" d="M270 20h460a250 250 0 0 1 250 250v460a250 250 0 0 1-250 250H270A250 250 0 0 1 20 730V270A250 250 0 0 1 270 20zm305 495a85 85 0 0 0-85 85v200a85 85 0 0 0 85 85h200a85 85 0 0 0 85-85V600a85 85 0 0 0-85-85z"/>
  <path fill="#000000" d="{DROP2}"/>
</svg>
'''

SVG_MONO = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" width="20" height="20">
  <path fill-rule="evenodd" fill="#000000" d="M310 110h380a200 200 0 0 1 200 200v380a200 200 0 0 1-200 200H310a200 200 0 0 1-200-200V310a200 200 0 0 1 200-200zm305 435a70 70 0 0 0-70 70v150a70 70 0 0 0 70 70h150a70 70 0 0 0 70-70V615a70 70 0 0 0-70-70z"/>
  <path fill="#000000" d="{DROP}"/>
</svg>
'''


def droplet_path(cx, cy, r, tip_y):
    dist = cy - tip_y
    ang = math.acos(r / dist)
    base = -math.pi / 2
    a1, a2 = base + ang, base - ang
    p1 = (cx + r * math.cos(a1), cy + r * math.sin(a1))
    p2 = (cx + r * math.cos(a2), cy + r * math.sin(a2))
    return 'M%.1f %.1f L%.1f %.1f A%.1f %.1f 0 1 1 %.1f %.1f Z' % (cx, tip_y, p1[0], p1[1], r, r, p2[0], p2[1])


# ----------------------------------------------------------------------------- wordmark / lockups

def text_image(text, fnt, color, pad=4):
    """Render shaped Persian text to a tight RGBA image."""
    shaped = reshape(text)
    tmp = Image.new('L', (10, 10))
    bbox = ImageDraw.Draw(tmp).textbbox((0, 0), shaped, font=fnt)
    w, h = bbox[2] - bbox[0] + 2 * pad, bbox[3] - bbox[1] + 2 * pad
    mask = Image.new('L', (w, h), 0)
    ImageDraw.Draw(mask).text((pad - bbox[0], pad - bbox[1]), shaped, font=fnt, fill=255)
    img = Image.new('RGBA', (w, h), color + (0,))
    img.putalpha(mask)
    return img, mask


def gradient_text(text, fnt, stops):
    img, mask = text_image(text, fnt, (0, 0, 0))
    grad = gradient(img.size, stops, (0, img.size[1]), (img.size[0], 0)).convert('RGBA')
    grad.putalpha(mask)
    return grad


def lockup(dark_bg=False, px_height=400):
    """Horizontal logo: mark on the right, two-tone wordmark + tagline on the left (RTL)."""
    H = px_height * S
    mark = draw_mark(px=int(H * 1.0))  # square, includes its own padding
    big = font('Vazirmatn-Bold.ttf', H * 0.52)
    small = font('Vazirmatn-Medium.ttf', H * 0.14)
    main_color = (255, 255, 255) if dark_bg else NAVY
    sub_color = (200, 204, 224) if dark_bg else INK

    right_part, _ = text_image('هم', big, main_color)           # «هم‌» (final mim)
    left_part = gradient_text('رنگ', big, STOPS)               # «رنگ» in the brand gradient
    gap = int(H * 0.012)
    word_w = right_part.size[0] + gap + left_part.size[0]
    word_h = max(right_part.size[1], left_part.size[1])
    tag, _ = text_image('واترمارک هوشمند تصاویر', small, sub_color)

    text_w = max(word_w, tag.size[0])
    W = mark.size[0] + int(H * 0.02) + text_w + int(H * 0.12)
    canvas = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    # mark on the right edge
    mx = W - mark.size[0]
    canvas.alpha_composite(mark, (mx, 0))
    # word block vertically centred as a group (word + tagline)
    group_h = word_h + int(H * 0.04) + tag.size[1]
    top = (H - group_h) // 2
    right_edge = mx - int(H * 0.02)
    x_right = right_edge - right_part.size[0]
    canvas.alpha_composite(right_part, (x_right, top + (word_h - right_part.size[1]) // 2))
    x_left = x_right - gap - left_part.size[0]
    canvas.alpha_composite(left_part, (x_left, top + (word_h - left_part.size[1]) // 2))
    canvas.alpha_composite(tag, (right_edge - tag.size[0], top + word_h + int(H * 0.04)))
    # trim transparent columns on the left, keep a small margin
    bbox = canvas.getbbox()
    margin = int(H * 0.06)
    canvas = canvas.crop((max(0, bbox[0] - margin), 0, W, H))
    return canvas.resize((canvas.size[0] // S, canvas.size[1] // S), Image.LANCZOS)


def wordmark_only(dark_bg=False, px_height=240):
    H = px_height * S
    big = font('Vazirmatn-Bold.ttf', H * 0.78)
    main_color = (255, 255, 255) if dark_bg else NAVY
    right_part, _ = text_image('هم', big, main_color)
    left_part = gradient_text('رنگ', big, STOPS)
    gap = int(H * 0.02)
    W = right_part.size[0] + gap + left_part.size[0] + 2 * int(H * 0.08)
    canvas = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    xr = W - int(H * 0.08) - right_part.size[0]
    canvas.alpha_composite(right_part, (xr, (H - right_part.size[1]) // 2))
    canvas.alpha_composite(left_part, (xr - gap - left_part.size[0], (H - left_part.size[1]) // 2))
    return canvas.resize((W // S, H // S), Image.LANCZOS)


# ----------------------------------------------------------------------------- main

def save(img, path, **kw):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    img.save(path, optimize=True, **kw)
    print('  wrote', os.path.relpath(path, HERE), img.size)


def main():
    print('Hamrang brand assets')
    mark = draw_mark(1000)
    save(mark, os.path.join(OUT_LOGO, 'hamrang-mark-1000.png'))
    for size in (512, 320, 256, 128, 64, 48, 32):
        save(mark.resize((size, size), Image.LANCZOS), os.path.join(OUT_ICON, 'icon-%d.png' % size))
    # RastChin: 320×320 PNG, symbol centred, ≥10 % safe padding (tile occupies 78 %)
    save(mark.resize((320, 320), Image.LANCZOS), os.path.join(OUT_ICON, 'rastchin-icon-320.png'))

    mono = draw_mark(256, mono=True)
    save(mono, os.path.join(OUT_LOGO, 'hamrang-mark-mono.png'))

    drop = droplet_path(690, 716, 54, 602)
    with open(os.path.join(OUT_LOGO, 'hamrang-mark.svg'), 'w', encoding='utf-8') as fh:
        fh.write(SVG_COLOR.replace('{DROP}', drop))
    with open(os.path.join(OUT_LOGO, 'hamrang-mark-mono.svg'), 'w', encoding='utf-8') as fh:
        fh.write(SVG_MONO.replace('{DROP}', drop))
    print('  wrote logo/hamrang-mark.svg, logo/hamrang-mark-mono.svg')

    save(lockup(False, 400), os.path.join(OUT_LOGO, 'hamrang-logo-fa.png'))
    save(lockup(True, 400), os.path.join(OUT_LOGO, 'hamrang-logo-fa-on-dark.png'))
    save(wordmark_only(False, 240), os.path.join(OUT_LOGO, 'hamrang-wordmark-fa.png'))
    save(wordmark_only(True, 240), os.path.join(OUT_LOGO, 'hamrang-wordmark-fa-on-dark.png'))

    # white version of the mark's silhouette → placeholder logo for the live preview
    white = draw_mark(512, mono=True)
    arr = np.asarray(white).copy()
    arr[..., :3] = 255
    save(Image.fromarray(arr, 'RGBA'), os.path.join(OUT_LOGO, 'hamrang-mark-white.png'))
    arr[..., :3] = 26
    save(Image.fromarray(arr, 'RGBA'), os.path.join(OUT_LOGO, 'hamrang-mark-black.png'))

    # runtime copies used by the plugin itself
    os.makedirs(PLUGIN_BRAND, exist_ok=True)
    save(mark.resize((128, 128), Image.LANCZOS), os.path.join(PLUGIN_BRAND, 'hamrang-mark-128.png'))
    save(Image.fromarray(np.asarray(draw_mark(512, mono=True)).copy() * np.array([0, 0, 0, 1], np.uint8) + np.array([255, 255, 255, 0], np.uint8), 'RGBA'), os.path.join(PLUGIN_BRAND, 'sample-logo-white.png'))
    with open(os.path.join(PLUGIN_BRAND, 'menu-icon.svg'), 'w', encoding='utf-8') as fh:
        fh.write(SVG_MENU.replace('{DROP2}', droplet_path(675, 735, 70, 585)))
    save(lockup(False, 400).resize((lockup(False, 400).size[0] // 2, 200), Image.LANCZOS), os.path.join(PLUGIN_BRAND, 'hamrang-logo-fa.png'))
    print('done')


if __name__ == '__main__':
    main()
