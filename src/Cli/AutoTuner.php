<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Scanning\FileScanner;

/**
 * Auto-tunes detection parameters from a quick file-tree probe.
 *
 * Counts files before any AST work, then maps the corpus shape to a
 * small decision tree:
 *
 *   - tiny     (<200 files)       — relaxed thresholds, surface fixtures
 *                                    and toy projects don't get drowned
 *                                    by min-impact / max-df defaults.
 *   - small    (<2_000 files)     — defaults (no overrides).
 *   - medium   (<20_000 files)    — tighter max-df (uniqueness scales);
 *                                    min-block-size only where it really
 *                                    moves (see the precedence note below).
 *   - large    (>=20_000 files)   — exact-only suggestion + much tighter
 *                                    thresholds; refusing to OOM is the
 *                                    right call here.
 *
 * Returns a {@see Suggestion} value object with the override dict and a
 * human-readable rationale string the CLI can print.
 *
 * once-S26 (php-dup-finder-once-030) — the tuner reads the run's EFFECTIVE
 * config instead of a stock-defaults vacuum:
 *  - $effective carries the value each knob would actually have without the
 *    tuner (explicit config/CLI/profile value where set, stock default
 *    otherwise). A suggestion that equals it is a narrated no-op — the old
 *    medium tier claimed "min_block_size=8" against a default of 8 (PD-CFG-12
 *    "8→8") — and is dropped from both the override dict and the rationale.
 *  - $locked carries knobs the user explicitly set. The tuner fills gaps
 *    only: it never writes over an explicit value (COPY-05's live proof:
 *    tuned values entered the TOP override layer and outranked --config).
 *    A locked knob the tier wanted to move is narrated as "kept".
 *  - With no context passed (empty $effective/$locked) every tier's raw
 *    intent is suggested, byte-identical to the pre-once-S26 tables — the
 *    contract for direct callers that know nothing about the run.
 *
 * The probe is counting-only: the band decision consumes the file count
 * alone, so no per-file stat() runs on top of the scanner traversal
 * (shell-S25 / php-dup-finder-shell-043 — a --auto-tune run must not pay
 * for a third full tree walk). Suggestion::$bytes stays 0 from tune();
 * {@see pick()} still accepts a byte figure for direct callers.
 */
final class AutoTuner
{
    public function __construct(
        private readonly int $smallMax  = 2_000,
        private readonly int $mediumMax = 20_000,
        private readonly int $tinyMax   = 200,
    ) {
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludeGlobs the full effective exclude list of the
     *                                   run to be — defaults merged with the
     *                                   resolved profile's AND the root
     *                                   config's own excludes, exclude_reset
     *                                   honored (shell-S25 added the profile
     *                                   term, once-S26 the config term: a
     *                                   blind census inflates the band with
     *                                   trees the scan will never read, and
     *                                   forces large-tier rules like
     *                                   exact_only on what is really a
     *                                   post-exclude tiny corpus)
     * @param array<string,int|float|bool|string> $effective current effective
     *                                                       value per tuner
     *                                                       knob, as the run
     *                                                       would see it
     *                                                       without --auto-tune
     * @param list<string> $locked knobs explicitly set by the user — never
     *                             suggested, only narrated as kept
     */
    public function tune(
        array $paths,
        array $excludeGlobs,
        array $effective = [],
        array $locked = [],
    ): Suggestion {
        $totalFiles = 0;
        foreach ($paths as $root) {
            $scanner = new FileScanner($excludeGlobs);
            foreach ($scanner->scan($root) as $file) {
                $totalFiles++;
            }
        }

        // Counting-only: bytes never drove a band and the real scan reads
        // every file anyway — stat-ing the whole tree here was pure tax.
        return $this->pick($totalFiles, 0, $effective, $locked);
    }

    /**
     * @param array<string,int|float|bool|string> $effective
     * @param list<string> $locked
     */
    public function pick(int $totalFiles, int $totalBytes, array $effective = [], array $locked = []): Suggestion
    {
        if ($totalFiles < $this->tinyMax) {
            // shell-S25 outside-merge (ENGINE-PDDETECT o-o-g 2): this tier's
            // max_df=0.5 relaxation flows through the single df-cap seat,
            // NgramInvertedIndex::candidatesFor()'s max(2, ceil(N × ratio))
            // floor (engine-S01) — so it can no longer stay dead at N ≤ 3
            // and needs no separate floored copy here.
            return $this->verdict(
                profile: 'tiny',
                files:   $totalFiles,
                bytes:   $totalBytes,
                intent: [
                    'min_block_size'     => 4,
                    'min_cluster_impact' => 0,
                    'max_df'             => 0.5,
                ],
                clauses: [
                    'min_block_size'     => 'min_block_size=4',
                    'min_cluster_impact' => 'min_impact=0',
                    'max_df'             => 'max_df=0.5',
                ],
                lead:   'relaxing',
                join:   ', ',
                suffix: ' so small/fixture trees report findings instead of empty output',
                idle:   'the effective thresholds already suit a tree this small — nothing to relax',
                effective: $effective,
                locked:    $locked,
            );
        }
        if ($totalFiles < $this->smallMax) {
            return new Suggestion(
                profile: 'small',
                files:   $totalFiles,
                bytes:   $totalBytes,
                overrides: [],
                rationale: sprintf(
                    'small corpus (%d files): keeping defaults — they target the small/medium real-world band already.',
                    $totalFiles,
                ),
            );
        }
        if ($totalFiles < $this->mediumMax) {
            return $this->verdict(
                profile: 'medium',
                files:   $totalFiles,
                bytes:   $totalBytes,
                intent: [
                    'min_block_size' => 8,
                    'max_df'         => 0.005,
                ],
                clauses: [
                    'max_df'         => 'max_df=0.005 (n-gram uniqueness scales with corpus size)',
                    'min_block_size' => 'min_block_size=8',
                ],
                lead:   'tightening',
                join:   ' and ',
                suffix: '',
                idle:   'the effective settings already match this band — nothing to tighten',
                effective: $effective,
                locked:    $locked,
            );
        }
        return $this->verdict(
            profile: 'large',
            files:   $totalFiles,
            bytes:   $totalBytes,
            intent: [
                'min_block_size' => 12,
                'max_df'         => 0.002,
                'exact_only'     => true,
            ],
            clauses: [
                'exact_only'     => 'exact-only',
                'min_block_size' => 'min_block_size=12',
                'max_df'         => 'max_df=0.002',
            ],
            lead:   '',
            join:   ' + ',
            suffix: ' to keep memory in check; rerun without --auto-tune for full near-duplicate analysis when ready',
            idle:   'the effective settings are already at or beyond this band\'s strictness — the tier adds nothing',
            effective: $effective,
            locked:    $locked,
        );
    }

    /**
     * Compose one tier verdict under the once-S26 contract: drop intent the
     * user already owns (locked knobs are never written; a value equal to the
     * effective one is a no-op), and narrate ONLY what is actually applied —
     * plus a truthful "kept (explicitly set)" tail for locked knobs the tier
     * wanted to move. With no context the raw tier table passes through
     * byte-identical to the pre-once-S26 strings.
     *
     * @param array<string,int|float|bool> $intent   tier overrides, applied order
     * @param array<string,string>         $clauses  display clause per key, narration order
     * @param array<string,mixed>          $effective
     * @param list<string>                 $locked
     */
    private function verdict(
        string $profile,
        int $files,
        int $bytes,
        array $intent,
        array $clauses,
        string $lead,
        string $join,
        string $suffix,
        string $idle,
        array $effective,
        array $locked,
    ): Suggestion {
        $applied = [];
        $kept    = [];
        foreach ($intent as $key => $value) {
            if (in_array($key, $locked, true)) {
                $kept[] = array_key_exists($key, $effective)
                    ? sprintf('%s=%s kept (explicitly set)', $key, $this->describeEffective($effective[$key]))
                    : sprintf('%s kept (explicitly set)', $key);
                continue;
            }
            if (array_key_exists($key, $effective) && $effective[$key] == $value) {
                // PD-CFG-12: an "8→8" suggestion was pure narration noise —
                // the tier claimed to tighten a knob that already sat there.
                continue;
            }
            $applied[$key] = $value;
        }

        $parts = [];
        foreach ($clauses as $key => $clause) {
            if (array_key_exists($key, $applied)) {
                $parts[] = $clause;
            }
        }
        $body = $parts === []
            ? $idle
            : ($lead === '' ? '' : $lead . ' ') . implode($join, $parts) . $suffix;
        if ($kept !== []) {
            $body .= '; ' . implode(', ', $kept);
        }

        return new Suggestion(
            profile:   $profile,
            files:     $files,
            bytes:     $bytes,
            overrides: $applied,
            rationale: sprintf('%s corpus (%d files): %s.', $profile, $files, $body),
        );
    }

    /**
     * Display form of an effective value for the kept-narration. CLI knobs
     * arrive as raw (uncoerced) strings from parseCliOverrides — they are
     * shown trimmed, exactly as the user typed them.
     */
    private function describeEffective(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return trim($value);
        }
        return (string)$value;
    }
}
