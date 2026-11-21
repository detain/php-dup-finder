# dup-logo generator tools — harvest

Harvested 2026-11-21 from the lab dirs `/tmp/opencode/dup-logo-lab/agent-*`,
`/tmp/opencode/dup-logo-lab2/idea-*`, `/tmp/opencode/dup-logo-lab3/idea-*`
after the dup-logo program closed 30/30. One file per seat-authored executable
source, renamed `r<round>-<seatNN>-<original name>` so every seat's toolchain
sits flat in this directory. Purposes below are read from each file's own
header comment.

**These scripts document the process; they do not run in-repo as-is.** Each
seat's `build.sh` expects its own lab layout (relative paths like
`php generate.php`, `frames/`, ImageMagick `convert`, and — for round-1 seats —
a sibling pristine copy of candy-top's `logo-kit.php`). The labs remain the
place to re-run them byte-for-byte.

Where several seats carried byte-identical copies of a shared lab tool
(the render-gif / ansi2gif lineages), each seat keeps its own prefixed copy —
the prefix documents which chain produced which artifact.

## Round 1 — `/tmp/opencode/dup-logo-lab/agent-NN` (26 files)

| File | Origin | Purpose (from file header) |
|---|---|---|
| `r1-01-build.sh` | agent-01/build.sh | Regenerate the DUP-convergence pipeline: `generate.php` → logo.ansi, `render-gif.php` → frame PNGs, `convert` → dup-logo.gif (36 frames @ 50 ms). |
| `r1-01-generate.php` | agent-01/generate.php | "DUP converges" generator: hundreds of D/U/P glyph particles ease along bezier paths and resolve into the wordmark (requires stock logo-kit + glyph-sprite). |
| `r1-01-render-gif.php` | agent-01/render-gif.php | Rasterise logo.ansi to one PNG per frame with a real monospace TTF. **candy-top derived** — "cell-grid renderer in the spirit of candy-top's ansi-ttf-preview.php, but frame-aware". |
| `r1-02-build.sh` | agent-02/build.sh | Regenerate "MATCH IGNITION": `generate-dup.php >logo.ansi`, `ansi-anim-to-png.php` → frames, `convert` → gif. |
| `r1-02-ansi-anim-to-png.php` | agent-02/ansi-anim-to-png.php | Split the animation on its cursor-up seam and render every frame as a GD cell grid with DejaVu Sans Mono. **candy-top derived** — "the same approach as candy-top's [ansi-ttf-preview]". |
| `r1-02-generate-dup.php` | agent-02/generate-dup.php | "MATCH IGNITION": violet original DUP + hot-magenta clone slide home; pixels ignite white where the masks intersect. |
| `r1-03-build.sh` | agent-03/build.sh | One-shot pipeline: `gen-dup.php` → logo.ansi + frames, `render-gif.php` → PNGs, `convert` → 42-frame gif. |
| `r1-03-gen-dup.php` | agent-03/gen-dup.php | "SNAP / SCAN / VERDICT": two identical token-bar bands scroll out of phase, snap aligned as match-connectors light, scan beam freezes the DUP verdict. |
| `r1-03-render-gif.php` | agent-03/render-gif.php | Frame-aware ANSI→PNG rasteriser. **candy-top derived** — "Derived from candy-top tools/ansi-ttf-preview.php (cell-grid approach)". |
| `r1-04-build-dup.php` | agent-04/build-dup.php | "Clone Collapse": finder beam sweeps a field of identical chip pairs; matched pairs' pixels fly into the gradient wordmark DUP. |
| `r1-04-build.sh` | agent-04/build.sh | Rebuild "Clone Collapse" — deterministic (fixed seed), php GD+TTF + ImageMagick. |
| `r1-05-build.sh` | agent-05/build.sh | `make-logo.php` → logo.ansi + frames → `convert` → looping gif. |
| `r1-05-make-logo.php` | agent-05/make-logo.php | "Deduplicate: the clone convergence" — builds FRAMES cell-grids, emits logo.ansi plus per-frame PNGs; self-contained. |
| `r1-06-build.sh` | agent-06/build.sh | `generate-dup.php` → logo.ansi + frames/fNN.png, then gif. |
| `r1-06-generate-dup.php` | agent-06/generate-dup.php | "MIRROR MATCH": 3 acts in 36 frames — cyan scanner sweep reveals DUP (violet→magenta half-block gradient under an AST branch tree), then the clone act. |
| `r1-07-build.sh` | agent-07/build.sh | Regenerate "DIFF ALIGN": `dupgen.php` → 40-frame logo.ansi, gif @ 25 fps. |
| `r1-07-dupgen.php` | agent-07/dupgen.php | "DIFF ALIGN": violet original burns in; red clone slides into alignment, its pixels reading red/green like a live diff (requires stock logo-kit). |
| `r1-07-ansi2gif.php` | agent-07/ansi2gif.php | Render an animated truecolor .ansi (cursor-up joined frames) to numbered PNGs. **candy-top derived** — "Fork of candy-top's ansi-ttf-preview.php, extended to:". |
| `r1-08-build.sh` | agent-08/build.sh | `gen-dup-logo.php` → frames + logo.ansi, `convert` → gif (8/100 s per frame). |
| `r1-08-gen-dup-logo.php` | agent-08/gen-dup-logo.php | "Twin Converge / Clone Detected": DUP drawn twice from ONE pixel mask — original + ghost clone — then the twins fold together. |
| `r1-09-build.sh` | agent-09/build.sh | "Clone Collapse" pipeline: design → logo.ansi → per-frame PNGs (GD + DejaVuSansMono) → gif. |
| `r1-09-gen-dup.php` | agent-09/gen-dup.php | "Clone Collapse" (radar concept): code-fragment bars drift; a magenta beam locks amber/cyan pairs and they slam into the ghost wordmark. |
| `r1-09-render-frames.php` | agent-09/render-frames.php | Rasterise every ANSI frame to PNG. **candy-top derived** — "cell-grid method from candy-top's ansi-ttf-preview.php, extended to split frames". |
| `r1-10-build.sh` | agent-10/build.sh | `generate-dup.php` → frames + logo.ansi + logo-still.png, `convert` → gif. |
| `r1-10-generate-dup.php` | agent-10/generate-dup.php | "Clone Collapse" (split-screen arc, 40 frames ≈ 1.6 s): identical PHP panes, strobing scan-beam, clones collide into one warm-gradient mark. |
| `r1-10-raster.php` | agent-10/raster.php | Render a logo-kit cell grid straight to a truecolor PNG with a monospace TTF. **candy-top derived** — "Port of candy-top's ansi-ttf-preview.php cell math". |

## Round 2 — `/tmp/opencode/dup-logo-lab2/idea-NN` (53 files)

| File | Origin | Purpose (from file header) |
|---|---|---|
| `r2-01-build.php` | idea-01/build.php | Emit logo.ansi + static plain fallback FROM the DupBanner class (single source of truth). |
| `r2-01-build.sh` | idea-01/build.sh | Regenerate logo.ansi + logo.gif FROM DupBanner.php. |
| `r2-01-DupBanner.php` | idea-01/DupBanner.php | "Twin Fusion": blue + violet DUP twins collide white and fuse into warm gold under a sheen sweep. |
| `r2-01-render-gif.php` | idea-01/render-gif.php | ANSI→PNG frame rasteriser; byte-identical to `r1-01-render-gif.php`. **candy-top derived** ("in the spirit of candy-top's ansi-ttf-preview.php"). |
| `r2-01-verify.php` | idea-01/verify.php | Fail-loud assertions for DupBanner against owner hard constraints. |
| `r2-02-build.sh` | idea-02/build.sh | Regenerate logo.ansi + logo.gif FROM DupBanner.php, then fail-loud gates + static-analysis evidence capture. |
| `r2-02-DupBanner.php` | idea-02/DupBanner.php | "Hash Rain Lock-in": columns of falling hex hashes from a seeded pool; aligned twin columns lock amber and stamp the PHPDUP letters. |
| `r2-02-build-logo.php` | idea-02/tools/build-logo.php | Regenerate logo.ansi + PNG frames FROM DupBanner — "copied/adapted from the proven round-1 agent-05 renderer". |
| `r2-02-verify.php` | idea-02/verify.php | Fail-loud gates for the Hash Rain logo (exit 0 only when every constraint holds). |
| `r2-03-build.sh` | idea-03/build.sh | Regenerate logo.ansi + logo.gif FROM TreeGrowthBanner.php. |
| `r2-03-ansi2gif.php` | idea-03/ansi2gif.php | Animated .ansi → numbered PNGs; byte-identical to `r1-07-ansi2gif.php`. **candy-top derived** (fork of ansi-ttf-preview.php). |
| `r2-03-emit.php` | idea-03/emit.php | Writes logo.ansi (+ static plain sheet) FROM the class; build.sh calls this first. |
| `r2-03-TreeGrowthBanner.php` | idea-03/TreeGrowthBanner.php | "Syntax Tree Growth": two ASTs grow node-per-tick from one seed; identical translated subtrees grow emerald, dotted bridges join, canopies merge. |
| `r2-03-verify.php` | idea-03/verify.php | Fail-loud gates: layout, sizes, budgets, timings + in-process re-emission. |
| `r2-04-build.sh` | idea-04/build.sh | Regenerate ALL deliverables FROM DupBanner.php. Byte-identical twin of `r3-04-build.sh`. |
| `r2-04-DupBanner.php` | idea-04/DupBanner.php | "Antiunify Holes": PHPDUP whose glyph counters are typed holes cycling int/string/array/mixed, locking to synthesised labels. |
| `r2-04-gen-ansi.php` | idea-04/gen-ansi.php | Produce logo.ansi + logo-256.ansi + logo-plain.txt straight from DupBanner. Byte-identical twin of `r3-04-gen-ansi.php`. |
| `r2-04-render-gif.php` | idea-04/render-gif.php | ANSI animation → per-frame PNGs. **candy-top derived** — "in the spirit of sugarcraft/candy-top ansi-ttf-preview.php". |
| `r2-04-verify.php` | idea-04/verify.php | Fail-loud gate: determinism, size caps, fallback chain, timing budget, SGR validity. |
| `r2-05-build.sh` | idea-05/build.sh | Regenerate logo.ansi + logo.gif FROM DupBanner.php, then fail-loud verify (determinism, bounds, fallbacks, coalesce). |
| `r2-05-DupBanner.php` | idea-05/DupBanner.php | "Five-Stage Conveyor": a comet token traverses the pipeline stations reshaping file→tree→cluster→signature→document, dissolving into the wordmark. |
| `r2-05-gen-ansi.php` | idea-05/tools/gen-ansi.php | Write logo.ansi from the DupBanner class (`php tools/gen-ansi.php [out.ansi]`). Identical twin of `r3-05-gen-ansi.php`. |
| `r2-05-render-frames.php` | idea-05/tools/render-frames.php | Rasterise a DupBanner ANSI stream to PNG frames (DejaVu Sans Mono); "adapted from the round-1 lab renderer" (candy-top cell-grid lineage two steps down). Identical twin of `r3-05-render-frames.php`. |
| `r2-05-verify.php` | idea-05/verify.php | Fail-loud constraint gate (exit 0 + ALL PASS or first failure aborts). |
| `r2-06-build.php` | idea-06/build.php | Regenerate the deliverable streams from RadarBanner (class = single source of truth). |
| `r2-06-build.sh` | idea-06/build.sh | Regenerate ALL deliverables from RadarBanner.php (php GD/TTF, ImageMagick, fixed seed). |
| `r2-06-RadarBanner.php` | idea-06/RadarBanner.php | "Radar Clone Sweep": phosphor arm sweeps 360° firing similarity-hued blips; PHPDUP holds over the frozen scope. |
| `r2-06-render-frames.php` | idea-06/render-frames.php | Animated .ansi → numbered PNGs; seat-renamed file carrying the ansi2gif-fork header. **candy-top derived** (fork of ansi-ttf-preview.php). |
| `r2-06-verify.php` | idea-06/verify.php | Fail-loud contract checks for RadarBanner, one PASS line per check. |
| `r2-07-build.sh` | idea-07/build.sh | Regenerate every artifact FROM DupBanner.php (one-shot stream ending on the hold). |
| `r2-07-ansi2gif.php` | idea-07/ansi2gif.php | Animated .ansi → numbered PNGs; byte-identical to `r1-07-ansi2gif.php`. **candy-top derived** (fork of ansi-ttf-preview.php). |
| `r2-07-DupBanner.php` | idea-07/DupBanner.php | "Plasma Crystallization": boiling plasma field crystallises radially into the sharp phpdup wordmark. |
| `r2-07-gen-ansi.php` | idea-07/gen-ansi.php | Driver: write logo.ansi (truecolor stream) from DupBanner. |
| `r2-07-verify.php` | idea-07/verify.php | Fail-loud constraint gates ("ALL GATES PASS" only when everything holds). |
| `r2-08-build.sh` | idea-08/build.sh | Regenerate every deliverable FROM DupBanner.php. |
| `r2-08-build.php` | idea-08/build.php | Emit logo.ansi, logo256.ansi and logo.txt (plain static) FROM the class. |
| `r2-08-DupBanner.php` | idea-08/DupBanner.php | "Jigsaw Snap": six puzzle-piece bricks (one letter each) fly in with spring easing and snap interlocked; matching pieces share a fill. |
| `r2-08-autoload-shim.php` | idea-08/autoload-shim.php | Seat-written shim: pulls the repo vendor autoloader + the lab class for standalone phpstan runs. |
| `r2-08-inspect.php` | idea-08/inspect.php | Debug: print a tick's pixel grid as 2-char colour codes for eyeballing. |
| `r2-08-render.php` | idea-08/render.php | Animated .ansi → numbered PNGs for GIF stitching; modified from the ansi2gif fork (pristine `render-tool-base.php` left in the lab, excluded here). **candy-top derived**. |
| `r2-08-verify.php` | idea-08/verify.php | Fail-loud constraint asserts (failing check named on exit). |
| `r2-09-build.sh` | idea-09/build.sh | Regenerate logo.ansi + logo.gif strictly FROM DupBanner.php, fail-loud at every step. |
| `r2-09-DupBanner.php` | idea-09/DupBanner.php | "Diff Fold": green +/red − diff rows scroll in; identical − rows fold upward into one cyan `phpdup(...)` call. |
| `r2-09-preview.php` | idea-09/preview.php | Debug viewer: dump selected ticks as plain-styled text to eyeball choreography. |
| `r2-09-render-ansi.php` | idea-09/render-ansi.php | Emit the deliverable byte streams strictly FROM the DupBanner class (invoked by build.sh). |
| `r2-09-render-gif.php` | idea-09/render-gif.php | ANSI → per-frame PNGs; modified from the shared render-gif base (kept pristine in-lab as `render-gif-base.php`, excluded here). **candy-top derived**. |
| `r2-09-verify.php` | idea-09/verify.php | Fail-loud constraint gate for the Diff Fold logo. |
| `r2-10-build.sh` | idea-10/build.sh | "Lens over Repeated Code": regenerate frames/, logo.ansi, logo-256.ansi, logo-final.png, logo.gif. |
| `r2-10-build-frames.php` | idea-10/build-frames.php | Render the LensBanner ticks to PNG frames + logo.ansi. |
| `r2-10-LensBanner.php` | idea-10/LensBanner.php | "Lens over Repeated Code": an elliptical lens drifts over repeated pseudo-code; each duplicate family lights in its own hue. |
| `r2-10-play.php` | idea-10/play.php | Watch the held-raw logo.ansi byte stream at real 30 fps pacing. |
| `r2-10-raster.php` | idea-10/raster.php | logo-kit cell grid → truecolor PNG with a real monospace TTF. **candy-top derived** — "Port of candy-top's ansi-ttf-preview.php cell math". |
| `r2-10-verify.php` | idea-10/verify.php | Fail-loud asserts for the LensBanner deliverables. |

## Round 3 — `/tmp/opencode/dup-logo-lab3/idea-NN` (54 files)

| File | Origin | Purpose (from file header) |
|---|---|---|
| `r3-01-build.sh` | idea-01/build.sh | Regenerate logo.ansi + logo.gif FROM DupBanner.php, then static-analysis and constraint gates. |
| `r3-01-DupBanner.php` | idea-01/DupBanner.php | "Dot Plot Diagonals": a real computed token dot plot in braille; spliced clones brighten along diagonals under pink corner brackets. |
| `r3-01-gen-ansi.php` | idea-01/gen-ansi.php | Write the deliverable ANSI streams FROM DupBanner (pure frame emission, no timing). |
| `r3-01-render-gif.php` | idea-01/render-gif.php | Rasterise DupBanner frames to PNG, one per tick. |
| `r3-01-verify.php` | idea-01/verify.php | Fail-loud assertions against every owner hard constraint. |
| `r3-02-build.sh` | idea-02/build.sh | Regenerate ALL art FROM DupBanner.php; step 0 refreshes `normalized.php` by running the REAL repo Normalizer. |
| `r3-02-build.php` | idea-02/build.php | Write every art artefact FROM DupBanner: truecolor, 256-colour, static final frame, plain text. |
| `r3-02-DupBanner.php` | idea-02/DupBanner.php | "Normalization Erosion": three Normalizer-mode beams rewrite two PHP lines into the real Normalizer's harvested output until byte-identical, then cross-dissolve into PHPDUP. |
| `r3-02-harvest.php` | idea-02/harvest.php | BUILD-TIME ONLY: run the real phpdup Normalizer and freeze its output into `normalized.php` (that generated data file is excluded from this harvest; rerun this to rebuild it). |
| `r3-02-render-gif.php` | idea-02/render-gif.php | Rasterise logo.ansi to PNG frames, one per animation tick, with a real monospace TTF. |
| `r3-02-verify.php` | idea-02/verify.php | Fail-loud checks against the owner's hard constraints; non-zero on ANY violation. |
| `r3-03-build.sh` | idea-03/build.sh | Regenerate logo.ansi + logo.gif FROM DupBanner.php. |
| `r3-03-build.php` | idea-03/build.php | Regenerate every art artefact FROM DupBanner: truecolor stream, 256-colour twin, fallbacks. |
| `r3-03-dump.php` | idea-03/.dump.php | Debug dump: print ticks 0 and 43 with escapes stripped, plus the plain final frame. (Lab name carried a leading dot; renamed visible on harvest.) |
| `r3-03-DupBanner.php` | idea-03/DupBanner.php | "LSH Jackpot": two signature rows roll past as 16 slot reels of 2-digit MinHash hex; band 2 lands 4/4 gold, band 3 a pink near-miss. |
| `r3-03-render-gif.php` | idea-03/render-gif.php | ANSI→PNG rasteriser; byte-identical to `r1-01-render-gif.php`. **candy-top derived** ("in the spirit of candy-top's ansi-ttf-preview.php"). |
| `r3-03-verify.php` | idea-03/verify.php | Fail-loud constraint gate (exit 1 on any violation). |
| `r3-04-build.sh` | idea-04/build.sh | Regenerate ALL deliverables FROM DupBanner.php; byte-identical twin of `r2-04-build.sh`. |
| `r3-04-DupBanner.php` | idea-04/DupBanner.php | "Union-Find Constellations": 41 stars, 31 scored edges replayed through a real path-halving union-find; components take their computed root's hue. |
| `r3-04-gen-ansi.php` | idea-04/gen-ansi.php | Produce logo.ansi + logo-256.ansi + logo-plain.txt straight from DupBanner. Byte-identical twin of `r2-04-gen-ansi.php`. |
| `r3-04-render-gif.php` | idea-04/render-gif.php | Rasterise an ANSI animation (logo.ansi) into per-frame PNGs. |
| `r3-04-play.php` | idea-04/play.php | Live paced playback of DupBanner frames (supports `--no-ansi`); no header — read from the code. |
| `r3-04-debug-dots.php` | idea-04/debug-dots.php | Dev tool: print a tick's private 112×28 dot grid as literal dots so the letters can be read directly. |
| `r3-04-verify.php` | idea-04/verify.php | Fail-loud gate for the Union-Find Constellations DupBanner. |
| `r3-05-build.sh` | idea-05/build.sh | Regenerate logo.ansi + logo.gif FROM DupBanner.php, then fail-loud verify (cross-process determinism, bounds, budget, coalesce). |
| `r3-05-DupBanner.php` | idea-05/DupBanner.php | "Jaccard Eclipse": two shingle-speckled discs eclipse with additive-linear blending; the readout counts the real set sizes past the repo's real 0.80 threshold tick. |
| `r3-05-gen-ansi.php` | idea-05/tools/gen-ansi.php | Write logo.ansi from the DupBanner class; identical twin of `r2-05-gen-ansi.php`. |
| `r3-05-render-frames.php` | idea-05/tools/render-frames.php | Rasterise a DupBanner ANSI stream to PNG frames (DejaVu Sans Mono); adapted from the round-1 lab renderer; identical twin of `r2-05-render-frames.php`. |
| `r3-05-verify.php` | idea-05/verify.php | Fail-loud verification battery for the Jaccard-Eclipse banner. |
| `r3-06-build.sh` | idea-06/build.sh | Regenerate logo.ansi + logo.gif FROM DupBanner.php. |
| `r3-06-build.php` | idea-06/build.php | Generate the truecolor animation stream and fallback artefacts FROM the DupBanner class. |
| `r3-06-DupBanner.php` | idea-06/DupBanner.php | "Glitch Dedupe": the wordmark arrives corrupted (datamosh smears, RGB ghosts); an amber scanner collapses nine duplicate bands and the channels re-converge clean. |
| `r3-06-render-gif.php` | idea-06/render-gif.php | ANSI → per-frame PNGs with a real monospace TTF. **candy-top derived** ("in the spirit of candy-top's ansi-ttf-preview.php"). |
| `r3-06-verify.php` | idea-06/verify.php | Fail-loud assertions against the owner's hard constraints. |
| `r3-07-build.sh` | idea-07/build.sh | Regenerate every artifact FROM DupBanner.php (one-shot stream ending on the hold). |
| `r3-07-ansi2gif.php` | idea-07/ansi2gif.php | Animated .ansi → numbered PNGs; byte-identical to `r1-07-ansi2gif.php`. **candy-top derived** (fork of ansi-ttf-preview.php). |
| `r3-07-DupBanner.php` | idea-07/DupBanner.php | "Call-Site Rewire": three identical `fn` chips on a circuit board; two desolder and their traces re-route one segment per tick onto the survivor. |
| `r3-07-gen-ansi.php` | idea-07/gen-ansi.php | Driver: write logo.ansi (truecolor) + logo-256.ansi from DupBanner. |
| `r3-07-verify.php` | idea-07/verify.php | Fail-loud constraint gates ("ALL GATES PASS" only when everything holds). |
| `r3-08-build.sh` | idea-08/build.sh | Regenerate ALL art for "Photocopy Drift" FROM DupBanner.php. |
| `r3-08-DupBanner.php` | idea-08/DupBanner.php | "Photocopy Drift": a copier bar ejects drifting xerox copies; bigram-Jaccard scores compute live; the sub-0.6 outlier slides off, survivors merge under an ×3 badge. |
| `r3-08-generate.php` | idea-08/generate.php | Write logo.ansi (truecolor), logo256.ansi and logo.txt (plain fallback) straight from DupBanner. |
| `r3-08-render.php` | idea-08/render.php | Animated .ansi → numbered PNGs; modified from round-2 seat-08's render (its pristine backup `render-base.php` excluded here). **candy-top derived** (second-generation ansi2gif lineage). |
| `r3-08-verify.php` | idea-08/verify.php | Fail-loud constraint gate (FAIL names the check, exits non-zero). |
| `r3-09-build.sh` | idea-09/build.sh | Regenerate every art asset FROM DupBanner.php, then coalesce-verify the GIF and the full contract. |
| `r3-09-DupBanner.php` | idea-09/DupBanner.php | "Debt Skyline": six towers rise as per-directory duplicate debt against the red BaselineStore gate line, demolished floor-by-floor down to exactly PHPDUP, moon rising. Self-contained — no logo-kit, no deps. |
| `r3-09-make-art.php` | idea-09/make-art.php | Regenerate every static art asset FROM DupBanner.php. |
| `r3-09-render-gif.php` | idea-09/render-gif.php | Rasterise logo.ansi to PNG frames, one per tick. **candy-top derived** — "adapted from candy-top's ansi-ttf-preview / round-2 lineage". |
| `r3-09-verify.php` | idea-09/verify.php | Fail-loud contract checks for the Debt Skyline banner. |
| `r3-10-build.sh` | idea-10/build.sh | Regenerate ALL deliverables FROM DupBanner.php. |
| `r3-10-DupBanner.php` | idea-10/DupBanner.php | "Double Helix Alignment": a helix of structural-hash-coloured bases; rungs snap only where a real LCS aligns them, then it unwinds into the phpdup wordmark. |
| `r3-10-gen-ansi.php` | idea-10/gen-ansi.php | Produce logo.ansi + logo-256.ansi + logo-plain.txt straight from DupBanner. |
| `r3-10-render-gif.php` | idea-10/render-gif.php | Rasterise an ANSI animation into per-frame PNGs. **candy-top derived** — "in the spirit of sugarcraft/candy-top ansi-ttf-preview.php". |
| `r3-10-verify.php` | idea-10/verify.php | Fail-loud gate for the "Double Helix Alignment" banner. |

## Borrowed candy-top stock (NOT harvested — pristine copies)

Stock tools live in `/home/sites/sugarcraft/candy-top/logo/tools/`. Every lab
copy below was md5-verified **byte-identical** to its stock original and
therefore left out of this harvest:

| Stock tool | md5 | Carried pristine by |
|---|---|---|
| `logo-kit.php` | `c3aaa7a6971bc57b2b27722c70be54ec` | r1-01, r1-02, r1-03, r1-04, r1-06, r1-07, r1-08, r1-09, r1-10; r2-05 (`tools/logo-kit.orig.php`), r2-06, r2-10; r3-02, r3-03 — 14 copies |
| `ansi-ttf-preview.php` | `973eafba509f32190a853c0d9b698631` | r1-01, r1-02, r1-03, r1-09; r2-05 (`tools/ansi-ttf-preview.orig.php`); r3-02, r3-03 — 7 copies |
| `glyph-sprite.php` | `9b939ae08300fee03047209d166c4738` | r1-01 — 1 copy |
| `logo-play.php` | `9d9270e038883c6d881b9f9d451be64f` | r1-01 — 1 copy |
| `generate-template.php` | `f89552d2205f41ccf7ae72fa35bcf9f2` | r3-02 — 1 copy |

24 pristine stock copies excluded in total. The seats' generators `require`
these files from their lab dirs at build time (round-1 especially), which is
part of why the `build.sh` scripts document rather than re-run in-repo.
Modified derivatives of stock tools ARE harvested and marked **candy-top
derived** in the tables above (20 marked entries — the ansi2gif / render-gif /
render-frames / raster / ansi-anim-to-png family; the fifth watch-listed stock
tool `record-animations.sh` was never copied by any seat).

## Other exclusions (for the record)

- Lab-internal pristine reference backups, byte-identical to shared lab tools:
  `idea-05/tools/render-gif.orig.php` (= r1-01 lineage), `idea-08/render-tool-base.php`
  (= ansi2gif lineage), `idea-09/render-gif-base.php` (= r1-01 lineage),
  `idea-08/render-base.php` r3 (= `r2-08-render.php`). Their modified siblings are harvested.
- `r3-02 normalized.php` — generated data (real-Normalizer output frozen by
  `harvest.php`; regenerable).
- All artefacts and evidence: `*.ansi`, `*.gif`, `*.png`, `*.txt`, `*.out`,
  `*.log`, `*-evidence.txt`, `frames/`, `frames256/`, `coalesced/`, `work/`,
  `scratch/` (incl. r2-09's `DupBanner.pre-inline.php` snapshot), `png/`,
  `.analysis-tmp/`, plus local QA configs (`psalm*.xml`, `phpstan*.neon`,
  `phpstan-lab.php`, lab `composer.json`, `vendor/` symlink) and agent-08's
  misnamed `md5:` / `md5:-` shell-redirect artifacts (GIF bytes, not tools).
