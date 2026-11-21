<?php

declare(strict_types=1);

namespace Phpdup\Branding;

/**
 * "Normalization Erosion" — truecolor animated ANSI logo for phpdup.
 *
 * Two PHP lines that look DIFFERENT are stacked. Three scanning beams sweep
 * left-to-right, one per Normalizer mode (strict / default / aggressive).
 * Behind a beam, characters are REWRITTEN to exactly what the real
 * Phpdup\Normalization\Normalizer returns (harvested at build time into
 * normalized.php - never invented), and any column that now matches across
 * both lines turns red -> green. After the last beam the lines are identical;
 * the bottom line slides up onto the top, and the merged skeleton
 * cross-dissolves into the wordmark.
 *
 * Round-3 concept 2. Hard contract (owner spec):
 *  - 24-bit SGR, falling back to 256-colour, then plain text (zero escapes).
 *  - one-shot: TICKS * TICK_MS <= 1.5 s, <= 30 fps, <= 45 ticks, hold final.
 *  - 7 rows x <= 56 cols; both snippet lines fit at EVERY stage.
 *  - frame(int $tick): string is pure, seeded, clock-free; tick 0 and the
 *    final tick are byte-reproducible across processes.
 *  - THE REPO IS NEVER TOUCHED AT FRAME TIME: the Normalizer output lives in
 *    normalized.php (regenerate: php harvest.php --write).
 */
final class DupBanner
{
    /** Animation length. Playback = 44 * 34 ms = 1.496 s, 29.4 fps. */
    public const TICKS = 44;

    /** Suggested per-tick delay for terminal playback. */
    public const TICK_MS = 34;

    public const ROWS = 7;
    public const COLS = 56;

    // Phase bounds (inclusive last tick). Concept 0.3 + 3x0.3 + 0.25 + 0.25 s
    // = 1.65 s, over the 1.5 s budget -> compressed x0.906 (8/24/6/6 ticks).
    public const HOLD_END = 7;
    public const BEAM_ENDS = [15, 23, 31];
    public const MERGE_END = 37;
    // reveal 38..43

    private const ROW_TITLE = 0;
    private const ROW_LEGEND = 1;
    private const ROW_LINE_A = 2;
    private const ROW_LINE_B = 3;
    private const ROW_METER = 4;
    private const ROW_STATUS = 5;
    private const ROW_FOOTER = 6;

    // Palette (owner-specified).
    /** @var array{int,int,int} */
    private const C_STRICT = [148, 163, 184];    // #94A3B8
    /** @var array{int,int,int} */
    private const C_DEFAULT = [245, 158, 11];    // #F59E0B
    /** @var array{int,int,int} */
    private const C_AGGR = [168, 85, 247];       // #A855F7
    /** @var array{int,int,int} */
    private const C_MISMATCH = [248, 113, 113];  // #F87171
    /** @var array{int,int,int} */
    private const C_MATCH = [74, 222, 128];      // #4ADE80
    /** @var array{int,int,int} */
    private const C_MARK = [248, 250, 252];      // #F8FAFC
    /** @var array{int,int,int} */
    private const C_SLATE = [100, 116, 139];     // #64748B
    /** @var array{int,int,int} */
    private const C_DIM = [51, 65, 85];          // #334155
    /** @var array{int,int,int} */
    private const C_BG = [11, 17, 32];           // #0B1120

    /** @var list<string> beam order = Normalizer modes */
    private const BEAMS = ['strict', 'default', 'aggressive'];

    /**
     * Block glyphs, 4 rows x 4 cols, keyed by letter.
     * @var array<string, list<string>>
     */
    private const FONT = [
        'P' => ['1111', '1001', '1110', '1000'],
        'H' => ['1001', '1001', '1111', '1001'],
        'D' => ['1110', '1001', '1001', '1110'],
        'U' => ['1001', '1001', '1001', '1110'],
    ];

    public const WORDMARK = 'PHPDUP';

    private const TITLE_TEXT = 'normalization erosion';
    private const TAGLINE = 'AST duplicate-logic finder';
    private const FOOTER_TEXT = 'bin/phpdup analyze src --normalize';

    /** @var list<string> status lines: 0 hold, 1..3 beams, 4 merge, 5 merged */
    private const STATUS = [
        '2 snippets - columns that differ burn red',
        'beam 1/3 strict: identifiers fold to __V0',
        'beam 2/3 default: literals fold to __STR',
        'beam 3/3 aggressive: names fold away too',
        'skeletons match - bottom line slides up',
        'one canonical skeleton',
    ];

    public function __construct(
        private readonly string $depth = 'tc',
    ) {
        if (!in_array($depth, ['tc', '256', 'plain'], true)) {
            throw new \InvalidArgumentException("Unknown colour depth: {$depth}");
        }
    }

    /** truecolor -> 256 -> plain chain from NO_COLOR / TERM / COLORTERM. */
    public static function fromEnv(): self
    {
        if (getenv('NO_COLOR') !== false && getenv('NO_COLOR') !== '') {
            return new self('plain');
        }
        if (getenv('TERM') === 'dumb') {
            return new self('plain');
        }
        $ct = (string) getenv('COLORTERM');
        if ($ct === 'truecolor' || $ct === '24bit') {
            return new self('tc');
        }
        return new self('256');
    }

    public function depth(): string
    {
        return $this->depth;
    }

    /**
     * Single source of truth: deterministic, clock-free paint of tick $tick.
     * Clamped; ticks at/after the end return the held final frame.
     */
    public function frame(int $tick): string
    {
        $tick = max(0, min(self::TICKS - 1, $tick));
        if ($this->depth === 'plain') {
            return $this->plain();
        }
        return $this->encode($this->grid($tick));
    }

    /** Final held frame as one paint (static display). */
    public function render(): string
    {
        return $this->frame(self::TICKS - 1);
    }

    /**
     * Full one-shot stream: hide cursor, paint every tick joined by a
     * cursor-up overwrite, final frame held, show cursor.
     */
    public function play(): string
    {
        if ($this->depth === 'plain') {
            return $this->plain();
        }
        $frames = [];
        for ($t = 0; $t < self::TICKS; $t++) {
            $frames[] = $this->frame($t);
        }
        return "\e[?25l" . implode("\e[" . self::ROWS . "A\r", $frames) . "\e[0m\e[?25h";
    }

    /** Plain-text fallback: the FINAL held state, ZERO escape codes. */
    public function plain(): string
    {
        $out = "\u{2713}strict  \u{2713}default  \u{2713}aggressive\n";
        foreach (self::wordmarkRows('#') as $row) {
            $out .= $row . "\n";
        }
        $out .= self::TAGLINE . "\n";
        return $out;
    }

    // -------------------------------------------------------------- engine data

    /**
     * Frozen output of the REAL Normalizer (build-time harvest) - a plain file
     * read, no parser, no repo, no clock. Fail loud if absent or untrue.
     *
     * @return array{snippets: array<string,string>, modes: array<string, array<string,string>>}
     */
    private static function data(): array
    {
        /** @var array<array-key, mixed>|null $data */
        static $data = null;
        if (is_array($data)) {
            /** @var array{snippets: array<string,string>, modes: array<string, array<string,string>>} $data */
            return $data;
        }
        $file = __DIR__ . '/normalized.php';
        if (!is_file($file)) {
            throw new \RuntimeException(
                'normalized.php missing - run: php harvest.php --write'
                . ' (build-time cache of the real Normalizer output)',
            );
        }
        /** @var array<array-key, mixed> $loaded */
        $loaded = require $file;
        if (!is_array($loaded)) {
            throw new \RuntimeException('normalized.php did not return an array');
        }
        $need = ['strict', 'default', 'aggressive'];
        foreach ($need as $m) {
            if (!isset($loaded['modes'][$m]['A'], $loaded['modes'][$m]['B'])) {
                throw new \RuntimeException("normalized.php lacks mode '$m'");
            }
        }
        /** @var array{snippets: array<string,string>, modes: array<string, array<string,string>>} $typed */
        $typed = $loaded;
        if ($typed['modes']['aggressive']['A'] !== $typed['modes']['aggressive']['B']) {
            throw new \RuntimeException('aggressive lines are not identical - merge premise broken');
        }
        return $data = $typed;
    }

    // ----------------------------------------------------------------- painting

    /**
     * Paint tick $tick into a 7 x 56 cell grid.
     *
     * @return list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>>
     */
    private function grid(int $tick): array
    {
        $grid = self::blank();
        $st = $this->stageState($tick);
        $mode = $st['mode'];

        // Title + (until wordmark takeover) legend row.
        $titleCol = $mode >= 0 && $mode <= 2 ? self::beamColor($mode) : self::C_SLATE;
        self::put($grid, self::ROW_TITLE, 0, self::TITLE_TEXT, $titleCol);

        if ($st['reveal'] < 0) {
            // ---------- erosion + merge: stacked snippet lines, red->green
            self::paintLegend($grid, $tick);
            $newA = $st['newA'];
            $newB = $st['newB'];
            $this->paintSnippetRow($grid, self::ROW_LINE_A, $st['seqA'], $newA, $st['seqB'], $newB, $st['beam'], $st['head'], $st['mergeHead'], 0);
            $this->paintSnippetRow($grid, self::ROW_LINE_B, $st['seqB'], $newB, $st['seqA'], $newA, $st['beam'], $st['head'], $st['mergeHead'], 1);
            [$hits, $len] = $this->matchCount($st);
            self::paintMeter($grid, $hits, $len);
            self::paintStatus($grid, $this->statusIndex($tick, $st));
            self::put($grid, self::ROW_FOOTER, intdiv(self::COLS - strlen(self::FOOTER_TEXT), 2), self::FOOTER_TEXT, self::C_DIM);
        } else {
            // ---------- reveal: skeleton cross-dissolves into the wordmark
            self::paintDissolve($grid, $st['skel'], $st['reveal']);
            self::paintLegend($grid, $tick);
            self::put($grid, self::ROW_FOOTER, intdiv(self::COLS - strlen(self::TAGLINE), 2), self::TAGLINE, self::C_SLATE);
        }
        return $grid;
    }

    /**
     * Decode the tick into everything the painters need (strings + heads).
     *
     * @return array{
     *     seqA: string, seqB: string, newA: string, newB: string,
     *     beam: int, head: int, mode: int, mergeHead: int, reveal: float,
     *     skel: string
     * }
     */
    private function stageState(int $tick): array
    {
        $d = self::data();
        $chainA = [$d['snippets']['A']];
        $chainB = [$d['snippets']['B']];
        foreach (self::BEAMS as $m) {
            $chainA[] = $d['modes'][$m]['A'];
            $chainB[] = $d['modes'][$m]['B'];
        }
        $skel = $chainA[3];

        if ($tick <= self::HOLD_END) {
            return [
                'seqA' => $chainA[0], 'seqB' => $chainB[0], 'newA' => '', 'newB' => '',
                'beam' => -1, 'head' => -1, 'mode' => -1, 'mergeHead' => -1,
                'reveal' => -1.0, 'skel' => $skel,
            ];
        }
        if ($tick <= self::BEAM_ENDS[2]) {
            $mode = intdiv($tick - self::HOLD_END - 1, 8);
            $p = ($tick - self::HOLD_END - 1) % 8;
            $len = max(strlen($chainA[$mode]), strlen($chainB[$mode]), strlen($chainA[$mode + 1]), strlen($chainB[$mode + 1]));
            $head = (int) round((($p + 1) / 8) * ($len + 2)) - 1;
            return [
                'seqA' => $chainA[$mode], 'seqB' => $chainB[$mode],
                'newA' => $chainA[$mode + 1], 'newB' => $chainB[$mode + 1],
                'beam' => $mode, 'head' => $head, 'mode' => $mode, 'mergeHead' => -1,
                'reveal' => -1.0, 'skel' => $skel,
            ];
        }
        if ($tick <= self::MERGE_END) {
            $p = $tick - self::BEAM_ENDS[2] - 1;
            $len = strlen($skel);
            $mergeHead = (int) round((($p + 1) / 6) * ($len + 2)) - 1;
            return [
                'seqA' => $skel, 'seqB' => $skel, 'newA' => $skel, 'newB' => $skel,
                'beam' => -1, 'head' => -1, 'mode' => 3, 'mergeHead' => $mergeHead,
                'reveal' => -1.0, 'skel' => $skel,
            ];
        }
        $p = $tick - self::MERGE_END - 1;
        return [
            'seqA' => $skel, 'seqB' => $skel, 'newA' => $skel, 'newB' => $skel,
            'beam' => -1, 'head' => -1, 'mode' => 3, 'mergeHead' => -1,
            'reveal' => ($p + 1) / 6, 'skel' => $skel,
        ];
    }

    /**
     * @param array{seqA:string,seqB:string,newA:string,newB:string,beam:int,head:int,mode:int,mergeHead:int,reveal:float,skel:string} $st
     * @return array{0:int,1:int} matching columns, compared length
     */
    private function matchCount(array $st): array
    {
        $a = $st['seqA'];
        $b = $st['seqB'];
        if ($st['beam'] >= 0) {
            [$a, $b] = $this->revealBeamed($st);
        }
        $len = max(strlen($a), strlen($b));
        $hits = 0;
        for ($c = 0; $c < $len; $c++) {
            if (($a[$c] ?? ' ') === ($b[$c] ?? ' ')) {
                $hits++;
            }
        }
        return [$hits, $len];
    }

    /** Effective full visible strings given the beam head (for the meter).
     *
     * @param array{seqA:string,seqB:string,newA:string,newB:string,beam:int,head:int,mode:int,mergeHead:int,reveal:float,skel:string} $st
     * @return array{0:string,1:string}
     */
    private function revealBeamed(array $st): array
    {
        $head = $st['head'];
        $out = [];
        foreach (['A', 'B'] as $k) {
            $old = $st['seq' . $k];
            $new = $st['new' . $k];
            $len = max(strlen($old), strlen($new));
            $s = '';
            for ($c = 0; $c < $len; $c++) {
                $s .= $c <= $head ? ($new[$c] ?? ' ') : ($old[$c] ?? ' ');
            }
            $out[$k] = rtrim($s);
        }
        return [$out['A'], $out['B']];
    }

    /**
     * Draw one snippet row under the sweep rules.
     *  - c <= head      : NEW text (beam has passed) - match colour + tail bg
     *  - head < c <= +2 : 2-column bright band (beam colour)
     *  - c > head+2     : OLD text - match colour
     * During merge the row-A cells behind the head flash toward the mark.
     *
     * @param list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>> $grid
     */
    private function paintSnippetRow(
        array &$grid,
        int $row,
        string $old,
        string $new,
        string $otherOld,
        string $otherNew,
        int $beam,
        int $head,
        int $mergeHead,
        int $which,
    ): void {
        $len = max(strlen($old), strlen($new), strlen($otherOld), strlen($otherNew));
        $beamCol = $beam >= 0 ? self::beamColor($beam) : self::C_BG;
        for ($c = 0; $c < $len; $c++) {
            $passed = $beam >= 0 && $c <= $head;
            $inBand = $beam >= 0 && $c > $head && $c <= $head + 1;
            $selfCh = $passed || $inBand ? ($new[$c] ?? ' ') : ($old[$c] ?? ' ');
            $othCh = $passed || $inBand ? ($otherNew[$c] ?? ' ') : ($otherOld[$c] ?? ' ');

            if ($mergeHead >= 0) {
                // identical lines merging: A row gets a travelling bright head
                $selfCh = $new[$c] ?? ' ';
                $othCh = $new[$c] ?? ' ';
            }

            $match = $selfCh === $othCh;
            $fg = $match ? self::C_MATCH : self::C_MISMATCH;
            $bg = null;

            if ($beam >= 0) {
                if ($inBand) {
                    $fg = self::mix($beamCol, [255, 255, 255], 0.55);
                    $bg = $beamCol;
                } else {
                    $bg = self::tailBg($beamCol, $head, $c);
                }
            }
            if ($mergeHead >= 0 && $which === 0) {
                if ($c <= $mergeHead) {
                    $fg = self::mix(self::C_MATCH, self::C_MARK, 0.85);
                }
            }
            if ($mergeHead >= 0 && $which === 1) {
                // rising line: trail echo behind the head, bright ahead
                if ($c <= $mergeHead) {
                    $fg = self::C_BG;
                    $bg = self::tailBg(self::C_MATCH, $mergeHead, $c);
                } else {
                    $fg = self::C_MATCH;
                }
            }
            if ($mergeHead >= 0 && $which === 1 && $c <= $mergeHead && $bg === null) {
                $selfCh = ' ';
            }
            $grid[$row][1 + $c] = self::cell($selfCh === ' ' && $bg === null ? ' ' : $selfCh, $fg, $bg);
        }
    }

    /** Skeleton cross-dissolves into the wordmark (seeded cell order).
     *
     * @param list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>> $grid
     */
    private function paintDissolve(array &$grid, string $skel, float $f): void
    {
        $n = self::ROWS * self::COLS;
        $order = self::seededOrder($n);

        // layer 1 - skeleton (same indent as the snippet rows) dissolving OUT
        $x0 = 1;
        $len = strlen($skel);
        for ($c = 0; $c < $len; $c++) {
            $u = $order[$c % $n] / $n;
            if ($f <= $u) {
                $col = self::mix(self::C_MATCH, self::C_MARK, min(1.0, $f * 1.35));
                $grid[self::ROW_LINE_A][$x0 + $c] = self::cell($skel[$c], $col, null);
            }
        }

        // layer 2 - wordmark dissolving IN (rows 2..5), over the skeleton
        foreach (self::wordmarkCells() as $cell) {
            [$r, $cx] = $cell;
            $u = $order[abs($r * self::COLS + $cx + 13) % $n] / $n;
            if ($f > $u) {
                $depth = ($r - 2) / 3;
                $col = self::mix(self::C_MARK, self::C_STRICT, $depth * 0.30);
                $grid[$r][$cx] = self::cell('█', $col, null);
            }
        }
    }

    /** @return list<array{0:int,1:int}> painted cell coordinates (row, col) */
    private static function wordmarkCells(): array
    {
        $letters = str_split(self::WORDMARK);
        $glyphW = 4 * count($letters) + (count($letters) - 1);
        $x0 = intdiv(self::COLS - $glyphW, 2);
        $cells = [];
        foreach ($letters as $li => $letter) {
            $font = self::FONT[$letter] ?? null;
            if ($font === null) {
                continue;
            }
            foreach ($font as $r => $bits) {
                foreach (str_split(str_pad($bits, 5, '0')) as $c => $bit) {
                    if ($bit === '1') {
                        $cells[] = [2 + $r, $x0 + $li * 5 + $c];
                    }
                }
            }
        }
        return $cells;
    }

    /** @return list<string> final wordmark rows as plain characters */
    private static function wordmarkRows(string $block): array
    {
        $lines = [];
        for ($r = 2; $r <= 5; $r++) {
            $line = str_repeat(' ', self::COLS);
            foreach (self::wordmarkCells() as $cell) {
                if ($cell[0] === $r) {
                    $line[$cell[1]] = $block;
                }
            }
            $lines[] = rtrim($line);
        }
        return $lines;
    }

    /**
     * @param list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>> $grid
     */
    private static function paintLegend(array &$grid, int $tick): void
    {
        $mode = self::phaseMode($tick);
        $x = 0;
        foreach (self::BEAMS as $i => $label) {
            if ($mode < 0) {
                $col = self::C_DIM;
            } elseif ($mode > $i) {
                $col = self::C_MATCH;
            } elseif ($mode === $i) {
                $col = self::beamColor($i);
            } else {
                $col = self::C_DIM;
            }
            $glyph = $mode > $i ? "\u{2713}" : ($mode === $i ? '>' : '-');
            $chip = $glyph . $label;
            self::put($grid, self::ROW_LEGEND, $x, $chip, $col);
            $x += mb_strlen($chip) + 2;
        }
    }

    /**
     * @param list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>> $grid
     */
    private static function paintMeter(array &$grid, int $hits, int $len): void
    {
        if ($len <= 0) {
            return;
        }
        $barW = 22;
        $fill = (int) round($barW * $hits / $len);
        $txt = 'match ' . $hits . '/' . $len . '  '
            . str_repeat("\u{2593}", $fill) . str_repeat("\u{2591}", max(0, $barW - $fill));
        $col = $hits === $len ? self::C_MATCH : self::C_SLATE;
        self::put($grid, self::ROW_METER, 1, $txt, $col);
    }

    /**
     * @param list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>> $grid
     */
    private static function paintStatus(array &$grid, int $idx): void
    {
        $txt = self::STATUS[$idx] ?? '';
        self::put($grid, self::ROW_STATUS, 1, $txt, self::C_SLATE);
    }

    // -------------------------------------------------------------------- utils

    /** Phase index: -1 hold, 0..2 beams, 3 merged-onward. */
    private static function phaseMode(int $tick): int
    {
        if ($tick <= self::HOLD_END) {
            return -1;
        }
        if ($tick <= self::BEAM_ENDS[0]) {
            return 0;
        }
        if ($tick <= self::BEAM_ENDS[1]) {
            return 1;
        }
        if ($tick <= self::BEAM_ENDS[2]) {
            return 2;
        }
        return 3;
    }

    /**
     * @param array{seqA:string,seqB:string,newA:string,newB:string,beam:int,head:int,mode:int,mergeHead:int,reveal:float,skel:string} $st
     */
    private function statusIndex(int $tick, array $st): int
    {
        $mode = $st['mode'];
        if ($mode < 0) {
            return 0;
        }
        if ($mode <= 2) {
            return $mode + 1;
        }
        return $tick <= self::MERGE_END ? 4 : 5;
    }

    /**
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
     * @return array{int,int,int}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /** @return array{int,int,int} */
    private static function beamColor(int $mode): array
    {
        $palette = [self::C_STRICT, self::C_DEFAULT, self::C_AGGR];
        return $palette[max(0, min(2, $mode))];
    }

    /** Fading background tail behind a sweep head (8 columns).
     *
     * @param array{int,int,int} $col
     * @return ?array{int,int,int}
     */
    private static function tailBg(array $col, int $head, int $c): ?array
    {
        $d = $head - $c;
        if ($d < 1 || $d > 8) {
            return null;
        }
        return self::mix(self::C_BG, $col, 0.30 * (1 - $d / 8));
    }

    /**
     * @param array{int,int,int} $fg
     * @param ?array{int,int,int} $bg
     * @return array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}
     */
    private static function cell(string $ch, array $fg, ?array $bg): array
    {
        return ['ch' => $ch, 'fg' => $fg, 'bg' => $bg];
    }

    /** @return list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>> */
    private static function blank(): array
    {
        $grid = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            $row = [];
            for ($c = 0; $c < self::COLS; $c++) {
                $row[$c] = self::cell(' ', self::C_MARK, self::C_BG);
            }
            $grid[$r] = $row;
        }
        return $grid;
    }

    /** Write text into a grid row with colour. Multibyte-safe: one cell per
     *  character (every glyph we print is narrow/width-1).
     *
     * @param list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>> $grid
     * @param array{int,int,int} $fg
     */
    private static function put(array &$grid, int $row, int $x, string $text, array $fg): void
    {
        $col = $x;
        foreach (mb_str_split($text) as $ch) {
            if ($col >= self::COLS) {
                break;
            }
            if ($col >= 0) {
                $grid[$row][$col] = self::cell($ch, $fg, null);
            }
            $col++;
        }
    }

    /** Fixed-cell permutation from a seeded xorshift32 (never a clock).
     * @return list<int> */
    private static function seededOrder(int $n): array
    {
        $perm = range(0, $n - 1);
        $s = 0x85EBCA6B;
        for ($i = $n - 1; $i > 0; $i--) {
            $s ^= ($s << 13) & 0xFFFFFFFF;
            $s ^= ($s >> 17) & 0xFFFFFFFF;
            $s ^= ($s << 5) & 0xFFFFFFFF;
            $s &= 0xFFFFFFFF;
            $j = $s % ($i + 1);
            [$perm[$i], $perm[$j]] = [$perm[$j], $perm[$i]];
        }
        /** @var list<int> $perm */
        return $perm;
    }

    // ------------------------------------------------------------------- encode

    /** @param list<list<array{ch: string, fg: array{int,int,int}, bg: ?array{int,int,int}}>> $grid */
    private function encode(array $grid): string
    {
        $out = '';
        foreach ($grid as $row) {
            $curFg = null;
            $curBg = null;
            $line = '';
            foreach ($row as $cell) {
                $fg = $cell['fg'];
                $bg = $cell['bg'] ?? self::C_BG;
                if ($fg !== $curFg || $bg !== $curBg) {
                    $line .= "\e[0" . $this->fgCode($fg) . $this->bgCode($bg) . 'm';
                    $curFg = $fg;
                    $curBg = $bg;
                }
                $line .= $cell['ch'];
            }
            $out .= $line . "\e[0m\n";
        }
        return $out;
    }

    /** @param array{int,int,int} $c */
    private function fgCode(array $c): string
    {
        return $this->depth === 'tc'
            ? sprintf(';38;2;%d;%d;%d', $c[0], $c[1], $c[2])
            : ';38;5;' . self::to256($c);
    }

    /** @param array{int,int,int} $c */
    private function bgCode(array $c): string
    {
        return $this->depth === 'tc'
            ? sprintf(';48;2;%d;%d;%d', $c[0], $c[1], $c[2])
            : ';48;5;' . self::to256($c);
    }

    /** @param array{int,int,int} $c */
    private static function to256(array $c): int
    {
        return 16 + 36 * (int) round($c[0] * 5 / 255)
            + 6 * (int) round($c[1] * 5 / 255)
            + (int) round($c[2] * 5 / 255);
    }
}
