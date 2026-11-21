# phpdup Logo Lab Results — 30 animated truecolor ANSI logos

Artifacts of the **dup-logo lab program** (rounds 1–3, generated 2026-11-20/21 by
thirteen-seat read-only research lanes). Per the owner's ruling these are
**logos only**: nothing is wired into the tool. The generator scripts were
harvested from the lab dirs on 2026-11-21 into [`tools/`](tools/TOOLS.md) —
renamed per seat (`r<round>-<seatNN>-<original-name>`), with provenance,
purposes and the borrowed-stock/exclusion rules documented in
[`tools/TOOLS.md`](tools/TOOLS.md); the lab originals under `/tmp/opencode/`
remain the live sources (origins in the table below).

## Layout

```
logo/
  NN-slug/         one directory per seat concept (30 total)
    logo.gif        animated render (loops; coalesce-verified full-canvas scenes)
    logo.ansi       raw truecolor ANSI stream — the source of truth
    logo-256.ansi   xterm-256-colour fallback stream (round 2/3 seats, where the lab emitted one)
    logo-plain.txt  zero-escape plain-text fallback (round 2/3 seats, where the lab emitted one)
```

Round-1 seats predate the 256/plain fallback convention, so they carry only
`logo.gif` + `logo.ansi`. In every case the GIF was rasterized **from** the
ANSI stream — the `.ansi` is the source of truth.

## Playback

- `cat logo/NN-slug/logo.ansi` — plays in any truecolor terminal (≥ 80 cols ×
  ≥ 8 rows free); frames redraw in place via cursor-up framing and the stream
  **holds the final composed frame** on exit.
- `cat logo-256.ansi` — same animation for 256-colour terminals.
- `cat logo-plain.txt` — static fallback with zero escape codes.
- The `.gif` loops the same animation in any image viewer or browser.

## Seat map

| Directory | Round | Lab origin (`/tmp/opencode/…`) | Files | Concept (one line) |
|---|---|---|---|---|
| `01-swarm` | 1 | `dup-logo-lab/agent-01` | gif†, ansi | Candy-coloured glyph particles converge and snap staggered into a bold DUP while PHP/FINDER slide in; holds with a diagonal glint (36 scenes). |
| `02-pairing` | 1 | `dup-logo-lab/agent-02` | gif, ansi | "Match ignition": twin DUP marks — violet original + hot-magenta clone — slide home; where the masks intersect, pixels ignite white. |
| `03-fingerprint` | 1 | `dup-logo-lab/agent-03` | gif, ansi | Two token-bar bands (the same file twice) scroll out of phase, snap aligned as gold match-connectors light, and a scan beam freezes the twin into the DUP verdict. |
| `04-ast-grow` | 1 | `dup-logo-lab/agent-04` | gif, ansi | "Clone collapse": eight identical chip pairs flash matched under a cyan sweep, then their pixels peel off and fly to the centre assembling DUP in scan order. |
| `05-mirror` | 1 | `dup-logo-lab/agent-05` | gif, ansi | DUP types in, a xerox copy stamps out with chromatic-aberration jitter, the pair is bracketed at 100 % match, and the twins fold additively into one union-gradient survivor. |
| `06-scanbar` | 1 | `dup-logo-lab/agent-06` | gif, ansi | Cyan scanner lights ghost DUP cell-by-cell behind a filling progress bar; an amber hologram clone with a diff-gutter stripe slides into registration — white flash, "+ clone found — 100 % match". |
| `07-neon-crt` | 1 | `dup-logo-lab/agent-07` | gif, ansi | "Diff align": violet DUP burns in with CRT halo/scanlines while a red clone slides into alignment beneath — every clone pixel reads red/green like a live diff, then locks match-green. |
| `08-reveal` | 1 | `dup-logo-lab/agent-08` | gif, ansi | "Twin converge": DUP drawn twice (indigo original, magenta-cyan ghost clone); a scan band sweeps, the clone eases home, matching cells flare, PHP-FINDER types in. |
| `09-radar` | 1 | `dup-logo-lab/agent-09` | gif, ansi | Drifting code-fragment bars; the magenta beam locks amber/cyan pairs which slam into the ghost wordmark, igniting D, U, P one by one — DUP assembled out of collapsed duplicates. |
| `10-ignition` | 1 | `dup-logo-lab/agent-10` | gif, ansi | Split-screen editor with two identical PHP panes; the beam strobes matching rows, panes dissolve, and indigo + magenta DUP clones collide into one warm-gradient mark. |
| `01-twin-fusion` | 2 | `dup-logo-lab2/idea-01` | gif, ansi, 256‡, plain‡ | Blue and violet DUP twins slide in from opposite edges, collide white, and fuse into warm gold under a sheen sweep. |
| `02-hash-rain` | 2 | `dup-logo-lab2/idea-02` | gif, ansi | Columns of falling hex hashes (seeded duplicate streams); twin columns whose shared window aligns lock amber and stamp the PHPDUP letters. |
| `03-tree-growth` | 2 | `dup-logo-lab2/idea-03` | gif, ansi, 256, plain‡ | Two ASTs grow in lockstep from one seed; the identical +22-column-translated subtrees grow emerald, dotted bridges join them, canopies merge. |
| `04-antiunify-holes` | 2 | `dup-logo-lab2/idea-04` | gif, ansi, 256, plain | PHPDUP whose glyph counters are typed holes cycling int/string/array/mixed, locking to their synthesised labels in a white-hot cascade. |
| `05-conveyor` | 2 | `dup-logo-lab2/idea-05` | gif, ansi | A comet token traverses the five pipeline stations, reshaping file→tree→cluster→signature→document, then dissolves into the phpdup wordmark. |
| `06-radar-sweep` | 2 | `dup-logo-lab2/idea-06` | gif, ansi, 256, plain | Half-block radar scope; a phosphor arm sweeps 360° firing similarity-hued blips, and PHPDUP holds over the frozen scope. |
| `07-plasma` | 2 | `dup-logo-lab2/idea-07` | gif, ansi, 256 | A boiling plasma field crystallises radially into the sharp phpdup wordmark — fuzzy near-duplicates resolving into exact clusters. |
| `08-jigsaw` | 2 | `dup-logo-lab2/idea-08` | gif, ansi, 256‡, plain‡ | Six puzzle-piece bricks (one letter each, code-glyph studs) fly in with spring easing and snap interlocked; matching pieces share a fill. |
| `09-diff-fold` | 2 | `dup-logo-lab2/idea-09` | gif, ansi, 256‡, plain‡ | Green +/red − diff rows scroll in; the identical − rows fold upward into one cyan `phpdup(...)` call as the markers dissolve. |
| `10-lens` | 2 | `dup-logo-lab2/idea-10` | gif, ansi, 256 | An elliptical lens drifts over repeated pseudo-code; each duplicate family it passes lights in its own hue, settling into the PHPDUP wordmark. |
| `01-dot-plot` | 3 | `dup-logo-lab3/idea-01` | gif, ansi, 256‡, plain‡ | A real computed 112×28 token dot plot in braille; three spliced clones brighten along diagonals under pink corner brackets as the wordmark fades in. |
| `02-norm-erosion` | 3 | `dup-logo-lab3/idea-02` | gif, ansi, 256‡, plain‡ | Three Normalizer-mode beams rewrite two PHP lines into the real `Normalizer`'s harvested output; the lines become byte-identical, merge, and cross-dissolve into PHPDUP. |
| `03-lsh-jackpot` | 3 | `dup-logo-lab3/idea-03` | gif, ansi, 256‡, plain‡ | Slot-machine reels of MinHash-signature hex; bands stop one by one — band 2 lands 4/4 gold, band 3 flashes a pink near-miss. |
| `04-union-find` | 3 | `dup-logo-lab3/idea-04` | gif, ansi, 256, plain | 41 stars, 31 scored edges replayed through a real path-halving union-find with white compression sparks; components take their computed root's hue and read as phpdup. |
| `05-jaccard-eclipse` | 3 | `dup-logo-lab3/idea-05` | gif, ansi | Two shingle-speckled discs eclipse with additive-linear intersection blending; the readout counts the drawn \|A∩B\|/\|A∪B\| past the repo's real 0.80 threshold tick. |
| `06-glitch-dedupe` | 3 | `dup-logo-lab3/idea-06` | gif, ansi, 256‡, plain‡ | The wordmark arrives corrupted (datamosh smears, RGB channel ghosts); an amber scanner collapses nine duplicate bands — counter 9→0 — and the channels re-converge clean. |
| `07-call-site-rewire` | 3 | `dup-logo-lab3/idea-07` | gif, ansi, 256 | Three identical `fn` chips on a circuit board; two desolder and their traces re-route one segment per tick onto the survivor, which widens into the phpdup package. |
| `08-photocopy-drift` | 3 | `dup-logo-lab3/idea-08` | gif, ansi, 256‡, plain‡ | A copier bar ejects drifting xerox copies of phpdup; bigram-Jaccard scores compute live (0.833/0.800/0.500), the sub-0.6 outlier slides off, survivors merge under an ×3 badge. |
| `09-debt-skyline` | 3 | `dup-logo-lab3/idea-09` | gif, ansi, 256‡, plain‡ | Six towers rise as per-directory duplicate debt against the red BaselineStore gate line; they're demolished floor-by-floor down to exactly PHPDUP, moon rising. |
| `10-double-helix` | 3 | `dup-logo-lab3/idea-10` | gif, ansi, 256, plain | A double helix of structural-hash-coloured bases; rungs snap only where a real LCS aligns them, then it unwinds and the matched bases reshape into the phpdup wordmark. |

† `01-swarm/logo.gif` was `dup-logo.gif` in its lab dir.
‡ Name normalised during the move: `logo.256.ansi`/`logo256.ansi` → `logo-256.ansi`;
`logo.plain`/`logo.txt`/`logo-static.txt` → `logo-plain.txt` (all verified
zero-escape). Original lab filenames otherwise unchanged in content.

Round-1 dir slugs are the program's fallback names; the bolded concept
statements live in each lab dir's `NOTES.md`.

## Verification at import time

- 30/30 seat dirs, 91 files; every `logo.gif` re-identified post-copy with
  scene count > 1 (range 36–55 scenes).
- All artifacts produced by deterministic, seeded generators in the labs —
  re-running a lab's `build.sh` reproduces these streams byte-for-byte.
