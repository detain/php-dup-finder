#!/usr/bin/env bash
# Lens over Repeated Code — phpdup animated truecolor ANSI logo.
# Regenerates frames/, logo.ansi, logo-256.ansi, logo-final.png, logo.gif
# FROM the class (LensBanner.php is the single source of truth).
set -euo pipefail
cd "$(dirname "$0")"

php build-frames.php

convert -delay 3 -loop 0 frames/f-*.png logo.gif

echo "--- gif verification (coalesced) ---"
convert logo.gif -coalesce /tmp/idea10-coalesce-%02d.png
N=$(ls /tmp/idea10-coalesce-*.png | wc -l)
FULLS=$(identify -format '%wx%h\n' /tmp/idea10-coalesce-*.png | sort -u | wc -l)
CANVAS=$(identify -format '%wx%h\n' /tmp/idea10-coalesce-*.png | head -1)
rm -f /tmp/idea10-coalesce-*.png
echo "coalesced scenes=${N} unique-geometry-count=${FULLS} (1 = every scene full canvas) canvas=${CANVAS}"
echo "gif scenes: $(identify logo.gif | wc -l)  size: $(identify -format '%wx%h\n' logo.gif | head -1)  bytes: $(stat -c%s logo.gif)"
ls -l logo.gif logo.ansi logo-256.ansi logo-final.png

echo "--- verify ---"
php verify.php
head -c 80 logo.ansi | cat -v | head -2
