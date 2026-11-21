#!/usr/bin/env bash
# build.sh — regenerate logo.ansi + logo.gif FROM DupBanner.php (single source
# of truth), then fail-loud verify: cross-process determinism, bounds, budget,
# fallbacks, SGR shape, readout==drawn-discs, GIF coalesce integrity,
# PHPStan L6 + Psalm L6 on this lane's own files (repo binaries, no repo
# config touched).
set -euo pipefail
cd "$(dirname "$0")"
REPO=/home/sites/php-dup-finder

# 1) stream from the class — twice, in two separate processes, byte-compared
php tools/gen-ansi.php logo.ansi
php tools/gen-ansi.php /tmp/jaccard-echo.ansi
cmp -s logo.ansi /tmp/jaccard-echo.ansi || {
	echo "FAIL: cross-process stream bytes differ"
	exit 1
}
echo "streams OK: logo.ansi reproducible byte-for-byte across processes"

# 2) PNG frames (12x24 cell grid: half-block pixels are square)
php tools/render-frames.php logo.ansi frames 12 24

rm -f logo.gif
convert -delay 3.33 -loop 0 frames/f*.png logo.gif

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
SIZE=$(stat -c%s logo.gif)
echo "gif OK: $SCENES scenes, every scene $(printf '%s' "$GEOM" | sed '/^$/d' | head -1), ${SIZE} bytes"

# 3) full battery (needs logo.ansi on disk for the file-vs-frame determinism)
php verify.php

# 4) static analysis — repo binaries over THIS lane's files only
[ -x "$REPO/vendor/bin/phpstan" ] || {
	echo "note: repo vendor missing, skipping static-analysis step"
	exit 0
}
"$REPO/vendor/bin/phpstan" analyse --level 6 --no-progress --autoload-file="$REPO/vendor/autoload.php" \
	DupBanner.php verify.php tools/gen-ansi.php tools/render-frames.php \
	>static-analysis.log 2>&1 || {
	tail -25 static-analysis.log
	exit 1
}
"$REPO/vendor/bin/psalm" -c psalm-local.xml --no-cache --no-progress >>static-analysis.log 2>&1 || {
	tail -25 static-analysis.log
	exit 1
}
grep -q "No errors" static-analysis.log &&
	echo "static OK: phpstan L6 + psalm L6 clean on own files (static-analysis.log)"

echo "BUILD COMPLETE: logo.ansi + logo.gif regenerated from DupBanner.php"
