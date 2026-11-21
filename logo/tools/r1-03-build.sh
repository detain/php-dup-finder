#!/bin/sh
# build.sh — one-shot pipeline: ANSI frames → PNGs → animated truecolor GIF.
set -e
cd "$(dirname "$0")"

php gen-dup.php # writes logo.ansi + frames/f*.ansi
rm -rf png && mkdir png
php render-gif.php logo.ansi png 12 24 16 # writes png/f00.png … (42 frames)
rm -f logo.gif
convert -delay 4 -loop 0 png/f*.png logo.gif # 25 fps, infinite loop

echo "--- verify ---"
ls -l logo.gif logo.ansi
printf 'gif scenes: %s   last scene: ' "$(identify -format '%n\n' logo.gif | head -1)"
identify -format '%s %wx%h\n' logo.gif | tail -1
head -c 24 logo.ansi | od -c | head -2
