#!/usr/bin/env bash
# build.sh — regenerate every deliverable FROM DupBanner.php (single source of truth):
#   logo.ansi / logo256.ansi / logo.txt  (php build.php)
#   frames/frame_00..44.png              (php render.php on logo.ansi)
#   logo.gif                             (convert: 45 animated ticks + 6 hold copies, loop 0)
# Then coalesce-verify the GIF: >1 scene and every scene full-canvas.
set -euo pipefail
cd "$(dirname "$0")"

php build.php
php render.php logo.ansi frames 10 20 '#0F1117'

# hold tail: repeat the settled final frame so the loop pauses on the logo
for i in 45 46 47 48 49 50; do
	cp frames/frame_44.png "frames/frame_$i.png"
done

first=$(ls frames/frame_0[0-9].png frames/frame_[1-3][0-9].png frames/frame_4[0-4].png | tr '\n' ' ')
hold=$(ls frames/frame_4[5-9].png frames/frame_50.png | tr '\n' ' ')
convert -delay 3 $first -delay 30 $hold -loop 0 logo.gif
echo "logo.gif: $(stat -c %s logo.gif) bytes"

# verification: coalesce into a scratch dir, every scene must be full canvas
verify=$(mktemp -d)
convert logo.gif -coalesce "$verify/scene-%03d.png"
scenes=$(ls "$verify"/scene-*.png | wc -l)
dims=$(identify -format "%wx%h\n" "$verify"/scene-*.png | sort -u)
echo "coalesced scenes: $scenes (expect 51: 45 ticks + 6 hold)"
echo "unique dims: $dims (expect 560x100)"
[ "$scenes" -gt 1 ] || {
	echo "FAIL: gif not animated"
	exit 1
}
[ "$dims" = "560x100" ] || {
	echo "FAIL: non-uniform scene geometry"
	exit 1
}
rm -rf "$verify"
echo "build.sh: ALL OK"
