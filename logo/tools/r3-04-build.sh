#!/bin/bash
# build.sh — regenerate ALL deliverables FROM DupBanner.php (single source of truth).
#   php gen-ansi.php     → logo.ansi (truecolor) + logo-256.ansi + logo-plain.txt
#   php render-gif.php   → frames/fNNN.png (one PNG per tick)
#   convert              → logo.gif (45 ticks, 30 ms each, 600 ms hold on the final)
# then coalesce-verify the GIF and run the full gate (verify.php).
set -euo pipefail
cd "$(dirname "$0")"

php gen-ansi.php
php render-gif.php logo.ansi frames 10 20

# 44 cycling/flash frames at 30 ms each; the hold frame gets 600 ms so the GIF
# ends visibly still (the ANSI product itself is one-shot then holds).
SEQ=$(ls frames/f*.png | sort | grep -v 'f044.png')
convert \( -delay 3 $SEQ \) \( -delay 60 frames/f044.png \) -loop 1 -dither None logo.gif

echo "--- coalesce verify (GIFs store dirty-rect deltas: judge on the coalesced copy) ---"
rm -rf coalesced && mkdir coalesced
convert logo.gif -coalesce coalesced/c%03d.png
N=$(ls coalesced | wc -l)
DIMS=$(identify -format '%wx%h\n' coalesced/*.png | sort -u)
echo "coalesced scenes: $N"
echo "scene dims (must be one uniform full canvas): $DIMS"
[ "$N" -gt 1 ] || {
	echo "FAIL: gif has $N scene(s)"
	exit 1
}
[ "$(printf '%s\n' "$DIMS" | wc -l)" -eq 1 ] || {
	echo "FAIL: ragged scene sizes: $DIMS"
	exit 1
}
echo "logo.gif: $(stat -c %s logo.gif) bytes, raw scenes: $(identify logo.gif | wc -l), canvas: $DIMS"

echo "--- logo.ansi head ---"
head -c 80 logo.ansi | od -c | head -3

echo "--- gate ---"
php verify.php
