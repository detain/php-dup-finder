<?php

declare(strict_types=1);

namespace Phpdup\Branding;

/**
 * RadarBanner — "Radar Clone Sweep" animated truecolor ANSI banner for phpdup.
 *
 * A compact 26x7-cell phosphor scope (46x14 half-block pixels). A sweep arm
 * rotates exactly once over 45 ticks (1.5 s at 30 fps), leaving an
 * exponentially fading phosphor-green trail toward near-black. Eight seeded
 * "duplicate cluster" blips sit on the outer range ring; each fires one
 * white-hot pulse as the arm passes, then its colour rides a similarity heat
 * ramp (cool blue = fuzzy ... amber ... red = near-exact). A 4x5 pixel-font
 * PHPDUP wordmark rides dead centre. The final tick is the hold frame:
 * parked arm, frozen lit constellation, wordmark — playback is one-shot.
 *
 * Owner-spec contract:
 *   - frame(int $tick): string is PURE and deterministic (fixed-seed LCG,
 *     no wall clock): frame 0 and the final tick are byte-reproducible.
 *   - 24-bit SGR truecolor; fallback 38;5/48;5 256-colour; undecorated /
 *     NO_COLOR / --no-ansi renderers get a plain-text frame with ZERO
 *     escape codes (see detectDepth()/render()).
 *   - <= 7 text rows, <= 56 cols, <= 45 ticks, ends on hold.
 *
 * The scope is an ellipse (33x14 px) rather than a disc so the full PHPDUP
 * wordmark fits inside the range ring at the 7-row cap; blips are clamped to
 * the outer band so they never hide behind wordmark glyphs.
 */
final class RadarBanner
{
    /** Total ticks: 0..TICKS-1 = one exact rotation. */
    public const TICKS = 45;

    /** Index of the held final frame. */
    public const FINAL_TICK = 44;

    /** Canvas width in half-block pixels (= text columns). */
    public const COLS = 46;

    /** Canvas height in half-block pixels (= 2x text rows). */
    public const PX_ROWS = 14;

    /** Rendered text rows (7-row cap). */
    public const TEXT_ROWS = 7;

    /** Ellipse scope centre (px-space canvas coords). */
    private const CX = 22.5;
    private const CY = 6.5;

    /** Scope radii in px: full-height compact ellipse hugging the wordmark. */
    private const RX = 16.5;
    private const RY = 6.3;

    /** Stroke half-widths (px) for the outer range ring / mid graticule. */
    private const RING_W = 0.62;
    private const MID_K = 0.55;
    private const MID_W = 0.55;

    /** Phosphor palette (owner spec). */
    private const PHOSPHOR = [0x22, 0xC5, 0x5E];
    private const COOL = [0x0E, 0xA5, 0xE9];
    private const AMBER = [0xFA, 0xCC, 0x15];
    private const HOT = [0xEF, 0x44, 0x44];
    private const SCREEN = [0x02, 0x1A, 0x0B];
    private const WORD = [0xDF, 0xFF, 0xEA];
    private const WHITE = [255, 255, 255];

    /** Trail decay constant (degrees behind the arm head). */
    private const TRAIL_TAU = 27.0;

    /** Sweep-line gaussian sigma (degrees). */
    private const ARM_SIGMA = 1.5;

    /** Fixed LCG seed — deterministic blip constellation, no wall clock. */
    private const SEED = 52;

    /** Blips sit on the outer band so they never tuck behind wordmark glyphs. */
    private const BLIP_RHO_MIN = 0.85;
    private const BLIP_RHO_SPAN = 0.11;

    /** @var list<array{deg: float, rho: float, q: float}> seeded blips */
    private array $blips;

    /** 4x5 pixel-font glyphs (the PHPDUP letters); '#' = lit pixel. */
    private const FONT = [
        'P' => ['###.', '#..#', '###.', '#...', '#...'],
        'H' => ['#..#', '#..#', '####', '#..#', '#..#'],
        'D' => ['###.', '#..#', '#..#', '#..#', '###.'],
        'U' => ['#..#', '#..#', '#..#', '#..#', '.##.'],
    ];

    private const WORDMARK = ['P', 'H', 'P', 'D', 'U', 'P'];

    /** Wordmark glyph box: cols 8..36, pixel rows 4..8 (centred). */
    private const WORD_X0 = 8;
    private const WORD_Y0 = 4;

    public function __construct()
    {
        $this->blips = $this->seededBlips();
    }

    /** One truecolor ANSI frame (all 7 rows, each ending with a reset). */
    public function frame(int $tick): string
    {
        return $this->frameAtDepth($tick, 'tc');
    }

    /** @param 'tc'|'256' $depth */
    public function frameAtDepth(int $tick, string $depth): string
    {
        return $this->encode($this->pixels($tick), $depth);
    }

    /** The held final frame (truecolor). */
    public function finalFrame(): string
    {
        return $this->frame(self::FINAL_TICK);
    }

    /**
     * One-shot animation stream: 45 frames joined by cursor-up, ending on
     * the frozen hold frame. $depth: 'tc'|'256'.
     */
    public function play(string $depth = 'tc'): string
    {
        $frames = [];
        for ($t = 0; $t < self::TICKS; $t++) {
            $frames[] = $this->frameAtDepth($t, $depth);
        }
        return "\e[?25l" . implode("\e[" . self::TEXT_ROWS . "A\r", $frames) . "\e[?25h";
    }

    /** Static fallback: one plain line, zero escape codes. */
    public static function plainFrame(): string
    {
        return 'PHPDUP - RADAR CLONE SWEEP';
    }

    /**
     * Fallback chain: undecorated/NO_COLOR -> plain, COLORTERM truecolor/
     * 24bit -> tc, everything else -> 256. $env defaults to the real env.
     *
     * @param array<string,string>|null $env
     * @return 'plain'|'tc'|'256'
     */
    public static function detectDepth(bool $decorated, ?array $env = null): string
    {
        if (!$decorated) {
            return 'plain';
        }
        $env ??= self::sysEnv();
        if (array_key_exists('NO_COLOR', $env)) {
            return 'plain';
        }
        $colorterm = strtolower($env['COLORTERM'] ?? '');
        if ($colorterm === 'truecolor' || $colorterm === '24bit') {
            return 'tc';
        }
        return '256';
    }

    /**
     * Convenience: full animation (or plain fallback) at the auto-detected
     * depth. Callers pass their console's decorated() + env.
     *
     * @param array<string,string>|null $env
     */
    public function render(bool $decorated, ?array $env = null): string
    {
        $depth = self::detectDepth($decorated, $env);
        return $depth === 'plain' ? self::plainFrame() . "\n" : $this->play($depth);
    }

    // ============================================================ internals

    /** @return array<string,string> */
    private static function sysEnv(): array
    {
        $out = [];
        foreach (getenv() as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        return $out;
    }

    /**
     * Deterministic LCG (glibc constants) -> blip constellation.
     * @return list<array{deg: float, rho: float, q: float}>
     */
    private function seededBlips(): array
    {
        $state = self::SEED;
        $next = static function () use (&$state): float {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
            return $state / 0x80000000;
        };
        $blips = [];
        for ($i = 0; $i < 8; $i++) {
            // Even compass coverage with +-10 deg jitter; q = similarity score.
            $deg = -157.5 + $i * 45.0 + ($next() * 20.0 - 10.0);
            $q = 0.30 + 0.68 * $next();
            $rho = self::BLIP_RHO_MIN + self::BLIP_RHO_SPAN * $next();
            $blips[] = ['deg' => $deg, 'rho' => $rho, 'q' => $q];
        }
        return $blips;
    }

    /**
     * Similarity heat ramp: 0 cool blue -> 0.5 phosphor -> 0.78 amber -> 1 hot red.
     * @return array{0:int,1:int,2:int}
     */
    public function heat(float $q): array
    {
        if ($q < 0.5) {
            return self::mix(self::COOL, self::PHOSPHOR, $q / 0.5);
        }
        if ($q < 0.78) {
            return self::mix(self::PHOSPHOR, self::AMBER, ($q - 0.5) / 0.28);
        }
        return self::mix(self::AMBER, self::HOT, ($q - 0.78) / 0.22);
    }

    /**
     * Render the canvas for one tick as a px grid.
     * @return list<list<array{0:int,1:int,2:int}>>
     */
    private function pixels(int $tick): array
    {
        $arm = -90.0 + 360.0 * $tick / (self::TICKS - 1);
        $freeze = $tick >= self::FINAL_TICK;
        $rx = self::RX;
        $ry = self::RY;
        $px = [];
        for ($y = 0; $y < self::PX_ROWS; $y++) {
            $row = [];
            $dy = $y - self::CY;
            for ($x = 0; $x < self::COLS; $x++) {
                $dx = $x - self::CX;
                // CRT scanlines: odd px rows sit darker
                $base = $y % 2 === 1 ? self::mul(self::SCREEN, 0.72) : self::SCREEN;
                $u = $dx / $rx;
                $v = $dy / $ry;
                $rho = sqrt($u * $u + $v * $v);
                if ($rho > 1.09) {
                    $row[$x] = $base;
                    continue;
                }
                // Sweep field uses the warped (ellipse-normalised) angle so
                // the arm stays a straight radius line with an even trail.
                $phi = rad2deg(atan2($v, $u));
                $behind = self::wrap180($arm - $phi);
                $glow = $behind <= 0.0
                    ? exp(-($behind * $behind) / (2.0 * self::ARM_SIGMA * self::ARM_SIGMA))
                    : exp(-$behind / self::TRAIL_TAU);
                // Structure uses exact px distance to the ellipse curve.
                $sa = atan2($dy, $dx);
                $cs = cos($sa);
                $sn = sin($sa);
                $ellR = $rx * $ry / sqrt(($ry * $cs) ** 2 + ($rx * $sn) ** 2);
                $pd = sqrt($dx * $dx + $dy * $dy);
                $i = $rho <= 1.02 ? $glow * 0.9 : 0.0;
                if (abs($pd - $ellR) < self::RING_W) {
                    $i += 0.20 + $glow * 0.55;
                } elseif (abs($pd - $ellR * self::MID_K) < self::MID_W) {
                    $i += 0.045 + $glow * 0.35;
                } elseif ($rho <= 1.02) {
                    $i += 0.028;
                }
                $c = $i > 0.0
                    ? self::add($base, self::mul(self::PHOSPHOR, min(1.0, $i)))
                    : $base;
                if ($behind > -2.5 && $behind < 2.5 && $rho <= 1.02) {
                    // hot filament inside the sweep line
                    $g = exp(-($behind * $behind) / (2.0 * self::ARM_SIGMA * self::ARM_SIGMA));
                    $c = self::add(self::mul($c, 1.0 - 0.55 * $g), self::mul(self::WHITE, 0.55 * $g));
                }
                $row[$x] = $c;
            }
            $px[$y] = $row;
        }

        // blips: fire once as the arm passes, pulse white, decay; frozen lit
        foreach ($this->blips as $b) {
            $rad = deg2rad($b['deg']);
            $bx = self::CX + $rx * $b['rho'] * cos($rad);
            $by = self::CY + $ry * $b['rho'] * sin($rad);
            if ($freeze) {
                $lit = 1.0;
                $pulse = 0.0;
            } else {
                $travel = fmod($b['deg'] + 90.0 + 360.0, 360.0);
                $tHit = $travel * (self::TICKS - 1) / 360.0;
                $e = $tick - $tHit;
                if ($e < -1.0) {
                    $lit = 0.0;
                    $pulse = 0.0;
                } elseif ($e < 0.0) {
                    // faint anticipation as the arm approaches, no pulse
                    $lit = 0.18 * (1.0 + $e);
                    $pulse = 0.0;
                } else {
                    $lit = $e < 1.5 ? 1.0 : max(0.0, 0.88 ** ($e - 1.5));
                    $pulse = $e < 1.5 ? 1.0 - $e / 1.5 : 0.0;
                }
            }
            if ($lit <= 0.003) {
                continue;
            }
            $heat = $this->heat($b['q']);
            $x0 = max(0, (int) floor($bx - 1.5));
            $x1 = min(self::COLS - 1, (int) ceil($bx + 1.5));
            $y0 = max(0, (int) floor($by - 1.5));
            $y1 = min(self::PX_ROWS - 1, (int) ceil($by + 1.5));
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = $x0; $x <= $x1; $x++) {
                    $d = sqrt(($x - $bx) ** 2 + ($y - $by) ** 2);
                    $f = $d <= 0.55 ? 1.0 : max(0.0, 1.0 - ($d - 0.55) / 0.95);
                    $f = $f * $f * (3.0 - 2.0 * $f);
                    if ($f * $lit >= 0.62) {
                        // solid hot core: overwrite, not additive (keeps hue pure)
                        $px[$y][$x] = self::add(self::mul($px[$y][$x], 1.0 - $lit), self::mul($heat, $lit));
                        if ($pulse > 0.0) {
                            $px[$y][$x] = self::add(self::mul($px[$y][$x], 1.0 - 0.7 * $pulse), self::mul(self::WHITE, 0.7 * $pulse));
                        }
                    } else {
                        $add = self::mul($heat, $f * $lit);
                        if ($pulse > 0.0) {
                            $add = self::add($add, self::mul(self::WHITE, $f * $pulse * 0.8));
                        }
                        $px[$y][$x] = self::add($px[$y][$x], $add);
                    }
                }
            }
        }

        // wordmark last: bright phosphor-white block glyphs over the scope
        foreach (self::WORDMARK as $i => $ch) {
            $mask = self::FONT[$ch];
            $gx = self::WORD_X0 + $i * 5;
            for ($gy = 0; $gy < 5; $gy++) {
                for ($gxp = 0; $gxp < 4; $gxp++) {
                    if ($mask[$gy][$gxp] === '#') {
                        $px[self::WORD_Y0 + $gy][$gx + $gxp] = self::WORD;
                    }
                }
            }
        }

        return $px;
    }

    /**
     * @param list<list<array{0:int,1:int,2:int}>> $px
     * @param 'tc'|'256' $depth
     */
    private function encode(array $px, string $depth): string
    {
        $out = '';
        for ($r = 0; $r < self::TEXT_ROWS; $r++) {
            $line = '';
            $curFg = null;
            $curBg = null;
            for ($x = 0; $x < self::COLS; $x++) {
                $codes = [];
                $bg = $this->sgr($px[2 * $r + 1][$x], true, $depth);
                if ($bg !== $curBg) {
                    $codes[] = $curBg = $bg;
                }
                $fg = $this->sgr($px[2 * $r][$x], false, $depth);
                if ($fg !== $curFg) {
                    $codes[] = $curFg = $fg;
                }
                if ($codes !== []) {
                    $line .= "\e[" . implode(';', $codes) . 'm';
                }
                $line .= "\u{2580}";
            }
            $out .= $line . "\e[0m\n";
        }
        return $out;
    }

    /** @param array{0:int,1:int,2:int} $c */
    private function sgr(array $c, bool $bg, string $depth): string
    {
        $pre = $bg ? '48' : '38';
        if ($depth === 'tc') {
            return "{$pre};2;{$c[0]};{$c[1]};{$c[2]}";
        }
        return "{$pre};5;" . self::to256($c);
    }

    /** @param array{0:int,1:int,2:int} $c */
    private static function to256(array $c): int
    {
        static $memo = [];
        $k = $c[0] . ',' . $c[1] . ',' . $c[2];
        if (isset($memo[$k])) {
            return $memo[$k];
        }
        $levels = [0, 95, 135, 175, 215, 255];
        $best = 16;
        $bestD = INF;
        foreach ($levels as $ri) {
            foreach ($levels as $gi) {
                foreach ($levels as $bi) {
                    $d = self::dist($c, [$ri, $gi, $bi]);
                    if ($d < $bestD) {
                        $bestD = $d;
                        $best = 16 + 36 * array_search($ri, $levels, true)
                            + 6 * array_search($gi, $levels, true)
                            + array_search($bi, $levels, true);
                    }
                }
            }
        }
        for ($g = 0; $g < 24; $g++) {
            $v = 8 + $g * 10;
            $d = self::dist($c, [$v, $v, $v]);
            if ($d < $bestD) {
                $bestD = $d;
                $best = 232 + $g;
            }
        }
        return $memo[$k] = $best;
    }

    /**
     * redmean-ish squared distance.
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    private static function dist(array $a, array $b): float
    {
        $rm = ($a[0] + $b[0]) / 2.0;
        $dr = $a[0] - $b[0];
        $dg = $a[1] - $b[1];
        $db = $a[2] - $b[2];
        return (2.0 + $rm / 256.0) * $dr * $dr + 4.0 * $dg * $dg + (2.0 + (255.0 - $rm) / 256.0) * $db * $db;
    }

    private static function wrap180(float $deg): float
    {
        while ($deg <= -180.0) {
            $deg += 360.0;
        }
        while ($deg > 180.0) {
            $deg -= 360.0;
        }
        return $deg;
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
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
     * @param array{0:int,1:int,2:int} $c
     * @return array{0:int,1:int,2:int}
     */
    private static function mul(array $c, float $k): array
    {
        return [
            (int) round($c[0] * $k),
            (int) round($c[1] * $k),
            (int) round($c[2] * $k),
        ];
    }

    /**
     * Additive channel clamp.
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     * @return array{0:int,1:int,2:int}
     */
    private static function add(array $a, array $b): array
    {
        return [
            min(255, max(0, $a[0] + $b[0])),
            min(255, max(0, $a[1] + $b[1])),
            min(255, max(0, $a[2] + $b[2])),
        ];
    }
}
