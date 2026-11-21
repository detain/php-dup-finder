<?php

declare(strict_types=1);

namespace Phpdup\Support;

/**
 * DupBanner — "Plasma Crystallization" animated truecolor ANSI logo for phpdup.
 *
 * Concept: a boiling plasma noise field (violet / magenta / orange) is pulled
 * toward the "phpdup" wordmark mask; cell by cell the fuzz CRYSTALLIZES into a
 * sharp mark (#F8FAFC) on deep navy (#0B1020) — fuzzy near-duplicates resolving
 * into one exact cluster (Jaccard + APTED thresholds made visible).
 *
 * Hard constraints honoured:
 *   - 24-bit SGR (38;2) with 256-color (38;5) and plain-text fallbacks.
 *   - One-shot playback, 44 ticks at 30 fps = 1.467 s, ends on a byte-stable
 *     hold (every frame from SETTLE_TICK onward is identical).
 *   - 7 rows x 56 cols, no horizontal overflow at 80 cols.
 *   - Pure deterministic frame(int): same tick always returns identical bytes.
 */
final class DupBanner
{
    public const FPS = 30;
    public const TICKS = 44;            // ticks 0..43 (<= 45 budget)
    public const FINAL_TICK = 43;
    public const SETTLE_TICK = 40;      // first byte-identical hold frame

    public const ROWS = 7;
    public const COLS = 56;

    public const SEED = 20261120;

    // phase map (original 0.3 s boil / 2.5 s crystallize / 0.3 s hold,
    // compressed proportionally to fit the hard <= 1.5 s budget at 30 fps)
    private const BOIL_END = 3;         // last pure-plasma tick
    private const LOCK_START = 4;       // first cell can crystallize here
    private const LOCK_SPAN = 28;       // radial sweep duration (ticks)
    private const LOCK_JITTER = 6;      // per-cell noise offset (ticks)
    private const LOCK_END = 38;        // LOCK_START + LOCK_SPAN + LOCK_JITTER
    private const PULL_WINDOW = 5;      // glow-toward-mark ticks before lock
    private const FADE_LAG = 2;         // field keeps boiling this long into lock
    private const FADE_SPAN = 26;       // field cells reach navy within this span
    private const FLASH_TICKS = 2;      // white overshoot after a lock

    public const VIOLET = '#6D28D9';
    public const MAGENTA = '#DB2777';
    public const ORANGE = '#F97316';
    public const MARK = '#F8FAFC';
    public const NAVY = '#0B1020';

    /** 5x6 pixel glyphs for the wordmark "phpdup". */
    private const GLYPHS = [
        'p' => ['####.', '#####', '#####', '####.', '#....', '#....'],
        'h' => ['#....', '#....', '####.', '#####', '#...#', '#...#'],
        'd' => ['....#', '....#', '.####', '#####', '#...#', '#...#'],
        'u' => ['#...#', '#...#', '#...#', '#...#', '#####', '.###.'],
    ];

    /** @var array<int, array<int, int>> cell => lock tick for mask cells */
    private array $lockTick = [];

    /** @var array<int, array<int, bool>> */
    private array $mask = [];

    /** @var array<int, array<int, float>> */
    private array $jitter = [];

    private float $maxDist = 1.0;

    public function __construct()
    {
        $this->buildMask();
    }

    /**
     * Pure frame: byte-identical output for the same tick, every run.
     * Emits a truecolor frame (7 lines, each terminated \e[0m\n).
     */
    public function frame(int $tick): string
    {
        return $this->renderFrame($tick, true);
    }

    /** 256-color variant of frame(): valid 38;5 SGR only. */
    public function frame256(int $tick): string
    {
        return $this->renderFrame($tick, false);
    }

    /** Full truecolor animation stream — plays once, ends on the hold frame. */
    public function play(): string
    {
        return $this->assembleStream(true);
    }

    /** Full 256-color animation stream. */
    public function play256(): string
    {
        return $this->assembleStream(false);
    }

    /** Plain-text fallback frame — guaranteed zero escape codes. */
    public function staticText(): string
    {
        return 'phpdup';
    }

    /**
     * Pick the right rendition for the current environment:
     * NO_COLOR / --no-ansi style undecorated -> plain; COLORTERM truecolor
     * -> 24-bit; anything else -> 256-color approximation.
     *
     * @param array<string, string> $env
     */
    public function render(array $env = []): string
    {
        $env += getenv() ?: [];
        if (($env['NO_COLOR'] ?? '') !== '') {
            return $this->staticText();
        }
        $ct = strtolower($env['COLORTERM'] ?? '');
        if ($ct === 'truecolor' || $ct === '24bit') {
            return $this->play();
        }
        return $this->play256();
    }

    /**
     * Write a stream to a file handle, pacing ticks at FPS and holding the
     * final frame afterwards.
     * @param resource $handle
     */
    public function emit($handle, string $stream): void
    {
        $chunks = explode("\e[" . self::ROWS . "A\r", $stream);
        foreach ($chunks as $i => $chunk) {
            fwrite($handle, $chunk);
            if ($i < count($chunks) - 1) {
                usleep((int) round(1_000_000 / self::FPS));
            }
        }
        fflush($handle);
    }

    // ================================================================ core

    private function assembleStream(bool $truecolor): string    {
        $out = "\e[?25l";
        $sep = "\e[" . self::ROWS . "A\r";
        for ($t = 0; $t < self::TICKS; $t++) {
            if ($t > 0) {
                $out .= $sep;
            }
            $out .= $this->renderFrame($t, $truecolor);
        }
        return $out . "\e[?25h";
    }

    private function renderFrame(int $tick, bool $truecolor): string
    {
        if ($tick < 0 || $tick >= self::TICKS) {
            throw new \InvalidArgumentException("DupBanner tick out of range: {$tick}");
        }

        $lines = '';
        for ($y = 0; $y < self::ROWS; $y++) {
            $prev = null;
            $line = '';
            for ($x = 0; $x < self::COLS; $x++) {
                $rgb = $this->cellColor($x, $y, $tick);
                if ($rgb !== $prev) {
                    $line .= $truecolor
                        ? sprintf("\e[38;2;%d;%d;%dm", $rgb[0], $rgb[1], $rgb[2])
                        : sprintf("\e[38;5;%dm", $this->xterm256($rgb));
                    $prev = $rgb;
                }
                $line .= "\u{2588}"; // full block
            }
            $lines .= $line . "\e[0m\n";
        }
        return $lines;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function cellColor(int $x, int $y, int $tick): array
    {
        if (isset($this->lockTick[$y][$x])) {
            return $this->markCellColor($x, $y, $tick);
        }
        return $this->fieldCellColor($x, $y, $tick);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function markCellColor(int $x, int $y, int $tick): array
    {
        $lock = $this->lockTick[$y][$x];
        $plasma = $this->plasma($x, $y, $tick);

        if ($tick >= $lock) {
            $age = $tick - $lock;
            if ($age < self::FLASH_TICKS) {
                $k = $age === 0 ? 0.8 : 0.35;
                return $this->mix($this->hex(self::MARK), $this->hex('#FFFFFF'), $k);
            }
            return $this->hex(self::MARK);
        }

        $before = $lock - $tick;
        if ($before <= self::PULL_WINDOW) {
            $pull = $this->ease((self::PULL_WINDOW - $before) / self::PULL_WINDOW);
            return $this->mix($plasma, $this->hex(self::MARK), $pull * 0.9);
        }
        return $plasma;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function fieldCellColor(int $x, int $y, int $tick): array
    {
        $plasma = $this->plasma($x, $y, $tick);
        if ($tick <= self::BOIL_END + self::FADE_LAG) {
            return $plasma;
        }
        $jit = $this->jitter[$y][$x];
        $start = self::BOIL_END + self::FADE_LAG + $jit * self::LOCK_JITTER;
        $fade = $this->clamp(($tick - $start) / self::FADE_SPAN);
        return $this->mix($plasma, $this->hex(self::NAVY), $this->ease($fade));
    }

    /**
     * Cheap three-sine plasma value mapped over violet -> magenta -> orange
     * -> violet with a breathing brightness wave. ~6*40+ cells, 3 sines each.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function plasma(int $x, int $y, int $tick): array
    {
        $t = $tick * 0.55;
        $v = sin($x * 0.28 + sin($y * 0.5) * 2.2 + $t * 0.8)
            + sin($y * 0.55 - cos($x * 0.18) * 1.8 - $t * 0.55)
            + sin(($x + $y * 2.0) * 0.14 + $t * 0.3);
        $u = $this->clamp(($v + 3) / 6);
        $u = fmod($u + $t * 0.05, 1.0); // drift the hue cycle over time

        $violet = $this->hex(self::VIOLET);
        $magenta = $this->hex(self::MAGENTA);
        $orange = $this->hex(self::ORANGE);
        if ($u < 1 / 3) {
            $rgb = $this->mix($violet, $magenta, $u * 3);
        } elseif ($u < 2 / 3) {
            $rgb = $this->mix($magenta, $orange, ($u - 1 / 3) * 3);
        } else {
            $rgb = $this->mix($orange, $violet, ($u - 2 / 3) * 3);
        }

        $k = 0.8 + 0.2 * sin($u * M_PI * 2.0 + $t * 0.8);
        return [
            (int) round($this->clamp0255($rgb[0] * $k)),
            (int) round($this->clamp0255($rgb[1] * $k)),
            (int) round($this->clamp0255($rgb[2] * $k)),
        ];
    }

    // ================================================================ mask

    private function buildMask(): void
    {
        $word = self::GLYPHS;
        $letters = str_split('phpdup');
        $startX = intdiv(self::COLS - (count($letters) * 5 + (count($letters) - 1) * 2), 2);
        $startY = 1;

        foreach ($letters as $letter) {
            $rows = $word[$letter];
            foreach ($rows as $gy => $bits) {
                foreach (str_split($bits) as $gx => $bit) {
                    if ($bit === '#') {
                        $this->mask[$startY + $gy][$startX + $gx] = true;
                    }
                }
            }
            $startX += 7; // 5-wide glyph + 2-col gap
        }

        // deterministic per-cell jitter over the whole canvas
        for ($y = 0; $y < self::ROWS; $y++) {
            for ($x = 0; $x < self::COLS; $x++) {
                $this->jitter[$y][$x] = $this->hashUnit($x, $y);
            }
        }

        // lock ticks spread radially from the wordmark centre + jitter
        $cx = self::COLS / 2 - 0.5;
        $cy = $startY + 2.5;
        $dists = [];
        foreach ($this->mask as $y => $row) {
            foreach ($row as $x => $on) {
                $d = sqrt(pow(($x - $cx) * 2.0, 2) + pow($y - $cy, 2));
                $dists[$y . ':' . $x] = $d;
                if ($d > $this->maxDist) {
                    $this->maxDist = $d;
                }
            }
        }
        foreach ($this->mask as $y => $row) {
            foreach ($row as $x => $on) {
                $nd = $dists[$y . ':' . $x] / $this->maxDist;
                $lock = self::LOCK_START
                    + (int) round($nd * self::LOCK_SPAN)
                    + (int) round($this->jitter[$y][$x] * self::LOCK_JITTER);
                $this->lockTick[$y][$x] = min($lock, self::LOCK_END);
            }
        }
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function hex(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }

    /** integer hash -> unit float (64-bit platform assumed) */
    private function hashUnit(int $x, int $y): float
    {
        $n = ($x * 374761393 + $y * 668265263 + self::SEED) & 0x7FFFFFFF;
        $n = (($n ^ ($n >> 13)) * 1274126177) & 0x7FFFFFFF;
        $n = ($n ^ ($n >> 16)) & 0x7FFFFFFF;
        return ($n & 0xFFFF) / 0xFFFF;
    }

    /**
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     * @return array{0: int, 1: int, 2: int}
     */
    private function mix(array $a, array $b, float $f): array
    {
        $f = $this->clamp($f);
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $f),
            (int) round($a[1] + ($b[1] - $a[1]) * $f),
            (int) round($a[2] + ($b[2] - $a[2]) * $f),
        ];
    }

    private function ease(float $t): float
    {
        return $t * $t * (3 - 2 * $t);
    }

    private function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    private function clamp0255(float $v): float
    {
        return max(0.0, min(255.0, $v));
    }

    /**
     * Nearest xterm-256 cube index for a 3-channel color.
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private function xterm256(array $rgb): int
    {
        $steps = [0, 95, 135, 175, 215, 255];
        $idx = [0, 0, 0];
        foreach ($rgb as $c => $v) {
            $best = 0;
            $bestD = 255;
            foreach ($steps as $s => $step) {
                $d = abs($v - $step);
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = $s;
                }
            }
            $idx[$c] = $best;
        }
        return 16 + 36 * $idx[0] + 6 * $idx[1] + $idx[2];
    }
}
