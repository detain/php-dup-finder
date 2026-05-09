#!/usr/bin/env bash
# Render all VHS tapes in this directory to docs/media/*.gif.
#
# Run from the repo root:
#
#   docs/tapes/render.sh                # render every tape
#   docs/tapes/render.sh tui-live.tape  # render one
#
# VHS needs a Chromium binary. If your distro's chromium-browser is the
# Snap stub (Ubuntu 22.04+), point CHROME_BIN at any --no-sandbox-capable
# Chrome — Playwright's chrome-headless-shell works well:
#
#   CHROME_BIN=/path/to/chrome-headless-shell docs/tapes/render.sh

set -euo pipefail

cd "$(dirname "$0")/../.."

if ! command -v vhs >/dev/null; then
    echo "vhs not found on PATH. Install: https://github.com/charmbracelet/vhs" >&2
    exit 1
fi

# Build a tiny PATH shim that exposes a sandbox-tolerant chromium-browser
# binary if one was supplied via CHROME_BIN. VHS will pick this up
# transparently — its rod browser launcher only checks PATH.
shim=""
if [ -n "${CHROME_BIN:-}" ] && [ -x "$CHROME_BIN" ]; then
    shim="$(mktemp -d)"
    trap 'rm -rf "$shim"' EXIT
    cat > "$shim/chromium-browser" <<EOF
#!/bin/bash
exec "$CHROME_BIN" --no-sandbox --disable-gpu --headless=new "\$@"
EOF
    chmod +x "$shim/chromium-browser"
    cp "$shim/chromium-browser" "$shim/chromium"
    cp "$shim/chromium-browser" "$shim/google-chrome"
    export PATH="$shim:$PATH"
fi

tapes=()
if [ "$#" -gt 0 ]; then
    for arg in "$@"; do
        if [ -f "docs/tapes/$arg" ]; then
            tapes+=("docs/tapes/$arg")
        elif [ -f "$arg" ]; then
            tapes+=("$arg")
        else
            echo "Tape not found: $arg" >&2
            exit 1
        fi
    done
else
    while IFS= read -r -d '' f; do
        tapes+=("$f")
    done < <(find docs/tapes -maxdepth 1 -name '*.tape' -print0 | sort -z)
fi

mkdir -p docs/media

for tape in "${tapes[@]}"; do
    echo "→ rendering $tape"
    vhs "$tape"
done

echo "✓ wrote $(ls -1 docs/media | wc -l) gif(s) to docs/media/"
