#!/usr/bin/env bash
# build.sh — regenerate the phpdup animated truecolor logo end to end.
set -euo pipefail
cd "$(dirname "$0")"

php make-logo.php # -> logo.ansi + frames/frame_*.png
rm -f logo.gif
convert -delay 5 -loop 0 frames/frame_*.png logo.gif # ~1.8 s looping gif

echo "--- gif frames (index WxH) ---"
identify -format '%s %wx%h\n' logo.gif | tail -1
ls -l logo.gif logo.ansi
