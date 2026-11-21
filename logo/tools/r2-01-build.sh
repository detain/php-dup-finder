#!/bin/bash
# build.sh — regenerate logo.ansi + logo.gif FROM DupBanner.php (the single
# source of truth). Nothing here edits /home/sites/php-dup-finder.
#
#   ./build.sh
#
#   1. php build.php        -> logo.ansi, logo.256.ansi, logo-static.ansi, logo.plain
#   2. php render-gif.php   -> frames/fNNN.png  (one PNG per tick)
#   3. convert              -> logo.gif
#   4. phpstan + psalm      -> level-6 evidence on the authored PHP files
#   5. php verify.php       -> fail-loud constraint asserts
#
# ANSI playback = TICKS*TICK_MS = 40*37ms = 1.48s (<= 1.5s budget, <= 30fps).
# GIF delay 4 (=40ms) x 40 frames loops at 1.6s; the terminal one-shot is the cap.
set -euo pipefail
cd "$(dirname "$0")"

REPO=/home/sites/php-dup-finder

php build.php
php render-gif.php logo.ansi frames 12 24
convert -delay 4 -loop 1 frames/f*.png -layers optimize -dither None logo.gif

echo "--- coalesce verify ---"
co="$(mktemp -d)"
convert logo.gif -coalesce "$co"/c-%03d.png
echo "coalesced scenes: $(ls "$co"/c-*.png | wc -l)"
echo "distinct dims:"
identify -format "%wx%h\n" "$co"/c-*.png | sort -u
echo "all full-canvas (min w): $(identify -format "%w\n" "$co"/c-*.png | sort -n | head -1)"
echo "GIF bytes: $(stat -c %s logo.gif)"
rm -rf "$co"

echo "--- phpstan (level 6, all authored files) ---"
php "$REPO/vendor/bin/phpstan" analyse -c phpstan.neon 2>&1 | tail -2
echo "--- psalm (errorLevel 6, all authored files) ---"
php "$REPO/vendor/bin/psalm" --config=psalm.full.xml --no-cache --no-progress 2>&1 | grep -E "No errors|Found [0-9]+ error"

echo "--- verify.php ---"
php verify.php
