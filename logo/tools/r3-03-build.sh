#!/bin/bash
# build.sh — regenerate logo.ansi + logo.gif FROM DupBanner.php (single source
# of truth). Nothing here writes to /home/sites/php-dup-finder.
#
#   ./build.sh
#
#   1. php build.php        -> logo.ansi, logo.256.ansi, logo-static.ansi, logo.plain
#   2. php render-gif.php   -> frames/fNNN.png (one PNG per tick, real DejaVu TTF)
#   3. convert              -> logo.gif (29.4 fps, loops)
#   4. phpstan + psalm      -> level-6 evidence on the authored PHP files
#   5. php verify.php       -> fail-loud constraint asserts
set -euo pipefail
cd "$(dirname "$0")"

REPO=/home/sites/php-dup-finder

php build.php
php render-gif.php logo.ansi frames 12 24
convert -delay 3.4 -loop 1 frames/f*.png -layers optimize -dither None logo.gif

echo "--- coalesce verify ---"
co="$(mktemp -d)"
convert logo.gif -coalesce "$co"/c-%03d.png
n="$(ls "$co"/c-*.png | wc -l)"
minw="$(identify -format '%w\n' "$co"/c-*.png | sort -n | head -1)"
canvas="$(identify -format '%wx%h\n' logo.gif | head -1)"
echo "scenes: $n | canvas: $canvas | min scene width: $minw | GIF bytes: $(stat -c %s logo.gif)"
[ "$n" -gt 1 ] || {
	echo "FAIL: single-scene GIF"
	exit 1
}
[ "$minw" = "${canvas%x*}" ] || {
	echo "FAIL: a scene is smaller than the canvas"
	exit 1
}
rm -rf "$co"

echo "--- phpstan (level 6, DupBanner.php + build.php) ---"
php "$REPO/vendor/bin/phpstan" analyse -c phpstan.neon --no-progress 2>&1 | tail -3
echo "--- psalm (errorLevel 6, same files, repo binary, zero repo-config changes) ---"
php "$REPO/vendor/bin/psalm" --config=psalm.xml --no-cache --no-progress 2>&1 | grep -E "No errors|Found [0-9]+ error|ERROR" || true

echo "--- verify.php ---"
php verify.php
