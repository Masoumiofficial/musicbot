#!/usr/bin/env bash
# Build the distributable zip: plugins/releases/hamrang-watermark-<version>.zip (folder name inside: hamrang-watermark/)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/../hamrang-watermark"
VERSION="$(grep -m1 '^ \* Version:' "$SRC/hamrang-watermark.php" | awk '{print $3}')"
OUT_DIR="$HERE/../releases"
OUT="$OUT_DIR/hamrang-watermark-$VERSION.zip"
mkdir -p "$OUT_DIR"
rm -f "$OUT"
TMP="$(mktemp -d)"
cp -r "$SRC" "$TMP/hamrang-watermark"
find "$TMP/hamrang-watermark" -name '.DS_Store' -delete
( cd "$TMP" && zip -qr -X "$OUT" hamrang-watermark )
rm -rf "$TMP"
ls -la "$OUT"
unzip -l "$OUT" | tail -1
