#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Synthetic "album cover" sample images for the live preview in the Hamrang settings page.
Three moods: vivid / dark / light (the light one triggers the dark-logo variant).

    python3 make_samples.py   → ../../hamrang-watermark/assets/samples/sample-{1,2,3}.jpg
"""
import math
import os
import random

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.normpath(os.path.join(HERE, '..', '..', 'hamrang-watermark', 'assets', 'samples'))
SIZE = 720
S = 2


def radial(size, inner, outer, cx=0.5, cy=0.5):
    w = h = size
    ys, xs = np.mgrid[0:h, 0:w].astype(np.float32)
    d = np.sqrt(((xs / w) - cx) ** 2 + ((ys / h) - cy) ** 2) / 0.75
    d = np.clip(d, 0, 1)[..., None]
    col = np.array(inner, np.float32) * (1 - d) + np.array(outer, np.float32) * d
    return Image.fromarray(col.clip(0, 255).astype(np.uint8), 'RGB')


def grain(img, amount=6, seed=1):
    rng = np.random.default_rng(seed)
    arr = np.asarray(img).astype(np.int16)
    arr += rng.integers(-amount, amount + 1, arr.shape, dtype=np.int16)
    return Image.fromarray(arr.clip(0, 255).astype(np.uint8), 'RGB')


def blob(draw, cx, cy, r, color, points=18, wobble=0.18, seed=0):
    rnd = random.Random(seed)
    pts = []
    for i in range(points):
        a = 2 * math.pi * i / points
        rr = r * (1 + rnd.uniform(-wobble, wobble))
        pts.append((cx + rr * math.cos(a), cy + rr * math.sin(a)))
    draw.polygon(pts, fill=color)


def sample_vivid():
    W = SIZE * S
    base = radial(W, (255, 196, 90), (178, 36, 96), 0.3, 0.25)
    layer = Image.new('RGBA', (W, W), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    blob(d, W * 0.68, W * 0.62, W * 0.34, (36, 110, 190, 215), seed=3)
    blob(d, W * 0.25, W * 0.78, W * 0.24, (255, 88, 60, 200), seed=5)
    blob(d, W * 0.62, W * 0.28, W * 0.14, (255, 230, 120, 230), seed=7)
    base = Image.alpha_composite(base.convert('RGBA'), layer).filter(ImageFilter.GaussianBlur(W * 0.012))
    d = ImageDraw.Draw(base)
    for i in range(7):  # thin diagonal lines, like light streaks
        x = W * (0.1 + 0.12 * i)
        d.line([(x, 0), (x + W * 0.35, W)], fill=(255, 255, 255, 38), width=int(W * 0.006))
    img = base.convert('RGB').resize((SIZE, SIZE), Image.LANCZOS)
    return grain(img, 5, 11)


def sample_dark():
    W = SIZE * S
    base = radial(W, (26, 42, 64), (6, 8, 14), 0.5, 0.42)
    layer = Image.new('RGBA', (W, W), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    # glowing arc
    for k in range(10, 0, -1):
        a = int(60 * (1 - k / 10) ** 2) + 8
        d.ellipse((W * 0.18 - k * 6, W * 0.22 - k * 6, W * 0.82 + k * 6, W * 0.86 + k * 6), outline=(40, 220, 200, a), width=int(W * 0.012))
    d.ellipse((W * 0.18, W * 0.22, W * 0.82, W * 0.86), outline=(120, 255, 230, 230), width=int(W * 0.006))
    blob(d, W * 0.5, W * 0.54, W * 0.17, (30, 160, 150, 120), seed=2)
    base = Image.alpha_composite(base.convert('RGBA'), layer).filter(ImageFilter.GaussianBlur(W * 0.003))
    img = base.convert('RGB').resize((SIZE, SIZE), Image.LANCZOS)
    return grain(img, 7, 22)


def sample_light():
    W = SIZE * S
    base = radial(W, (252, 248, 240), (222, 214, 206), 0.4, 0.35)
    layer = Image.new('RGBA', (W, W), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    blob(d, W * 0.36, W * 0.42, W * 0.28, (250, 205, 190, 180), seed=4)
    blob(d, W * 0.66, W * 0.60, W * 0.22, (190, 215, 240, 170), seed=6)
    blob(d, W * 0.58, W * 0.30, W * 0.10, (255, 240, 170, 200), seed=8)
    base = Image.alpha_composite(base.convert('RGBA'), layer).filter(ImageFilter.GaussianBlur(W * 0.02))
    d = ImageDraw.Draw(base)
    d.rectangle((W * 0.12, W * 0.12, W * 0.88, W * 0.88), outline=(90, 90, 100, 60), width=int(W * 0.004))
    img = base.convert('RGB').resize((SIZE, SIZE), Image.LANCZOS)
    return grain(img, 4, 33)


def main():
    os.makedirs(OUT, exist_ok=True)
    for i, fn in enumerate((sample_vivid, sample_dark, sample_light), 1):
        img = fn()
        path = os.path.join(OUT, 'sample-%d.jpg' % i)
        img.save(path, quality=84, optimize=True, progressive=True)
        print('wrote', path, os.path.getsize(path), 'bytes')


if __name__ == '__main__':
    main()
