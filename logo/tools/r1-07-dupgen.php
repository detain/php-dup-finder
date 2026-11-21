<?php

declare(strict_types=1);

/**
 * dupgen.php — "DIFF ALIGN" animated truecolor ANSI logo for phpdup.
 *
 * Concept (NOTES.md): the violet original DUP burns in; a red clone copy
 * materializes offset, slides into alignment and each of its pixels
 * self-classifies like a diff (red = unmatched, green = matched); on lock a
 * white ripple passes, the clone settles green and the status line reports
 * the match. A cyan laser sweeps continuously; pixel-noise dissolve restarts
 * the loop.
 *
 * Pipeline: 40 cell-grid frames -> lkEncode('tc') -> lkAnim -> logo.ansi
 * Render to PNGs with ansi2gif.php, stitch with build.sh (ImageMagick).
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ canvas

const W      = 88;   // pixel columns == terminal columns
const HPX    = 32;   // pixel rows == 16 terminal rows (half-blocks)
const FRAMES = 40;
const LOCK   = 30;   // white ripple begins here
const HOLD   = 34;   // locked/breathing frames begin here

const WORD   = 'DUP';
const WORD_X = 26;   // left column of the wordmark
const WORD_W = 35;   // 3 glyphs x 9 + 2 gaps x 4
const ORIG_Y  = 2;   // top pixel row of the original  (cells 1..6)
const CLONE_Y = 16;  // top pixel row of the clone     (cells 8..13)
const DX_MAX  = 24.0;        // clone slide-in offset (columns)
const SLIDE_A = 14;          // slide-in start frame
const SLIDE_B = 29;          // slide-in end frame (dx -> 0)
const MAT_A   = 10;          // clone materialize start frame
const DISS    = 6;           // dissolve-out frames at loop head

/** chunky 9x12 pixel masks (stroke ~3) */
const GLYPH = [
    'D' => [
        '#######..', '########.', '###....##', '###....##', '###...###', '###...###',
        '###...###', '###...###', '###....##', '###....##', '########.', '#######..',
    ],
    'U' => [
        '###...###', '###...###', '###...###', '###...###', '###...###', '###...###',
        '###...###', '###...###', '###...###', '.###.###.', '.#######.', '..#####..',
    ],
    'P' => [
        '#######..', '########.', '###....##', '###....##', '###...###', '###...###',
        '###....##', '########.', '#######..', '###......', '###......', '###......',
    ],
];

const C_BASE   = '#0B0A14';
const C_STRIPE = '#161229';
const C_LINE   = '#2C2554';
const C_HDR    = '#D6D1F5';
const C_DIM    = '#8B86B8';
const C_FAINT  = '#4A4570';
const C_STATUS = '#8A8FB8';
const C_OK     = '#4FE6A2';
const C_HINT   = '#E8A0B4';
const C_LASER  = '#3FF2FF';
const C_WHITE  = '#FFFFFF';

/** vertical gradients (top -> bottom stops) */
const G_ORIG  = ['#D29BFF', '#8F6BFF', '#5B4FE8'];
const G_RED   = ['#FF9E5E', '#FF5C5C'];
const G_GREEN = ['#6CFFB4', '#22C787'];
const H_ORIG  = '#4B2E9E';
const H_RED   = '#7A2030';
const H_GREEN = '#1E6B4A';

// ================================================================ timeline

function easeOutCubic(float $p): float
{
    return 1 - (1 - $p) ** 3;
}

/** slide offset for a frame (null = clone not present at an offset slot) */
function slideDx(int $f): ?float
{
    if ($f < MAT_A) {
        return null;
    }
    if ($f < SLIDE_A) {
        return DX_MAX;
    }
    if ($f <= SLIDE_B) {
        $p = max(0.0, min(1.0, ($f - SLIDE_A) / (SLIDE_B - SLIDE_A)));
        return DX_MAX * (1 - easeOutCubic($p));
    }
    return 0.0;
}

/** clone visibility 0..1 (pixel-noise dissolve threshold) */
function cloneAlpha(int $f): float
{
    if ($f < DISS) {
        return max(0.0, 1 - $f / DISS);              // dissolve out (loop head)
    }
    if ($f < MAT_A) {
        return 0.0;                                  // gone: idle scan window
    }
    if ($f < SLIDE_A) {
        return ($f - MAT_A + 1) / (SLIDE_A - MAT_A); // materialize in
    }
    return 1.0;
}

function isLocked(int $f): bool
{
    return $f >= LOCK;
}

/** laser column (float), sweeps past both words; wraps outside the panel */
function laserX(int $f): float
{
    return fmod($f * 2.6, W + 24) - 12;
}

function slideProgress(int $f): float
{
    if ($f < SLIDE_A) {
        return 0.0;
    }
    if ($f > SLIDE_B) {
        return 1.0;
    }
    return ($f - SLIDE_A) / (SLIDE_B - SLIDE_A);
}

// ================================================================ word mask

/** @return list<string> 12 rows x 35 chars of '#'/.' */
function wordMask(): array
{
    static $mask = null;
    if ($mask !== null) {
        return $mask;
    }
    [$m] = lkLayout(GLYPH, WORD, letterGap: 4, wordGap: 4, margin: 0);
    return $mask = $m;
}

// ================================================================ painting

/** mix one canvas pixel toward $c, stronger on ink pixels */
function mixInk(array &$px, array &$ink, int $x, int $y, array $c, float $kInk, float $kBg): void
{
    $k = !empty($ink[$y][$x]) ? $kInk : $kBg;
    if ($k > 0) {
        $px[$y][$x] = lkMix($px[$y][$x], $c, $k);
    }
}

/** soft halo around a boolean ink field, washed into the canvas bg */
function addHalo(array &$px, array $field, array $color): void
{
    $in = static fn (int $x, int $y): bool => ($field[$y][$x] ?? false);
    for ($y = 0; $y < HPX; $y++) {
        for ($x = 0; $x < W; $x++) {
            if ($in($x, $y)) {
                continue;
            }
            $near = 0.0;
            foreach ([[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]] as [$dy, $dx]) {
                if ($in($x + $dx, $y + $dy)) {
                    $near = max($near, 0.30);
                }
            }
            if ($near === 0.0) {
                foreach ([[-2, 0], [2, 0], [0, -2], [0, 2]] as [$dy, $dx]) {
                    if ($in($x + $dx, $y + $dy)) {
                        $near = max($near, 0.12);
                    }
                }
            }
            if ($near > 0) {
                $px[$y][$x] = lkMix($px[$y][$x], $color, $near);
            }
        }
    }
}

/** paint one 12-px word pass; colours from $colorAt(mx, my) */
function paintWord(array &$px, array &$ink, int $xBase, int $yTop, array $mask, callable $colorAt, ?array $haloColor): void
{
    $field = [];
    foreach ($mask as $my => $row) {
        for ($mx = 0; $mx < strlen($row); $mx++) {
            if ($row[$mx] !== '#') {
                continue;
            }
            $x = $xBase + $mx;
            $y = $yTop + $my;
            if ($x < 0 || $x >= W || $y < 0 || $y >= HPX) {
                continue;
            }
            $px[$y][$x]    = $colorAt($mx, $my);
            $ink[$y][$x]   = true;
            $field[$y][$x] = true;
        }
    }
    if ($haloColor !== null) {
        addHalo($px, $field, $haloColor);
    }
}

// ================================================================ frame

function buildFrame(int $f): array
{
    $base   = lkHex(C_BASE);
    $stripe = lkHex(C_STRIPE);
    $mask   = wordMask();

    // 1. base canvas + header/footer stripes
    $px = [];
    for ($y = 0; $y < HPX; $y++) {
        $c = ($y <= 1 || $y >= HPX - 2) ? $stripe : $base;
        $px[$y] = array_fill(0, W, $c);
    }
    $ink = array_fill(0, HPX, array_fill(0, W, false));

    // 2. original word: vertical gradient, slow chroma flow, lock breathe
    $breathe  = $f >= HOLD ? 1.0 + 0.12 * sin(($f - HOLD) / 2.0) : 1.0;
    $origHalo = isLocked($f) ? lkMix(lkHex(H_ORIG), lkHex(H_GREEN), 0.45) : lkHex(H_ORIG);
    paintWord(
        $px, $ink, WORD_X, ORIG_Y, $mask,
        static function (int $mx, int $my) use ($f, $breathe): array {
            $t = max(0.0, min(1.0, $my / 11 + 0.05 * sin($f * 0.16 + $mx * 0.2 + $my * 0.35)));
            return lkScale(lkGradient(G_ORIG, $t), $breathe);
        },
        $origHalo,
    );

    // 3. clone word (lagged trail ghosts while sliding)
    $alpha = cloneAlpha($f);
    if ($alpha > 0) {
        $dx    = slideDx($f) ?? DX_MAX;
        $slide = $f >= SLIDE_A && $f <= SLIDE_B;
        $cloneHalo = isLocked($f) ? lkHex(H_GREEN) : lkHex(H_RED);
        foreach ([6 => 0.15, 3 => 0.30, 0 => 1.0] as $lag => $gain) {
            if ($lag > 0 && !$slide) {
                continue;
            }
            $d     = $lag > 0 ? (slideDx($f - $lag) ?? DX_MAX) : $dx;
            $paint = $alpha * $gain;
            $xBase = WORD_X + (int) round($d);
            paintWord(
                $px, $ink, $xBase, CLONE_Y, $mask,
                static function (int $mx, int $my) use ($f, $xBase, $paint, $lag, $mask): array {
                    $oc     = $xBase + $mx - WORD_X;          // same-space column
                    $match  = $oc >= 0 && $oc < WORD_W && $mask[$my][$oc] === '#';
                    $locked = isLocked($f) && $lag === 0;
                    $stops  = ($match || $locked) ? G_GREEN : G_RED;
                    $gain   = (!$match && !$locked && ($mx + $my * 2 + $f * 2) % 7 < 3) ? 0.72 : 1.0;
                    return lkScale(lkGradient($stops, max(0.0, min(1.0, $my / 11))), $paint * $gain);
                },
                $lag === 0 ? $cloneHalo : null,
            );
        }
    }

    // 4. laser sweep (bright on ink, subtle on background)
    $laser = lkHex(C_LASER);
    $lx    = laserX($f);
    for ($y = ORIG_Y; $y < CLONE_Y + 12; $y++) {
        for ($x = max(0, (int) floor($lx - 3)); $x <= min(W - 1, (int) ceil($lx + 3)); $x++) {
            $d = abs($x - $lx);
            $k = $d < 1.0 ? 0.42 : ($d < 2.0 ? 0.16 : 0.06);
            mixInk($px, $ink, $x, $y, $laser, $k * 0.9, $k * 0.28);
        }
    }

    // 5. lock ripple: white rings radiating from both word centres
    if ($f >= LOCK && $f < LOCK + 4) {
        $r  = ($f - LOCK + 1) * 10.5;
        $wh = lkHex(C_WHITE);
        foreach ([ORIG_Y + 6, CLONE_Y + 6] as $cy) {
            for ($y = 0; $y < HPX; $y++) {
                for ($x = 0; $x < W; $x++) {
                    if (abs(sqrt(($x - (WORD_X + 17)) ** 2 + ($y - $cy) ** 2) - $r) < 3.2) {
                        mixInk($px, $ink, $x, $y, $wh, 0.92, 0.30);
                    }
                }
            }
        }
    }

    // 6. CRT atmosphere: flicker, scanlines, vignette
    $flick = 0.975 + 0.03 * lkNoise(0, 0, $f);
    for ($y = 0; $y < HPX; $y++) {
        $sl = ($y % 2 === 1) ? 0.85 : 1.0;
        for ($x = 0; $x < W; $x++) {
            $dxv = ($x - W / 2) / (W / 2);
            $dyv = ($y - HPX / 2) / (HPX / 2);
            $vig = max(0.72, 1 - 0.30 * ($dxv * $dxv + $dyv * $dyv));
            $px[$y][$x] = lkScale($px[$y][$x], $flick * $sl * $vig);
        }
    }

    // 7. cells + text overlays
    $cells = lkHalfBlock($px);
    overlayText($cells, $f);
    return $cells;
}

function overlayText(array &$cells, int $f): void
{
    $hdr   = lkHex(C_HDR);
    $dim   = lkHex(C_DIM);
    $line  = lkHex(C_LINE);
    $faint = lkHex(C_FAINT);

    // header bar
    lkText($cells, 2, 0, 'phpdup', $hdr);
    lkText($cells, 9, 0, '·', $faint);
    lkText($cells, 11, 0, 'AST duplicate-logic finder', $dim);
    lkText($cells, W - 22, 0, 'clone · diff · detect', $faint);

    // hairline between the two words
    for ($x = 1; $x < W - 1; $x++) {
        lkPut($cells, $x, 7, '─', $line);
    }

    // status line (cell 14)
    $spinners = ['|', '/', '-', '\\'];
    if ($f < DISS) {
        lkText($cells, 3, 14, $spinners[$f % 8] . ' scanning source blocks', $dim);
    } elseif ($f >= MAT_A && $f < SLIDE_A) {
        lkText($cells, 3, 14, $spinners[$f % 8] . ' clone spotted · offset +' . (int) round(DX_MAX), lkHex(C_HINT));
    } elseif ($f >= SLIDE_A && $f < LOCK) {
        $p     = slideProgress($f);
        $filled = str_repeat('=', (int) round($p * 20));
        $msg = sprintf(
            'aligning AST blocks [%s>%-20s] %3d%%',
            $filled,
            str_repeat('.', 20 - strlen($filled)),
            (int) round($p * 100),
        );
        lkText($cells, 3, 14, $msg, $dim);
    } else {
        $ok = lkHex(C_OK);
        lkText($cells, 3, 14, '✓ match · 2 clones · 98% similar', $f < HOLD ? lkMix($dim, $ok, 0.4) : $ok);
    }

    // footer legend (cell 15), right-anchored
    lkText($cells, 2, 15, 'php-dup-finder', $faint);
    $x = 60;
    lkText($cells, $x, 15, '[', $faint);
    lkPut($cells, $x + 1, 15, '█', lkGradient(G_ORIG, 0.5));
    lkText($cells, $x + 2, 15, '] orig ', $faint);
    lkText($cells, $x + 9, 15, '[', $faint);
    lkPut($cells, $x + 10, 15, '█', lkGradient(G_RED, 0.5));
    lkText($cells, $x + 11, 15, '] dup ', $faint);
    lkText($cells, $x + 17, 15, '[', $faint);
    lkPut($cells, $x + 18, 15, '█', lkGradient(G_GREEN, 0.5));
    lkText($cells, $x + 19, 15, '] match', $faint);
}

// ================================================================ emit

$frames = [];
for ($f = 0; $f < FRAMES; $f++) {
    $frames[] = lkEncode(buildFrame($f), 'tc');
}
$anim = lkAnim($frames, $frames[FRAMES - 1]);
[$w, $h] = lkVerify(lkLastFrame($anim), 'dup/tc');
lkCheckDepth($anim, 'tc', 'dup/tc');
file_put_contents(__DIR__ . '/logo.ansi', $anim);
fwrite(STDERR, "logo.ansi: {$w}x{$h}, " . count($frames) . " frames, " . strlen($anim) . " bytes\n");
