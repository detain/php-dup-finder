#!/bin/bash
# Rebuild the phpdup "Clone Collapse" animated truecolor ANSI logo.
# Deterministic (fixed seed). Requires php with GD+TTF, ImageMagick convert.
#   DUP_DEBUG=1 php build-dup.php   → prints static-frame grid probe
#   DUP_PROBE=NN php build-dup.php  → prints grid of frame NN (cols 40-50)
set -euo pipefail
cd "$(dirname "$0")"
php build-dup.php
convert -delay 4 -loop 0 frames/f_*.png logo.gif
identify -format '%s %wx%h\n' logo.gif | tail -1
ls -la logo.gif logo.ansi logo-still.png
head -c 80 logo.ansi | cat -v
