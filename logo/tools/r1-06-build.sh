#!/bin/bash
# build.sh — regenerate the phpdup animated logo end to end.
set -euo pipefail
cd "$(dirname "$0")"

rm -rf frames
mkdir -p frames

php generate-dup.php                             # logo.ansi + frames/fNN.png

convert -delay 4 -loop 0 frames/f??.png logo.gif # 40ms/frame, endless loop

echo "--- verify ---"
identify -format 'frames=%s %wx%h\n' logo.gif | tail -1
ls -l logo.gif logo.ansi
test "$(stat -c%s logo.gif)" -gt 10240 && echo "gif size OK (>10KB)"
test -s logo.ansi && echo "ansi nonempty OK"
head -c 80 logo.ansi | cat -v | head -2
