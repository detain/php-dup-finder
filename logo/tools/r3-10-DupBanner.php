<?php

declare(strict_types=1);

namespace Phpdup\Lab;

use InvalidArgumentException;

/**
 * DupBanner — animated truecolor ANSI logo for phpdup (concept 10, round 3).
 *
 * @phpstan-type CellShape array{row: int, col: int, glyph: string, rgb: array{0: int, 1: int, 2: int}|null, key?: string, pair?: int}
 * @psalm-type CellShape = array{row: int, col: int, glyph: string, rgb: array{0: int, 1: int, 2: int}|null, key?: string, pair?: int}
 *
 * "Double Helix Alignment". Two strands of structural-hash "bases" twist as a
 * double helix. Every base glyph is COLORED BY ITS PER-STATEMENT STRUCTURAL
 * HASH (same hash => same colour, same glyph). Depth rides the sine wave: the
 * back strand renders at 40 % brightness and the strands swap at every
 * crossover. White rungs snap between bases that a REAL longest-common-
 * subsequence alignment pairs — rungs appear ONLY at aligned positions;
 * unaligned bases (insertions / deletions) bulge outward as hollow loops.
 * The helix then unwinds into two flat rows, matched columns lined up on a
 * shared slot grid, gaps rendered as ─ — and finally the strands wipe away
 * while the aligned bases recolor and reshape into the phpdup wordmark.
 *
 * Timeline — 45 ticks @ 30 fps = 1.4999 s, one-shot, final frame holds:
 *   ticks  0..20  rotation, rungs snapping in            (0.0-0.7 s)
 *   ticks 21..32  unwind + flatten into two aligned rows (0.7-1.1 s)
 *   ticks 33..44  wipe + morph into the wordmark         (1.1-1.5 s)
 *
 * Ties to phpdup internals:
 *   - src/Refactor/AntiUnifier.php: LCS over per-statement structural hashes
 *     IS the rung-placement rule used here.
 *   - src/Refactor/PatternRecognizer.php: the `optional-segments` tag — the
 *     bulging loop bases are exactly the unaligned optional statements.
 *
 * Owner hard constraints honoured:
 *   - 24-bit SGR (38;2/48;2) + xterm-256 fallback (38;5/48;5) when COLORTERM
 *     lacks truecolor/24bit; NO_COLOR / undecorated / --no-ansi => plain
 *     frame with ZERO escape codes.
 *   - <= 7 rows x <= 56 cols (helix swings +/-3 rows around centre row 3).
 *   - frame()/layout() are PURE: deterministic, seeded by tick + SEED only.
 */
final class DupBanner
{
    public const DEPTH_TRUECOLOR = 'truecolor';
    public const DEPTH_256 = 'xterm256';
    public const DEPTH_PLAIN = 'plain';

    public const COLS = 56;
    public const ROWS = 7;
    public const CENTER = 3;
    public const AMP = 3;                 // +/-3 rows around centre = exactly 7
    public const WAVE = 12.0;             // spatial wavelength of the twist, cols
    public const X0 = 3;                  // first strand column
    public const PITCH = 2;               // cols between consecutive bases
    public const FLAT_A = 1;              // flat row of strand A
    public const FLAT_B = 5;              // flat row of strand B

    public const FPS = 30;
    public const TICK_COUNT = 45;
    public const LAST_TICK = self::TICK_COUNT - 1;
    public const TICK_USEC = 33_333;      // 45 * 33.333 ms = 1.4999 s <= 1.5 s

    public const T_FLATTEN = 21;          // first unwind tick
    public const T_WORDMARK = 33;         // first morph tick
    public const SNAP_FIRST = 1;          // rungs snap across ticks 1..20

    private const SEED = 0x5EEDB10;       // deterministic strand seed
    private const HASH_SPAN = 3000;       // structural-hash id space
    private const CORE_LEN = 17;          // statements shared by both clones
    private const INS_A = [4, 10, 13];    // A-only optional segments (loops)
    private const INS_B = [7, 14];        // B-only optional segments (loops)
    private const BACK_DIM = 0.4;         // back-strand brightness

    /** @var array<int, array{0: int, 1: int, 2: int}> the six concept hues */
    public const PALETTE = [
        [244, 63, 94],    // #F43F5E rose
        [245, 158, 11],   // #F59E0B amber
        [132, 204, 22],   // #84CC16 lime
        [6, 182, 212],    // #06B6D4 cyan
        [99, 102, 241],   // #6366F1 indigo
        [217, 70, 239],   // #D946EF magenta
    ];

    /** @var array{0: int, 1: int, 2: int} */
    public const RUNG_RGB = [255, 255, 255];
    /** @var array{0: int, 1: int, 2: int} */
    public const GAP_RGB = [71, 85, 105];   // #475569
    /** @var array{0: int, 1: int, 2: int} */
    public const BG_RGB = [3, 7, 18];       // #030712

    /** @var list<string> base glyphs, indexed by structural hash */
    public const BASE_GLYPHS = ["\u{25CF}", "\u{25C9}", "\u{25C6}"]; // ● ◉ ◆
    public const LOOP_GLYPH = "\u{25CB}";   // ○ — unaligned base = hole
    public const RUNG_GLYPH = "\u{2502}";   // │
    public const GAP_GLYPH = "\u{2500}";    // ─
    public const BLOCK_GLYPH = "\u{2588}";  // █ — wordmark ink

    public const WORD = 'phpdup';
    private const LETTER_DX = 5;            // 4-wide glyphs + 1 col gap

    /** @var array<string, list<string>> 4x5 lowercase bitmaps, row-major bits */
    private const FONT = [
        'p' => ['1110', '1001', '1110', '1000', '1000'],
        'h' => ['1000', '1100', '1010', '1010', '1010'],
        'd' => ['0010', '0010', '0110', '1010', '0110'],
        'u' => ['1010', '1010', '1010', '1010', '0110'],
    ];

    /** @var list<int> */
    private array $seqA;
    /** @var list<int> */
    private array $seqB;
    /** @var list<array{kind: string, i: int|null, j: int|null, hash: int}> */
    private array $slots;
    /** @var array<int, int> seqA index -> slot */
    private array $slotOfA = [];
    /** @var array<int, int> seqB index -> slot */
    private array $slotOfB = [];
    /** @var list<array{0: int, 1: int}> LCS-aligned pairs, in strand order */
    private array $pairs;

    public function __construct()
    {
        $built = $this->buildSequences();
        $this->seqA = $built[0];
        $this->seqB = $built[1];
        $this->slots = $built[2];

        $iA = 0;
        $iB = 0;
        $this->pairs = [];
        foreach ($this->slots as $q => $slot) {
            if ($slot['kind'] === 'm') {
                $this->pairs[] = [$iA, $iB];
                $this->slotOfA[$iA] = $q;
                $this->slotOfB[$iB] = $q;
                $iA++;
                $iB++;
            } elseif ($slot['kind'] === 'a') {
                $this->slotOfA[$iA] = $q;
                $iA++;
            } else {
                $this->slotOfB[$iB] = $q;
                $iB++;
            }
        }
        if ($iA !== count($this->seqA) || $iB !== count($this->seqB)) {
            throw new InvalidArgumentException('slot map desynced from strands');
        }
    }

    // ============================================================ public surface

    /** Pure truecolor frame for the given tick (0..LAST_TICK). */
    public function frame(int $tick): string
    {
        return $this->frameAtDepth($tick, self::DEPTH_TRUECOLOR);
    }

    public function finalFrame(): string
    {
        return $this->frame(self::LAST_TICK);
    }

    /**
     * One-shot ANSI stream: every tick, final frame left on screen (HOLD).
     * The plain depth is by definition static: it emits the final plain frame.
     */
    public function stream(string $depth = self::DEPTH_TRUECOLOR): string
    {
        if ($depth === self::DEPTH_PLAIN) {
            return $this->frameAtDepth(self::LAST_TICK, self::DEPTH_PLAIN);
        }
        $frames = [];
        for ($t = 0; $t <= self::LAST_TICK; $t++) {
            $frames[] = $this->frameAtDepth($t, $depth);
        }

        return "\e[0m\e[?25l"
            . implode("\e[" . self::ROWS . "A\r", $frames)
            . "\e[0m\n\e[?25h";
    }

    /**
     * Live playback (wall clock lives HERE, never in frame()). After the last
     * tick the final frame simply stays on screen — nothing is cleared.
     *
     * @param resource $out
     * @param array<string, string>|null $env
     */
    public function play($out = STDOUT, bool $decorated = true, bool $noAnsi = false, ?array $env = null): void
    {
        $depth = self::detectDepth($decorated, $noAnsi, $env);
        if ($depth === self::DEPTH_PLAIN) {
            \fwrite($out, $this->frameAtDepth(self::LAST_TICK, self::DEPTH_PLAIN) . "\n");
            return;
        }
        $up = "\e[" . self::ROWS . "A\r";
        \fwrite($out, "\e[?25l");
        for ($t = 0; $t <= self::LAST_TICK; $t++) {
            if ($t > 0) {
                \fwrite($out, $up);
            }
            \fwrite($out, $this->frameAtDepth($t, $depth));
            if ($t < self::LAST_TICK) {
                usleep(self::TICK_USEC);
            }
        }
        \fwrite($out, "\e[0m\n\e[?25h");
    }

    /**
     * Fallback chain: --no-ansi / undecorated / NO_COLOR / TERM=dumb => plain;
     * COLORTERM=truecolor|24bit => 24-bit; anything else => xterm-256.
     *
     * @param array<string, string>|null $env
     */
    public static function detectDepth(bool $decorated = true, bool $noAnsi = false, ?array $env = null): string
    {
        if ($noAnsi || !$decorated) {
            return self::DEPTH_PLAIN;
        }
        $get = static function (string $key) use ($env): ?string {
            if ($env !== null) {
                return isset($env[$key]) ? (string) $env[$key] : null;
            }
            $v = getenv($key);
            return $v === false ? null : $v;
        };
        if (($get('NO_COLOR') ?? '') !== '') {
            return self::DEPTH_PLAIN;
        }
        if (($get('TERM') ?? '') === 'dumb') {
            return self::DEPTH_PLAIN;
        }
        $ct = strtolower($get('COLORTERM') ?? '');
        if ($ct === 'truecolor' || $ct === '24bit') {
            return self::DEPTH_TRUECOLOR;
        }
        return self::DEPTH_256;
    }

    // ============================================================ pure geometry

    /** Structural hash -> palette colour index (same hash = same colour). */
    public static function hashColor(int $hash): int
    {
        return $hash % count(self::PALETTE);
    }

    /** Structural hash -> base glyph index. */
    public static function hashGlyph(int $hash): int
    {
        return intdiv($hash, 8) % count(self::BASE_GLYPHS);
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function colorOf(int $hash): array
    {
        return self::PALETTE[self::hashColor($hash)];
    }

    /**
     * Back-strand shading: 40 % brightness of the given colour.
     *
     * @param array{0: int, 1: int, 2: int} $rgb
     * @return array{0: int, 1: int, 2: int}
     */
    public static function dimRgb(array $rgb): array
    {
        return [
            (int) round($rgb[0] * self::BACK_DIM),
            (int) round($rgb[1] * self::BACK_DIM),
            (int) round($rgb[2] * self::BACK_DIM),
        ];
    }

    /** Helix column of a base at its own strand index (tick-0 layout). */
    public static function helixCol(int $idx): int
    {
        return self::X0 + self::PITCH * $idx;
    }

    /** Tape column of alignment slot q once fully flattened. */
    public static function slotCol(int $q): int
    {
        return self::X0 + self::PITCH * $q;
    }

    /** Flatten factor 0 (pure helix) -> 1 (two aligned flat rows). */
    public function flatten(int $tick): float
    {
        if ($tick <= self::T_FLATTEN) {
            return 0.0;
        }
        if ($tick >= self::T_WORDMARK) {
            return 1.0;
        }
        return ($tick - self::T_FLATTEN) / (self::T_WORDMARK - self::T_FLATTEN);
    }

    /** Helix wave angle of a base: fixed spatial phase + global rotation. */
    public function theta(int $tick, string $seq, int $idx): float
    {
        $phi = 2.0 * M_PI * min($tick, self::T_FLATTEN) / (float) self::T_FLATTEN;
        $spatial = 2.0 * M_PI * self::helixCol($idx) / self::WAVE;
        return $seq === 'A' ? $spatial + $phi : $spatial + $phi + M_PI;
    }

    /** Animated row of a strand base (flat rows + loop bulges included). */
    public function rowOf(int $tick, string $seq, int $idx): int
    {
        $f = $this->flatten($tick);
        $flat = $seq === 'A' ? self::FLAT_A : self::FLAT_B;
        $helix = self::CENTER + self::AMP * sin($this->theta($tick, $seq, $idx));
        $row = (int) round((1 - $f) * $helix + $f * $flat);

        $slot = $this->slotOf($seq, $idx);
        if ($this->slots[$slot]['kind'] !== 'm' && $f < 0.5) {
            $dir = $row < self::CENTER || ($row === self::CENTER && $seq === 'A') ? -1 : 1;
            $row = max(0, min(self::ROWS - 1, $row + $dir)); // bulge outward as a loop
        }
        return max(0, min(self::ROWS - 1, $row));
    }

    /** Animated column of a strand base (helix col -> shared slot col). */
    public function colOf(int $tick, string $seq, int $idx): int
    {
        $f = $this->flatten($tick);
        return (int) round((1 - $f) * self::helixCol($idx) + $f * self::slotCol($this->slotOf($seq, $idx)));
    }

    /** True while the base rides the near side of the helix (full colour). */
    public function frontOf(int $tick, string $seq, int $idx): bool
    {
        if ($this->flatten($tick) >= 1.0) {
            return true; // flat alignment view: no depth
        }
        return cos($this->theta($tick, $seq, $idx)) >= 0.0;
    }

    /** Tick at which the rung for LCS pair p snaps in. */
    public function rungSnapTick(int $p): int
    {
        $n = max(1, count($this->pairs) - 1);
        return self::SNAP_FIRST + (int) round($p * (self::T_FLATTEN - 1 - self::SNAP_FIRST) / $n);
    }

    /**
     * Rung cells for aligned pair p at this tick: every row strictly between
     * the two bases' rows, at the rounded mid column. Empty when the strands
     * overlap (pair momentarily indistinguishable at a crossover).
     *
     * @return array{col: int, rows: list<int>}
     */
    public function rungGeometry(int $tick, int $p): array
    {
        [$i, $j] = $this->pairs[$p];
        $ra = $this->rowOf($tick, 'A', $i);
        $rb = $this->rowOf($tick, 'B', $j);
        $col = intdiv($this->colOf($tick, 'A', $i) + $this->colOf($tick, 'B', $j) + 1, 2);
        $rows = [];
        for ($r = min($ra, $rb) + 1; $r <= max($ra, $rb) - 1; $r++) {
            if ($r >= 0 && $r < self::ROWS) {
                $rows[] = $r;
            }
        }
        return ['col' => $col, 'rows' => $rows];
    }

    /** @return list<int> */
    public function sequenceA(): array
    {
        return $this->seqA;
    }

    /** @return list<int> */
    public function sequenceB(): array
    {
        return $this->seqB;
    }

    /** @return list<array{0: int, 1: int}> */
    public function alignPairs(): array
    {
        return $this->pairs;
    }

    /** @return list<array{kind: string, i: int|null, j: int|null, hash: int}> */
    public function tapeSlots(): array
    {
        return $this->slots;
    }

    // ================================================================== painting

    /**
     * Composited cell layout of one tick. Later writers overwrite earlier
     * ones (rungs first, then gaps, then bases, then wordmark), so every
     * position carries exactly one glyph. `pair` is present on rung cells:
     * the LCS pair index that produced them.
     *
     * @return list<array{row: int, col: int, glyph: string, rgb: array{0: int, 1: int, 2: int}|null, key?: string, pair?: int}>
     */
    public function layout(int $tick): array
    {
        if ($tick < 0 || $tick > self::LAST_TICK) {
            throw new InvalidArgumentException("tick $tick outside 0.." . self::LAST_TICK);
        }
        if ($tick < self::T_WORDMARK) {
            $cells = $this->strandCells($tick, $this->flatten($tick));
        } else {
            $wipe = self::X0 + 1 + ($tick - self::T_WORDMARK) * 4.6;
            $cells = $this->strandCells($tick, 1.0, $wipe);
            foreach ($this->wordmarkCells($tick) as $cell) {
                $cells[] = $cell;
            }
        }
        $paint = [];
        foreach ($cells as $cell) {
            $paint[$cell['row'] . ':' . $cell['col']] = $cell;
        }
        return array_values($paint);
    }

    /**
     * Strand phase cells: rungs first, then gap dashes, then bases, so bases
     * win shared positions. Cells off-canvas or already swept by the wordmark
     * wipe are dropped.
     *
     * @return list<array{row: int, col: int, glyph: string, rgb: array{0: int, 1: int, 2: int}|null, key?: string, pair?: int}>
     */
    private function strandCells(int $tick, float $f, float $wipe = 0.0): array
    {
        $cells = [];

        // 1) rungs first — ONLY between LCS-aligned bases
        foreach ($this->pairs as $p => [$i, $j]) {
            if ($this->rungSnapTick($p) > $tick) {
                continue;
            }
            $geo = $this->rungGeometry($tick, $p);
            foreach ($geo['rows'] as $r) {
                $cells[] = ['row' => $r, 'col' => $geo['col'], 'glyph' => self::RUNG_GLYPH, 'rgb' => self::RUNG_RGB, 'pair' => $p];
            }
        }

        // 2) gap dashes once the tape is flat: an indel shows as ─ on the
        //    strand that lacks the statement
        if ($f > 0.5) {
            foreach ($this->slots as $q => $slot) {
                if ($slot['kind'] === 'a') {
                    $cells[] = ['row' => self::FLAT_B, 'col' => self::slotCol($q), 'glyph' => self::GAP_GLYPH, 'rgb' => self::GAP_RGB];
                } elseif ($slot['kind'] === 'b') {
                    $cells[] = ['row' => self::FLAT_A, 'col' => self::slotCol($q), 'glyph' => self::GAP_GLYPH, 'rgb' => self::GAP_RGB];
                }
            }
        }

        // 3) base glyphs last so they win shared positions
        foreach ($this->seqA as $i => $hash) {
            $cells[] = $this->baseCell($tick, 'A', $i, $hash, $f);
        }
        foreach ($this->seqB as $j => $hash) {
            $cells[] = $this->baseCell($tick, 'B', $j, $hash, $f);
        }

        $kept = [];
        foreach ($cells as $cell) {
            if ($cell['row'] < 0 || $cell['row'] >= self::ROWS || $cell['col'] < 0 || $cell['col'] >= self::COLS) {
                continue;
            }
            if ($cell['col'] < $wipe) {
                continue; // already swept by the wordmark wipe
            }
            $kept[] = $cell;
        }
        return $kept;
    }

    /** @return array{row: int, col: int, glyph: string, rgb: array{0: int, 1: int, 2: int}, key: string} */
    private function baseCell(int $tick, string $seq, int $idx, int $hash, float $f): array
    {
        $slot = $this->slots[$this->slotOf($seq, $idx)];
        $glyph = $slot['kind'] === 'm'
            ? self::BASE_GLYPHS[self::hashGlyph($hash)]
            : self::LOOP_GLYPH;
        $rgb = self::colorOf($hash);
        if (!$this->frontOf($tick, $seq, $idx)) {
            $rgb = self::dimRgb($rgb);
        }
        return [
            'row' => $this->rowOf($tick, $seq, $idx),
            'col' => $this->colOf($tick, $seq, $idx),
            'glyph' => $glyph,
            'rgb' => $rgb,
            'key' => $seq . '#' . $idx,
        ];
    }

    /** @return list<array{row: int, col: int, glyph: string, rgb: array{0: int, 1: int, 2: int}, key: string}> */
    private function wordmarkCells(int $tick): array
    {
        $cells = [];
        $letters = str_split(self::WORD);
        $x0 = intdiv(self::COLS - (count($letters) * self::LETTER_DX - 1), 2);
        foreach ($letters as $k => $ch) {
            $charge = self::T_WORDMARK + 2 * $k;
            if ($tick < $charge) {
                continue;
            }
            $glyph = $tick === $charge ? self::BASE_GLYPHS[1] : self::BLOCK_GLYPH;
            $rgb = self::PALETTE[$k % count(self::PALETTE)];
            $mask = self::FONT[$ch];
            for ($r = 0; $r < 5; $r++) {
                for ($c = 0; $c < 4; $c++) {
                    if ($mask[$r][$c] === '1') {
                        $cells[] = [
                            'row' => 1 + $r,
                            'col' => $x0 + self::LETTER_DX * $k + $c,
                            'glyph' => $glyph,
                            'rgb' => $rgb,
                            'key' => 'letter#' . $k,
                        ];
                    }
                }
            }
        }
        return $cells;
    }

    /** Paint the composited layout for one of the three colour depths. */
    public function frameAtDepth(int $tick, string $depth): string
    {
        if (!in_array($depth, [self::DEPTH_TRUECOLOR, self::DEPTH_256, self::DEPTH_PLAIN], true)) {
            throw new InvalidArgumentException("unknown depth $depth");
        }
        $grid = array_fill(0, self::ROWS, array_fill(0, self::COLS, null));
        foreach ($this->layout($tick) as $cell) {
            $grid[$cell['row']][$cell['col']] = [$cell['glyph'], $cell['rgb']];
        }

        $out = [];
        foreach ($grid as $row) {
            if ($depth === self::DEPTH_PLAIN) {
                $line = '';
                foreach ($row as $cell) {
                    $line .= self::plainGlyph($cell === null ? ' ' : $cell[0]);
                }
                $out[] = $line;
                continue;
            }
            $bg = $depth === self::DEPTH_TRUECOLOR
                ? "\e[48;2;" . implode(';', self::BG_RGB) . 'm'
                : "\e[48;5;" . self::rgb256(self::BG_RGB) . 'm';
            $line = $bg;
            $prev = 'INIT';
            foreach ($row as $cell) {
                $rgb = $cell === null ? null : $cell[1];
                $key = $rgb === null ? '' : implode(';', $rgb);
                if ($key !== $prev) {
                    $line .= $rgb === null
                        ? "\e[39m"
                        : ($depth === self::DEPTH_TRUECOLOR
                            ? "\e[38;2;" . $key . 'm'
                            : "\e[38;5;" . self::rgb256($rgb) . 'm');
                    $prev = $key;
                }
                $line .= $cell === null ? ' ' : $cell[0];
            }
            $out[] = $line . "\e[0m";
        }

        return implode("\n", $out);
    }

    /**
     * Nearest xterm-256 cube index for an RGB triple.
     *
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    public static function rgb256(array $rgb): int
    {
        $steps = [0, 95, 135, 175, 215, 255];
        $pick = static function (int $v) use ($steps): int {
            $best = 0;
            foreach ($steps as $i => $s) {
                if (abs($v - $s) < abs($v - $steps[$best])) {
                    $best = $i;
                }
            }
            return $best;
        };
        return 16 + 36 * $pick($rgb[0]) + 6 * $pick($rgb[1]) + $pick($rgb[2]);
    }

    /** ASCII-safe glyph for the zero-escape fallback. */
    public static function plainGlyph(string $g): string
    {
        return match ($g) {
            "\u{25CF}", "\u{25CB}" => 'o',
            "\u{25C9}" => 'O',
            "\u{25C6}" => '*',
            "\u{2502}" => '|',
            "\u{2500}" => '-',
            "\u{2588}" => '#',
            default => $g,
        };
    }

    /** @return int */
    private function slotOf(string $seq, int $idx): int
    {
        $map = $seq === 'A' ? $this->slotOfA : $this->slotOfB;
        if (!isset($map[$idx])) {
            throw new InvalidArgumentException("bad index $seq#$idx");
        }
        return $map[$idx];
    }

    // =================================================================== seeding

    /**
     * Build the two seeded structural-hash strands plus the alignment tape.
     *
     * A 17-statement common core is shared by both clones; strand A carries 3
     * extra statements and strand B 2. Every hash drawn is unique across both
     * strands, so the optimal LCS over hash equality is EXACTLY the planted
     * core — and the rung rule ("alignments only") is a real LCS property,
     * independently re-checkable by dynamic programming (see verify.php).
     *
     * @return array{list<int>, list<int>, list<array{kind: string, i: int|null, j: int|null, hash: int}>}
     */
    private function buildSequences(): array
    {
        $state = self::SEED;
        /** @var array<int, int> $used */
        $used = [];
        $draw = static function () use (&$state, &$used): int {
            do {
                $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
                $x = 100 + $state % self::HASH_SPAN;
            } while (isset($used[$x]));
            $used[$x] = 1;
            return $x;
        };

        $core = [];
        for ($k = 0; $k < self::CORE_LEN; $k++) {
            $core[] = $draw();
        }
        $aIns = [];
        foreach (self::INS_A as $pos) {
            $aIns[$pos] = $draw();
        }
        $bIns = [];
        foreach (self::INS_B as $pos) {
            $bIns[$pos] = $draw();
        }

        $slots = [];
        for ($k = 0; $k < self::CORE_LEN; $k++) {
            $slots[] = ['kind' => 'm', 'i' => null, 'j' => null, 'hash' => $core[$k]];
            if (isset($aIns[$k])) {
                $slots[] = ['kind' => 'a', 'i' => null, 'j' => null, 'hash' => $aIns[$k]];
            }
            if (isset($bIns[$k])) {
                $slots[] = ['kind' => 'b', 'i' => null, 'j' => null, 'hash' => $bIns[$k]];
            }
        }

        $seqA = [];
        $seqB = [];
        foreach ($slots as $q => $slot) {
            if ($slot['kind'] === 'm') {
                $seqA[] = $slot['hash'];
                $seqB[] = $slot['hash'];
                $slots[$q]['i'] = count($seqA) - 1;
                $slots[$q]['j'] = count($seqB) - 1;
            } elseif ($slot['kind'] === 'a') {
                $seqA[] = $slot['hash'];
                $slots[$q]['i'] = count($seqA) - 1;
            } else {
                $seqB[] = $slot['hash'];
                $slots[$q]['j'] = count($seqB) - 1;
            }
        }

        return [$seqA, $seqB, $slots];
    }
}
