#!/usr/bin/env bash
# build.sh — regenerate the whole "Clone Collapse" phpdup logo pipeline:
#   design → logo.ansi (truecolor) → per-frame PNGs (GD + DejaVuSansMono) → logo.gif
set -euo pipefail
cd "$(dirname "$0")"

BG='#0C0B14'
FONT=/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf

php gen-dup.php # → logo.ansi (+ width/height verify)

rm -rf png
php render-frames.php logo.ansi png "$BG" 12 24 "$FONT" # → png/f-NN.png + logo-still.png

# 40ms/frame ≈ 1.6s loop; infinite loop; optimize keeps size down
convert -delay 4 -loop 0 png/f-*.png -layers optimize logo.gif

echo "--- verify ---"
identify -format '%s %wx%h\n' logo.gif | tail -1
ls -l logo.gif logo.ansi logo-still.png
