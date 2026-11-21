# dup-logo generator tools — harvested lab executables

Harvested 2026-11-21 from the round-1/2/3 lab directories under
`/tmp/opencode/dup-logo-lab*/` after the 30/30 concept close. An earlier
repair attempt phantom-reported this harvest without writing the files; this
directory is the disk-verified harvest. Naming:
`r<round>-<seatNN>-<original-filename>`. Where two seats ran the same tool
unchanged, both per-seat copies are harvested; byte-identical ones say so and
name the canonical file (md5 recorded).

Each seat's directory is `dup-logo-lab/agent-NN` (round 1),
`dup-logo-lab2/idea-NN` (round 2), `dup-logo-lab3/idea-NN` (round 3); the
`logo/NN-slug/` mapping is in [`../README.md`](../README.md)'s seat table.
Purposes below are read from each file's own header. Per-seat `build.sh`
scripts encode lab paths and reference sibling tools by their original names
— they document how each artifact was produced; they are **not runnable
as-is from this directory**.

## Round 1

### agent-01 · `01-swarm`

- `r1-01-build.sh` — regenerate the whole DUP convergence logo pipeline from scratch.
- `r1-01-generate.php` — "DUP converges" animated truecolor ANSI logo for phpdup (per-frame generator, the seat's ANSI stream).
- `r1-01-render-gif.php` — rasterise logo.ansi (truecolor ANSI animation) to PNG frames, one per animation frame, using a real monospace TTF. **Canonical** of the render-gif lineage (md5 637bd66c).

### agent-02 · `02-pairing`

- `r1-02-ansi-anim-to-png.php` — rasterize EVERY frame of a truecolor ANSI animation to PNG.
- `r1-02-build.sh` — regenerate the phpdup "MATCH IGNITION" animated truecolor ANSI logo.
- `r1-02-generate-dup.php` — "MATCH IGNITION": animated truecolor ANSI logo for phpdup.

### agent-03 · `03-fingerprint`

- `r1-03-build.sh` — one-shot pipeline: ANSI frames → PNGs → animated truecolor GIF.
- `r1-03-gen-dup.php` — truecolor ANSI animation generator for php-dup-finder.
- `r1-03-render-gif.php` — rasterize EVERY frame of a truecolor ANSI animation to PNG (seat's own variant of the render-gif shape).

### agent-04 · `04-ast-grow`

- `r1-04-build-dup.php` — "Clone Collapse" animated truecolor ANSI logo for phpdup (per-frame generator).
- `r1-04-build.sh` — rebuild the phpdup "Clone Collapse" animated truecolor ANSI logo.

### agent-05 · `05-mirror`

- `r1-05-build.sh` — regenerate the phpdup animated truecolor logo end to end.
- `r1-05-make-logo.php` — "Deduplicate: the clone convergence" animated truecolor ANSI logo for phpdup (per-frame generator).

### agent-06 · `06-scanbar`

- `r1-06-build.sh` — regenerate the phpdup animated logo end to end.
- `r1-06-generate-dup.php` — "MIRROR MATCH" animated truecolor ANSI logo for phpdup (per-frame generator; header title, dir slug per README seat map).

### agent-07 · `07-neon-crt`

- `r1-07-ansi2gif.php` — render an ANIMATED truecolor .ansi (frames joined by cursor-up moves) to an animated GIF. **Canonical** of the ansi2gif lineage (md5 9f20eaab).
- `r1-07-build.sh` — regenerate the phpdup "DIFF ALIGN" animated logo.
- `r1-07-dupgen.php` — "DIFF ALIGN" animated truecolor ANSI logo for phpdup (per-frame generator).

### agent-08 · `08-reveal`

- `r1-08-build.sh` — regenerate the phpdup animated logo (truecolor ANSI → GIF).
- `r1-08-gen-dup-logo.php` — "Twin Converge / Clone Detected" animated truecolor ANSI logo (per-frame generator).

### agent-09 · `09-radar`

- `r1-09-build.sh` — regenerate the whole "Clone Collapse" logo pipeline: design → logo.ansi (truecolor) → per-frame PNGs (GD + DejaVuSansMono) → logo.gif.
- `r1-09-gen-dup.php` — "Clone Collapse" animated truecolor ANSI logo for phpdup (per-frame generator; header title, dir slug per README seat map).
- `r1-09-render-frames.php` — rasterize EVERY frame of an ANSI animation to PNG.

### agent-10 · `10-ignition`

- `r1-10-build.sh` — "Clone Collapse" phpdup animated truecolor ANSI logo rebuild (header title; dir slug `10-ignition` per README).
- `r1-10-generate-dup.php` — "Clone Collapse" animated truecolor ANSI logo for phpdup (per-frame generator).
- `r1-10-raster.php` — render a logo-kit cell grid ([glyph, fg rgb|null, bg rgb|null]) to an image (seat's rasterizer).

## Round 2

### idea-01 · `01-twin-fusion`

- `r2-01-build.php` — generate the truecolor animation stream (logo.ansi) and a static fallback.
- `r2-01-build.sh` — regenerate logo.ansi + logo.gif FROM DupBanner.php (the single source of truth).
- `r2-01-DupBanner.php` — "Twin Fusion" truecolor animated ANSI wordmark: two identical DUP marks (blue, violet) slide in and fuse.
- `r2-01-verify.php` — fail-loud assertions for the DupBanner logo against the owner's hard constraints.
- `r2-01-render-gif.php` — per-seat copy: byte-identical (md5 637bd66c) to `r1-01-render-gif.php` (the seat ran the round-1 renderer unchanged).

### idea-02 · `02-hash-rain`

- `r2-02-build-logo.php` (from lab `tools/build-logo.php`) — regenerate logo.ansi + PNG frames FROM DupBanner (single source of truth); adapted from the round-1 agent-05 renderer.
- `r2-02-build.sh` — regenerate logo.ansi + logo.gif FROM DupBanner.php (single source of truth).
- `r2-02-DupBanner.php` — "Hash Rain Lock-in" animated truecolor ANSI logo for phpdup.
- `r2-02-verify.php` — fail-loud gates for the DupBanner "Hash Rain Lock-in" logo.

### idea-03 · `03-tree-growth`

- `r2-03-build.sh` — regenerate logo.ansi + logo.gif FROM TreeGrowthBanner.php.
- `r2-03-emit.php` — write logo.ansi (+ static plain sheet) FROM the class.
- `r2-03-TreeGrowthBanner.php` — phpdup animated ANSI logo, concept 3 "Syntax Tree Growth".
- `r2-03-verify.php` — fail-loud gates for the Syntax Tree Growth banner.
- `r2-03-ansi2gif.php` — per-seat copy: byte-identical (md5 9f20eaab) to `r1-07-ansi2gif.php` (the seat ran the round-1 renderer unchanged).

### idea-04 · `04-antiunify-holes`

- `r2-04-build.sh` — regenerate ALL deliverables FROM DupBanner.php (single source of truth). **Canonical** of its cross-round copy in r3-04 (md5 9065aef8).
- `r2-04-DupBanner.php` — animated truecolor ANSI logo for phpdup (PHPDUP whose glyph counters are typed holes).
- `r2-04-gen-ansi.php` — produce logo.ansi (truecolor) + the two fallback streams. **Canonical** of its cross-round copy in r3-04 (md5 949f1a56).
- `r2-04-render-gif.php` — rasterise an ANSI animation (logo.ansi) into per-frame PNGs (seat's own renderer).
- `r2-04-verify.php` — fail-loud gate for the DupBanner deliverable.

### idea-05 · `05-conveyor`

- `r2-05-build.sh` — regenerate logo.ansi + logo.gif FROM DupBanner.php (single source of truth), then fail-loud verify: determinism, bounds, fallbacks, coalesce.
- `r2-05-DupBanner.php` — "Five-Stage Conveyor" animated truecolor ANSI banner.
- `r2-05-gen-ansi.php` (from lab `tools/gen-ansi.php`) — write logo.ansi from the DupBanner class (single source of truth). **Canonical** of its cross-round copy in r3-05 (md5 7cad5e14).
- `r2-05-render-frames.php` (from lab `tools/render-frames.php`) — rasterise a DupBanner truecolor ANSI stream to PNG frames using DejaVu Sans Mono; reworked from the round-1 lab renderer. **Canonical** of its cross-round copy in r3-05 (md5 9d64421f).
- `r2-05-verify.php` — fail-loud constraint gate for the Five-Stage Conveyor banner.
- not harvested: lab `tools/render-gif.orig.php` — byte-identical (md5 637bd66c) to `r1-01-render-gif.php` (kept only as the seat's `.orig` base snapshot, not a runnable per-seat name); see `r1-01-render-gif.php`.

### idea-06 · `06-radar-sweep`

- `r2-06-build.php` — regenerate the deliverable streams from RadarBanner.
- `r2-06-build.sh` — regenerate ALL deliverables from RadarBanner.php (single source of truth).
- `r2-06-RadarBanner.php` — "Radar Clone Sweep" animated truecolor ANSI banner for phpdup.
- `r2-06-render-frames.php` — render an animated truecolor .ansi to frames (file header self-titles `ansi2gif.php`; seat's own variant, distinct from the shared lineage).
- `r2-06-verify.php` — fail-loud contract checks for RadarBanner (exit non-zero on breach).

### idea-07 · `07-plasma`

- `r2-07-build.sh` — regenerate every artifact FROM DupBanner.php (single source of truth).
- `r2-07-DupBanner.php` — "Plasma Crystallization" animated truecolor ANSI logo for phpdup.
- `r2-07-gen-ansi.php` — driver: writes logo.ansi (truecolor stream) from DupBanner.
- `r2-07-verify.php` — fail-loud constraint gates for the Plasma Crystallization logo.
- `r2-07-ansi2gif.php` — per-seat copy: byte-identical (md5 9f20eaab) to `r1-07-ansi2gif.php` (the seat ran the round-1 renderer unchanged).

### idea-08 · `08-jigsaw`

- `r2-08-autoload-shim.php` — shim pulling the repo vendor autoloader + the lab class for standalone phpstan runs on this lab.
- `r2-08-build.php` — emit the logo artefacts FROM the class (single source of truth).
- `r2-08-build.sh` — regenerate every deliverable FROM DupBanner.php (single source of truth).
- `r2-08-DupBanner.php` — "Jigsaw Snap": animated truecolor ANSI wordmark for phpdup.
- `r2-08-inspect.php` — print a tick's pixel grid as 2-char colour codes for eyeballing (debug tool).
- `r2-08-render.php` — rasterise an ANIMATED .ansi (frames joined by cursor-move resets) to a GIF. **Canonical** of its cross-round copy r3-08 (md5 c745c7c8).
- `r2-08-verify.php` — fail-loud constraint asserts for the DupBanner logo.
- not harvested: lab `render-tool-base.php` — byte-identical (md5 9f20eaab) to `r1-07-ansi2gif.php` (the seat's base-snapshot name); see `r1-07-ansi2gif.php`.

### idea-09 · `09-diff-fold`

- `r2-09-build.sh` — regenerate logo.ansi + logo.gif strictly FROM DupBanner.php.
- `r2-09-DupBanner.php` — "Diff Fold" animated truecolor ANSI logo for phpdup (also documents terminal colour-capability tiers).
- `r2-09-preview.php` — debug viewer: dumps selected ticks as plain-styled text.
- `r2-09-render-ansi.php` — emit the deliverable byte streams strictly FROM the banner class.
- `r2-09-render-gif.php` — rasterise the truecolor logo.ansi into per-frame PNGs (seat's own override of the base renderer).
- `r2-09-verify.php` — fail-loud constraint gate for the Diff Fold logo.
- not harvested: lab `render-gif-base.php` — byte-identical (md5 637bd66c) to `r1-01-render-gif.php` (the seat's base-snapshot name); see `r1-01-render-gif.php`.

### idea-10 · `10-lens`

- `r2-10-build-frames.php` — render the LensBanner ticks to PNG frames + logo.ansi.
- `r2-10-build.sh` — "Lens over Repeated Code" phpdup animated truecolor ANSI logo rebuild.
- `r2-10-LensBanner.php` — "Lens over Repeated Code" animated truecolor ANSI banner for phpdup.
- `r2-10-play.php` — watch the held-raw logo.ansi byte stream at real 30 fps pacing.
- `r2-10-raster.php` — render a logo-kit cell grid ([glyph, fg rgb|null, bg rgb|null]) to an image (seat's rasterizer).
- `r2-10-verify.php` — fail-loud asserts for the LensBanner deliverables.

## Round 3

### idea-01 · `01-dot-plot`

- `r3-01-build.sh` — regenerate logo.ansi + logo.gif FROM DupBanner.php (single source of truth), then run the static-analysis and constraint gates.
- `r3-01-DupBanner.php` — "Dot Plot Diagonals" animated ANSI logo for phpdup.
- `r3-01-gen-ansi.php` — write the deliverable ANSI streams FROM DupBanner.php.
- `r3-01-render-gif.php` — rasterise DupBanner frames to PNG, one per tick (seat's own renderer).
- `r3-01-verify.php` — fail-loud assertions for the Dot-Plot DupBanner against every hard constraint.

### idea-02 · `02-norm-erosion`

- `r3-02-build.php` — write every art artefact FROM DupBanner.php (single source of truth): truecolor stream, 256-colour stream, static final frame, plain text.
- `r3-02-build.sh` — regenerate ALL art FROM DupBanner.php (single source of truth).
- `r3-02-DupBanner.php` — "Normalization Erosion" truecolor animated ANSI logo: two PHP lines that look DIFFERENT, rewritten by three mode beams.
- `r3-02-harvest.php` — build-time-only probe: runs the two logo snippets through the REAL `Phpdup\Normalization\Normalizer` and freezes the output into the lab's `normalized.php` (that generated data file is NOT harvested — re-run this to regenerate it).
- `r3-02-render-gif.php` — rasterise logo.ansi (truecolor ANSI animation) to PNG (seat's own renderer).
- `r3-02-verify.php` — fail-loud checks of the DupBanner logo against the owner's hard constraints.

### idea-03 · `03-lsh-jackpot`

- `r3-03-build.php` — regenerate every art artefact FROM DupBanner.php (single source of truth).
- `r3-03-build.sh` — regenerate logo.ansi + logo.gif FROM DupBanner.php.
- `r3-03-dump.php` (from lab's hidden `.dump.php`; renamed visible on harvest) — strip the ANSI escapes and dump selected ticks plus the plain final frame (debug dumper).
- `r3-03-DupBanner.php` — "LSH Jackpot" phpdup animated ANSI banner (round-3, concept 03).
- `r3-03-verify.php` — fail-loud constraint gate for the LSH Jackpot banner.
- `r3-03-render-gif.php` — per-seat copy: byte-identical (md5 637bd66c) to `r1-01-render-gif.php` (the seat ran the round-1 renderer unchanged).

### idea-04 · `04-union-find`

- `r3-04-debug-dots.php` — dev tool: print the private 112×28 dot grid of a tick as text.
- `r3-04-DupBanner.php` — "Union-Find Constellations" animated truecolor ANSI logo for phpdup (round-3 concept 4).
- `r3-04-play.php` — play the DupBanner animation to STDOUT with --no-ansi / NO_COLOR handling (no header comment; purpose read from the code).
- `r3-04-render-gif.php` — rasterise an ANSI animation (logo.ansi) into per-frame PNGs (seat's own renderer).
- `r3-04-verify.php` — fail-loud gate for the Union-Find Constellations DupBanner.
- `r3-04-build.sh` — per-seat copy: byte-identical (md5 9065aef8) to `r2-04-build.sh` (round-3 seat re-ran the round-2 harness unchanged).
- `r3-04-gen-ansi.php` — per-seat copy: byte-identical (md5 949f1a56) to `r2-04-gen-ansi.php`.

### idea-05 · `05-jaccard-eclipse`

- `r3-05-build.sh` — regenerate logo.ansi + logo.gif FROM DupBanner.php (single source of truth).
- `r3-05-DupBanner.php` — "Jaccard Eclipse" animated truecolor ANSI banner.
- `r3-05-verify.php` — fail-loud verification battery for the Jaccard-Eclipse banner.
- `r3-05-gen-ansi.php` — per-seat copy (from lab `tools/gen-ansi.php`): byte-identical (md5 7cad5e14) to `r2-05-gen-ansi.php` (round-3 seat re-ran the round-2 harness unchanged).
- `r3-05-render-frames.php` — per-seat copy (from lab `tools/render-frames.php`): byte-identical (md5 9d64421f) to `r2-05-render-frames.php`.

### idea-06 · `06-glitch-dedupe`

- `r3-06-build.php` — generate the truecolor animation stream (logo.ansi) and the fallback artefacts FROM the DupBanner class (single source of truth).
- `r3-06-build.sh` — regenerate logo.ansi + logo.gif FROM DupBanner.php.
- `r3-06-DupBanner.php` — "Glitch Dedupe" truecolor animated ANSI wordmark: the wordmark arrives corrupted (datamoshed glyph segments).
- `r3-06-render-gif.php` — rasterise logo.ansi (truecolor ANSI animation) to PNG (seat's own renderer).
- `r3-06-verify.php` — fail-loud assertions for the "Glitch Dedupe" DupBanner logo.

### idea-07 · `07-call-site-rewire`

- `r3-07-build.sh` — regenerate every artifact FROM DupBanner.php (single source of truth).
- `r3-07-DupBanner.php` — "Call-Site Rewire" animated truecolor ANSI logo for phpdup.
- `r3-07-gen-ansi.php` — driver: writes logo.ansi (truecolor) + logo-256.ansi from DupBanner.
- `r3-07-verify.php` — fail-loud constraint gates for the Call-Site Rewire logo.
- `r3-07-ansi2gif.php` — per-seat copy: byte-identical (md5 9f20eaab) to `r1-07-ansi2gif.php` (the seat ran the round-1 renderer unchanged).

### idea-08 · `08-photocopy-drift`

- `r3-08-build.sh` — regenerate ALL art for Concept 8 "Photocopy Drift" FROM DupBanner.php.
- `r3-08-DupBanner.php` — Concept 8 "Photocopy Drift" animated truecolor ANSI logo.
- `r3-08-generate.php` — writes logo.ansi (truecolor), logo256.ansi and logo.txt from the banner class.
- `r3-08-render.php` — rasterise an ANIMATED .ansi to a GIF (seat's own modified derivative in the r2-08 render lineage).
- `r3-08-verify.php` — fail-loud constraint gate for the Photocopy Drift logo.
- not harvested: lab `render-base.php` — byte-identical (md5 c745c7c8) to `r2-08-render.php` (the seat's base-snapshot name); see `r2-08-render.php`.

### idea-09 · `09-debt-skyline`

- `r3-09-build.sh` — regenerate every art asset FROM DupBanner.php, then coalesce-verify the GIF and the full contract.
- `r3-09-DupBanner.php` — "Debt Skyline" truecolor animated ANSI wordmark: a city skyline at dusk, six towers, one per source directory.
- `r3-09-make-art.php` — regenerate every static art asset FROM DupBanner.php.
- `r3-09-render-gif.php` — rasterise logo.ansi (truecolor ANSI animation) to PNG (seat's own renderer).
- `r3-09-verify.php` — fail-loud contract checks for the Debt Skyline banner.

### idea-10 · `10-double-helix`

- `r3-10-build.sh` — regenerate ALL deliverables FROM DupBanner.php (single source of truth): gen-ansi → logo.ansi + logo-256.ansi + logo-plain.txt, then the render/verify legs.
- `r3-10-DupBanner.php` — animated truecolor ANSI logo for phpdup (concept 10, round 3; "Double Helix Alignment" per its verify gate).
- `r3-10-gen-ansi.php` — produce logo.ansi (truecolor) + the two fallback streams.
- `r3-10-render-gif.php` — rasterise an ANSI animation (logo.ansi) into per-frame PNGs (seat's own renderer).
- `r3-10-verify.php` — fail-loud gate for the idea-10 "Double Helix Alignment" banner.

## Borrowed candy-top stock (NOT copied into this repo)

Pristine, byte-identical copies of stock
[`sugarcraft/candy-top/logo/tools/`](https://github.com/detain/sugarcraft)
scripts that the seats used as reference/base. Owner ruling: only the logos
ship — these stay out of the repo; the originals live in candy-top. md5s
prove the unmodified state:

| stock file | md5 | held pristine by |
|---|---|---|
| `logo-kit.php` | `c3aaa7a6971bc57b2b27722c70be54ec` | r1 agents 01–04, 06–10; r2 idea-05 (`tools/logo-kit.orig.php`), 06, 10; r3 idea-02, 03 |
| `ansi-ttf-preview.php` | `973eafba509f32190a853c0d9b698631` | r1 agents 01, 02, 03, 09; r2 idea-05 (`tools/ansi-ttf-preview.orig.php`); r3 idea-02, 03 |
| `logo-play.php` | `9d9270e038883c6d881b9f9d451be64f` | r1 agent-01 |
| `generate-template.php` | `f89552d2205f41ccf7ae72fa35bcf9f2` | r3 idea-02 |
| `glyph-sprite.php` | `9b939ae08300fee03047209d166c4738` | r1 agent-01 (candy-top tool beyond the five-stock watch list — same treatment) |

`record-animations.sh` (`30d6fa7bac33ef0fbf4e62cba4d0a318`) — the fifth stock
tool — had no copy in any lab seat. No modified derivatives of any candy-top
stock file were found in the labs, so no entry carries the "candy-top
derived" marker.

## Excluded during harvest

- Outputs/artifacts of every kind: `*.ansi`, `*.gif`, `*.png`, `*.txt`,
  `*.out`, `*.log`, `*-evidence.txt`, plus the `frames/`, `coalesced/`,
  `png/` render dirs (the shipped art itself lives in `logo/NN-slug/`).
- Generated data: r3 idea-02's `normalized.php` (header: "GENERATED by
  harvest.php at build time") — the tool that rebuilds it is harvested.
- QA/analysis configs: `psalm*.xml`, `phpstan*.neon`, `phpstan-lab.php`
  (r2-08, r3-08), and r2-03's `.analysis-tmp/` phpstan cache.
- r2 idea-08's `composer.json` + `vendor/` (lab-local dependency scaffolding).
- Scratch: r2 idea-09's `scratch/DupBanner.pre-inline.php` (pre-inline
  snapshot); r1 agent-08's stray `md5:` / `md5:-` files (accidental GIF-byte
  shell artifacts, not tools).
