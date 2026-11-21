<?php

declare(strict_types=1);

namespace Phpdup\Branding;

/**
 * "Glitch Dedupe" — a truecolor animated ANSI wordmark for phpdup.
 *
 * The wordmark ARRIVES corrupted: glyph segments are datamoshed (column bands
 * smeared sideways, scanline bands repeated down), the image is split into
 * colour channels (red ghost shifted left, cyan ghost shifted right, white
 * where they overlap), and an amber scanner line sweeps top-to-bottom. Every
 * duplicated segment it reaches is outlined and collapses to zero width/
 * height. The decorative counter "duplicated: 9" ticks down with each
 * collapse; at 0 the channels re-converge, the mark settles to #F1F5F9, and
 * one white glint travels down the 'd' stem. Held clean.
 *
 * Story tie: exact (type-1) clone removal + Report::totalDuplicatedLines() —
 * duplicated fragments detected (9), removed one by one (0), clean code left.
 * The counter is seeded and decorative per the round-3 build notes.
 *
 * Design contract (owner spec, round 3):
 *  - 24-bit SGR; falls back to 256-colour then plain text (zero escapes).
 *  - one-shot <= 1.5 s, <= 45 ticks, <= 30 fps, ends held on the final frame.
 *  - final logo <= 7 rows x <= 56 cols; no overflow at 80 cols.
 *  - frame(int $tick): string is PURE and seeded — byte-reproducible, no clock.
 *
 * Self-contained single file; drops into a project as-is.
 */
final class DupBanner
{
    /** Total animation ticks (<= 45 hard cap). Playback = TICKS * TICK_MS ms. */
    public const TICKS = 44;          // 44 * 34 ms = 1496 ms, 29.4 fps

    /** Rows / columns of the canvas (final logo <= 7 x 56; canvas 52 <= 56). */
    public const ROWS = 7;
    public const COLS = 52;

    /** Rows occupied by the wordmark (0..MARK_ROWS-1); row 6 = counter. */
    public const MARK_ROWS = 6;

    /** Per-tick wall-clock suggestion for the player (34 ms < 1000/30 ms). */
    public const TICK_MS = 34;

    /** Phase boundaries (inclusive last tick of each phase). */
    public const A_END = 14;          // 0..14   seeded glitch jitter
    public const B_END = 34;          // 15..34  scanner sweep + collapse
    //  CONVERGE_START = 35 (counter hits 0 at 35; dx 1 -> 0 at 38; settle 41)

    /** Wordmark layout: 6 letters x 5 cols, pitch 7 (5 glyph + 2 gap). */
    private const WORD = 'phpdup';
    private const X0 = 2;
    private const PITCH = 7;

    /** Column of the 'd' right stem that catches the final glint. */
    private const GLINT_COL = 27;

    // Palette (owner-specified).
    /** @var list<int> */
    private const RED = [255, 42, 109];      // #FF2A6D red channel
    /** @var list<int> */
    private const CYAN = [5, 217, 232];      // #05D9E8 cyan channel
    /** @var list<int> */
    private const WHITE = [255, 255, 255];   // #FFFFFF channel overlap + glint
    /** @var list<int> */
    private const AMBER = [251, 191, 36];    // #FBBF24 scanner / outline / counter
    /** @var list<int> */
    private const CLEAN = [241, 245, 249];   // #F1F5F9 settled wordmark
    /** @var list<int> */
    private const BG = [13, 2, 33];          // #0D0221 canvas
    /** @var list<int> */
    private const GREEN = [74, 222, 128];    // #4ADE80 counter at zero

    /** @var array<string, list<string>> 6-row block glyphs, 5 cols each. */
    private const FONT = [
        'p' => ['11110', '10001', '10001', '11110', '10000', '10000'],
        'h' => ['10000', '11110', '10001', '10001', '10001', '10001'],
        'd' => ['01111', '10001', '10001', '01111', '00001', '00001'],
        'u' => ['10001', '10001', '10001', '10001', '10001', '01110'],
    ];

    /**
     * The nine duplicated fragments ("duplicated: 9").
     * Each: [r0, r1, c0, c1, dc, dr, collapseTick] — copy of the rect
     * (rows r0..r1, cols c0..c1) shifted by (dr, dc); at collapseTick it is
     * outlined in amber and halved, gone on the next tick. dr != 0 = a
     * vertical scanline repeat (collapses in height); dc != 0 = a horizontal
     * band repeat (collapses in width).
     *
     * @var list<array{int,int,int,int,int,int,int}>
     */
    private const SEGMENTS = [
        [0, 5,  2, 13,  7,  0, 22],   // G1 "php" band smeared right
        [0, 5, 23, 34,  7,  0, 22],   // G2 "du" band smeared right
        [0, 2, 37, 41,  6,  0, 19],   // G3 top of final p, tail smear
        [3, 5,  2,  6,  3,  0, 33],   // G4 p0 descender smear
        [0, 2,  2, 41,  0,  2, 26],   // G5 scanline repeat (top half -> rows 2-4)
        [4, 5,  2, 41,  0, -1, 34],   // G6 scanline repeat (bottom -> row 3-4)
        [1, 4, 16, 27,  4,  0, 26],   // G7 mid smear p1+d
        [0, 1,  9, 20,  0,  3, 30],   // G8 band of h/p1 repeated down
        [0, 5, 30, 34, -5,  0, 30],   // G9 "u" smeared back over d
    ];

    /** @var list<list<bool>>|null ROWS x COLS occupancy grid (built lazily). */
    private static ?array $mask = null;

    public function __construct(
        private readonly string $depth = 'tc',
    ) {
        if (!in_array($depth, ['tc', '256', 'plain'], true)) {
            throw new \InvalidArgumentException("Unknown colour depth: {$depth}");
        }
    }

    /**
     * Pick a depth from the environment: NO_COLOR/TERM=dumb wins for plain,
     * COLORTERM truecolor/24bit wins for tc, otherwise 256-colour.
     * (Callers honouring `--no-ansi` simply construct new DupBanner('plain').)
     */
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

    /** @return list<list<bool>> */
    private static function mask(): array
    {
        if (self::$mask !== null) {
            return self::$mask;
        }
        /** @var list<list<bool>> $m */
        $m = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            /** @var list<bool> $line */
            $line = array_fill(0, self::COLS, false);
            $m[] = $line;
        }
        foreach (str_split(self::WORD) as $i => $letter) {
            $x = self::X0 + $i * self::PITCH;
            foreach (self::FONT[$letter] as $r => $bits) {
                for ($c = 0; $c < 5; $c++) {
                    if ($bits[$c] === '1') {
                        $m[$r][$x + $c] = true;
                    }
                }
            }
        }
        return self::$mask = $m;
    }

    /**
     * The single source of truth: a deterministic, clock-free paint of tick $t.
     * $tick is clamped to [0, TICKS-1]; anything >= TICKS-1 returns the held frame.
     */
    public function frame(int $tick): string
    {
        $tick = max(0, min(self::TICKS - 1, $tick));
        if ($this->depth === 'plain') {
            return $this->plain();
        }
        return self::encode($this->grid($tick), $this->depth);
    }

    /** Final held frame as a single paint (for one-shot / static display). */
    public function render(): string
    {
        return $this->frame(self::TICKS - 1);
    }

    /**
     * Full one-shot animation stream: hide cursor, paint every tick joined by
     * a cursor-up overwrite, leave the final frame held, then show cursor.
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
        return "\e[?25l" . implode("\e[" . self::ROWS . "A\r", $frames) . "\e[?25h";
    }

    /** Plain-text fallback: the settled wordmark, '#' blocks, ZERO escapes. */
    public function plain(): string
    {
        $mask = self::mask();
        $text = 'duplicated: ' . self::aliveCount(self::TICKS - 1);
        $len = strlen($text);
        $out = '';
        for ($r = 0; $r < self::ROWS; $r++) {
            $line = '';
            for ($c = 0; $c < self::COLS; $c++) {
                if ($r < self::MARK_ROWS) {
                    $line .= $mask[$r][$c] ? '#' : ' ';
                    continue;
                }
                $i = $c - self::X0;
                $line .= ($i >= 0 && $i < $len) ? $text[$i] : ' ';
            }
            $out .= rtrim($line) . "\n";
        }
        return $out;
    }

    // ------------------------------------------------------------- animation

    /** How many duplicated fragments are still visible at tick $tick. */
    public static function aliveCount(int $tick): int
    {
        $n = 0;
        foreach (self::SEGMENTS as $seg) {
            if ($tick <= $seg[6]) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Bitmask of the glitched wordmark at tick $tick (base + surviving copies
     * + phase-A jitter) plus the amber outline boxes for fragments
     * collapsing exactly now.
     *
     * @return array{list<list<bool>>, list<array{int,int,int,int}>}
     */
    private static function glitchState(int $tick): array
    {
        $mask = self::mask();
        /** @var list<list<bool>> $g */
        $g = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            $g[] = $mask[$r];
        }

        /** @var list<array{int,int,int,int}> $outlines */
        $outlines = [];
        foreach (self::SEGMENTS as [$r0, $r1, $c0, $c1, $dc, $dr, $ct]) {
            if ($tick > $ct) {
                continue;
            }
            $collapsing = $tick === $ct;
            $sr1 = $r1;
            $sc1 = $c1;
            if ($collapsing) {
                // collapse animation: halve width (horizontal band) or height
                // (scanline repeat) before vanishing next tick.
                if ($dr === 0) {
                    $sc1 = $c0 + intdiv($c1 - $c0 + 1, 2);
                } else {
                    $sr1 = $r0 + intdiv($r1 - $r0 + 1, 2);
                }
            }
            // Intersect the copy rect with the canvas at construction time:
            // every target cell (r+dr, c+dc) is then in-bounds by design —
            // no dead runtime range-guards needed.
            $rlo = max($r0, -$dr);
            $rhi = min($sr1, self::MARK_ROWS - 1 - $dr);
            $clo = max($c0, -$dc);
            $chi = min($sc1, self::COLS - 1 - $dc);
            /** @var array{int,int,int,int}|null $bb */
            $bb = null;
            if ($rlo <= $rhi && $clo <= $chi) {
                for ($r = $rlo; $r <= $rhi; $r++) {
                    for ($c = $clo; $c <= $chi; $c++) {
                        if (!$g[$r][$c]) {
                            continue;
                        }
                        $tr = $r + $dr;
                        $tc = $c + $dc;
                        $g[$tr][$tc] = true;
                        $bb = $bb === null
                            ? [$tr, $tr, $tc, $tc]
                            : [min($bb[0], $tr), max($bb[1], $tr), min($bb[2], $tc), max($bb[3], $tc)];
                    }
                }
            }
            if ($collapsing && $bb !== null) {
                $outlines[] = $bb;
            }
        }

        if ($tick <= self::A_END) {
            // Seeded datamosh: every mark row rotates a little; some rows are
            // outright replaced by the row above them (scanline repetition).
            for ($r = 0; $r < self::MARK_ROWS; $r++) {
                $k = (self::noise($tick, 1 + $r) % 5) - 2;
                if ($k !== 0) {
                    /** @var list<bool> $rot */
                    $rot = [];
                    for ($c = 0; $c < self::COLS; $c++) {
                        $rot[] = $g[$r][((($c - $k) % self::COLS) + self::COLS) % self::COLS];
                    }
                    $g[$r] = $rot;
                }
                if ($r > 0 && self::noise($tick, 31 + $r) % 6 === 0) {
                    $g[$r] = $g[$r - 1];
                }
            }
        }

        return [$g, $outlines];
    }

    /**
     * @param list<list<bool>> $g
     */
    private static function at(array $g, int $r, int $c): bool
    {
        return $r >= 0 && $r < self::ROWS && $c >= 0 && $c < self::COLS && $g[$r][$c];
    }

    /**
     * Paint of tick $tick as a ROWS x COLS grid of cells
     * (null = bare canvas background).
     *
     * @return list<list<array{ch:string,rgb:list<int>}|null>>
     */
    private function grid(int $tick): array
    {
        [$g, $outlines] = self::glitchState($tick);

        // Channel split: ghosts at +/- dx columns; converges to dx=0 late in C.
        $dx = $tick <= 37 ? 1 : 0;
        if ($tick <= self::A_END && self::noise($tick, 7) % 4 === 0) {
            $dx = 2;
        }
        $converged = $tick > 37;
        $settle = self::clamp01(($tick - 38) / 3);

        /** @var list<list<array{ch:string,rgb:list<int>}|null>> $cells */
        $cells = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            /** @var list<array{ch:string,rgb:list<int>}|null> $line */
            $line = array_fill(0, self::COLS, null);
            $cells[] = $line;
        }

        // Wordmark rows: blend red (ghost left) + cyan (ghost right); where
        // both channels cover a cell it burns in white, which settles to
        // #F1F5F9 once the channels re-converge.
        for ($r = 0; $r < self::MARK_ROWS; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                $litRed = self::at($g, $r, $c + $dx);
                $litCyan = self::at($g, $r, $c - $dx);
                if ($litRed && $litCyan) {
                    $rgb = $converged ? self::mix(self::WHITE, self::CLEAN, $settle) : self::WHITE;
                } elseif ($litRed) {
                    $rgb = self::RED;
                } elseif ($litCyan) {
                    $rgb = self::CYAN;
                } else {
                    continue;
                }
                $cells[$r][$c] = ['ch' => '█', 'rgb' => $rgb];
            }
        }

        // Amber outline on a fragment as it collapses.
        foreach ($outlines as [$a, $b, $cc, $dd]) {
            for ($r = $a; $r <= $b; $r++) {
                for ($c = $cc; $c <= $dd; $c++) {
                    if ($r === $a || $r === $b || $c === $cc || $c === $dd) {
                        $cells[$r][$c] = ['ch' => '▓', 'rgb' => self::AMBER];
                    }
                }
            }
        }

        // Amber scanner line, sweeping rows 0..5 across phase B.
        if ($tick >= self::A_END + 1 && $tick <= self::B_END) {
            $s = min(self::MARK_ROWS - 1, intdiv(($tick - (self::A_END + 1)) * self::MARK_ROWS, 19));
            for ($c = 0; $c < self::COLS; $c++) {
                if ($cells[$s][$c] === null) {
                    $cells[$s][$c] = ['ch' => '▄', 'rgb' => self::AMBER];
                }
            }
        }

        // One white glint travelling down the 'd' stem in the closing beats.
        if ($tick >= 40) {
            $gr = min(2, $tick - 40);
            $cells[$gr][self::GLINT_COL] = ['ch' => '█', 'rgb' => self::WHITE];
        }

        // Decorative counter, seeded from the collapse schedule (9 -> 0).
        $n = self::aliveCount($tick);
        $text = 'duplicated: ' . $n;
        $rgb = $n === 0 ? self::GREEN : self::AMBER;
        for ($i = 0; $i < strlen($text); $i++) {
            $cells[self::ROWS - 1][self::X0 + $i] = ['ch' => $text[$i], 'rgb' => $rgb];
        }

        return $cells;
    }

    // ----------------------------------------------------------- deterministic noise

    /** Seeded integer hash of (tick, salt) — pure, clock-free, cross-platform. */
    private static function noise(int $tick, int $salt): int
    {
        $x = $tick * 2654435761 + $salt * 97 + 1442695041;
        $x = ($x ^ ($x >> 13)) & 0x7FFFFFFF;
        $x = ($x * 1274126177) & 0x7FFFFFFF;
        return ($x ^ ($x >> 16)) & 0x7FFFFFFF;
    }

    // ------------------------------------------------------------- colour math

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function mix(array $a, array $b, float $t): array
    {
        $t = self::clamp01($t);
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    private static function clamp01(float $v): float
    {
        return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
    }

    // --------------------------------------------------------------- encoding

    /**
     * @param list<list<array{ch:string,rgb:list<int>}|null>> $cells
     */
    private static function encode(array $cells, string $depth): string
    {
        $bgSeq = $depth === '256'
            ? "\e[48;5;" . self::to256(self::BG) . 'm'
            : "\e[48;2;" . implode(';', self::BG) . 'm';
        $out = '';
        foreach ($cells as $row) {
            $line = $bgSeq;
            $curFg = '';
            foreach ($row as $cell) {
                if ($cell === null) {
                    $line .= ' ';
                    continue;
                }
                $seq = $depth === '256'
                    ? "\e[38;5;" . self::to256($cell['rgb']) . 'm'
                    : "\e[38;2;" . implode(';', $cell['rgb']) . 'm';
                if ($seq !== $curFg) {
                    $line .= $seq;
                    $curFg = $seq;
                }
                $line .= $cell['ch'];
            }
            $out .= $line . "\e[0m\n";
        }
        return $out;
    }

    /**
     * Nearest xterm-256 palette entry (0..255) for a truecolor rgb.
     *
     * @param list<int> $c
     */
    private static function to256(array $c): int
    {
        static $pal = null;
        /** @var array<string,int> $memo */
        static $memo = [];
        if ($pal === null) {
            /** @var array<int,array{int,int,int}> $pal */
            $pal = [];
            /** @var array<int,int> $s */
            $s = [0, 95, 135, 175, 215, 255];
            $n = 0;
            for ($r = 0; $r < 6; $r++) {
                for ($gg = 0; $gg < 6; $gg++) {
                    for ($bb = 0; $bb < 6; $bb++) {
                        $pal[16 + $n] = [$s[$r], $s[$gg], $s[$bb]];
                        $n++;
                    }
                }
            }
            for ($i = 0; $i < 24; $i++) {
                $v = 8 + $i * 10;
                $pal[232 + $i] = [$v, $v, $v];
            }
        }
        $key = "{$c[0]},{$c[1]},{$c[2]}";
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $best = 16;
        $bd = INF;
        foreach ($pal as $nn => $p) {
            $dr = $c[0] - $p[0];
            $dg = $c[1] - $p[1];
            $db = $c[2] - $p[2];
            $d = $dr * $dr + $dg * $dg + $db * $db;
            if ($d < $bd) {
                $bd = $d;
                $best = (int) $nn;
            }
        }
        return $memo[$key] = $best;
    }
}
