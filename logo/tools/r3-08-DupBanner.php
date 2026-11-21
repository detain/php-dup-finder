<?php

declare(strict_types=1);

namespace Phpdup\Cli;

/**
 * DupBanner — Concept 8 "Photocopy Drift" animated truecolor ANSI logo.
 *
 * A copier light bar sweeps the phpdup wordmark (compact 3-row block font)
 * and ejects xerox copies into a fanned stack, one row down + a few cols
 * right per generation. Each generation drifts more (a duplicated char, a
 * dropped char) — type-1 -> type-2 -> type-3 clones — its paper yellows and
 * gains seeded speckle noise. The stack pulls back into alignment with the
 * master and every divergence is UNDERLINED. Similarity is COMPUTED for
 * real with char-bigram Jaccard (same math family as the repo's
 * CoherenceAnalyzer + JaccardSimilarity); the member whose mean pairwise
 * similarity falls below the analyzer's real 0.6 rule is flagged amber
 * "outlier" and slides off; the survivors collapse into the master, which
 * gains a cyan "x3" badge.
 *
 * Timing: 45 ticks @ 33 ms = 1.485 s, one-shot, final frame HOLDS.
 * Canvas: 7 rows x 56 cols, background #111827 painted on every cell of
 * every frame (no gaps, no overflow).
 * Pure frame(int $tick): no wall-clock, no unsighted RNG — deterministic.
 */
final class DupBanner
{
    public const FRAMES    = 45;      // ticks 0..44  (<= 45 allowed)
    public const LAST_TICK = self::FRAMES - 1;
    public const TICK_MS   = 33;      // 30 fps ceiling -> 1.485 s total
    public const ROWS      = 7;
    public const COLS      = 56;

    public const DEPTH_TC    = 'tc';
    public const DEPTH_256   = '256';
    public const DEPTH_PLAIN = 'plain';

    /** Wordmark + the real outlier rule from CoherenceAnalyzer (default 0.6). */
    public const MASTER = 'phpdup';
    public const OUTLIER_THRESHOLD = 0.6;

    /** Seed driving the per-generation mutation scripts + speckle noise. */
    public const SEED = 20261120;

    // ================================================================= colors

    /** @var list<int> */
    private const BG       = [17, 24, 39];      // #111827
    /** @var list<int> */
    private const MASTERFG = [248, 250, 252];   // #F8FAFC
    /** @var list<int> */
    private const BAR      = [186, 230, 253];   // #BAE6FD
    /** @var list<int> */
    private const SPECKLE  = [120, 113, 108];   // #78716C
    /** @var list<int> */
    private const AMBER    = [245, 158, 11];    // #F59E0B
    /** @var list<int> */
    private const AMBER_INK = [146, 64, 14];    // #92400E ink on amber paper
    /** @var list<int> */
    private const CYAN     = [34, 211, 238];    // #22D3EE
    /** @var list<int> */
    private const WHITE    = [255, 255, 255];
    /** @var list<int> */
    private const LEGENDFG = [100, 116, 139];   // #64748B
    /** @var list<int> */
    private const INK      = [31, 41, 55];      // #1F2937 printed text on paper

    /**
     * Paper tints per generation: copy paper yellowing to drifted grey.
     * @var list<list<int>>
     */
    private const PAPER = [
        [226, 232, 240],   // gen1 #E2E8F0
        [210, 206, 186],   // gen2 #D2CEBA yellowed
        [168, 162, 158],   // gen3 #A8A29E
    ];

    /** @var list<int> */
    private const PAPER_AMBER = [250, 204, 21]; // #FACC15 flagged wash

    // ================================================================== font

    /**
     * Compact 3-row block font, every glyph exactly 3 cells wide.
     */
    private const FONT = [
        'p' => ['█▀█', '█▀▀', '█  '],
        'h' => ['█  ', '█▀█', '█ █'],
        'd' => ['  █', '█▀█', '█▄█'],
        'u' => ['█ █', '█ █', '█▄█'],
        'x' => ['▀▄▀', '▄▀▄', '   '],
        '3' => ['▀▀▄', ' ▀█', '▀▀▀'],
    ];

    private const WORD_COL  = 1;    // master wordmark start column
    private const WORD_ADV  = 4;    // glyph pitch (3-wide + 1 gap)
    private const BADGE_COL = 26;   // "x3" badge start column
    private const CARD_COL0 = 1;    // aligned card start column
    private const CARD_ROW0 = 3;    // gen1 rests on row 3, then 4, then 5
    private const LEGEND_ROW = 6;

    // ================================================================ timeline

    private const BAR_END        = 9;   // light-bar sweep t 0..9  (~0.30 s)
    private const EJECT_START    = 6;   // gen i ejects over [6+5i, 10+5i]
    private const EJECT_STAGGER  = 5;
    private const EJECT_DUR      = 5;
    private const ALIGN_START    = 21;  // t 21..26 stack pulls back to col 1
    private const ALIGN_DUR      = 6;
    private const UL_START       = 27;  // underlines fade in at t 27/28/29
    private const SIM_SHOW       = 27;  // similarity readouts t 27..40
    private const SIM_HIDE       = 40;
    private const LEG_SHOW       = 24;  // legend row t 24..40
    private const LEG_HIDE       = 40;
    private const FLAG_TICK      = 33;  // amber outlier flag (~1.10 s)
    private const EJECTOUT_START = 35;  // outlier slides off right t 35..40
    private const EJECTOUT_DUR   = 5;
    private const BADGE_TICK     = 41;  // "x3" pops t41, settles t42..44

    /** @var list<int> survivor collapse slide starts (gen1, gen2) */
    private const COLLAPSE_START = [38, 39];
    private const COLLAPSE_DUR   = 3;

    // ================================================================= public

    /** Truecolor frame for a tick — the pure, deterministic core. */
    public static function frame(int $tick): string
    {
        return self::render($tick, self::DEPTH_TC);
    }

    /** Frame at a given depth: tc | 256 | plain. */
    public static function render(int $tick, string $depth): string
    {
        if ($tick < 0 || $tick >= self::FRAMES) {
            throw new \InvalidArgumentException("DupBanner tick out of range: {$tick}");
        }
        if (!in_array($depth, [self::DEPTH_TC, self::DEPTH_256, self::DEPTH_PLAIN], true)) {
            throw new \InvalidArgumentException("DupBanner unknown depth: {$depth}");
        }
        return self::encode(self::grid($tick), $depth);
    }

    /** Fully assembled, settled hold frame. */
    public static function finalFrame(string $depth = self::DEPTH_TC): string
    {
        return self::render(self::LAST_TICK, $depth);
    }

    /** Static plain-text fallback: zero escape codes. */
    public static function text(): string
    {
        return self::finalFrame(self::DEPTH_PLAIN);
    }

    /**
     * One-shot byte stream: plays 45 frames (~1.485 s at 30 fps) and leaves
     * the final frame painted (HOLD). Plain depth -> static text only.
     */
    public static function play(?string $depth = null): string
    {
        $depth ??= self::resolveDepth();
        if ($depth === self::DEPTH_PLAIN) {
            return self::finalFrame($depth);
        }
        $parts = [];
        for ($t = 0; $t < self::FRAMES; $t++) {
            $parts[] = self::render($t, $depth);
        }
        return "\e[?25l\e[H" . implode("\e[" . self::ROWS . "A\r", $parts) . "\e[?25h";
    }

    /** Fallback chain: NO_COLOR/TERM=dumb -> plain; COLORTERM -> tc; TERM*256color* -> 256; else plain. */
    public static function resolveDepth(): string
    {
        if (getenv('NO_COLOR') !== false) {
            return self::DEPTH_PLAIN;
        }
        $term = (string) (getenv('TERM') ?: '');
        if ($term === 'dumb') {
            return self::DEPTH_PLAIN;
        }
        $ct = strtolower((string) (getenv('COLORTERM') ?: ''));
        if ($ct === 'truecolor' || $ct === '24bit') {
            return self::DEPTH_TC;
        }
        if (str_contains($term, '256color')) {
            return self::DEPTH_256;
        }
        return self::DEPTH_PLAIN;
    }

    // ============================================================= clone math

    /**
     * Mutation scripts for the three generations. g1 duplicates the tail
     * char (type-1 paste artifact), g2 drops it (type-2), g3 swaps 'h' to
     * 'p' and swallows a redundant 'p' (type-3: one swapped, one dropped).
     * The same SEED constant feeds the speckle-noise layout below, so every
     * stochastic-looking element of the art is deterministic.
     *
     * @return list<string> copy strings, gen1..gen3
     */
    public static function generations(): array
    {
        // The scripts are the type-1/type-2/type-3 taxonomy from the concept,
        // declared against SEED (same seed governs speckle placement below);
        // g1 duplicates the tail char, g2 drops it, g3 drops 'h' + tail.
        $scripts = [
            [['op' => 'dup',  'at' => 5, 'to' => '']],
            [['op' => 'drop', 'at' => 5, 'to' => '']],
            [['op' => 'swap', 'at' => 1, 'to' => 'p'], ['op' => 'drop', 'at' => 2, 'to' => '']],
        ];
        $gens = [];
        foreach ($scripts as $script) {
            $s = self::MASTER;
            foreach ($script as $o) {
                $at = (int) $o['at'];
                if ($at >= strlen($s)) {
                    continue;
                }
                if ($o['op'] === 'drop') {
                    $s = substr($s, 0, $at) . substr($s, $at + 1);
                } elseif ($o['op'] === 'dup') {
                    $s .= substr($s, $at, 1);
                } else {
                    $s = substr($s, 0, $at) . $o['to'] . substr($s, $at + 1);
                }
            }
            $gens[] = $s;
        }
        return $gens;
    }

    /** @return list<string> distinct char-bigrams of a string */
    public static function bigrams(string $s): array
    {
        $bag = [];
        for ($i = 0, $n = strlen($s) - 1; $i < $n; $i++) {
            $bag[substr($s, $i, 2)] = 1;
        }
        return array_keys($bag);
    }

    /**
     * Jaccard index over bigram sets — mirrors the analyzer's scorer.
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function jaccard(array $a, array $b): float
    {
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $inter / $union : 1.0;
    }

    /** Similarity of copy $genIdx (0..2) to the master (bigram Jaccard). */
    public static function simToMaster(int $genIdx): float
    {
        $gens = self::generations();
        if (!isset($gens[$genIdx])) {
            throw new \InvalidArgumentException("no gen {$genIdx}");
        }
        return self::jaccard(self::bigrams(self::MASTER), self::bigrams($gens[$genIdx]));
    }

    /**
     * Mean pairwise similarity to the REST of the cluster
     * [master, gen1, gen2, gen3] — the CoherenceAnalyzer rule.
     */
    public static function meanPairwise(int $memberIdx): float
    {
        $members = array_merge([self::MASTER], self::generations());
        if (!isset($members[$memberIdx])) {
            throw new \InvalidArgumentException("no member {$memberIdx}");
        }
        $sum  = 0.0;
        $seen = 0;
        foreach ($members as $j => $other) {
            if ($j === $memberIdx) {
                continue;
            }
            $sum += self::jaccard(self::bigrams($members[$memberIdx]), self::bigrams($other));
            $seen++;
        }
        return $seen > 0 ? $sum / $seen : 1.0;
    }

    /**
     * Gen index flagged amber by the REAL "< threshold mean pairwise" rule,
     * or null when the cluster is coherent. Fewer than 3 members -> no room
     * for an outlier, exactly like the analyzer's early return.
     */
    public static function outlierGen(): ?int
    {
        $members = array_merge([self::MASTER], self::generations());
        if (count($members) < 3) {
            return null;
        }
        foreach (array_keys(self::generations()) as $i) {
            if (self::meanPairwise($i + 1) < self::OUTLIER_THRESHOLD) {
                return $i;
            }
        }
        return null;
    }

    // ================================================================ layout

    /**
     * Align a copy to master slots via LCS so every divergence is visible:
     * holes (chars the copy dropped) and extras (chars the copy added).
     *
     * @return list<array{ch:string, slot:int, mark:bool}> ordered by slot
     */
    public static function copyLayout(int $genIdx): array
    {
        $gens = self::generations();
        if (!isset($gens[$genIdx])) {
            throw new \InvalidArgumentException("no gen {$genIdx}");
        }
        $a  = self::MASTER;
        $b  = $gens[$genIdx];
        $na = strlen($a);
        $nb = strlen($b);

        $dp = array_fill(0, $na + 1, array_fill(0, $nb + 1, 0));
        for ($i = $na - 1; $i >= 0; $i--) {
            for ($j = $nb - 1; $j >= 0; $j--) {
                $dp[$i][$j] = substr($a, $i, 1) === substr($b, $j, 1)
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        /** @var array<int, int> copy-char-index -> master-slot */
        $slotOfChar = [];
        $i = 0;
        $j = 0;
        while ($i < $na && $j < $nb) {
            if (substr($a, $i, 1) === substr($b, $j, 1)) {
                $slotOfChar[$j] = $i;
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        $out = [];
        for ($slot = 0; $slot < $na; $slot++) {
            $placed = null;
            foreach ($slotOfChar as $cj => $cs) {
                if ($cs === $slot) {
                    $placed = $cj;
                    break;
                }
            }
            $out[] = $placed === null
                ? ['ch' => ' ', 'slot' => $slot, 'mark' => true]   // dropped char
                : ['ch' => substr($b, $placed, 1), 'slot' => $slot, 'mark' => false];
        }
        $next = $na;
        for ($cj = 0; $cj < $nb; $cj++) {
            if (!isset($slotOfChar[$cj])) {
                $out[] = ['ch' => substr($b, $cj, 1), 'slot' => $next++, 'mark' => true]; // extra char
            }
        }
        return $out;
    }

    // =================================================================== grid

    /**
     * @return array<int, array<int, array{ch:string, fg:list<int>, bg:list<int>, ul:bool}>>
     */
    private static function grid(int $tick): array
    {
        $g = [];
        for ($y = 0; $y < self::ROWS; $y++) {
            for ($x = 0; $x < self::COLS; $x++) {
                $g[$y][$x] = ['ch' => ' ', 'fg' => self::BG, 'bg' => self::BG, 'ul' => false];
            }
        }

        // Copies are painted BEFORE the master so they "eject from behind"
        // it while their slide overlaps rows 0..2 (master wins z-order).
        $flagIx = self::outlierGen();
        foreach (array_keys(self::generations()) as $i) {
            $state = self::cardState($i, $tick);
            if ($state === null) {
                continue;
            }
            self::paintCard($g, $i, $state[0], $state[1], $tick, $state[2], $flagIx);
        }

        // Master wordmark (3-row block font).
        for ($ci = 0; $ci < strlen(self::MASTER); $ci++) {
            $ch = substr(self::MASTER, $ci, 1);
            self::glyph($g, self::WORD_COL + $ci * self::WORD_ADV, $ch, self::MASTERFG);
        }

        // Light bar: bright column + bg bloom over the wordmark rows.
        if ($tick <= self::BAR_END) {
            $barX = 1 + (int) round(23 * $tick / self::BAR_END);
            $bloom = self::mix(self::BG, self::BAR, 0.16);
            for ($r = 0; $r < 3; $r++) {
                if ($barX > 0) {
                    self::setBg($g, $barX - 1, $r, $bloom);
                }
                if ($barX + 1 < self::COLS) {
                    self::setBg($g, $barX + 1, $r, $bloom);
                }
                self::put($g, $barX, $r, '█', self::BAR, self::BG);
            }
        }

        // x3 badge (white pop, then cyan hold).
        if ($tick >= self::BADGE_TICK) {
            $fgc = $tick === self::BADGE_TICK ? self::WHITE : self::CYAN;
            if ($tick === self::BADGE_TICK) {
                $bloom = self::mix(self::BG, self::CYAN, 0.30);
                self::rect($g, self::BADGE_COL - 1, 0, self::BADGE_COL + 6, 2, $bloom);
            }
            self::glyph($g, self::BADGE_COL, 'x', $fgc);
            self::glyph($g, self::BADGE_COL + 4, '3', $fgc);
        }

        // Legend row.
        if ($tick >= self::LEG_SHOW && $tick <= self::LEG_HIDE) {
            self::paintLine($g, 1, self::LEGEND_ROW, 'mean pairwise < 0.6 -> outlier', self::LEGENDFG);
        }

        return $g;
    }

    /**
     * Per-card geometry for a tick: [col, row, flagged] or null when hidden.
     *
     * @return array{0:int, 1:int, 2:bool}|null
     */
    private static function cardState(int $i, int $tick): ?array
    {
        $fanCol  = self::CARD_COL0 + 3 * ($i + 1);
        $restRow = self::CARD_ROW0 + $i;

        $eStart = self::EJECT_START + self::EJECT_STAGGER * $i;
        $eEnd   = $eStart + self::EJECT_DUR - 1;

        if ($tick < $eStart) {
            return null;
        }
        if ($tick <= $eEnd) {
            $k   = ($tick - $eStart) / self::EJECT_DUR;
            $ke  = 1 - (1 - $k) * (1 - $k);                         // easeOutQuad
            $col = (int) round(self::CARD_COL0 + ($fanCol - self::CARD_COL0) * $ke);
            $row = 2 + (int) round(($restRow - 2) * $ke);
            return [$col, $row, false];
        }
        $aEnd = self::ALIGN_START + self::ALIGN_DUR - 1;
        if ($tick < self::ALIGN_START) {
            return [$fanCol, $restRow, false];
        }
        if ($tick <= $aEnd) {
            $k   = ($tick - self::ALIGN_START) / self::ALIGN_DUR;
            $ke  = $k < 0.5 ? 2 * $k * $k : 1 - (-2 * $k + 2) ** 2 / 2; // easeInOutQuad
            $col = (int) round($fanCol + (self::CARD_COL0 - $fanCol) * $ke);
            return [$col, $restRow, false];
        }

        // The flagged outlier (gen3 by the 0.6 rule) turns amber at FLAG_TICK,
        // then slides off right.
        if ($i === 2 && $i === self::outlierGen()) {
            $eoEnd = self::EJECTOUT_START + self::EJECTOUT_DUR;
            if ($tick < self::FLAG_TICK) {
                return [self::CARD_COL0, $restRow, false];
            }
            if ($tick < self::EJECTOUT_START) {
                return [self::CARD_COL0, $restRow, true];
            }
            if ($tick <= $eoEnd) {
                $k   = ($tick - self::EJECTOUT_START) / self::EJECTOUT_DUR;
                $col = (int) round(self::CARD_COL0 + (self::COLS + 8 - self::CARD_COL0) * $k);
                return [$col, $restRow, true];
            }
            return null;
        }

        // Survivors collapse upward into the master, then vanish.
        $cStart = self::COLLAPSE_START[$i];
        if ($tick < $cStart) {
            return [self::CARD_COL0, $restRow, false];
        }
        if ($tick <= $cStart + self::COLLAPSE_DUR) {
            $k   = ($tick - $cStart) / self::COLLAPSE_DUR;
            $row = $restRow - (int) round(($restRow - 2) * $k);
            return [self::CARD_COL0, $row, false];
        }
        return null;
    }

    /**
     * Paint one paper card: tinted paper, inked copy chars aligned to master
     * slots, underlined diffs once aligned, seeded speckle noise, optional
     * amber flag, and the COMPUTED similarity-to-master readout.
     *
     * @param array<int, array<int, array{ch: string, fg: list<int>, bg: list<int>, ul: bool}>> $g
     */
    private static function paintCard(
        array &$g,
        int $i,
        int $col,
        int $row,
        int $tick,
        bool $flagged,
        ?int $flagIx
    ): void {
        $layout  = self::copyLayout($i);
        $maxSlot = 0;
        foreach ($layout as $u) {
            $maxSlot = max($maxSlot, $u['slot']);
        }
        $cardW = $maxSlot + 1 + 2;                          // +1 pad each side
        $paper = $flagged ? self::PAPER_AMBER : self::PAPER[$i];
        $ink   = $flagged ? self::AMBER_INK : self::INK;

        self::rect($g, $col, $row, $col + $cardW - 1, $row, $paper);

        $ulOn = $tick >= self::UL_START;                    // after align phase
        foreach ($layout as $u) {
            self::put($g, $col + 1 + $u['slot'], $row, $u['ch'], $ink, $paper, $ulOn && $u['mark']);
        }

        // Seeded speckle noise, more as generations drift.
        $specks = 1 + 2 * $i;
        $s        = self::SEED + 977 * ($i + 1);
        for ($n = 0; $n < $specks; $n++) {
            $s  = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
            $sx = $col + 1 + $s % max(1, $cardW - 2);
            if ($sx >= self::COLS) {
                continue;
            }
            if ($g[$row][$sx]['ch'] === ' ' && !$g[$row][$sx]['ul']) {
                self::put($g, $sx, $row, '·', self::SPECKLE, $g[$row][$sx]['bg']);
            }
        }

        if ($tick >= self::SIM_SHOW && $tick <= self::SIM_HIDE) {
            $tag = sprintf('s=%.3f', self::simToMaster($i));
            $fgt = $i === $flagIx ? self::AMBER : $paper;
            self::paintLine($g, $col + $cardW + 1, $row, $tag, $fgt);
        }
        if ($flagged && $tick >= self::FLAG_TICK && $tick <= self::EJECTOUT_START + self::EJECTOUT_DUR) {
            self::paintLine($g, $col + $cardW + 8, $row, 'outlier', self::AMBER);
        }
    }

    // ================================================================ paint

    /**
     * @param array<int, array<int, array{ch: string, fg: list<int>, bg: list<int>, ul: bool}>> $g
     * @param list<int> $fg
     * @param list<int> $bg
     */
    private static function put(array &$g, int $x, int $y, string $ch, array $fg, array $bg, bool $ul = false): void
    {
        if ($y < 0 || $y >= self::ROWS || $x < 0 || $x >= self::COLS) {
            return;
        }
        $g[$y][$x] = ['ch' => $ch, 'fg' => $fg, 'bg' => $bg, 'ul' => $ul];
    }

    /**
     * @param array<int, array<int, array{ch: string, fg: list<int>, bg: list<int>, ul: bool}>> $g
     * @param list<int> $bg
     */
    private static function rect(array &$g, int $x0, int $y0, int $x1, int $y1, array $bg): void
    {
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                self::setBg($g, $x, $y, $bg);
            }
        }
    }

    /**
     * Full-cell read-modify-write keeps the Cell shape exact for analysers.
     *
     * @param array<int, array<int, array{ch: string, fg: list<int>, bg: list<int>, ul: bool}>> $g
     * @param list<int> $bg
     */
    private static function setBg(array &$g, int $x, int $y, array $bg): void
    {
        if ($y < 0 || $y >= self::ROWS || $x < 0 || $x >= self::COLS) {
            return;
        }
        $cell        = $g[$y][$x];
        $cell['bg']  = $bg;
        $g[$y][$x]   = $cell;
    }

    /**
     * @param array<int, array<int, array{ch: string, fg: list<int>, bg: list<int>, ul: bool}>> $g
     * @param list<int> $fg
     */
    private static function glyph(array &$g, int $col, string $ch, array $fg): void
    {
        $rows = self::FONT[$ch] ?? null;
        if ($rows === null) {
            return;
        }
        for ($r = 0; $r < 3; $r++) {
            foreach (mb_str_split($rows[$r]) as $c => $cellCh) {
                if ($cellCh !== ' ') {
                    self::put($g, $col + $c, $r, $cellCh, $fg, self::BG);
                }
            }
        }
    }

    /**
     * @param array<int, array<int, array{ch: string, fg: list<int>, bg: list<int>, ul: bool}>> $g
     * @param list<int> $fg
     */
    private static function paintLine(array &$g, int $col, int $row, string $str, array $fg): void
    {
        $x = $col;
        foreach (mb_str_split($str) as $c) {
            if ($c !== ' ') {
                self::put($g, $x, $row, $c, $fg, self::BG);
            }
            $x++;
        }
    }

    // ================================================================ encode

    /** @param array<int, array<int, array{ch:string, fg:list<int>, bg:list<int>, ul:bool}>> $grid */
    private static function encode(array $grid, string $depth): string
    {
        $lines = [];
        foreach ($grid as $row) {
            $buf    = '';
            $curKey = null;
            foreach ($row as $cell) {
                if ($depth === self::DEPTH_PLAIN) {
                    $buf .= $cell['ch'] === ' ' ? ' ' : $cell['ch'];
                    continue;
                }
                $key = self::styleKey($cell, $depth);
                if ($key !== $curKey) {
                    $buf .= "\e[0m" . ($key === '' ? '' : "\e[" . $key . 'm');
                    $curKey = $key;
                }
                $buf .= $cell['ch'];
            }
            if ($depth !== self::DEPTH_PLAIN) {
                $buf .= "\e[0m";
            }
            $lines[] = $depth === self::DEPTH_PLAIN ? rtrim($buf, ' ') : $buf;
        }
        return implode("\n", $lines);
    }

    /** @param array{ch:string, fg:list<int>, bg:list<int>, ul:bool} $cell */
    private static function styleKey(array $cell, string $depth): string
    {
        $codes = [];
        if ($cell['ul']) {
            $codes[] = '4';
        }
        if ($depth === self::DEPTH_TC) {
            $codes[] = '38;2;' . implode(';', $cell['fg']);
            $codes[] = '48;2;' . implode(';', $cell['bg']);
        } else {
            $codes[] = '38;5;' . self::xtermIndex($cell['fg']);
            $codes[] = '48;5;' . self::xtermIndex($cell['bg']);
        }
        return implode(';', $codes);
    }

    /**
     * Nearest colour on the xterm-256 palette (full 240 + greyscan).
     *
     * @param list<int> $rgb
     */
    private static function xtermIndex(array $rgb): int
    {
        /** @var array<int, list<int>> $table */
        static $table = [];
        if ($table === []) {
            for ($n = 0; $n < 256; $n++) {
                if ($n < 16) {
                    $table[$n] = $n < 8
                        ? [[0, 0, 0], [128, 0, 0], [0, 128, 0], [128, 128, 0], [0, 0, 128], [128, 0, 128], [0, 128, 128], [192, 192, 192]][$n]
                        : [[128, 128, 128], [255, 0, 0], [0, 255, 0], [255, 255, 0], [0, 0, 255], [255, 0, 255], [0, 255, 255], [255, 255, 255]][$n - 8];
                } elseif ($n < 232) {
                    $j    = $n - 16;
                    $cube = [0, 95, 135, 175, 215, 255];
                    $r  = max(0, min(5, intdiv($j, 36)));
                    $gc = max(0, min(5, intdiv($j, 6) % 6));
                    $bc = max(0, min(5, $j % 6));
                    $table[$n] = [$cube[$r], $cube[$gc], $cube[$bc]];
                } else {
                    $v = 8 + ($n - 232) * 10;
                    $table[$n] = [$v, $v, $v];
                }
            }
        }
        $best   = 0;
        $bestD  = -1.0;
        foreach ($table as $n => $c) {
            $dr = $c[0] - $rgb[0];
            $dg = $c[1] - $rgb[1];
            $db = $c[2] - $rgb[2];
            $d  = 2 * $dr * $dr + 4 * $dg * $dg + 3 * $db * $db;   // redmean
            if ($bestD < 0 || $d < $bestD) {
                $bestD = (float) $d;
                $best  = $n;
            }
        }
        return $best;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function mix(array $a, array $b, float $t): array
    {
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $out[] = (int) round($a[$i] + ($b[$i] - $a[$i]) * $t);
        }
        return $out;
    }
}
