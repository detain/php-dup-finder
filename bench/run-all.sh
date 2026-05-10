#!/usr/bin/env bash
# bench/run-all.sh — one-liner end-to-end benchmark.
#
# Steps:
#   1. ensure bench/tools/phpcpd.phar (offline-friendly: skip on download fail)
#   2. ensure bench/tools/node_modules/.bin/jscpd (skip on npm fail)
#   3. download corpora (skips already-present)
#   4. run all tools across all corpora
#   5. score the synthetic corpus
#
# Idempotent. Safe to run repeatedly.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="${ROOT}/bench/tools"
mkdir -p "${TOOLS}"

LABEL="${LABEL:-run-$(date +%Y%m%d-%H%M%S)}"

# 1. phpcpd phar
if [ ! -f "${TOOLS}/phpcpd.phar" ]; then
    echo "[bench] downloading phpcpd.phar"
    if timeout 30 wget -q https://phar.phpunit.de/phpcpd.phar -O "${TOOLS}/phpcpd.phar"; then
        chmod +x "${TOOLS}/phpcpd.phar"
    else
        echo "[bench] phpcpd download failed — skipping"
        rm -f "${TOOLS}/phpcpd.phar"
    fi
fi

# 2. jscpd via npm — only if node is on PATH and bench/tools/node_modules
# isn't already populated.
if command -v npm >/dev/null && [ ! -x "${TOOLS}/node_modules/.bin/jscpd" ]; then
    echo "[bench] installing jscpd"
    if ! ( cd "${TOOLS}" && timeout 120 npm install --silent --no-fund --no-audit jscpd@4 2>&1 | tail -5 ); then
        echo "[bench] jscpd install failed — skipping"
    fi
fi

# 3. corpora
echo "[bench] preparing corpora"
php "${ROOT}/bench/corpora.php"

# 4. run
echo "[bench] running tools (label=${LABEL})"
php "${ROOT}/bench/run.php" --label="${LABEL}"

# 5. score
echo "[bench] scoring synthetic corpus"
php "${ROOT}/bench/score.php" "${ROOT}/bench/results/${LABEL}.json"

echo
echo "[bench] done — see:"
echo "    ${ROOT}/bench/results/latest.md"
echo "    ${ROOT}/bench/results/detection-rate.md"
