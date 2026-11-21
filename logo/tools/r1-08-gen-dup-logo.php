<?php

declare(strict_types=1);

/**
 * gen-dup-logo — "Twin Converge / Clone Detected" animated truecolor ANSI logo
 * for phpdup (PHP duplicate-logic finder).
 *
 * The wordmark DUP is drawn twice from ONE pixel mask: an original (PHP
 * purple->indigo->violet) and a ghost clone (magenta->cyan) that eases from an
 * offset into perfect alignment while a scan band sweeps the "code page".
 * Overlapping cells flare = match. Subline PHP-FINDER types in; a glint locks.
 *
 * Outputs:
 *   frames/frame_XX.png  — one rasterised cell-grid per frame (block art = full
 *                          colour rects, so GIF == terminal ANSI visually)
 *   logo.ansi            — raw 24-bit truecolor ANSI animation (cat-able, loops)
 *
 * Usage:  php gen-dup-logo.php            (writes frames/ + logo.ansi)
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ geometry

const N    = 36;    // frames
const PXW  = 72;    // canvas pixel columns (= cell columns, half-block world)
const PXH  = 36;    // canvas pixel rows (= 2 * terminal rows -> 18 cells)

// ================================================================ fonts

/** Big word font, 14 rows tall, fixed 8 cols. */
const FONT_D = [
    '#######.',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '#######.',
];
const FONT_U = [
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '##...##.',
    '##...##.',
    '.#####..',
];
const FONT_P = [
    '#######.',
    '##....##',
    '##....##',
    '##....##',
    '##....##',
    '#######.',
    '##......',
    '##......',
    '##......',
    '##......',
    '##......',
    '##......',
    '##......',
    '##......',
];
const BIGFONT = ['D' => FONT_D, 'U' => FONT_U, 'P' => FONT_P];
const BIG_TEXT = 'DUP';
const BIG_GAP  = 2;

/** Mini subline font, 5 rows tall (ragged widths ok). */
const MINI = [
    'F' => ['####', '#...', '###.', '#...', '#...'],
    'P' => ['###.', '#..#', '###.', '#...', '#...'],
    'H' => ['#..#', '#..#', '####', '#..#', '#..#'],
    'I' => ['###', '.#.', '.#.', '.#.', '###'],
    'N' => ['#...#', '##..#', '#.#.#', '#..##', '#...#'],
    'D' => ['###.', '#..#', '#..#', '#..#', '###.'],
    'E' => ['####', '#...', '###.', '#...', '####'],
    'R' => ['###.', '#..#', '##..', '#.#.', '#..#'],
    '-' => ['....', '....', '####', '....', '....'],
    ' ' => ['..', '..', '..', '..', '..'],
];
const SUB_TEXT = 'PHP-FINDER';

// ================================================================ palette

const STOPS_ORIG  = ['#7C3AED', '#6366F1', '#8B5CF6', '#A855F7']; // purple -> indigo -> violet
const STOPS_CLONE = ['#EC4899', '#22D3EE'];                       // magenta -> cyan ghost
const PAGE_BG     = '#0A0917';                                   // near-black indigo
const CODE_BAR    = '#241F3A';                                   // faint code lines
const SUB_COLOR   = '#67E8F9';                                   // cyan subline ink

// word / subline anchors (px). Word sits slightly left so the clone can slide
// in from the off-screen right edge.
function wordX(): int { return intdiv(PXW - 3 * 8 - 2 * BIG_GAP, 2) - 6; }   // 28 wide, nudged left
function wordY(): int { return 6; }
function subY(): int  { return wordY() + 14 + 4; }                       // 24

// ================================================================ helpers

/** Sample a horizontal gradient in [0,1]. */
function gradH(array $stops, float $t): array { return lkGradient($stops, max(0.0, min(1.0, $t))); }

function easeInOut(float $u): float
{
    $u = max(0.0, min(1.0, $u));
    return $u < 0.5 ? 2 * $u * $u : 1 - (-2 * $u + 2) ** 2 / 2;
}

/**
 * Lay BIG_TEXT into a mask array [14][w].
 * @return list<list<string>>
 */
function buildWordMask(): array
{
    $rows = array_fill(0, 14, '');
    $chars = str_split(BIG_TEXT);
    foreach ($chars as $i => $ch) {
        $g = BIGFONT[$ch];
        foreach ($rows as $y => $_) { $rows[$y] .= $g[$y]; }
        if (($chars[$i + 1] ?? null) !== null) { foreach ($rows as $y => $_) { $rows[$y] .= str_repeat('.', BIG_GAP); } }
    }
    return array_map(static fn (string $r): array => str_split($r), $rows);
}

/** Lay SUB_TEXT into a DENSE 5-row bool mask, equal width every row. */
function buildSubMask(): array
{
    $rows = array_fill(0, 5, []);
    $x = 0;
    foreach (str_split(SUB_TEXT) as $ch) {
        $g = MINI[$ch] ?? MINI[' '];
        foreach ($g as $y => $line) {
            foreach (str_split($line) as $dx => $c) { $rows[$y][$x + $dx] = ($c === '#'); }
        }
        $x += strlen($g[0]) + 1; // 1-col letter gap
    }
    $w = max(1, $x - 1); // drop the trailing letter gap
    $out = [];
    for ($y = 0; $y < 5; $y++) {
        $r = [];
        for ($i = 0; $i < $w; $i++) { $r[$i] = !empty($rows[$y][$i]); }
        $out[] = $r;
    }
    return $out;
}

// pre-computed masks (bool grids) for speed
/** Normalise a mask (rows of chars and/or bools, sparse) to rows of bools. */
function toBool(array $mask): array
{
    $out = [];
    foreach ($mask as $row) {
        $r = [];
        foreach ($row as $x => $v) {
            $r[$x] = ($v === true || $v === '#');
        }
        $out[] = $r;
    }
    return $out;
}

// ================================================================ frame build

/**
 * Build a pixel grid [PXH][PXW] of rgb|null for frame $f of N.
 * @return list<list<?array{int,int,int}>>
 */
function buildFrame(int $f): array
{
    static $word = null, $sub = null, $wordW = 0;
    if ($word === null) {
        $word    = toBool(buildWordMask());
        $sub     = toBool(buildSubMask());
        $wordW   = count($word[0]);
    }
    $t      = $f / (N - 1);
    $settle = $f >= N - 2;                       // last 2 frames = clean static

    // clone slides in from the off-screen right, easing into perfect overlap
    $u   = easeInOut(min(1.0, $t / 0.80));
    $cdx = (int) round((1 - $u) * 46);           // px to the right (enters off-canvas)
    $cdy = 0;                                      // pure horizontal merge
    // scan band sweeps down, repeating; off on settled frames
    $scanY = $settle ? -999 : (((int) round($t * 96)) % (PXH + 12)) - 6;
    // glint: diagonal sweep locked on at the end
    $glintOn = $t > 0.80 && !$settle ? true : false;
    $glintK  = ($t - 0.80) / 0.20 * (PXW + PXH + 30) - 15;

    $ox = wordX(); $oy = wordY();

    $px = array_fill(0, PXH, array_fill(0, PXW, null));

    // ---- faint background code bars (two pages of "source" being compared)
    $barsFade = 1.0 - $u * 0.55;                  // fade as match locks
    $barRows  = [8, 11, 13, 16, 19, 22, 25];
    foreach ($barRows as $i => $by) {
        if ($by >= PXH) { continue; }
        $len = ($i % 2 === 0) ? 58 : 44;
        $bg  = 3 + ($i % 3) * 9;                  // slight staggered indent
        for ($x = $bg; $x < min(PXW, $bg + $len); $x++) {
            $seed = lkNoise($x, $by, $f > 0 ? 0 : 7); // static texture
            if ($seed > 0.28) {
                $px[$by][$x] = lkScale(lkHex(CODE_BAR), 0.6 + 0.5 * $barsFade + $seed * 0.2);
            }
        }
    }

    // ---- wordmark, pass 1: the ghost CLONE (same mask, translated by cdx/cdy)
    // drawn first so the original over-paints it; offset -> divergence = ghost.
    $boot = 0.35 + 0.65 * min(1.0, $t / 0.20); // logo "boots up" brightness
    for ($y = 0; $y < 14; $y++) {
        for ($x = 0; $x < $wordW; $x++) {
            if (!$word[$y][$x]) { continue; }
            $gx = $ox + $x + $cdx; $gy = $oy + $y + $cdy;
            if ($gx < 0 || $gx >= PXW || $gy < 0 || $gy >= PXH) { continue; }
            $gc = gradH(STOPS_CLONE, $x / max(1, $wordW - 1));
            $gc = lkScale(lkShade($gc, 0.10 - 0.30 * ($y / 13)), $boot * 0.92);
            // ghost trails darker as it approaches lock
            $gc = lkScale($gc, 1.0 - 0.45 * $u);
            if (abs($gy - $scanY) <= 1) {
                $gc = lkMix($gc, [255, 255, 255], 0.45 * (1.0 - abs($gy - $scanY) / 2.0));
            }
            $px[$gy][$gx] = $px[$gy][$gx] === null ? $gc : lkMix($px[$gy][$gx], $gc, 0.6);
        }
    }

    // ---- wordmark, pass 2: the ORIGINAL (anchored). Where the clone now
    // coincides, flare the cell = the "duplicate detected" lock.
    for ($y = 0; $y < 14; $y++) {
        for ($x = 0; $x < $wordW; $x++) {
            if (!$word[$y][$x]) { continue; }
            $gx = $ox + $x; $gy = $oy + $y;
            if ($gx < 0 || $gx >= PXW || $gy < 0 || $gy >= PXH) { continue; }

            $c = gradH(STOPS_ORIG, $x / max(1, $wordW - 1));
            $c = lkShade($c, 0.20 - 0.36 * ($y / 13));  // lit top, grounded base

            // clone coincident here? (a mask cell sitting under the orig)
            $clx = $x - $cdx; $cly = $y - $cdy;
            $inClone = ($clx >= 0 && $clx < $wordW && $cly >= 0 && $cly < 14) && $word[$cly][$clx];
            if ($inClone) {
                $flare = 0.10 + 0.55 * ($u ** 2);
                $c = lkMix($c, lkShade($c, 0.9), $flare * 0.65);
                $c = lkMix($c, gradH(STOPS_CLONE, $x / max(1, $wordW - 1)), $flare * 0.16);
            }
            $c = lkScale($c, $boot);

            if (abs($gy - $scanY) <= 1) {
                $c = lkMix($c, [255, 255, 255], 0.55 * (1.0 - abs($gy - $scanY) / 2.0));
            }
            // edge depth: darken mask boundary for a slight 3d lip
            $edge = !$word[$y][max(0, $x - 1)] || !$word[$y][min($wordW - 1, $x + 1)]
                 || ($y === 0 || $y === 13 ? false : (!$word[$y - 1][$x] || !$word[$y + 1][$x]));
            if ($edge) { $c = lkShade($c, -0.16); }

            $px[$gy][$gx] = $c;
        }
    }

    // ---- glint diagonal sweep (only while locking)
    if ($glintOn) {
        for ($y = 0; $y < 14; $y++) {
            for ($x = 0; $x < $wordW; $x++) {
                if (!$word[$y][$x]) { continue; }
                $gx = $ox + $x; $gy = $oy + $y;
                if (!isset($px[$gy][$gx]) || $px[$gy][$gx] === null) { continue; }
                if (abs(($gx + $gy) - $glintK) < 1.6) {
                    $px[$gy][$gx] = lkMix($px[$gy][$gx], [255, 255, 255], 0.8);
                }
            }
        }
    }

    // ---- subline PHP-FINDER types in left -> right by t=0.92
    $subWidth = count($sub[0]);
    $sox = intdiv(PXW - $subWidth, 2); $soy = subY();
    $typed = (int) round(min(1.0, $t / 0.92) * ($subWidth - 1));
    for ($y = 0; $y < 5; $y++) {
        for ($x = 0; $x < $subWidth; $x++) {
            if (!$sub[$y][$x]) { continue; }
            if ($x > $typed) { continue; }
            $gx = $sox + $x; $gy = $soy + $y;
            if ($gx < 0 || $gx >= PXW || $gy < 0 || $gy >= PXH) { continue; }
            $c = gradH([SUB_COLOR, '#A5F3FC', '#C4B5FD'], $x / max(1, $subWidth - 1));
            $c = lkScale($c, 0.78 + 0.22 * min(1.0, $t / 0.20));
            // caret blinking just ahead of the typed edge
            if (!$settle && $x === $typed && ($f % 6) < 3) {
                $c = [255, 255, 255];
            }
            $px[$gy][$gx] = $c;
        }
    }

    // ---- corner brackets (detector HUD) fade in
    $brK = min(1.0, $t / 0.5);
    if ($brK > 0.02) {
        $col = lkScale(lkHex('#3B2F63'), 0.6 + 0.8 * $brK);
        $marks = [[1, 1], [PXW - 2, 1], [1, PXH - 2], [PXW - 2, PXH - 2]];
        foreach ($marks as [$bx, $by]) {
            foreach ([[$bx, $by], [$bx - 1, $by], [$bx + 1, $by], [$bx, $by - 1], [$bx, $by + 1]] as [$mx, $my]) {
                if ($mx >= 0 && $mx < PXW && $my >= 0 && $my < PXH && ($px[$my][$mx] ?? null) === null) {
                    $px[$my][$mx] = $col;
                }
            }
        }
    }

    return $px;
}

// ================================================================ renderers

const CW = 9;   // cell pixel width in the GIF
const CH = 18;  // cell pixel height in the GIF (2 per row => 9 each)

/** Rasterise a pixel grid to a GD image (one colour rect per half-cell). */
function rasterise(array $px): GdImage
{
    $cells = lkHalfBlock($px);                 // [PXH/2][PXW] of [glyph,fg,bg]
    $rows  = count($cells);
    $cols  = count($cells[0]);
    $im    = imagecreatetruecolor($cols * CW, $rows * CH);
    $bg    = imagecolorallocate($im, ...lkHex(PAGE_BG));
    imagefilledrectangle($im, 0, 0, $cols * CW, $rows * CH, $bg);

    foreach ($cells as $y => $row) {
        foreach ($row as $x => [$glyph, $fg, $cellBg]) {
            if ($glyph === ' ') { continue; }
            $y0 = $y * CH;
            if ($glyph === '█') {
                $c = imagecolorallocate($im, ...$fg);
                imagefilledrectangle($im, $x * CW, $y0, ($x + 1) * CW - 1, $y0 + CH - 1, $c);
            } elseif ($glyph === '▀') {
                $ct = imagecolorallocate($im, ...$fg);
                imagefilledrectangle($im, $x * CW, $y0, ($x + 1) * CW - 1, $y0 + intdiv(CH, 2) - 1, $ct);
                if ($cellBg !== null) {
                    $cb = imagecolorallocate($im, ...$cellBg);
                    imagefilledrectangle($im, $x * CW, $y0 + intdiv(CH, 2), ($x + 1) * CW - 1, $y0 + CH - 1, $cb);
                }
            } elseif ($glyph === '▄') {
                $cb = imagecolorallocate($im, ...$fg);
                imagefilledrectangle($im, $x * CW, $y0 + intdiv(CH, 2), ($x + 1) * CW - 1, $y0 + CH - 1, $cb);
            }
        }
    }
    return $im;
}

// ================================================================ main

@mkdir(__DIR__ . '/frames', 0777, true);
foreach (glob(__DIR__ . '/frames/*.png') as $old) { unlink($old); }

$frameStrs = [];
$static    = '';
for ($f = 0; $f < N; $f++) {
    $px    = buildFrame($f);
    $cells = lkHalfBlock($px);
    $enc   = lkEncode($cells, 'tc');
    lkVerify($enc, "frame $f");
    $frameStrs[] = $enc;
    if ($f === N - 1) { $static = $enc; }

    $im  = rasterise($px);
    imagepng($im, sprintf(__DIR__ . '/frames/frame_%02d.png', $f));
    imagedestroy($im);
}

// assemble looping animation: frames joined with cursor-up, ending on static
$anim = "\e[?25l" . implode("\e[" . (PXH / 2) . "A\r", $frameStrs) . "\e[?25h";
file_put_contents(__DIR__ . '/logo.ansi', $anim);

fwrite(STDERR, sprintf("wrote %d frames + logo.ansi (%d cols x %d rows)\n", N, PXW, PXH / 2));
