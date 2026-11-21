# LLM-PASS — stage-2 operator work orders for the audit truth

The adapters are mechanical and stop at certainty. This document is the work
order for the *human/LLM pass* that closes the remainder. **No lane here calls
any external LLM or API** — `llm-packet.php` merely emits the gap list; an
operator runs the pass wherever they choose (their own model, their own
machine). Counts below are the 2026-11-21 capture — regenerate with `--stats`
for current numbers. Result is a new truth JSON, same schema
(`audit-truth/1`), with
`derived_by` suffixed `+ llm-pass <YYYY-MM-DD> <operator>` (bump to
`audit-truth/1.1` only if the reserved `scope` field is materialized).

Work orders (packet records carry exactly these `reasons`):

1. **`claim-gap` (my corpus)** — the report claims *N copies* but the
   LOCATIONS dialect materialized *M ≠ N* ranged members (52 findings). Read
   the actual files at the scan pin (`git -C /home/my archive ad58b221c5 <sub> |
   tar -x -C $TMP`), enumerate the true member ranges, keep the claim text.
2. **`prose-only` (both corpora)** — finding has zero members: its locations
   live only in prose/code blocks. Read the corpus, add members with real
   ranges. Origin: `llm`.
3. **`unresolved-cites` (phlix, 102 tokens)** — bare `` `:NN` `` lines with no
   prior anchor, side tags (`S:212`) before any anchor, and twin-ambiguous
   basenames (`RateLimiter.php` exists in both repos). Disambiguate from
   paragraph context; never coin a path not seen in either manifest.
4. **`rangeless` (both corpora)** — cites without line ranges (my: 399
   rangeless tokens; a subset of the 890). Anchor at file level; optionally
   materialize block ranges by reading the file (preferred for scoring).
5. **`class-unmapped`** — title rules left `??` (my: 25, phlix: 48). Assign the
   §2.1 letter (A exact clone / B shared idiom / C scaffold / D same-intent
   divergent / E repeated literal or config / F cross-repo twin / G
   under-adopted single source), or propose a new letter — record the decision
   in the pass report, not silently in data.
6. **`summary-table heads` (phlix: 149 − 138 = 11)** — findings present only in
   the §1–§6 summary tables, no dossier body. Reconstruct id/title/class from
   the table rows.
7. **`W6 currency`** — 11 `[W6 …]` stamps exist report-wide; 3 ride in parsed
   finding regions and were stripped. Confirm the remaining 8 (summary-section
   stamps) attach to findings *added by* order 6, so scan-era truth stays the
   benchmark truth (the corpora are pinned at the scan commits — a CURED
   finding still counts as a phpdup hit on the pinned tree).

Rules for the pass:

- Only edit `members[]`, `class`, add `scope` (reserved), never delete
  mechanically-derived members that are `verified: true`.
- Every pass-added member: `origin: "llm"`, `verified` from the manifest.
- Keep `source_sha256` — the pass validates against the same pinned bytes.
- Diff-of-truth (mechanical → passed) goes into the operator's pass report;
  the repo stores none of it (privacy gate, README.md).
