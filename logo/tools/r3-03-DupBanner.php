<?php

declare(strict_types=1);

namespace Phpdup\Branding;

use OutOfRangeException;

/**
 * LSH Jackpot — phpdup animated ANSI banner (round-3, concept 03).
 *
 * Two signature rows (blocks A and B) roll past as 16 slot reels of 2-digit
 * hex MinHash values, grouped into 4 bands of 4 — mirroring
 * Phpdup\Fingerprint\MinHashSignature::ROWS_PER_BAND = 4, the banding that
 * makes Phpdup\Index\LshIndex emit a candidate pair when any band collides.
 * Reels stop left-to-right with motion blur (dimmed flicker strip values);
 * a band whose 4/4 positions agree lights its frame gold ("candidate pair");
 * a 3/4 band flashes pink to show a band only counts when EVERY row matches.
 *
 * Pure and deterministic: frame() depends on the tick alone (avalanche-hash
 * spin stream, no wall clock, no rand()).
 *
 * Budget: 44 ticks @ 34 ms = 1.496 s, then the terminal HOLDS the final
 * frame. Canvas 7 rows x 53 cols (2 label + 4x12 band + 3 separators).
 * Truecolor SGR (38;2/48;2) with a 256-colour ladder fallback and a
 * zero-escape, ASCII-boxed plain fallback for NO_COLOR / undecorated.
 */
final class DupBanner
{
    public const MODE_TRUECOLOR = 'tc';
    public const MODE_256       = '256';
    public const MODE_PLAIN     = 'plain';

    public const ROWS    = 7;
    public const COLS    = 53;
    public const TICKS   = 44;   // <= 45 ticks
    public const TICK_MS = 34;   // 29.4 fps <= 30; 44 * 34 = 1496 ms <= 1500 ms

    public const BANDS     = 4;
    public const BAND_ROWS = 4;  // == MinHashSignature::ROWS_PER_BAND (verified in repo)

    /** Block A's 16 MinHash positions, 2-digit hex (the reel faces). */
    public const SIG_A = ['7c', '19', 'b4', '02', '41', '8d', 'f0', 'ac', '6a', '2b', 'c8', '93', '5d', 'e7', '90', '1a'];
    /** Block B — band 2 matches A fully (jackpot); band 3 matches 3/4 (near miss). */
    public const SIG_B = ['e3', '5a', '6f', '9d', 'f2', '11', '84', 'db', '6a', '2b', 'c8', '93', '5d', 'e7', '90', '9c'];

    public const JACKPOT_BAND  = 2;
    public const NEARMISS_BAND = 3;

    // Owner palette (concept 03).
    private const FACE_RGB  = [0x1E, 0x1B, 0x4B]; // #1E1B4B reel face
    private const DIGIT_RGB = [0xE0, 0xE7, 0xFF]; // #E0E7FF digits
    private const FRAME_RGB = [0x63, 0x66, 0xF1]; // #6366F1 band frames
    private const GOLD_RGB  = [0xFA, 0xCC, 0x15]; // #FACC15 jackpot
    private const GOLD2_RGB = [0xFD, 0xE6, 0x8A]; // #FDE68A jackpot flash
    private const PINK_RGB  = [0xFB, 0x71, 0x85]; // #FB7185 near miss

    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private const PALETTE = [
        'face'     => self::FACE_RGB,
        'digits'   => self::DIGIT_RGB,
        'frame'    => self::FRAME_RGB,
        'gold'     => self::GOLD_RGB,
        'gold2'    => self::GOLD2_RGB,
        'pink'     => self::PINK_RGB,
        'page'     => [0x0B, 0x0A, 0x16], // slab behind the machine
        'label'    => [0xA5, 0xB4, 0xFC],
        'settle'   => [0x4C, 0x46, 0x9E], // one-tick highlight under a stopping reel
        'settleFg' => [0xEE, 0xF2, 0xFF],
        'pinkDim'  => [0xC2, 0x5E, 0x78], // settled near-miss frame (desaturated pink)
        'winFace'  => [0x2A, 0x26, 0x62], // jackpot band face (lit)
        'wordA'    => [0x81, 0x8C, 0xF8], // wordmark gradient endpoints
        'wordB'    => [0xFA, 0xCC, 0x15],
        'tagDim'   => [0x6B, 0x72, 0xA8], // tagline ramp start
    ];

    /** @var array<string, int> frame-colour priority for shared separators */
    private const RANK = ['frame' => 0, 'pinkDim' => 2, 'pink' => 3, 'gold2' => 4, 'gold' => 5];

    private const WORDMARK = 'phpdup';
    private const TAGLINE  = '4/4 rows collide -> candidate pair';

    /** Ticks the sweep starts checking each band (27 = 0.9s). */
    private const CHECK_BASE = 27;

    /** Label gutter: two columns (block A / B labels) left of the machine. */
    private const X0 = 2;

    /** @var list<int> tick each reel settles on (~1.5 ticks = 50 ms apart) */
    private const STOPS = [3, 5, 6, 8, 9, 11, 12, 14, 15, 17, 18, 20, 21, 23, 24, 26];

    /** @var array<string, string> plain-mode ASCII box substitutions (width-preserving) */
    private const PLAIN_BOX = ['│' => '|', '─' => '-', '═' => '='];

    private function __construct()
    {
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Pure: the full frame for one tick — ROWS rows joined by \n, no
     * trailing \n; every row space-padded to COLS so all modes share one
     * occupancy mask. $mode is 'tc' | '256' | 'plain'.
     */
    public static function frame(int $tick, string $mode = self::MODE_TRUECOLOR): string
    {
        if ($tick < 0 || $tick >= self::TICKS) {
            throw new OutOfRangeException('tick ' . $tick . ' outside 0..' . (self::TICKS - 1));
        }
        if (!in_array($mode, [self::MODE_TRUECOLOR, self::MODE_256, self::MODE_PLAIN], true)) {
            throw new OutOfRangeException('mode not supported');
        }

        $rows = [];
        for ($y = 0; $y < self::ROWS; $y++) {
            $cells = [];
            for ($x = 0; $x < self::COLS; $x++) {
                $cells[] = self::cell($tick, $y, $x);
            }
            $rows[] = self::encodeRow($cells, $mode);
        }
        return implode("\n", $rows);
    }

    /**
     * One-shot animation stream: all ticks joined by cursor-up rewinds; the
     * final frame is left on screen (HOLD). Plain mode: final frame only.
     * $mode is 'tc' | '256' | 'plain'.
     */
    public static function play(string $mode = self::MODE_TRUECOLOR): string
    {
        if ($mode === self::MODE_PLAIN) {
            return self::frame(self::TICKS - 1, $mode) . "\n";
        }
        $frames = [];
        for ($t = 0; $t < self::TICKS; $t++) {
            $frames[] = self::frame($t, $mode);
        }
        return "\e[?25l" . implode("\e[" . (self::ROWS - 1) . "A\r", $frames) . "\e[0m\e[?25h\n";
    }

    /** Static final frame (the hold state). */
    public static function render(string $mode = self::MODE_TRUECOLOR): string
    {
        return self::frame(self::TICKS - 1, $mode);
    }

    /**
     * Fallback chain: NO_COLOR wins, then undecorated, then COLORTERM.
     *
     * @param array<string, string> $env
     * @return self::MODE_*
     */
    public static function resolveMode(bool $decorated, array $env): string
    {
        if (array_key_exists('NO_COLOR', $env) || !$decorated) {
            return self::MODE_PLAIN;
        }
        $ct = strtolower($env['COLORTERM'] ?? '');
        return (str_contains($ct, 'truecolor') || str_contains($ct, '24bit'))
            ? self::MODE_TRUECOLOR
            : self::MODE_256;
    }

    /**
     * Matching A/B positions inside one band.
     *
     * @return int<0, max>
     */
    public static function bandMatches(int $band): int
    {
        if ($band < 0 || $band >= self::BANDS) {
            throw new OutOfRangeException("band $band outside 0.." . (self::BANDS - 1));
        }
        $offset = $band * self::BAND_ROWS;
        $a      = array_slice(self::SIG_A, $offset, self::BAND_ROWS);
        $b      = array_slice(self::SIG_B, $offset, self::BAND_ROWS);
        $hits   = 0;
        foreach ($a as $k => $v) {
            if (($b[$k] ?? null) === $v) {
                $hits++;
            }
        }
        return $hits;
    }

    // ------------------------------------------------------------------
    // Timeline (pure functions of the tick)
    // ------------------------------------------------------------------

    private static function stopTick(int $reel): int
    {
        return self::STOPS[$reel];
    }

    /** Strip value under a spinning reel: avalanche hash of (reel, tick, row). */
    private static function spinHex(int $reel, int $tick, int $row): string
    {
        $h = (($reel + 1) * 0x9E3779B1 + ($tick + 1) * 0x85EBCA6B + ($row + 1) * 0x2545F491) & 0xFFFFFFFF;
        $h ^= $h >> 16;
        $h = ($h * 0x7F4A7C15) & 0xFFFFFFFF;
        $h ^= $h >> 13;
        return sprintf('%02x', $h & 0xFF);
    }

    /** Frame colour of a band's rules + separators over time. */
    private static function frameColor(int $tick, int $band): string
    {
        if ($band === self::JACKPOT_BAND) {
            if ($tick >= 30) {
                if ($tick === 32 || $tick === 34) {
                    return 'gold2'; // jackpot flash pale
                }
                return 'gold';     // flashes gold from 31, then stays lit
            }
        }
        if ($band === self::NEARMISS_BAND) {
            if ($tick >= 28 && $tick <= 30 && $tick % 2 === 0) {
                return 'pink';     // two pink flashes while it checks
            }
            if ($tick >= 31) {
                return 'pinkDim';  // settled: dimmed pink, remembers the miss
            }
        }
        if ($tick === self::CHECK_BASE + $band) {
            return 'gold2';        // single-tick "checking" sweep pulse
        }
        return 'frame';
    }

    /** Hotter of two adjacent bands' frame colour (for shared separators). */
    private static function separatorColor(int $tick, int $left, int $right): string
    {
        $a = self::frameColor($tick, $left);
        $b = self::frameColor($tick, $right);
        return (self::RANK[$b] ?? 0) > (self::RANK[$a] ?? 0) ? $b : $a;
    }

    /** Reel-face key: the jackpot band's face lights with the winning frame. */
    private static function cellFace(int $tick, int $band): string
    {
        return ($band === self::JACKPOT_BAND && $tick >= 30) ? 'winFace' : 'face';
    }

    // ------------------------------------------------------------------
    // Grid: one cell = [char, fgKey|null, bgKey|null, spinDim?int]
    // ------------------------------------------------------------------

    /** @return array{0: string, 1: ?string, 2: ?string, 3?: int} */
    private static function cell(int $tick, int $y, int $x): array
    {
        if ($y >= 5) {
            return self::wordCell($tick, $y, $x);
        }

        if ($x < self::X0) {
            if (($y === 1 || $y === 3) && $x === 0) {
                return [$y === 1 ? 'A' : 'B', 'label', 'face'];
            }
            return [' ', null, 'page'];
        }

        $gx   = $x - self::X0;
        $band = intdiv($gx, 13);
        $col  = $gx % 13;

        if ($col === 12 && $band < self::BANDS - 1) {
            return ['│', self::separatorColor($tick, $band, $band + 1), 'page'];
        }

        $reel = intdiv($col, 3);
        $slot = $col % 3;

        if ($y === 0 || $y === 2 || $y === 4) {
            $fc   = self::frameColor($tick, $band);
            $bull = $fc === 'gold' || $fc === 'gold2';
            return [$bull ? '═' : '─', $fc, 'page'];
        }

        // Digit rows: 1 = block A, 3 = block B.
        if ($slot === 2) {
            return [' ', null, 'page']; // inter-reel gap
        }
        $reelIndex = $band * self::BAND_ROWS + $reel;
        $sig       = $y === 1 ? self::SIG_A : self::SIG_B;
        $stop      = self::stopTick($reelIndex);

        if ($tick < $stop) {
            $dim = (($tick + $reelIndex) % 2 === 0) ? 55 : 35;
            return [substr(self::spinHex($reelIndex, $tick, $y === 1 ? 0 : 1), $slot, 1), 'digits', self::cellFace($tick, $band), $dim];
        }
        if ($tick === $stop) {
            return [substr($sig[$reelIndex], $slot, 1), 'settleFg', 'settle'];
        }
        $lit = $tick >= 30 && $band === self::JACKPOT_BAND;
        return [substr($sig[$reelIndex], $slot, 1), 'digits', $lit ? 'winFace' : 'face'];
    }

    /** @return array{0: string, 1: ?string, 2: ?string, 3?: int} */
    private static function wordCell(int $tick, int $y, int $x): array
    {
        if ($y === 5) {
            // wordmark: types in one glyph per tick over ticks 36-41
            $i     = $x - self::X0;
            $typed = max(0, min(strlen(self::WORDMARK), $tick - 35));
            if ($i < 0 || $i >= strlen(self::WORDMARK) || $i >= $typed) {
                return [' ', null, null];
            }
            $t   = $i / max(1, strlen(self::WORDMARK) - 1);
            $rgb = self::mix(self::PALETTE['wordA'], self::PALETTE['wordB'], $t);
            return [substr(self::WORDMARK, $i, 1), self::srgb($rgb), null];
        }

        // tagline: whole line fades up over ticks 42-44 (final frame at 44)
        if ($tick < 42) {
            return [' ', null, null];
        }
        $i = $x - self::X0;
        if ($i < 0 || $i >= strlen(self::TAGLINE)) {
            return [' ', null, null];
        }
        $alpha = match (true) {
            $tick === 42 => 0.35,
            $tick === 43 => 0.68,
            default => 1.0,
        };
        $rgb = self::mix(self::PALETTE['tagDim'], self::PALETTE['digits'], $alpha);
        return [substr(self::TAGLINE, $i, 1), self::srgb($rgb), null];
    }

    // ------------------------------------------------------------------
    // Colour + encoding plumbing
    // ------------------------------------------------------------------

    /**
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     * @return array{0: int, 1: int, 2: int}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        $k = max(0.0, min(1.0, $t));
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $k),
            (int) round($a[1] + ($b[1] - $a[1]) * $k),
            (int) round($a[2] + ($b[2] - $a[2]) * $k),
        ];
    }

    /**
     * Literal RGB carried through the fg slot as "raw:r:g:b" (decoded in
     * colors()) so gradient glyphs need no palette entry.
     *
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private static function srgb(array $rgb): string
    {
        return 'raw:' . $rgb[0] . ':' . $rgb[1] . ':' . $rgb[2];
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private static function xterm256(array $rgb): int
    {
        [$r, $g, $b] = $rgb;
        if (abs($r - $g) <= 8 && abs($g - $b) <= 8) {
            if (max($r, $g, $b) <= 8) {
                return 16;
            }
            if (min($r, $g, $b) >= 238) {
                return 231;
            }
            $i = (int) round(((max($r, $g, $b) - 8) / 247) * 23);
            return 232 + max(0, min(23, $i));
        }
        $q = static fn (int $v): int => (int) round(($v / 255) * 5);
        return 16 + 36 * $q($r) + 6 * $q($g) + $q($b);
    }

    /**
     * @param array{0: string, 1: ?string, 2: ?string, 3?: int} $cell
     * @return array{0: ?array{0: int, 1: int, 2: int}, 1: ?array{0: int, 1: int, 2: int}}
     */
    private static function colors(array $cell): array
    {
        $fgName = $cell[1];
        $bgName = $cell[2];

        $fg = null;
        if ($fgName !== null) {
            if (str_starts_with($fgName, 'raw:')) {
                /** @var list<string> $p */
                $p    = explode(':', $fgName);
                $fg   = [(int) $p[1], (int) $p[2], (int) $p[3]];
            } else {
                $fg = self::PALETTE[$fgName];
            }
            $dim = $cell[3] ?? null;
            if ($dim !== null) {
                $fg = self::mix($fg, self::PALETTE['face'], $dim / 100);
            }
        }
        $bg = $bgName === null ? null : self::PALETTE[$bgName];
        return [$fg, $bg];
    }

    /**
     * Encode one row for a mode. Colored modes emit SGR runs covering every
     * cell (rows pad to COLS with explicit bg); plain carries zero escapes
     * and swaps box glyphs for their ASCII twins.
     *
     * @param list<array{0: string, 1: ?string, 2: ?string, 3?: int}> $cells
     */
    private static function encodeRow(array $cells, string $mode): string
    {
        if ($mode === self::MODE_PLAIN) {
            $line = '';
            foreach ($cells as $c) {
                $line .= self::PLAIN_BOX[$c[0]] ?? $c[0];
            }
            return $line;
        }

        $out = '';
        $n   = count($cells);
        $i   = 0;
        while ($i < $n) {
            [$fg, $bg] = self::colors($cells[$i]);
            $j = $i;
            while ($j < $n) {
                [$fg2, $bg2] = self::colors($cells[$j]);
                if ($fg2 !== $fg || $bg2 !== $bg) {
                    break;
                }
                $j++;
            }
            $run = '';
            for ($k = $i; $k < $j; $k++) {
                $run .= $cells[$k][0];
            }
            if ($fg === null && $bg === null) {
                $out .= $run; // unpainted tail (label row edges, word rows)
            } else {
                $seq = '';
                if ($fg !== null) {
                    $seq .= $mode === self::MODE_TRUECOLOR
                        ? sprintf("\e[38;2;%d;%d;%dm", $fg[0], $fg[1], $fg[2])
                        : sprintf("\e[38;5;%dm", self::xterm256($fg));
                }
                if ($bg !== null) {
                    $seq .= $mode === self::MODE_TRUECOLOR
                        ? sprintf("\e[48;2;%d;%d;%dm", $bg[0], $bg[1], $bg[2])
                        : sprintf("\e[48;5;%dm", self::xterm256($bg));
                }
                $out .= $seq . $run . "\e[0m";
            }
            $i = $j;
        }
        return $out;
    }
}
