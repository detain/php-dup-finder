#!/usr/bin/env bash
# build.sh — regenerate ALL art for Concept 8 "Photocopy Drift" FROM DupBanner.php.
set -euo pipefail
cd "$(dirname "$0")"

php -l DupBanner.php
php generate.php                                # logo.ansi (tc) + logo256.ansi + logo.txt
php render.php logo.ansi frames 10 20 '#111827' # 45 PNGs, fixed 56x7-cell canvas
convert -delay 3 -loop 0 frames/frame_*.png logo.gif

echo "--- coalesce verify ---"
identify logo.gif | wc -l | xargs echo "raw scenes:"
convert logo.gif -coalesce co.miff
identify -format "%Wx%H\n" co.miff | sort | uniq -c
identify co.miff | wc -l | xargs echo "coalesced scenes:"
rm -f co.miff
ls -la logo.ansi logo.gif

echo "--- constraint gate ---"
php verify.php
echo "BUILD OK"
