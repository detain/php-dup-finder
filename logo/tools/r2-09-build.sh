#!/usr/bin/env bash
# build.sh — regenerate logo.ansi + logo.gif strictly FROM DupBanner.php
# (the class is the single source of truth). Fail-loud at every step.
set -euo pipefail
cd "$(dirname "$0")"

php -l DupBanner.php >/dev/null
php -l render-ansi.php >/dev/null
php -l render-gif.php >/dev/null

# 1. class -> byte streams
php render-ansi.php

# 2. truecolor stream -> PNG frames (cell 12x24, DejaVu Sans Mono)
rm -rf frames
php render-gif.php logo.ansi frames 12 24

# 3. GIF stitch — delay 3cs keeps the one-shot at <=1.5 s total
#    (45 frames x 0.03 s = 1.35 s; ImageMagick GIF delay is whole 1/100 s)
convert -delay 3 -loop 0 frames/f*.png logo.gif

# 4. coalesce-verify: every scene must be a FULL identical canvas
convert logo.gif -coalesce coalesce-check.gif
identify coalesce-check.gif >identify-scenes.txt
SCENES=$(wc -l <identify-scenes.txt)
UNIQ=$(awk '{print $3}' identify-scenes.txt | sort -u | wc -l)
echo "scenes after coalesce: $SCENES (expect $(php -r 'require "DupBanner.php"; echo Phpdup\Logo\DupBanner::TICKS;'))"
[ "$SCENES" -eq "$(php -r 'require "DupBanner.php"; echo Phpdup\Logo\DupBanner::TICKS;')" ] || {
	echo "FAIL: scene count"
	exit 1
}
[ "$UNIQ" -eq 1 ] || {
	echo "FAIL: scenes are not all full-canvas identical geometry"
	exit 1
}
identify -format 'final canvas %wx%h\n' logo.gif | tail -1

# 5. QA contact sheet of key beats
montage frames/f000.png frames/f008.png frames/f019.png frames/f023.png \
	frames/f029.png frames/f034.png frames/f039.png frames/f044.png \
	-tile 2x4 -geometry +3+3 -background '#0D1117' montage.png

# 6. full constraint gate (also re-asserts logo.ansi == class output)
php verify.php

ls -l logo.ansi logo.gif logo-static.ansi logo-static.txt
echo "BUILD OK"
