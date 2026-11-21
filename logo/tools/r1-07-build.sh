#!/usr/bin/env bash
# build.sh — regenerate the phpdup "DIFF ALIGN" animated logo.
#   logo.ansi  : raw truecolor ANSI animation (cat-able)
#   logo.gif   : final looping GIF (frames @ 25 fps via -delay 4)
set -euo pipefail
cd "$(dirname "$0")"

php dupgen.php                                    # -> logo.ansi (40 frames)
php ansi2gif.php logo.ansi frames 10 20 '#000000' # -> frames/f_001.png ...

convert -delay 4 -loop 0 frames/f_*.png logo.gif

echo "--- verify ---"
frames=$(identify -format '%s\n' logo.gif | tail -1)
size=$(stat -c %s logo.gif)
echo "gif frames: $frames | bytes: $size"
identify -format '%s %wx%h\n' logo.gif | tail -1
[ "$frames" -ge 10 ] || {
	echo "FAIL: gif not animated"
	exit 1
}
[ "$size" -gt 10240 ] || {
	echo "FAIL: gif too small (likely blank)"
	exit 1
}
[ -s logo.ansi ] || {
	echo "FAIL: logo.ansi empty"
	exit 1
}
grep -q $'\e\[' logo.ansi || {
	echo "FAIL: no escapes in logo.ansi"
	exit 1
}
echo "OK: logo.gif ($frames frames, $size bytes) + logo.ansi"
