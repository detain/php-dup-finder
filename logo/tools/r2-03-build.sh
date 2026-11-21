#!/usr/bin/env bash
# build.sh — regenerate logo.ansi + logo.gif FROM TreeGrowthBanner.php
# (the class is the single source of truth).
#
#   ./build.sh            canonical seed 0
#   SEED=2 ./build.sh     variant (spacing-4 leaves)
#
# Outputs in this dir: logo.ansi (truecolor stream), logo-256.ansi,
# logo-static.txt (zero-escape fallback), frames/f_*.png, logo.gif.
set -euo pipefail
cd "$(dirname "$0")"
SEED=${SEED:-0}
CELL_W=${CELL_W:-10}
CELL_H=${CELL_H:-20}
BG=${BG:-'#0b0e14'}

php emit.php "$SEED"
php ansi2gif.php logo.ansi frames "$CELL_W" "$CELL_H" "$BG" # -> frames/f_001.png ...
pngs=$(ls frames/f_*.png | wc -l)

convert -delay 4 -loop 0 frames/f_*.png logo.gif # 25 fps GIF ≤ 30 fps cap

# --- GIF verification on a COALESCED copy (GIFs store dirty-rect deltas;
#     uncoalesce-d judgement is a dirty-rect artifact, not truth)
rm -f .coalesce-check.gif
convert logo.gif -coalesce .coalesce-check.gif
scenes=$(identify .coalesce-check.gif | wc -l)
dims=$(identify -format '%wx%h\n' .coalesce-check.gif | sort -u)
bytes=$(stat -c %s logo.gif)
expect_px=$((CELL_W * 56))x$((CELL_H * 7))

echo "--- verify ---"
echo "png frames: $pngs | gif scenes (coalesced): $scenes | every scene: $dims | logo.gif: $bytes bytes | logo.ansi: $(stat -c %s logo.ansi) bytes"

[ "$scenes" -gt 1 ] || {
	echo "FAIL: gif is not animated"
	exit 1
}
[ "$scenes" -eq "$pngs" ] || {
	echo "FAIL: gif scenes != rendered png frames"
	exit 1
}
[ "$(echo "$dims" | wc -l)" -eq 1 ] || {
	echo "FAIL: coalesced scenes have mixed canvas sizes: $dims"
	exit 1
}
[ "$dims" = "$expect_px" ] || {
	echo "FAIL: full-canvas render expected $expect_px per scene, got $dims"
	exit 1
}
[ "$bytes" -gt 10240 ] || {
	echo "FAIL: logo.gif suspiciously small (likely blank)"
	exit 1
}
[ -s logo.ansi ] || {
	echo "FAIL: logo.ansi empty"
	exit 1
}
grep -q $'\e\[38;2;' logo.ansi || {
	echo "FAIL: logo.ansi has no truecolor SGR"
	exit 1
}

# --- fail-loud PHP gates (in-process + cross-invocation determinism)
php verify.php
php verify.php --store
php verify.php --cross

rm -f .coalesce-check.gif
echo "OK: logo.gif ($scenes coalesced scenes @ $dims, $bytes bytes) + logo.ansi + fallbacks, all gates green"
