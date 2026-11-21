#!/usr/bin/env bash
# build.sh — regenerate the phpdup "MATCH IGNITION" animated truecolor ANSI logo.
# Source of truth is the .ansi animation; the GIF is rendered from it.
set -euo pipefail
cd "$(dirname "$0")"

php generate-dup.php >logo.ansi
php ansi-anim-to-png.php logo.ansi frames

rm -f logo.gif
convert -delay 5 -loop 0 frames/frame_*.png logo.gif

# ---------------- verification (fail loud) ----------------
frames=$(identify -format '%s\n' logo.gif | tail -n1)
px=$(identify -format '%wx%h\n' logo.gif | tail -n1)
size=$(stat -c%s logo.gif)
echo "gif: $((frames + 1)) frames, $size bytes, $px px"

[ "$((frames + 1))" -gt 10 ] || {
	echo "FAIL: gif not animated ($((frames + 1)) frames)"
	exit 1
}
[ "$size" -gt 10240 ] || {
	echo "FAIL: gif too small ($size bytes)"
	exit 1
}
[ -s logo.ansi ] || {
	echo "FAIL: logo.ansi empty"
	exit 1
}
grep -q $'\e\[38;2' logo.ansi || {
	echo "FAIL: no truecolor escapes in logo.ansi"
	exit 1
}
grep -q $'\e\[14A' logo.ansi || {
	echo "FAIL: no frame separators in logo.ansi"
	exit 1
}
echo "OK"
