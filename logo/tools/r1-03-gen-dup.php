<?php

declare(strict_types=1);

/**
 * gen-dup.php — truecolor ANSI animation generator for php-dup-finder.
 *
 * Concept "SNAP / SCAN / VERDICT": two identical token-bar code streams
 * (file a.php in PHP-violet, its clone b.php in teal) slide out-of-phase,
 * snap into perfect alignment (the match-seam gutter lights up wherever the
 * ridges agree), then a finder beam sweeps left→right; behind the beam the
 * code freezes into a dim ghost archive while the matched ridges condense
 * into the DUP wordmark + caption pill, corner brackets lock on, glint pass,
 * fade to black — seamless 42-frame loop at 25 fps.
 *
 * Writes:  frames/fNN.ansi (one truecolor ANSI frame per file)
 *          logo.ansi       (all frames joined via lkAnim + static final)
 *
 * Requires logo-kit.php (copied from candy-top tools). No repo access.
 */

require __DIR__ . '/logo-kit.php';

// ---------------------------------------------------------------- canvas
const W = 90;          // terminal cols
const ROWS = 16;       // terminal rows
const PH = 32;         // design pixel rows (half-block: 2 px per cell)
const NF = 42;         // animation frames (~1.68 s @ 25 fps)

// pixel-row map
const TOP0 = 2;   const TOP1 = 13;   // top band  (12 px, rows 0..11)
const GUT0 = 14;  const GUT1 = 15;   // match seam
const BOT0 = 16;  const BOT1 = 27;   // bottom band (12 px)
// cell rows: 0 margin+a.php | 1-6 top band | 7 seam | 8-13 bot band+b.php pill
//            | 12 brackets | 13 ... | 14 caption | 15 margin+b.php

// DUP wordmark: pixel-doubled 5x7 chunky glyphs, 10 px each, 4 px gaps
const LETX = 26;  const LETY = 8;     // px origin, block 38 wide x 14 tall

const FONT = [
    'D' => ['####.', '#...#', '#...#', '#...#', '#...#', '#...#', '####.'],
    'U' => ['#...#', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
];

const CAPTION = 'php-dup · the duplicate finder';   // 30 chars, cols 30..59

// ---------------------------------------------------------------- palette
function pal(string $hex): array
{
    static $m = [];
    return $m[$hex] ??= lkHex($hex);
}

// ---------------------------------------------------------------- helpers
function clamp01(float $v): float
{
    return max(0.0, min(1.0, $v));
}

function smooth(float $t): float
{
    $t = clamp01($t);
    return $t * $t * (3 - 2 * $t);
}

/** global loop brightness: 3-frame symmetric fade so GIF seams invisibly */
function kFade(int $f): float
{
    return min(1.0, clamp01(min(($f + 1) / 3, (NF - $f) / 3)));
}

/** scroll position (frozen once snapped): 3 design px / frame */
function scrollAt(int $f): int
{
    return 3 * min($f, 14);
}

/** phase offset bottom band vs top: 26 px → 0 by f14 (the SNAP) */
function offsetAt(int $f): int
{
    return (int) round(26 * (1 - smooth($f / 14)));
}

/** finder beam col: offscreen, sweeps -6→96 over f16..31, then gone */
function beamAt(int $f): float
{
    if ($f < 16) {
        return -999.0;
    }
    if ($f <= 31) {
        return -6 + (($f - 16) / 15) * 102;
    }
    return 1e9;
}

/** hold-phase glint: -6→96 over f32..39 */
function glintAt(int $f): float
{
    if ($f < 32) {
        return -999.0;
    }
    if ($f <= 39) {
        return -6 + (($f - 32) / 7) * 102;
    }
    return 1e9;
}

/** caption/bracket lock-on alpha */
function appearAt(int $f): float
{
    return clamp01(($f - 29) / 3);
}

/** snap flash keyframes */
function flashAt(int $f): float
{
    return $f === 14 ? 1.0 : ($f === 15 ? 0.45 : 0.0);
}

/** gold ignition band hugging just behind the beam head (0..1) */
function ignite(float $bx, int $x): float
{
    $d = $bx - $x;
    if ($d <= 0 || $d > 20) {
        return 0.0;
    }
    return clamp01($d / 4) * clamp01(1 - ($d - 4) / 8);
}

/** crossfade reveal at col x for the beam */
function reveal(float $bx, int $x): float
{
    if ($bx < -500) {
        return 0.0;
    }
    if ($bx > 500) {
        return 1.0;
    }
    return clamp01(($bx - $x) / 5);
}

// ---------------------------------------------------------------- texture
/**
 * Shared fingerprint pattern — the SAME for both bands (that's the joke:
 * two files, identical ridges). 8 px token runs in 4-line "code" rows.
 */
function patternInk(int $row, int $col): bool
{
    if ($row % 3 === 2) {
        return false;                       // inter-line gap
    }
    $line = intdiv($row, 3);
    return lkNoise(intdiv($col, 8), $line, 17) > 0.44;
}

function patternBr(int $row, int $col): float
{
    $line = intdiv($row, 3);
    return 0.55 + 0.45 * lkNoise(intdiv($col, 8), $line, 29)
        * (0.8 + 0.2 * lkNoise($col, $row, 41));
}

/** ink colour of a band token px, null where pattern is off */
function streamAt(int $band, int $row, int $col, float $flash): ?array
{
    if (!patternInk($row, $col)) {
        return null;
    }
    $c = $band === 0
        ? lkMix(pal('#5B4BE0'), pal('#A08CFF'), $row / 11)
        : lkMix(pal('#0FA396'), pal('#5FE9D6'), $row / 11);
    $c = lkScale($c, patternBr($row, $col));
    if ($flash > 0) {
        $c = lkMix($c, [255, 255, 255], 0.16 * $flash);
    }
    return $c;
}

/** match-seam px: lights where the (identical) ridges agree above and below */
function connAt(int $x, int $s, int $D, float $flash): ?array
{
    $cnt = 0;
    $tc = null;
    foreach ([0, 1, 3, 4, 6, 7, 9, 10] as $row) {
        if (patternInk($row, $x + $s) && patternInk($row, $x + $s + $D)) {
            $cnt++;
            $tc ??= streamAt(0, $row, $x + $s, 0);
        }
    }
    if ($cnt < 4 || $tc === null) {
        return null;                        // out of phase → mostly dark
    }
    $bc = streamAt(1, 7, $x + $s + $D, 0) ?? pal('#0FA396');
    $c = lkMix($tc, $bc, 0.5);
    $c = lkScale($c, 0.7 + 0.5 * $cnt / 8);
    if ($flash > 0) {
        $c = lkMix($c, [255, 255, 255], 0.55 * $flash);
    }
    return $c;
}

/** dark terminal field: vertical centre glow, side vignette, CRT dither */
function bgPx(int $x, int $y, int $f): array
{
    $v = 1 - abs($y - 15.5) / 15.5;
    $c = lkMix(pal('#05050C'), pal('#0F1124'), 0.18 + 0.82 * $v * $v);
    $c = lkScale($c, 0.82 + 0.18 * (1 - abs($x - 44.5) / 44.5));
    if (($x + $y + $f) % 4 === 0) {
        $c = lkScale($c, 0.86);
    }
    return $c;
}

// ---------------------------------------------------------------- wordmark
/** is (tx,ty) — DUP-local px, 38x14 — inside an ink letter px? */
function letterAt(int $tx, int $ty): bool
{
    if ($tx < 0 || $tx >= 38 || $ty < 0 || $ty >= 14) {
        return false;
    }
    if ($tx >= 10 && $tx < 14) {
        return false;                       // D/U gap
    }
    if ($tx >= 24 && $tx < 28) {
        return false;                       // U/P gap
    }
    [$g, $off] = $tx < 10 ? ['D', 0] : ($tx < 24 ? ['U', 14] : ['P', 28]);
    return FONT[$g][intdiv($ty, 2)][intdiv($tx - $off, 2)] === '#';
}

function letterColor(int $tx, int $ty): array
{
    $c = lkGradient(['#8A5CFF', '#FF4FA3', '#FFC24A'], $tx / 37);
    $c = lkShade($c, 0.30 - 0.62 * $ty / 13);            // lit top, dark base
    return lkScale($c, 0.93 + 0.07 * lkNoise($tx, $ty, 61));
}

// ---------------------------------------------------------------- frame
/** build the 32x90 rgb px grid for one frame */
function pxFrame(int $f): array
{
    $s = scrollAt($f);
    $D = offsetAt($f);
    $bx = beamAt($f);
    $gx = glintAt($f);
    $fl = flashAt($f);
    $k = kFade($f);

    $px = [];
    for ($y = 0; $y < PH; $y++) {
        $row = [];
        for ($x = 0; $x < W; $x++) {
            $bg = bgPx($x, $y, $f);

            // live stream under this px (band ink | seam connector | none)
            $stream = null;
            if ($y >= TOP0 && $y <= TOP1) {
                $stream = streamAt(0, $y - TOP0, $x + $s, $fl);
            } elseif ($y >= BOT0 && $y <= BOT1) {
                $stream = streamAt(1, $y - BOT0, $x + $s + $D, $fl);
            } elseif ($y === GUT0 || $y === GUT1) {
                $stream = connAt($x, $s, $D, $fl);
            }
            $eff = $stream ?? $bg;

            // verdict layer target behind the beam
            $inBox = $x >= LETX && $x < LETX + 38 && $y >= LETY && $y < LETY + 14;
            $tx = $x - LETX;
            $ty = $y - LETY;
            $ig = ignite($bx, $x);
            if ($inBox && letterAt($tx, $ty)) {
                $target = letterColor($tx, $ty);
                if ($ig > 0) {
                    $target = lkMix($target, pal('#FFDCA0'), $ig * 0.35);   // ignites at the beam
                }
                if ($gx > -500 && $gx < 500 && abs($x - ($gx - $ty * 0.5)) < 1.5) {
                    $target = lkMix($target, [255, 255, 255], 0.68);         // hold-phase glint
                }
            } elseif ($inBox && letterAt($tx - 1, $ty - 1)) {
                $target = lkMix($bg, pal('#241C38'), 0.85);                   // drop shadow
            } else {
                $target = lkMix($bg, $eff, 0.13);                             // frozen ghost archive
            }

            // crossfade live → verdict at this column
            $r = reveal($bx, $x);
            $c = $r <= 0 ? $eff : ($r >= 1 ? $target : lkMix($eff, $target, $r));
            if ($r > 0 && $r < 1 && $stream !== null) {
                $c = lkMix($c, pal('#FFC24A'), $ig * 0.55);                   // gold ignition on live code
            }

            // beam column tint + glow
            if ($bx > -500 && $bx < 500) {
                $d = abs($x - $bx);
                if ($d <= 2) {
                    $tf = $d < 0.5 ? 0.50 : ($d < 1.5 ? 0.22 : 0.09);
                    $c = lkMix($c, pal('#FFE29A'), $tf);
                }
            }

            $row[] = lkScale($c, $k);
        }
        $px[$y] = $row;
    }
    return $px;
}

/** overlay text/corner cells onto the half-block grid */
function decorate(array &$cells, int $f): void
{
    $k = kFade($f);
    $ap = appearAt($f);
    $pill = pal('#0B0D1A');
    $capBg = pal('#0D1020');

    // file pills a.php (cell row 0) / b.php (cell row 15) — fade at beam pass
    $rl = reveal(beamAt($f), 3);
    foreach ([[0, 1, 'a.php', pal('#8F9CFF')], [15, 1, 'b.php', pal('#7FD9CB')]] as [$cy, $cx, $txt, $col]) {
        $fg = lkScale(lkMix($col, $pill, $rl), $k);
        foreach (str_split($txt) as $i => $g) {
            $cells[$cy][$cx + $i] = [$g, $fg, $pill];
        }
    }

    // caption pill appears with the verdict
    $capFg = lkScale(lkMix(pal('#8E9AC0'), $capBg, 1 - $ap), $k);
    foreach (mb_str_split(CAPTION) as $i => $g) {
        $cells[14][30 + $i] = [$g, $capFg, $capBg];
    }

    // corner brackets lock onto the wordmark
    $brFgBase = pal('#3E7BFA');
    foreach ([[3, 24, '┌'], [3, 65, '┐'], [11, 24, '└'], [11, 65, '┘']] as [$cy, $cx, $g]) {
        $behind = $cells[$cy][$cx];
        $base = $behind[2] ?? $behind[1] ?? pal('#0B0D1A');
        $cells[$cy][$cx] = [$g, lkScale(lkMix($brFgBase, $base, 1 - $ap), $k), $behind[2]];
    }
}

// ---------------------------------------------------------------- main
$dir = __DIR__;
@mkdir("$dir/frames", 0o755, true);

$frames = [];
foreach (range(0, NF - 1) as $f) {
    $cells = lkHalfBlock(pxFrame($f));
    decorate($cells, $f);
    $ansi = lkEncode($cells, 'tc');
    lkVerify($ansi, "frame $f");                    // equal widths, [0m ends
    $frames[] = $ansi;
    file_put_contents(sprintf('%s/frames/f%02d.ansi', $dir, $f), $ansi);
}

$anim = lkAnim($frames, $frames[NF - 3]);           // end bright (f39 = post-glint hold)
lkCheckDepth($anim, 'tc', 'logo');
file_put_contents("$dir/logo.ansi", $anim);
fwrite(STDERR, sprintf("wrote logo.ansi (%d bytes) + %d frames, %dx%d cells\n", strlen($anim), NF, W, ROWS));
