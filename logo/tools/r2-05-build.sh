#!/usr/bin/env bash
# build.sh — regenerate logo.ansi + logo.gif FROM DupBanner.php (single source
# of truth), then fail-loud verify: determinism, bounds, fallbacks, coalesce.
set -euo pipefail
cd "$(dirname "$0")"

php tools/gen-ansi.php logo.ansi
php tools/render-frames.php logo.ansi frames 12 24

rm -f logo.gif
convert -delay 3.3 -loop 0 frames/f*.png logo.gif

# GIFs store dirty-rect deltas — coalesce before judging frame integrity
rm -rf coalesced && mkdir -p coalesced
convert logo.gif -coalesce coalesced/c%03d.png

SCENES=$(ls coalesced/c*.png | wc -l)
[ "$SCENES" -gt 1 ] || {
	echo "FAIL: coalesced scene count $SCENES (want > 1)"
	exit 1
}
GEOM=$(identify -format "%wx%h\n" coalesced/c*.png | sort -u)
NTH=$(printf '%s\n' "$GEOM" | sed '/^$/d' | wc -l)
[ "$NTH" -eq 1 ] || {
	echo "FAIL: scenes not full-canvas uniform: $GEOM"
	exit 1
}
echo "gif OK: $SCENES scenes, every scene $(printf '%s' "$GEOM" | sed '/^$/d' | head -1)"

php verify.php
echo "BUILD COMPLETE: logo.ansi + logo.gif regenerated from DupBanner.php"
