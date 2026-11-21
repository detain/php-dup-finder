<?php

declare(strict_types=1);

namespace Phpdup\Ui;

/**
 * DupBanner — "Jaccard Eclipse" animated truecolor ANSI banner.
 *
 * Two shingle-speckled discs — blue set A and red set B — slide together
 * like an eclipse. The overlap blends ADDITIVELY toward magenta (#E879F9)
 * and shared shingles glow white. A right-side readout counts the Jaccard
 * ratio |A∩B| / |A∪B| up from 0.00 under a mini-gauge carrying a gold tick
 * at the repo's similarity threshold default (0.80, src/Cli/Config.php).
 * When the readout crosses the tick the overlap flashes and the discs
 * settle into one merged mark as the "phpdup" wordmark reveals.
 *
 * Ties to src/Similarity/JaccardSimilarity.php (near-duplicate phase) and
 * src/Similarity/ContainmentSimilarity.php.
 *
 * Owner constraints:
 *  - one-shot playback <= 1.5 s (44 ticks @ 33.333 ms = 1.467 s), holds the
 *    final frame; canvas <= 7 rows x <= 56 cols (exactly 7 x 56)
 *  - 24-bit SGR (38;2 / 48;2) with a 256-color fallback (38;5 / 48;5) and a
 *    plain zero-escape fallback for undecorated / NO_COLOR / --no-ansi
 *  - frame() is PURE + deterministic: seeded shingle jitter via hash(), no
 *    wall clock. frame(0) and frame(FINAL_TICK) are byte-reproducible.
 *  - the on-screen readout EQUALS |A∩B|/|A∪B| of the DRAWN discs, recomputed
 *    per frame (see verify.php).
 *  - PHP 8.1 floor.
 */
final class DupBanner
{
    public const ROWS = 7;
    public const COLS = 56;
    public const TICKS = 44;
    public const FINAL_TICK = 43;
    public const FRAME_US = 33334;

    public const MODE_TRUECOLOR = 0;
    public const MODE_256 = 1;
    public const MODE_PLAIN = 2;
    public const MODE_AUTO = 3;

    /** Similarity threshold default — src/Cli/Config.php $similarityThreshold. */
    public const THRESHOLD = 0.80;

    /** @var array{int, int, int} */
    public const COLOUR_A = [0x3B, 0x82, 0xF6];   // blue  disc A
    /** @var array{int, int, int} */
    public const COLOUR_B = [0xEF, 0x44, 0x44];   // red   disc B
    /** @var array{int, int, int} */
    public const COLOUR_OVERLAP = [0xE8, 0x79, 0xF9]; // magenta overlap target
    /** @var array{int, int, int} */
    public const COLOUR_HILITE = [0xFF, 0xFF, 0xFF];
    /** @var array{int, int, int} */
    public const COLOUR_READOUT = [0x94, 0xA3, 0xB8];
    /** @var array{int, int, int} */
    public const COLOUR_TICK = [0xFA, 0xCC, 0x15];
    /** @var array{int, int, int} */
    public const COLOUR_BG = [0x0A, 0x0A, 0x12];

    // Geometry (pixel-space; each text cell = 2 stacked square pixels).
    private const RADIUS = 6.5;
    private const CY = 6.5;      // disc centre pixel row (14 px tall grid)
    private const AX = 9.5;      // fixed centre of disc A
    private const B_START = 24.0;
    private const B_SLIDE_END = 12.0;
    private const B_MERGED = 10.4;
    private const SLIDE_END = 29;   // ticks 0..29  : eclipse slide-in (~0.97 s)
    private const SETTLE_END = 36;  // ticks 30..36 : readout crosses the tick (~1.20 s)
    private const LOCKUP_START = 39; // ticks 39..43 : wordmark reveal

    /** @var array{int, int, int} */
    private const WORD_A = [0x6D, 0xB4, 0xFF];
    /** @var array{int, int, int} */
    private const WORD_B = [0xE8, 0x79, 0xF9];

    /**
     * Pure deterministic frame for tick 0..FINAL_TICK (clamped). Each frame
     * repaints the whole 7 x 56 canvas and opens with a cursor-home so the
     * stream replays in place and HOLDs the final frame.
     */
    public static function frame(int $tick, int $mode = self::MODE_TRUECOLOR): string
    {
        $t = max(0, min(self::FINAL_TICK, $tick));
        $m = self::resolveMode($mode);
        $grid = self::build($t);
        $body = self::emit($grid, $m);

        if ($m === self::MODE_PLAIN) {
            return $body;
        }

        return "\e[H" . $body;
    }

    /** Single static fallback frame — plain text, ZERO escape codes. */
    public static function plain(): string
    {
        return self::frame(self::FINAL_TICK, self::MODE_PLAIN);
    }

    /**
     * The disc centres for a tick: [radius, centreRow, centreColA, centreColB].
     * The single source of geometric truth — frame() draws the discs from this
     * and verify.php recomputes |A∩B|/|A∪B| independently from it.
     *
     * @return array{float, float, float, float}
     */
    public static function geometry(int $tick): array
    {
        return [self::RADIUS, self::CY, self::AX, self::bx(max(0, min(self::FINAL_TICK, $tick)))];
    }

    /**
     * |A∩B| over |A∪B| for the discs as drawn at this tick — the value the
     * readout shows. Rasterised pixel-for-pixel over the disc geometry.
     */
    public static function overlapRatio(int $tick): float
    {
        return self::rasterRatio(self::bx(max(0, min(self::FINAL_TICK, $tick))));
    }

    /** Full one-shot animation byte stream; ends holding the final frame. */
    public static function stream(int $mode = self::MODE_AUTO): string
    {
        $m = self::resolveMode($mode);
        if ($m === self::MODE_PLAIN) {
            return self::plain();
        }

        $out = "\e[?25l";
        for ($t = 0; $t <= self::FINAL_TICK; $t++) {
            $out .= self::frame($t, $m);
        }

        return $out . "\e[?25h";
    }

    /**
     * CLI playback: paints the animation once (<= 1.5 s), then leaves the
     * final frame on screen. Sleeps are wall-clock pacing only — the visual
     * comes from the pure frame().
     */
    public static function play(int $mode = self::MODE_AUTO): void
    {
        $m = self::resolveMode($mode);
        if ($m === self::MODE_PLAIN) {
            echo self::plain(), "\n";
            return;
        }

        echo "\e[?25l";
        for ($t = 0; $t <= self::FINAL_TICK; $t++) {
            echo self::frame($t, $m);
            if ($t < self::FINAL_TICK) {
                usleep(self::FRAME_US);
            }
        }
        echo "\e[0m\e[?25h\n";
    }

    /** Choose truecolor / 256 / plain from the environment. */
    public static function detectMode(): int
    {
        $noColor = getenv('NO_COLOR');
        if (is_string($noColor) && $noColor !== '') {
            return self::MODE_PLAIN;
        }

        $term = getenv('TERM');
        if ($term === 'dumb' || $term === '' || $term === false) {
            return self::MODE_PLAIN;
        }

        $colorterm = getenv('COLORTERM');
        if ($colorterm === 'truecolor' || $colorterm === '24bit') {
            return self::MODE_TRUECOLOR;
        }

        return self::MODE_256;
    }

    private static function resolveMode(int $mode): int
    {
        return $mode === self::MODE_AUTO ? self::detectMode() : $mode;
    }

    // ------------------------------------------------------------- geometry --

    /** Disc B centre column for a tick (monotonic slide → settle → hold). */
    private static function bx(int $tick): float
    {
        if ($tick <= self::SLIDE_END) {
            return self::B_START - (self::B_START - self::B_SLIDE_END) * self::easeOut($tick / self::SLIDE_END);
        }
        if ($tick <= self::SETTLE_END) {
            $f = ($tick - self::SLIDE_END) / (self::SETTLE_END - self::SLIDE_END);

            return self::B_SLIDE_END - (self::B_SLIDE_END - self::B_MERGED) * self::easeInOut($f);
        }

        return self::B_MERGED;
    }

    /** @return array{int, int} [intersection pixel count, union pixel count] */
    private static function counts(float $bx): array
    {
        $r2 = self::RADIUS * self::RADIUS;
        $inter = 0;
        $union = 0;
        for ($pr = 0; $pr < 14; $pr++) {
            $dy = $pr + 0.5 - self::CY;
            for ($c = 0; $c < 56; $c++) {
                $dx = $c + 0.5;
                $inA = ($dx - self::AX) ** 2 + $dy ** 2 <= $r2;
                $inB = ($dx - $bx) ** 2 + $dy ** 2 <= $r2;
                if ($inA && $inB) {
                    $inter++;
                    $union++;
                } elseif ($inA || $inB) {
                    $union++;
                }
            }
        }

        return [$inter, $union];
    }

    private static function rasterRatio(float $bx): float
    {
        [$inter, $union] = self::counts($bx);

        return $union === 0 ? 0.0 : $inter / $union;
    }

    /** First tick at which the readout meets-or-exceeds the threshold. */
    private static function flashTick(): int
    {
        for ($t = self::SLIDE_END; $t <= self::SETTLE_END; $t++) {
            if (self::rasterRatio(self::bx($t)) >= self::THRESHOLD) {
                return $t;
            }
        }

        return self::SETTLE_END;
    }

    // ----------------------------------------------------------------- build --

    /**
     * @return array<int, array<int, array{g: string, f: ?array{int, int, int}, b: ?array{int, int, int}, t: bool}>>
     */
    private static function build(int $tick): array
    {
        $bx = self::bx($tick);
        [$inter, $union] = self::counts($bx);
        $val = $union === 0 ? 0.0 : $inter / $union;

        $grid = self::newGrid();
        self::drawDiscs($grid, $tick, $bx);
        self::drawShingles($grid, $bx);
        self::drawReadout($grid, $val);

        if ($tick >= self::LOCKUP_START) {
            self::drawWordmark($grid, $tick);
        }

        return $grid;
    }

    /**
     * @return array<int, array<int, array{g: string, f: ?array{int, int, int}, b: ?array{int, int, int}, t: bool}>>
     */
    private static function newGrid(): array
    {
        $grid = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                $grid[$r][$c] = ['g' => ' ', 'f' => null, 'b' => null, 't' => false];
            }
        }

        return $grid;
    }

    /**
     * Two half-block discs with additive magenta overlap, per-pixel rim shading,
     * a threshold white flash over the intersection, and seeded shingle glyphs.
     *
     * @param array<int, array<int, array{g: string, f: ?array{int, int, int}, b: ?array{int, int, int}, t: bool}>> $grid
     */
    private static function drawDiscs(array &$grid, int $tick, float $bx): void
    {
        $r2 = self::RADIUS * self::RADIUS;
        $flash = self::flashTick();
        $flashing = $tick >= $flash && $tick <= $flash + 2;

        for ($r = 0; $r < self::ROWS; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                $top = self::pixelColor($bx, 2 * $r, $c, $r2, $flashing);
                $bottom = self::pixelColor($bx, 2 * $r + 1, $c, $r2, $flashing);

                if ($top === self::COLOUR_BG && $bottom === self::COLOUR_BG) {
                    continue;
                }
                if ($top === $bottom) {
                    $grid[$r][$c] = ['g' => '█', 'f' => $top, 'b' => null, 't' => false];
                    continue;
                }
                if ($bottom === self::COLOUR_BG) {
                    $grid[$r][$c] = ['g' => '▀', 'f' => $top, 'b' => null, 't' => false];
                    continue;
                }
                if ($top === self::COLOUR_BG) {
                    $grid[$r][$c] = ['g' => '▄', 'f' => $bottom, 'b' => null, 't' => false];
                    continue;
                }
                $grid[$r][$c] = ['g' => '▀', 'f' => $top, 'b' => $bottom, 't' => false];
            }
        }
    }

    /**
     * Shingle-glyph speckle (· • ◦), anchored to each disc so the pattern
     * shimmers as the discs slide. Cells in the intersection carry a WHITE
     * shared shingle (glowing); single-disc cells carry a dim one.
     *
     * @param array<int, array<int, array{g: string, f: ?array{int, int, int}, b: ?array{int, int, int}, t: bool}>> $grid
     */
    private static function drawShingles(array &$grid, float $bx): void
    {
        $r2 = self::RADIUS * self::RADIUS;

        for ($r = 0; $r < self::ROWS; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                if ($grid[$r][$c]['g'] === ' ') {
                    continue; // speckle only over drawn disc cells
                }

                $dx = $c + 0.5;
                $dy = 2 * $r + 0.5 - self::CY;
                $inA = ($dx - self::AX) ** 2 + $dy ** 2 <= $r2;
                $inB = ($dx - $bx) ** 2 + $dy ** 2 <= $r2;
                if (!$inA && !$inB) {
                    continue;
                }

                // each disc carries its own seeded shingle texture; the A
                // pattern is anchored to A, the B pattern slides with B.
                $markA = self::noise('a' . (int) floor($dx - self::AX) . ':' . $r) >= 0.62;
                $markB = self::noise('b' . (int) floor($dx - $bx) . ':' . $r) >= 0.62;

                if ($inA && $inB && ($markA || $markB)) {
                    // shared shingle inside the lens — glows white
                    $grid[$r][$c] = ['g' => '•', 'f' => self::COLOUR_HILITE, 'b' => null, 't' => true];
                } elseif ($inA && $markA) {
                    $grid[$r][$c] = ['g' => '·', 'f' => self::lerp(self::COLOUR_A, self::COLOUR_HILITE, 0.35), 'b' => null, 't' => false];
                } elseif ($inB && $markB) {
                    $grid[$r][$c] = ['g' => '◦', 'f' => self::lerp(self::COLOUR_B, self::COLOUR_HILITE, 0.35), 'b' => null, 't' => false];
                }
            }
        }
    }

    /**
     * Colour of one half-block pixel: neither → background; one disc → that
     * disc's rim-shaded colour; both → additive blend toward magenta (white on
     * the threshold flash).
     *
     * @return array{int, int, int}
     */
    private static function pixelColor(float $bx, int $pr, int $c, float $r2, bool $flashing): array
    {
        $dx = $c + 0.5;
        $dy = $pr + 0.5 - self::CY;
        $dA2 = ($dx - self::AX) ** 2 + $dy ** 2;
        $dB2 = ($dx - $bx) ** 2 + $dy ** 2;
        $inA = $dA2 <= $r2;
        $inB = $dB2 <= $r2;

        if ($inA && $inB) {
            if ($flashing) {
                return self::COLOUR_HILITE;
            }
            $blend = self::additive(self::COLOUR_A, self::COLOUR_B);

            return self::lerp($blend, self::COLOUR_OVERLAP, 0.5);
        }
        if ($inA) {
            return self::shade(self::COLOUR_A, $dA2);
        }
        if ($inB) {
            return self::shade(self::COLOUR_B, $dB2);
        }

        return self::COLOUR_BG;
    }

    /**
     * @param array<int, array<int, array{g: string, f: ?array{int, int, int}, b: ?array{int, int, int}, t: bool}>> $grid
     */
    private static function drawReadout(array &$grid, float $val): void
    {
        self::put($grid, 1, 33, 'near-dup J', self::COLOUR_READOUT);

        // mini-gauge sits ABOVE the counting readout (20-cell track,
        // gold tick at the 0.80 similarity-threshold default)
        $trackW = 20;
        $x0 = 32;
        $yr = 2;

        self::put($grid, 3, 32, '|A∩B|/|A∪B|', self::COLOUR_READOUT);

        $num = sprintf('%.2f', $val);
        $numColor = $val >= self::THRESHOLD ? self::COLOUR_HILITE : self::COLOUR_OVERLAP;
        self::put($grid, 4, 33, 'J = ' . $num, $numColor);

        for ($i = 0; $i < $trackW; $i++) {
            $col = $x0 + $i;
            $isTick = $i === (int) round(self::THRESHOLD * ($trackW - 1));
            $fill = $i < (int) round($val * ($trackW - 1));
            if ($fill) {
                $ch = $isTick ? '█' : '▓';
                $colour = $isTick ? self::COLOUR_HILITE : self::COLOUR_OVERLAP;
            } elseif ($isTick) {
                $ch = '▏';
                $colour = self::COLOUR_TICK;
            } else {
                $ch = '░';
                $colour = self::dim(self::COLOUR_READOUT, 0.30);
            }
            self::put($grid, $yr, $col, $ch, $colour);
        }
    }

    /**
     * @param array<int, array<int, array{g: string, f: ?array{int, int, int}, b: ?array{int, int, int}, t: bool}>> $grid
     */
    private static function drawWordmark(array &$grid, int $tick): void
    {
        $word = 'phpdup';
        $n = mb_strlen($word);
        // letters reveal across the lockup window; full word at FINAL_TICK
        $revealed = min($n, 1 + (int) floor(($tick - self::LOCKUP_START + 1) * $n / (self::FINAL_TICK - self::LOCKUP_START + 1)));
        $x0 = 22;   // left of the readout block (starts col 32), clear of merged mark
        $yr = 3;

        for ($i = 0; $i < $revealed; $i++) {
            $col = self::lerp(self::WORD_A, self::WORD_B, $i / ($n - 1));
            self::put($grid, $yr, $x0 + $i, mb_substr($word, $i, 1), $col);
        }

        if ($tick >= self::FINAL_TICK - 1) {
            self::put($grid, $yr + 1, $x0, 'merged', self::COLOUR_READOUT);
        }
    }

    /** Deterministic seeded noise in [0,1). No wall clock. */
    private static function noise(string $key): float
    {
        return hexdec(substr(hash('crc32b', $key), 0, 8)) / 4294967296.0;
    }

    // --------------------------------------------------------------- colour --

    /**
     * Additive blend in LINEAR RGB, converted back to sRGB (concept law).
     *
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     * @return array{int, int, int}
     */
    private static function additive(array $a, array $b): array
    {
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $lin = self::toLinear($a[$i]) + self::toLinear($b[$i]);
            $out[$i] = self::toSrgb(min(1.0, $lin));
        }

        return [$out[0], $out[1], $out[2]];
    }

    /**
     * Distance-falloff rim shading: brighter core, darker edge.
     *
     * @param array{int, int, int} $c
     * @return array{int, int, int}
     */
    private static function shade(array $c, float $d2): array
    {
        $nd = sqrt($d2) / self::RADIUS;          // 0 at centre .. 1 at rim
        $f = 0.55 + 0.45 * (1.0 - $nd * $nd);
        $f = max(0.0, min(1.0, $f));

        return [(int) round($c[0] * $f), (int) round($c[1] * $f), (int) round($c[2] * $f)];
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     * @return array{int, int, int}
     */
    private static function lerp(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    private static function toLinear(int $v): float
    {
        return ($v / 255.0) ** 2.2;
    }

    private static function toSrgb(float $lin): int
    {
        return (int) round(max(0.0, min(1.0, $lin)) ** (1 / 2.2) * 255.0);
    }

    /**
     * @param array{int, int, int} $c
     * @return array{int, int, int}
     */
    private static function dim(array $c, float $f): array
    {
        return [(int) round($c[0] * $f), (int) round($c[1] * $f), (int) round($c[2] * $f)];
    }

    /**
     * @param array{int, int, int} $c
     */
    private static function to256(array $c): int
    {
        $max = max($c);
        $min = min($c);
        if ($max - $min <= 12) {
            $gray = (int) round(($c[0] + $c[1] + $c[2]) / 3);
            if ($gray < 8) {
                return 16;
            }
            if ($gray > 248) {
                return 231;
            }

            return 232 + (int) round(($gray - 8) / 240 * 23);
        }

        $q = static fn (int $v): int => (int) round($v / 255 * 5);

        return 16 + 36 * $q($c[0]) + 6 * $q($c[1]) + $q($c[2]);
    }

    // ----------------------------------------------------------------- emit --

    /**
     * @param array<int, array<int, array{g: string, f: ?array{int, int, int}, b: ?array{int, int, int}, t: bool}>> $grid
     */
    private static function emit(array $grid, int $mode): string
    {
        $lines = [];
        foreach ($grid as $row) {
            $line = '';
            $prev = null;
            foreach ($row as $cell) {
                [$ch, $fg, $bg, $bold] = [$cell['g'], $cell['f'], $cell['b'], $cell['t']];
                $key = $mode === self::MODE_PLAIN
                    ? 'p'
                    : ($fg === null ? '-' : $fg[0] . ',' . $fg[1] . ',' . $fg[2])
                        . '/' . ($bg === null ? '-' : $bg[0] . ',' . $bg[1] . ',' . $bg[2])
                        . '|' . ($bold ? '1' : '0');
                if ($key !== $prev) {
                    if ($mode !== self::MODE_PLAIN) {
                        $line .= "\e[0m" . self::sgr($fg, $bg, $bold, $mode);
                    }
                    $prev = $key;
                }
                $line .= $ch;
            }
            $lines[] = $mode === self::MODE_PLAIN ? $line : $line . "\e[0m";
        }

        return implode("\n", $lines);
    }

    /**
     * @param ?array{int, int, int} $fg
     * @param ?array{int, int, int} $bg
     */
    private static function sgr(?array $fg, ?array $bg, bool $bold, int $mode): string
    {
        if ($mode === self::MODE_PLAIN) {
            return '';
        }

        $b = $bold ? '1;' : '';

        if ($mode === self::MODE_TRUECOLOR) {
            if ($fg === null) {
                return '';
            }
            if ($bg !== null) {
                return "\e[{$b}38;2;{$fg[0]};{$fg[1]};{$fg[2]};48;2;{$bg[0]};{$bg[1]};{$bg[2]}m";
            }

            return "\e[{$b}38;2;{$fg[0]};{$fg[1]};{$fg[2]}m";
        }

        if ($mode === self::MODE_256) {
            if ($fg === null) {
                return '';
            }
            if ($bg !== null) {
                return "\e[{$b}38;5;" . self::to256($fg) . ';48;5;' . self::to256($bg) . 'm';
            }

            return "\e[{$b}38;5;" . self::to256($fg) . 'm';
        }

        return '';
    }

    /**
     * @param array<int, array<int, array{g: string, f: ?array{int, int, int}, b: ?array{int, int, int}, t: bool}>> $grid
     * @param ?array{int, int, int} $fg
     */
    private static function put(array &$grid, int $row, int $col, string $ch, ?array $fg): void
    {
        if ($row < 0 || $row >= self::ROWS || $col < 0 || $col >= self::COLS) {
            return;
        }

        // multi-char text helper: place each grapheme across consecutive cols
        $chars = mb_str_split($ch);
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $c = $col + $i;
            if ($c >= self::COLS) {
                return;
            }
            $grid[$row][$c] = ['g' => $chars[$i], 'f' => $fg, 'b' => null, 't' => false];
        }
    }

    private static function easeOut(float $t): float
    {
        $t = max(0.0, min(1.0, $t));

        return 1.0 - (1.0 - $t) ** 2;
    }

    private static function easeInOut(float $t): float
    {
        $t = max(0.0, min(1.0, $t));

        return $t < 0.5 ? 2.0 * $t * $t : 1.0 - ((1.0 - $t) ** 2) * 0.5;
    }
}
