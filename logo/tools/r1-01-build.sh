#!/bin/bash
# build.sh — regenerate the whole DUP convergence logo pipeline from scratch.
#   php generate.php   → logo.ansi (truecolor ANSI animation) + logo-static.ansi
#   php render-gif.php → frames/fNNN.png (one PNG per animation frame)
#   convert            → dup-logo.gif (36 frames, 50 ms/frame = 1.8 s loop)
set -euo pipefail
cd "$(dirname "$0")"

php generate.php
php render-gif.php logo.ansi frames 12 24
convert -delay 5 -loop 0 frames/f*.png -dither None dup-logo.gif

echo "--- verify ---"
identify -format '%s %wx%h\n' dup-logo.gif | tail -1
echo "scenes: $(identify dup-logo.gif | wc -l)  size: $(stat -c %s dup-logo.gif) bytes"
echo "ansi bytes: $(stat -c %s logo.ansi)"
head -c 90 logo.ansi | od -c | head -3
