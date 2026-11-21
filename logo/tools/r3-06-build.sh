#!/bin/bash
# build.sh — regenerate logo.ansi + logo.gif FROM DupBanner.php (the single
# source of truth). Nothing here edits /home/sites/php-dup-finder.
#
#   ./build.sh
#
#   1. php build.php        -> logo.ansi, logo.256.ansi, logo-static.ansi, logo.plain
#   2. php render-gif.php   -> frames/fNNN.png  (one PNG per tick)
#   3. hold scenes          -> 10 extra copies of the final frame (GIF "hold")
#   4. convert              -> logo.gif
#   5. phpstan + psalm      -> level-6 evidence on the authored PHP files
#   6. php verify.php       -> fail-loud constraint asserts
#
# ANSI playback = TICKS*TICK_MS = 44*34ms = 1.496s (<= 1.5s budget, 29.4 fps).
# GIF: delay 3 (30 ms) x 44 scenes = 1.32 s + 10 hold scenes = 0.3 s on the
# clean wordmark every loop (terminal one-shot is the duration cap).
set -euo pipefail
cd "$(dirname "$0")"

REPO=/home/sites/php-dup-finder

php build.php
php render-gif.php logo.ansi frames 12 24

# hold: repeat the final scene so the GIF ends every loop on the clean mark
last="$(ls frames/f*.png | sort | tail -1)"
i=0
while [ $i -lt 10 ]; do
	printf -v n '%03d' $((44 + i))
	cp "$last" "frames/f$n.png"
	i=$((i + 1))
done

convert -delay 3 -loop 1 frames/f*.png -layers optimize -dither None logo.gif

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
