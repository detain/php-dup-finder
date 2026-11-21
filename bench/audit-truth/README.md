# bench/audit-truth — derived ground truth from the scan reports

Two deterministic adapters turn the human-written scan reports of the pinned
private corpora into machine-consumable ground truth (`schema.md` defines the
`audit-truth/1` payload). They are stage 1 of the benchmark program
(improve_logic.md §6 / improve_logic_info.md §6; spec = filing f15 §2).

| adapter | source report (read-only) | corpus pinned at |
|---|---|---|
| `extract-my.php`    | `/home/my/refactor.md`          | `/home/my` @ `ad58b221c5…` |
| `extract-phlix.php` | `/home/sites/phlix/refactor.md` | phlix-server @ `375f7992`, phlix-hub @ `ba1ae3b8` |

Paths/digests: `bench/corpus-pins.txt`.

## ⛔ PRIVACY GATE (owner ruling, improve_logic_info.md §6 item 5)

The derived-truth JSON **must never be committed to this repository**. It
embeds file inventories and duplicate-location claims about private customer
codebases. Committed here: the *scripts* and this documentation only. Outputs
live in an operator directory **outside the repo** (the nightly host decides
where; `/tmp/opencode/...` during development). Enforced by simple mechanics:
every output path either defaults outside the repo (`/tmp/<corpus>.truth.json`)
or is required explicitly (`llm-packet.php`), the runbook below passes `--out`
in every invocation, nothing in this directory
writes into the repo, and lane commits are pathspec-only — no truth file ever
enters a staging area. `corpus-pins.txt` deliberately carries paths + commits +
digests and zero content.

## How to run

```bash
OUT=/tmp/opencode/wave1-1121/bench/out          # any dir OUTSIDE the repo
mkdir -p "$OUT"

# 1) pinned manifests — READ-ONLY git plumbing, never checkout/worktree:
git -C /home/my                        ls-tree -r --name-only ad58b221c5266775415f854aec9d8dc1d2d05977 | sort > "$OUT/manifest-my.txt"
git -C /home/sites/phlix/phlix-server  ls-tree -r --name-only 375f7992 | sort > "$OUT/manifest-server.txt"
git -C /home/sites/phlix/phlix-hub     ls-tree -r --name-only ba1ae3b8 | sort > "$OUT/manifest-hub.txt"

# 2) derive truth (add --stats for the parse census):
php bench/audit-truth/extract-my.php    --report=/home/my/refactor.md \
    --manifest="$OUT/manifest-my.txt"   --out="$OUT/my.truth.json"    --stats
php bench/audit-truth/extract-phlix.php --report=/home/sites/phlix/refactor.md \
    --manifest-server="$OUT/manifest-server.txt" --manifest-hub="$OUT/manifest-hub.txt" \
    --out="$OUT/phlix.truth.json" --stats

# 3) score a phpdup JSON report against a truth payload:
php bench/score-audit.php --truth="$OUT/my.truth.json" --report=<phpdup.json> \
    [--prefix include/Api/] [--top=50] [--out="$OUT/audit.md"]

# 4) emit the LLM-pass work packet (mechanical gaps only; see LLM-PASS.md):
php bench/audit-truth/llm-packet.php --my="$OUT/my.truth.json" --phlix="$OUT/phlix.truth.json" \
    --out="$OUT/llm-packet.jsonl"
```

## Honesty law (what the adapters will NOT do)

Deterministic binds only: a path/basename present in exactly one side's pinned
manifest, or an explicit prose anchor. Ambiguous twins, contextless bare lines,
unbalanced brace cross-products and prose-only findings are surfaced via
`needs_llm_pass` / zero-member, never guessed away. `verified: false` means the
cite named a file absent at the scan pin (renamed/shortened after the scan).

## Wiring into the nightly loop (documented, deliberately NOT auto-wired)

`bench/run-all.sh` and `bench/corpora.php` predate this program and are hot
files for other lanes (f15 §8) — this lane does not edit them. The intended
integration for whoever owns those files:

```bash
# after the per-corpus analyze rows of run-all.sh (pinned trees via
# `git -C <repo> archive <pin> | tar -x -C $TMP` outside the repo, workers=1,
# absolute cache_dir outside the repo — do NOT trust --no-cache):
php bench/audit-truth/extract-my.php    … --out="$OUT/my.truth.json"
php bench/audit-truth/extract-phlix.php … --out="$OUT/phlix.truth.json"
php bench/score-audit.php --truth="$OUT/my.truth.json"    --report="$OUT/run-my.json"    --out="$OUT/audit-my.md"
php bench/score-audit.php --truth="$OUT/phlix.truth.json" --report="$OUT/run-phlix.json" --out="$OUT/audit-phlix.md"
```

Full-corpus runs (689 / 1,030 php files serial) are nightly-host work
(f15 tier 3, >18 min budget) — not lane CI.
