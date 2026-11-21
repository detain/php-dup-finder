#!/usr/bin/env bash
# build.sh — regenerate every artifact FROM DupBanner.php (single source of truth):
#   logo.ansi      raw truecolor animation stream (one-shot, ends on hold)
#   logo-256.ansi  256-color fallback stream
#   frames/*.png   per-tick PNGs via ansi2gif.php (10x20 px cells -> 560x140)
#   logo.gif       one-shot GIF (41 scenes: 40 ticks @30fps + long hold frame)
set -euo pipefail
cd "$(dirname "$0")"

php gen-ansi.php                                  # logo.ansi + logo-256.ansi
php ansi2gif.php logo.ansi frames 10 20 '#0B1020' # frames/f_001..044.png

# ticks 0..39 move; first byte-identical hold frame (tick 40 = f_041) gets a
# long delay so the GIF plays once and rests on the final frame (no loop ext)
convert -delay 3 \
	$(seq -f 'frames/f_%03g.png' 1 40 | tr '\n' ' ') \
	-delay 40 frames/f_041.png \
	logo.gif

# --- gates ---------------------------------------------------------------
# truecolor + size sanity on the stream
[ -s logo.ansi ] && grep -q $'\e\[38;2;' logo.ansi
[ -s logo-256.ansi ] && ! grep -q $'\e\[38;2;' logo-256.ansi

# GIF: every coalesced scene must be the FULL 560x140 canvas
tmpco=$(mktemp -u /tmp/dupcoXXXX).gif
convert logo.gif -coalesce "$tmpco"
scenes=$(identify -format '%n\n' "$tmpco" | tail -1)
dims=$(identify -format '%wx%h\n' "$tmpco" | sort -u)
loop=$(identify -format 'Loop=%X\n' logo.gif | head -1)
rm -f "$tmpco"
echo "gif scenes=$scenes dims='$dims' $loop bytes=$(stat -c %s logo.gif)"
[ "$scenes" -eq 41 ] || {
	echo "FAIL: expected 41 coalesced scenes"
	exit 1
}
[ "$dims" = "560x140" ] || {
	echo "FAIL: not every scene is 560x140"
	exit 1
}

php verify.php
