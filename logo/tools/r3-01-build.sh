#!/bin/bash
# build.sh — regenerate logo.ansi + logo.gif FROM DupBanner.php (single source
# of truth), then run the static-analysis and constraint gates.
# Nothing here writes to /home/sites/php-dup-finder (read/execute-only).
#
#   ./build.sh
#
# 1. php gen-ansi.php     -> logo.ansi (truecolor, plays+holds), logo.256.ansi, logo.plain, logo-static.ansi
# 2. php render-gif.php   -> frames/fNNN.png  (one PNG per tick, exact braille raster)
# 3. convert              -> logo.gif  (3cs/frame play, 50cs hold on the final scene)
# 4. identify -coalesce   -> scene count + full-canvas proof
# 5. phpstan level 6      -> authored files (repo binary, lab-local config)
# 6. psalm errorLevel 6   -> DupBanner.php (repo binary, lab-local config)
# 7. php verify.php       -> fail-loud constraint asserts
set -euo pipefail
cd "$(dirname "$0")"

REPO=/home/sites/php-dup-finder

php gen-ansi.php
php render-gif.php
convert -delay 3 frames/f*.png -delay 50 "$(ls frames/f*.png | tail -1)" -loop 1 logo.gif

echo "--- coalesce verify ---"
co="$(mktemp -d)"
convert logo.gif -coalesce "$co"/c-%03d.png
echo "coalesced scenes: $(ls "$co"/c-*.png | wc -l)"
echo "distinct dims:"
identify -format "%wx%h\n" "$co"/c-*.png | sort -u
echo "min scene width: $(identify -format "%w\n" "$co"/c-*.png | sort -n | head -1)"
echo "GIF bytes: $(stat -c %s logo.gif)"
rm -rf "$co"

echo "--- phpstan (level 6) ---"
php "$REPO/vendor/bin/phpstan" analyse -c phpstan.neon --no-progress --error-format=table
echo "--- psalm (errorLevel 6) ---"
php "$REPO/vendor/bin/psalm" --config=psalm.xml --no-cache --no-progress

echo "--- verify.php ---"
php verify.php
