#!/usr/bin/env bash
# build.sh — regenerate every art asset FROM DupBanner.php, then coalesce-
# verify the GIF and the full contract. Never edits tools; everything stays
# in this directory.
set -euo pipefail
cd "$(dirname "$0")"

php make-art.php                    # logo.ansi + logo.256.ansi + logo.plain
php render-gif.php logo.ansi frames # one PNG per tick
rm -f logo.gif
# -delay is in 1/100 s per frame; 34 ms per tick -> 3.4
convert -delay 3.4 -loop 1 frames/f*.png logo.gif

echo "--- GIF coalesce verification ---"
convert logo.gif -coalesce info: | awk '
  NR > 1 { scenes++; if ($3 != dims) { drift++ } }
  NR == 1 { dims = $3 }
  END {
    printf "coalesced scenes=%d full-canvas=%s\n", scenes + 1, dims;
    if (scenes + 1 < 2)   { print "FAIL: single-scene GIF"; exit 1 }
    if (drift + 0 > 0)    { print "FAIL: not every scene fills the canvas"; exit 1 }
  }'
ls -la logo.gif
identify -format "canvas %wx%h\n" logo.gif | head -1

echo "--- contract verification ---"
php verify.php
echo "BUILD OK"
