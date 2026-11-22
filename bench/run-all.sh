#!/usr/bin/env bash
# bench/run-all.sh — one-liner end-to-end benchmark.
#
# Supply-chain law (once-S86 / php-dup-finder-once-108): third-party
# tools and corpora may enter this benchmark ONLY through a version +
# SHA256/commit pin recorded in bench/corpus-pins.txt. Pinned values
# are read from that file (single source of truth, via
# bench/corpora.php's reader), digests are re-verified on every cached
# reuse, and a mismatch ABORTS — unverified bytes are never executed
# and never silently re-used.
#
# Steps:
#   1. ensure bench/tools/phpcpd.phar at the pinned version + SHA256
#      (cached copies re-verified every run; download verified before it
#       is placed; digest mismatch aborts the whole run)
#   2. ensure bench/tools/node_modules/.bin/jscpd at the pinned EXACT
#      version (npm --ignore-scripts, so a bench-only tool install can
#      never execute upstream package lifecycle scripts — once-S11)
#   3. materialize corpora — bench/corpora.php ENFORCES bench/corpus-pins.txt
#      (digest mismatch = hard fail of this script via set -e)
#   4. run all tools across all corpora
#   5. score the synthetic corpus
#
# Idempotent. Safe to run repeatedly.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="${ROOT}/bench/tools"
mkdir -p "${TOOLS}"

LABEL="${LABEL:-run-$(date +%Y%m%d-%H%M%S)}"

# pin <block> <key> — read one value from bench/corpus-pins.txt using the
# same parser the corpora enforcer uses. Missing pin = hard abort: an
# unpinned download is exactly the once-108 disease.
pin() {
	ROOT="${ROOT}" php -r '
        define("PHPDUP_CORPORA_LIB", true);
        require_once getenv("ROOT") . "/bench/corpora.php";
        $pins = readPins(getenv("ROOT") . "/bench/corpus-pins.txt");
        $v = $pins[$argv[1]][$argv[2]] ?? null;
        if ($v === null || $v === "") {
            fwrite(STDERR, "pin missing: [{$argv[1]}] {$argv[2]} in bench/corpus-pins.txt\n");
            exit(1);
        }
        echo $v;' "$1" "$2"
}

verify_sha() { # <file> <expected-sha256> → success when the bytes match
	[ -f "$1" ] || return 1
	local actual
	actual="$(sha256sum "$1" | cut -d' ' -f1)"
	[ "${actual}" = "$2" ]
}

# 1. phpcpd — pinned by version + SHA256, verified on EVERY use
PHPCPD_VERSION="$(pin bench-tools phpcpd-version)"
PHPCPD_URL="$(pin bench-tools phpcpd-url)"
PHPCPD_SHA256="$(pin bench-tools phpcpd-sha256)"
PHPCPD_PHAR="${TOOLS}/phpcpd.phar"

if [ -f "${PHPCPD_PHAR}" ]; then
	if verify_sha "${PHPCPD_PHAR}" "${PHPCPD_SHA256}"; then
		echo "[bench] phpcpd ${PHPCPD_VERSION}: cached phar digest verified"
	else
		echo "[bench] cached phpcpd.phar does NOT match the pinned digest — discarding and re-fetching"
		rm -f "${PHPCPD_PHAR}"
	fi
fi
if [ ! -f "${PHPCPD_PHAR}" ]; then
	echo "[bench] downloading pinned phpcpd ${PHPCPD_VERSION} from ${PHPCPD_URL}"
	if timeout 60 wget -q "${PHPCPD_URL}" -O "${PHPCPD_PHAR}.tmp"; then
		if verify_sha "${PHPCPD_PHAR}.tmp" "${PHPCPD_SHA256}"; then
			mv "${PHPCPD_PHAR}.tmp" "${PHPCPD_PHAR}"
			chmod +x "${PHPCPD_PHAR}"
		else
			# corrupt or swapped artifact: abort loudly, never execute unverified bytes
			rm -f "${PHPCPD_PHAR}.tmp"
			echo "[bench] FATAL: downloaded phpcpd.phar SHA256 != pinned ${PHPCPD_SHA256} — aborting" >&2
			exit 1
		fi
	else
		rm -f "${PHPCPD_PHAR}.tmp"
		echo "[bench] phpcpd download failed — competitor will be skipped (optional tool; verified corpora are NOT optional)" >&2
	fi
fi

# 2. jscpd — EXACT pinned version, --ignore-scripts, only if node is on PATH
JSCPD_VERSION="$(pin bench-tools jscpd-npm)"
if command -v npm >/dev/null; then
	INSTALLED_JSCPD=""
	if [ -x "${TOOLS}/node_modules/.bin/jscpd" ]; then
		INSTALLED_JSCPD="$(cd "${TOOLS}" && node -p "require('./node_modules/jscpd/package.json').version" 2>/dev/null || true)"
	fi
	if [ "${INSTALLED_JSCPD}" = "${JSCPD_VERSION}" ]; then
		echo "[bench] jscpd ${INSTALLED_JSCPD} present (matches pin)"
	else
		if [ -n "${INSTALLED_JSCPD}" ]; then
			echo "[bench] installed jscpd ${INSTALLED_JSCPD} != pinned ${JSCPD_VERSION} — reinstalling"
			rm -rf "${TOOLS}/node_modules"
		fi
		echo "[bench] installing pinned jscpd ${JSCPD_VERSION} (--ignore-scripts)"
		if ! (cd "${TOOLS}" && timeout 120 npm install --silent --no-fund --no-audit --ignore-scripts "jscpd@${JSCPD_VERSION}" 2>&1 | tail -5); then
			echo "[bench] jscpd install failed — competitor will be skipped (optional tool)" >&2
		fi
	fi
fi

# 3. corpora — pin-enforced materialization (digest mismatch exits non-zero
#    and, via set -e, aborts this script: a half-verified corpus never gets
#    scored)
echo "[bench] preparing corpora (pin-enforced against bench/corpus-pins.txt)"
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
