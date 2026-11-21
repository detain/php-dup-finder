#!/bin/bash
# build.sh — regenerate ALL deliverables from RadarBanner.php (single source of truth).
#   php 8.1+ with GD/TTF, ImageMagick convert 6.9. Deterministic (fixed seed).
# End-to-end:
#   ./build.sh
# Outputs: logo.ansi (tc stream), logo-256.ansi, logo-still.txt, logo-plain.txt,
#          frames/f_*.png (45), frames256 (QA render), logo.gif (+ coalesce verify).
set -euo pipefail
cd "$(dirname "$0")"

PHPSTAN=/home/sites/php-dup-finder/vendor/bin/phpstan
PSALM=/home/sites/php-dup-finder/vendor/bin/psalm
REPO_AUTOLOAD=/home/sites/php-dup-finder/vendor/autoload.php

# 1) class -> raw ANSI streams + stills
php build.php

# 2) QA: contract asserts (determinism, size caps, fallbacks, SGR purity)
php verify.php

# 3) truecolor animation frames -> PNGs (10x20 px cells, page = screen bg)
php render-frames.php logo.ansi frames 10 20 '#021A0B'
mkdir -p frames256
php render-frames.php logo-256.ansi frames256 10 20 '#021A0B'

# 4) GIF: 30 fps (delay 3), then one extra held final scene at 0.6 s
convert -delay 3 frames/f_0*.png -delay 60 frames/f_045.png -loop 0 logo.gif

# 5) coalesce-verify the GIF (dirty-rect deltas get expanded; every scene
#    must be full canvas, scene count must exceed 1)
rm -rf coalesce
mkdir coalesce
convert logo.gif -coalesce coalesce/s.png
N=$(identify coalesce/s-*.png | wc -l)
G=$(identify -format '%wx%h\n' coalesce/s-*.png | sort -u)
echo "coalesced scenes: $N, unique geometry: $G"
[ "$N" -ge 2 ] || {
	echo "FAIL: gif has $N scenes"
	exit 1
}
[ "$G" = "460x140" ] || {
	echo "FAIL: scene geometry not uniform full canvas: $G"
	exit 1
}
# per-scene delays (1/100 s): first 45 must be 3 (30 fps), final held 60
identify -format '%T\n' logo.gif | uniq -c | tr '\n' ' '
echo

# 6) static analysis (repo binaries, own configs — repo config untouched)
"$PHPSTAN" analyse --no-progress --level 6 --error-format=raw \
	--autoload-file="$REPO_AUTOLOAD" \
	RadarBanner.php build.php verify.php render-frames.php
"$PSALM" --no-cache --config=psalm.xml --no-progress

rm -rf coalesce
echo "BUILD OK: logo.ansi $(stat -c%s logo.ansi) B | logo.gif $(stat -c%s logo.gif) B | $N scenes @ $G"
