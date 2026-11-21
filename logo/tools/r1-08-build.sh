#!/usr/bin/env bash
# build.sh — regenerate the phpdup animated logo (truecolor ANSI -> GIF).
set -euo pipefail
cd "$(dirname "$0")"

DELAY="${DELAY:-8}" # 1/100s per frame; 8 * 36 frames ~= 2.9s loop

rm -rf frames logo.gif logo.ansi
php gen-dup-logo.php # writes frames/frame_*.png + logo.ansi

# stitch the cell-grid PNGs into a looping animated gif. No layer-crop
# optimisation: keeps every frame full-size so verification is unambiguous.
convert -delay "$DELAY" -loop 0 frames/frame_*.png logo.gif

echo "---- verify ----"
total=$(identify -format '%s\n' logo.gif | tail -1)
echo "frame count: $((total + 1))   loop: 0 (infinite)"
echo "distinct frame sizes:"
identify -format '%wx%h\n' logo.gif | sort -u
printf 'files: '
ls -l logo.gif logo.ansi | awk '{printf "%s(%sB) ", $9, $5}'
echo
