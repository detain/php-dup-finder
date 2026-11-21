#!/usr/bin/env bash
# Clone Collapse — phpdup animated truecolor ANSI logo.
# Regenerates frames + logo.gif + logo.ansi + logo-still.png.
set -euo pipefail
cd "$(dirname "$0")"

php generate-dup.php # -> frames/f-XX.png, logo.ansi, logo-still.png

convert -delay 4 -loop 0 frames/f-*.png logo.gif

echo "--- verify ---"
identify -format 'gif frames=%s size=%wx%h\n' logo.gif | tail -1
ls -l logo.gif logo.ansi logo-still.png
head -c 60 logo.ansi | cat -v | head -2
