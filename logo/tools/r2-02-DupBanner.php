<?php

declare(strict_types=1);

/**
 * DupBanner — "Hash Rain Lock-in" animated truecolor ANSI logo for phpdup.
 *
 * Concept: columns of hex digits fall like rain, drawn from a tiny seeded pool
 * of 4-char hashes so duplicates occur BY DESIGN. Twin columns carry the same
 * hash stream; when the shared 4-char window lands on the same rows, both
 * cells lock into amber and stay put while the rain keeps flowing around them.
 * Each lock stamps the next letter of the wordmark PHPDUP into the middle band
 * while the unlocked rain fades to a ghost. Final frame: wordmark over faded
 * rain, locks holding.
 *
 * Ties to phpdup internals: exact hash-bucket matching (Clusterer phase 1)
 * over SubtreeHasher structural hashes.
 *
 * Hard constraints honoured:
 *   - 24-bit SGR (38;2/48;2) with 256-colour (38;5/48;5) fallback,
 *   - plain-text fallback with ZERO escape codes,
 *   - one-shot playback: 45 ticks @ 30 fps = 1.5 s, ends on the hold frame,
 *   - final logo <= 7 rows x <= 56 cols, no overflow at 80 cols,
 *   - PHP 8.1 floor, final class, strict types, PHPStan level 6 / Psalm 6 clean,
 *   - pure deterministic frame(int $tick): string — no wall-clock, no rand().
 *
 * Single source of truth: tools/build-logo.php renders logo.ansi + GIF from here.
 */
final class DupBanner
{
    public const COLS  = 56;
    public const ROWS  = 7;
    public const TICKS = 45;   // frames 0..44
    public const FPS   = 30;   // 45 / 30 = 1.5 s exactly

    /** seed for the deterministic LCG (tune only through verify.php gates) */
    public const SEED = 0xB10C7A3F;

    /** tiny hash pool — duplicates occur by design */
    public const POOL = ['7bd0', 'a3f1', 'e91c', '4d88', '5b21', 'c052', '9af7', '1ee5', 'd7a9', '0f3e'];

    /** rows fallen per tick (0.5 grid-rows/tick; slow layer halves it again) */
    private const FALL_PER_TICK = 0.5;

    /**
     * Lock events, in show order: [tick, hash, colL, colR, rowTop].
     * colL/colR are twin columns on one shared tape; the window lands on
     * rowTop..rowTop+3 at exactly tick $t → both lock amber → letter.
     *
     * @var list<array{0:int,1:string,2:int,3:int,4:int}>
     */
    private const EVENTS = [
        [5,  'a3f1',  1, 54, 3],   // decoy pair (low band, far edges)
        [9,  '7bd0',  4, 49, 1],   // P
        [15, '4d88',  5, 50, 1],   // H
        [20, '9af7',  6, 51, 1],   // P
        [25, '1ee5',  7, 48, 1],   // D
        [30, 'c052',  8, 47, 1],   // U
        [35, 'e91c',  9, 46, 1],   // P
    ];

    /** @var array<string, list<string>> 5x5 caps font */
    private const GLYPHS = [
        'P' => ['####.', '#...#', '#...#', '####.', '#....'],
        'H' => ['#...#', '#...#', '#####', '#...#', '#...#'],
        'D' => ['####.', '#...#', '#...#', '#...#', '####.'],
        'U' => ['#...#', '#...#', '#...#', '#...#', '.###.'],
    ];

    /** @var list<string> */
    private const WORD = ['P', 'H', 'P', 'D', 'U', 'P'];

    private const WORD_X0 = 11;   // wordmark box left col
    private const WORD_X1 = 45;   // wordmark box right col (inclusive)

    /** @var array<int, string>|null column slot => 16-char tape */
    private static ?array $tapes = null;

    /** @var array<int, array{0:int,1:int,2:int}>|null col => [phase, slow, tapeSlot] */
    private static ?array $cols = null;

    // --------------------------------------------------------------- palette

    /** @return array{0:int,1:int,2:int} */
    private static function teal(): array  { return [15, 118, 110]; }    // #0F766E

    /** @return array{0:int,1:int,2:int} */
    private static function lime(): array  { return [163, 230, 53]; }    // #A3E635

    /** @return array{0:int,1:int,2:int} */
    private static function amber(): array { return [251, 191, 36]; }    // #FBBF24

    /** @return array{0:int,1:int,2:int} */
    private static function flash(): array { return [255, 248, 225]; }

    /** @return array{0:int,1:int,2:int} */
    private static function bg(): array    { return [6, 18, 16]; }

    /** @return array{0:int,1:int,2:int} */
    private static function bgw(): array   { return [8, 22, 20]; }

    // ------------------------------------------------------------ public API

    /** Pure, deterministic truecolor frame. Same tick in, same bytes out, always. */
    public static function frame(int $tick): string
    {
        return self::encodeAnsi(self::cells($tick), true);
    }

    /** Same frame, 256-colour SGR (COLORTERM without truecolor). */
    public static function frame256(int $tick): string
    {
        return self::encodeAnsi(self::cells($tick), false);
    }

    /** Fallback frame: plain text, zero escape codes. */
    public static function framePlain(int $tick): string
    {
        return self::encodePlain(self::cells($tick));
    }

    /** The hold frame, plain. Drop-in for undecorated / NO_COLOR / --no-ansi. */
    public static function plain(): string
    {
        return self::framePlain(self::TICKS - 1);
    }

    /** Full one-shot truecolor byte stream (plays, ends holding the last frame). */
    public static function ansi(): string
    {
        return self::stream(true);
    }

    /** Full one-shot 256-colour byte stream. */
    public static function ansi256(): string
    {
        return self::stream(false);
    }

    /** Full one-shot plain stream (all frames, no escapes). */
    public static function plainStream(): string
    {
        $out = '';
        for ($t = 0; $t < self::TICKS; $t++) {
            if ($t > 0) {
                $out .= "\e[" . self::ROWS . "A\r";
            }
            $out .= self::encodePlain(self::cells($t));
        }

        return $out;
    }

    /**
     * Render through the truecolor → 256 → plain fallback chain.
     */
    public static function render(bool $decorated, bool $truecolor): string
    {
        if (!$decorated) {
            return self::plain();
        }

        return $truecolor ? self::ansi() : self::ansi256();
    }

    /**
     * Drive playback: pushes frames through $put, sleeping the tick budget.
     *
     * @param callable(string):void $put
     */
    public static function play(callable $put, ?callable $truecolorProbe = null): void
    {
        $tc   = $truecolorProbe === null ? true : $truecolorProbe();
        $wait = (int) round(1_000_000 / self::FPS);

        for ($t = 0; $t < self::TICKS; $t++) {
            $put(self::encodeAnsi(self::cells($t), $tc));
            if ($t < self::TICKS - 1) {
                usleep($wait);
            }
        }
    }

    /** Final tick index. */
    public static function finalTick(): int
    {
        return self::TICKS - 1;
    }

    /**
     * Lock-event table (evidence for verify.php): [tick, hash, colL, colR, rowTop].
     *
     * @return list<array{0:int,1:string,2:int,3:int,4:int}>
     */
    public static function locks(): array
    {
        return self::EVENTS;
    }

    /**
     * The cell grid for a tick: [glyph, fg|null, bg]. Exposed so the GIF
     * rasteriser shares this class as the single source of truth.
     *
     * @return array<int, array<int, array{0:string,1:array{0:int,1:int,2:int}|null,2:array{0:int,1:int,2:int}}>>
     */
    public static function cellsPublic(int $tick): array
    {
        return self::cells($tick);
    }

    // ------------------------------------------------------------ grid build

    /**
     * @return array<int, array<int, array{0:string,1:array{0:int,1:int,2:int}|null,2:array{0:int,1:int,2:int}}>>
     */
    private static function cells(int $tick): array
    {
        if ($tick < 0 || $tick >= self::TICKS) {
            throw new \InvalidArgumentException("tick out of range: {$tick}");
        }

        self::init();
        if (self::$tapes === null || self::$cols === null) {
            throw new \LogicException('world not initialised');
        }

        $tapes   = self::$tapes;
        $colDefs = self::$cols;

        $fade  = self::globalFade($tick);
        $cells = [];

        for ($r = 0; $r < self::ROWS; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                $cells[$r][$c] = [' ', null, self::bg()];
            }
        }

        // --- rain layer -------------------------------------------------------
        for ($c = 0; $c < self::COLS; $c++) {
            [$phase, $slow, $slot] = $colDefs[$c];
            $tape   = $tapes[$slot];
            $fstart = self::fallOffset($tick, $slow);
            $head   = ($tick * 3 + $phase * 5 + $slot * 11) % (self::ROWS + 4) - 2;

            for ($r = 0; $r < self::ROWS; $r++) {
                if ($r >= 1 && $r <= 5 && $c >= self::WORD_X0 && $c <= self::WORD_X1) {
                    continue; // wordmark box carved out
                }

                $d     = ($r - $head + 99) % (self::ROWS + 4);
                $depth = $slow === 1 ? 0.62 : 1.0;
                $comet = $d <= 5 ? max(0.0, 1.0 - $d * 0.16) : 0.08;
                $lit   = $fade * $depth * (0.25 + 0.75 * $comet);

                $ch  = $tape[((($r - $fstart) % 16) + 16) % 16];
                $ink = self::mix(self::teal(), self::lime(), min(1.0, $comet * $comet * 1.25));

                $cells[$r][$c] = [self::shadeGlyph($ch, $comet), self::scale($ink, max(0.10, $lit)), self::bg()];
            }
        }

        // --- wordmark box field ------------------------------------------------
        for ($r = 1; $r <= 5; $r++) {
            for ($c = self::WORD_X0; $c <= self::WORD_X1; $c++) {
                $cells[$r][$c] = [' ', null, self::bgw()];
            }
        }

        // --- locks (twin amber runs, stay put) ----------------------------------
        foreach (self::EVENTS as $ev) {
            [$et, $hash, $cl, $cr, $rt] = $ev;
            if ($tick < $et) {
                continue;
            }

            $heat = $tick - $et;
            $fg   = $heat <= 1 ? self::flash() : self::scale(self::amber(), $heat <= 4 ? 1.0 : 0.82);

            for ($i = 0; $i < 4; $i++) {
                $cells[$rt + $i][$cl] = [$hash[$i], $fg, self::bg()];
                $cells[$rt + $i][$cr] = [$hash[$i], $fg, self::bg()];
            }
        }

        // --- wordmark letters (revealed by lock events 2..7) --------------------
        for ($e = 0; $e < 6; $e++) {
            $stamp = self::EVENTS[$e + 1][0];
            if ($tick < $stamp) {
                continue;
            }

            $heat  = $tick - $stamp;
            $flash = $heat <= 1;
            $x     = self::WORD_X0 + $e * 6;
            $glyph = self::GLYPHS[self::WORD[$e]];

            for ($ry = 0; $ry < 5; $ry++) {
                for ($rx = 0; $rx < 5; $rx++) {
                    if ($glyph[$ry][$rx] !== '#') {
                        continue;
                    }

                    $ink = $flash ? self::flash() : self::mix([255, 214, 102], self::amber(), $ry / 4);
                    $cells[1 + $ry][$x + $rx] = ['█', $ink, self::bgw()];
                }
            }
        }

        return $cells;
    }

    /** rows fallen at tick $t for the given speed code (0 fast, 1 slow) */
    private static function fallOffset(int $tick, int $slow): int
    {
        $rate = self::FALL_PER_TICK * ($slow === 1 ? 0.5 : 1.0);

        return (int) floor($tick * $rate);
    }

    /** rain brightness ramp after the first lock; reaches hold floor by tick 40 */
    private static function globalFade(int $tick): float
    {
        if ($tick <= 9) {
            return 1.0;
        }

        if ($tick >= 40) {
            return 0.26;
        }

        return 1.0 - 0.74 * (($tick - 9) / 31);
    }

    /** near-head cells burn bright block; deep-tail cells dot down */
    private static function shadeGlyph(string $ch, float $comet): string
    {
        if ($comet >= 0.72) {
            return '▓';
        }

        return $comet >= 0.40 ? $ch : '·';
    }

    // ----------------------------------------------------------------- init

    private static function init(): void
    {
        if (self::$tapes !== null && self::$cols !== null) {
            return;
        }

        $state = self::SEED;
        $rand  = static function (int $n) use (&$state): int {
            $state = (int) (($state * 1103515245 + 12345) & 0x7FFFFFFF);

            return $state % $n;
        };

        // tape slots 0..count(EVENTS)-1 = lock pair tapes; the rest are texture
        $tapes = [];
        $pool  = self::POOL;

        for ($s = 0; $s < count(self::EVENTS) + 6; $s++) {
            $tape = '';
            for ($h = 0; $h < 4; $h++) {
                $tape .= $pool[$rand(count($pool))];
            }
            $tapes[$s] = $tape; // 16 chars
        }

        /** @var array<int, array{0:int,1:int,2:int}> $cols */
        $cols = [];
        for ($c = 0; $c < self::COLS; $c++) {
            $cols[$c] = [$rand(16), $c % 2, count(self::EVENTS) + $rand(6)];
        }

        // force each lock pair: shared tape slot, shared speed, and the event
        // hash window sitting on the target row band at exactly the event tick
        foreach (self::EVENTS as $e => $ev) {
            [$et, $hash, $cl, $cr, $rt] = $ev;

            $slot = $e;
            $slow = $cl % 2;
            $fs   = self::fallOffset($et, $slow);
            $k    = ((($rt - $fs) % 16) + 16) % 16;

            $tape = $tapes[$slot];
            for ($i = 0; $i < 4; $i++) {
                $idx  = ($k + $i) % 16;
                $tape = substr($tape, 0, $idx) . $hash[$i] . substr($tape, $idx + 1);
            }
            $tapes[$slot] = $tape;

            $cols[$cl] = [$rand(16), $slow, $slot];
            $cols[$cr] = $cols[$cl]; // full twin: identical stream, identical comet
        }

        self::$tapes = $tapes;
        self::$cols  = $cols;
    }

    // ------------------------------------------------------------ colour math

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     *
     * @return array{0:int,1:int,2:int}
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

    /**
     * @param array{0:int,1:int,2:int} $a
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function scale(array $a, float $k): array
    {
        $k = max(0.0, $k);

        return [
            min(255, (int) round($a[0] * $k)),
            min(255, (int) round($a[1] * $k)),
            min(255, (int) round($a[2] * $k)),
        ];
    }

    /**
     * Nearest xterm-256 index for an rgb triple.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public static function to256(array $rgb): int
    {
        $r     = (int) ($rgb[0] / 255 * 5 + 0.5);
        $g     = (int) ($rgb[1] / 255 * 5 + 0.5);
        $b     = (int) ($rgb[2] / 255 * 5 + 0.5);
        $cube  = 16 + 36 * $r + 6 * $g + $b;
        $best  = $cube;
        $bdist = self::rdist($rgb, self::cubeRGB($r, $g, $b));

        if ($rgb[0] === $rgb[1] && $rgb[1] === $rgb[2] && $rgb[0] > 8 && $rgb[0] < 238) {
            $gr    = (int) round(($rgb[0] - 8) / 10);
            $gs    = 8 + 10 * $gr;
            $gdist = self::rdist($rgb, [$gs, $gs, $gs]);
            if ($gdist < $bdist) {
                $best = 232 + $gr;
            }
        }

        return $best;
    }

    /**
     * @param int $r
     * @param int $g
     * @param int $b
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function cubeRGB(int $r, int $g, int $b): array
    {
        return [(int) round($r * 51), (int) round($g * 51), (int) round($b * 51)];
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    private static function rdist(array $a, array $b): int
    {
        return abs($a[0] - $b[0]) + abs($a[1] - $b[1]) + abs($a[2] - $b[2]);
    }

    // -------------------------------------------------------------- encoders

    /**
     * @param array<int, array<int, array{0:string,1:array{0:int,1:int,2:int}|null,2:array{0:int,1:int,2:int}}>> $cells
     */
    private static function encodeAnsi(array $cells, bool $truecolor): string
    {
        $out = '';

        foreach ($cells as $row) {
            $cf = null;
            $cb = null;
            $out .= "\e[0m";

            foreach ($row as $cell) {
                [$g, $fg, $bg] = $cell;

                if ($truecolor) {
                    if ($fg !== $cf) {
                        $out .= $fg === null ? "\e[39m" : sprintf("\e[38;2;%d;%d;%dm", $fg[0], $fg[1], $fg[2]);
                        $cf = $fg;
                    }
                    if ($bg !== $cb) {
                        // bg is non-nullable by grid contract
                        $out .= sprintf("\e[48;2;%d;%d;%dm", $bg[0], $bg[1], $bg[2]);
                        $cb = $bg;
                    }
                } else {
                    $f = $fg === null ? null : self::to256($fg);
                    $b = self::to256($bg);
                    if ($f !== $cf) {
                        $out .= $f === null ? "\e[39m" : "\e[38;5;{$f}m";
                        $cf = $f;
                    }
                    if ($b !== $cb) {
                        $out .= "\e[48;5;{$b}m";
                        $cb = $b;
                    }
                }

                $out .= $g;
            }

            $out .= "\e[0m\n";
        }

        return $out;
    }

    /**
     * @param array<int, array<int, array{0:string,1:array{0:int,1:int,2:int}|null,2:array{0:int,1:int,2:int}}>> $cells
     */
    private static function encodePlain(array $cells): string
    {
        $out = '';

        foreach ($cells as $row) {
            $line = '';
            foreach ($row as $cell) {
                $g    = $cell[0];
                $line .= $g === '█' ? '#' : $g;
            }
            $out .= rtrim($line) . "\n";
        }

        return $out;
    }

    /**
     * One-shot byte stream with in-place cursor rewinding.
     */
    private static function stream(bool $truecolor): string
    {
        $out = "\e[0m\e[?25l";

        for ($t = 0; $t < self::TICKS; $t++) {
            if ($t > 0) {
                $out .= "\e[" . self::ROWS . "A\r";
            }
            $out .= self::encodeAnsi(self::cells($t), $truecolor);
        }

        return $out . "\e[0m\e[?25h";
    }
}
