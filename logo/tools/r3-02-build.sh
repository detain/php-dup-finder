#!/bin/bash
# build.sh — regenerate ALL art FROM DupBanner.php (single source of truth).
# Step 0 refreshes normalized.php by running the REAL repo Normalizer (read/
# execute-only against /home/sites/php-dup-finder; nothing in the repo changes).
#
#   ./build.sh
#
#   0. php harvest.php --write   -> normalized.php (frozen Normalizer output)
#   1. php build.php             -> logo.ansi / logo.256.ansi / logo-static.ansi / logo.plain
#   2. php render-gif.php        -> frames/fNNN.png
#   3. convert                   -> logo.gif
#   4. coalesce verify           -> scenes / geometry
#   5. phpstan L6 + psalm L6     -> evidence logs (repo binaries, own configs)
#   6. php verify.php            -> fail-loud constraint asserts
#
# Playback = 44 ticks x 34 ms = 1.496 s one-shot, 29.4 fps, final frame held.
set -eu
cd "$(dirname "$0")"

REPO=/home/sites/php-dup-finder

if [ -f "$REPO/vendor/autoload.php" ]; then
	php harvest.php --write | tail -1
else
	echo "(repo autoload absent - reusing committed normalized.php)" >&2
fi

php build.php
php render-gif.php logo.ansi frames 12 24
convert -delay 4 -loop 1 frames/f*.png -layers optimize -dither None logo.gif

echo "--- coalesce verify ---"
co="$(mktemp -d)"
convert logo.gif -coalesce "$co"/c-%03d.png
echo "coalesced scenes: $(ls "$co"/c-*.png | wc -l)"
echo "distinct dims:"
identify -format "%wx%h\n" "$co"/c-*.png | sort -u
echo "min scene w: $(identify -format "%w\n" "$co"/c-*.png | sort -n | head -1)"
echo "GIF bytes: $(stat -c %s logo.gif)"
rm -rf "$co"

rc=0

echo "--- phpstan (level 6) ---"
if php "$REPO/vendor/bin/phpstan" analyse -c phpstan.neon --no-progress >phpstan.log 2>&1; then
	echo "phpstan: CLEAN"
else
	rc=1
	echo "phpstan: FINDINGS"
fi
grep -E "ERROR|OK|No errors" phpstan.log | tail -2

echo "--- psalm (errorLevel 6) ---"
if php "$REPO/vendor/bin/psalm" --config=psalm.full.xml --no-cache --no-progress >psalm.log 2>&1; then
	echo "psalm: CLEAN"
else
	rc=1
	echo "psalm: FINDINGS"
fi
grep -E "No errors|Found [0-9]+ error" psalm.log | tail -1 || true

echo "--- verify.php ---"
php verify.php || rc=1

exit $rc
