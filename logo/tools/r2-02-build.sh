#!/usr/bin/env bash
# build.sh — regenerate logo.ansi + logo.gif FROM DupBanner.php (the single source of
# truth), then run the fail-loud gates and the static-analysis evidence capture.
set -euo pipefail
cd "$(dirname "$0")"

REPO=/home/sites/php-dup-finder   # binaries used from repo vendor, config untouched

php tools/build-logo.php            # -> logo.ansi + frames/frame_*.png (0..44 motion, 45..54 hold)

MOTION=$(printf 'frames/frame_%04d.png ' $(seq 0 44))
HOLD=$(printf 'frames/frame_%04d.png ' $(seq 45 54))
rm -f logo.gif
# 30 fps motion = delay 3 (30 ms/frame → 1.35 s one-shot) + ~2.5 s hold tail
convert \( -delay 3 $MOTION \) \( -delay 25 $HOLD \) -loop 0 logo.gif

echo "--- gif scenes (coalesced) ---"
identify -format '%s\n' logo.gif | tail -1        # last scene index (55 scenes total)
convert logo.gif -coalesce info: | awk '{print $3}' | sort -u   # must be one line: 784x196

php verify.php                       # fail-loud constraint gates, exit 0 required

echo "--- static analysis (evidence -> static-analysis.txt) ---"
{
  echo "== phpstan (repo binary) level 6 =="
  "$REPO/vendor/bin/phpstan" analyse --level 6 --no-progress \
    --autoload-file="$REPO/vendor/autoload.php" --autoload-file=DupBanner.php \
    --memory-limit=512M DupBanner.php tools/build-logo.php verify.php || true
  echo
  echo "== psalm (repo binary) errorLevel 6 (psalm-config.xml local) =="
  "$REPO/vendor/bin/psalm" --config=psalm-config.xml --no-cache --no-progress \
    --show-info=true DupBanner.php tools/build-logo.php verify.php || true
} > static-analysis.txt 2>&1
tail -3 static-analysis.txt
ls -l logo.ansi logo.gif
