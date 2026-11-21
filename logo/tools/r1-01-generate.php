<?php

declare(strict_types=1);

/**
 * generate.php — "DUP converges" animated truecolor ANSI logo for phpdup.
 *
 * Effect: the screen is filled with hundreds of scattered D/U/P glyph
 * particles (varied colours, sizes, velocities). Every particle eases along
 * its own curved bezier path toward the middle and they resolve — cell by
 * cell, staggered — into one big bold "DUP" built from TTF-rendered
 * half-block letterforms (DejaVu Sans Mono Bold, inflated-sdf quality).
 * While the convergence happens, "PHP" slides in from the left and
 * "FINDER" slides in from the right, so the final composed frame reads:
 *
 *          PHP   DUP   FINDER
 *
 * Output: logo.ansi — a truecolor (38;2/48;2) cursor-up animation,
 * cat-able in any truecolor terminal (frames joined via lkAnim).
 *
 * Deterministic: every random value comes from lkNoise(seed) — re-runs are
 * byte-identical.
 *
 * Usage:  php generate.php            (writes logo.ansi, prints stats)
 */

require __DIR__ . '/logo-kit.php';
require __DIR__ . '/glyph-sprite.php';

// ================================================================ DESIGN DATA

const COLS  = 96;
const ROWS  = 14;                 // terminal rows
const SUB   = ROWS * 2;           // half-block pixel rows (1 col x 2 sub-rows = 1 cell)
const FRAMES = 36;                // ~1.8 s at convert -delay 5

const FONT_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf';

/** Canvas background: subtle vertical gradient, deep ink. */
function bgRow(int $row): array
{
    return lkGradient(['#131022', '#0D0B18'], $row / (ROWS - 1));
}

/** Colour families of the composed words. */
const PHP_GRAD   = ['#6C5CE7', '#9D8BFF', '#B3A6FF'];   // violet
const DUP_GRAD   = ['#FF3D7F', '#FF6C3D', '#FFC93C'];   // hot pink → ember orange → gold
const FND_GRAD   = ['#2ED8C8', '#5CE8A0', '#8BE86B'];   // teal → spring green

/** Scatter palette for particles (candy-bright, PHP-adjacent hues included). */
const SCATTER = ['#FF3D7F', '#FF6C3D', '#FFC93C', '#7CE38B', '#2ED8C8',
                 '#5CC8FF', '#6C5CE7', '#9D8BFF', '#FF5FA2', '#E8E6FF', '#B3A6FF'];

const PINS16 = [];   // (unused — tc only)

// ================================================================ deterministic rng

/** Stable pseudo-random in [0,1) per (particle index, channel). */
function nz(int $i, int $k): float
{
    return lkNoise($i, $k * 131 + 17, 7);
}

/** Quantise colour so the GIF palette stays small (step 5). */
function q5(array $c): array
{
    return array_map(static fn (int $v): int => (int) round($v / 5) * 5, $c);
}

// ================================================================ letterform masks

/**
 * Rasterise a string of individual TTF Bold glyphs onto the canvas grid,
 * binary-thresholded at half-block pixel resolution.
 *
 * @param list<string> $chars   letters to place
 * @param float        $cap     cap height in units (1 unit = 1 sub-row = 1 px)
 * @param float        $centerX canvas column of the word centre
 * @param float        $centerY canvas sub-row of the ink-box centre
 * @return array{0: array<int, array<int, int>>, 1: array{int,int,int,int}}
 *         [ink[sub][col] = letter index+1 (0 = empty), ink bbox [x0,x1,y0,y1]]
 */
function textMask(array $chars, float $cap, float $centerX, float $centerY): array
{
    $sx = 4; $sy = 8;                       // coverage samples per cell col / row
    $gw = COLS * $sx; $gh = ROWS * $sy;     // sample grid → 4x4 samples per px-cell
    $track = $cap * 0.86;                   // DejaVu Sans Mono advance + air
    $ink = array_fill(0, SUB, array_fill(0, COLS, 0));
    $acc = array_fill(0, SUB, array_fill(0, COLS, 0.0));
    $n = count($chars);
    foreach ($chars as $li => $ch) {
        $cx = $centerX + ($li - ($n - 1) / 2) * $track;
        $spr = gsLoad(FONT_BOLD, $ch);
        $cov = gsCoverage($spr, ['x' => $cx, 'y' => $centerY, 'h' => $cap], $gw, $gh, $sx, $sy, 4);
        $hpx = intdiv($sy, 2);                       // samples per half-block px row
    for ($j = 0; $j < $gh; $j++) {
            $sub = intdiv($j, $hpx);
            if ($sub >= SUB) { continue; }
            for ($i = 0; $i < $gw; $i++) {
                $acc[$sub][intdiv($i, $sx)] += $cov[$j][$i];
            }
        }
    }
    $bbox = [COLS, 0, SUB, 0];
    for ($sub = 0; $sub < SUB; $sub++) {
        for ($col = 0; $col < COLS; $col++) {
            if ($acc[$sub][$col] / 16.0 > 0.45) {     // any overlapping glyph inked here
                $ink[$sub][$col] = 1;
                $bbox[0] = min($bbox[0], $col);
                $bbox[1] = max($bbox[1], $col);
                $bbox[2] = min($bbox[2], $sub);
                $bbox[3] = max($bbox[3], $sub);
            }
        }
    }
    return [$ink, $bbox];
}

/**
 * Big DUP mask with per-letter ownership: rasterise D, U, P separately and
 * stamp letter ids (1=D, 2=U, 3=P) by ownership distance.
 */
function dupMask(): array
{
    $cap = 12.0; $track = $cap * 0.86; $cx = COLS / 2; $cy = $cap * 1.2;
    $own = array_fill(0, SUB, array_fill(0, COLS, 0));
    foreach (['D', 'U', 'P'] as $li => $ch) {
        [$ink] = textMask([$ch], $cap, $cx + ($li - 1) * $track, $cy);
        for ($sub = 0; $sub < SUB; $sub++) {
            for ($col = 0; $col < COLS; $col++) {
                if ($ink[$sub][$col] && !$own[$sub][$col]) {
                    $own[$sub][$col] = $li + 1;
                }
            }
        }
    }
    $bbox = [COLS, 0, SUB, 0];
    for ($sub = 0; $sub < SUB; $sub++) {
        for ($col = 0; $col < COLS; $col++) {
            if ($own[$sub][$col]) {
                $bbox[0] = min($bbox[0], $col); $bbox[1] = max($bbox[1], $col);
                $bbox[2] = min($bbox[2], $sub); $bbox[3] = max($bbox[3], $sub);
            }
        }
    }
    return [$own, $bbox];
}

/** Translate a mask by dx (columns). Out-of-range ink is clipped. */
function shiftMaskX(array $mask, int $dx): array
{
    $out = array_fill(0, count($mask), array_fill(0, COLS, 0));
    foreach ($mask as $sub => $row) {
        foreach ($row as $col => $v) {
            if ($v && $col + $dx >= 0 && $col + $dx < COLS) {
                $out[$sub][$col + $dx] = $v;
            }
        }
    }
    return $out;
}

// ================================================================ final render data

/**
 * DUP per-cell final render: cell => [glyph, fg, bg] using the half-block
 * trick with shading + edge bevel.
 */
function dupFinalCells(array $own, array $bbox): array
{
    [$x0, $x1, $y0, $y1] = $bbox;
    $w = $x1 - $x0 + 1;
    $firstSub = [];                          // per-column top ink sub-row (bevel)
    for ($col = $x0; $col <= $x1; $col++) {
        $firstSub[$col] = SUB;
        for ($sub = $y0; $sub <= $y1; $sub++) {
            if ($own[$sub][$col]) { $firstSub[$col] = $sub; break; }
        }
    }
    $pxCol = static function (int $sub, int $col) use ($own, $x0, $w, $y0, $y1, $firstSub): ?array {
        if (!$own[$sub][$col]) {
            return null;
        }
        $c = lkGradient(DUP_GRAD, ($col - $x0) / max(1, $w - 1));
        $shade = 0.22 - 0.52 * ($sub - $y0) / max(1, $y1 - $y0);      // lit top → dark base
        if ($sub === ($firstSub[$col] ?? $sub)) {
            $shade += 0.20;                                            // bright bevel edge
        }
        $edgeL = ($own[$sub][$col - 1] ?? 0) === 0;
        $edgeR = ($own[$sub][$col + 1] ?? 0) === 0;
        if ($edgeL || $edgeR) {
            $shade -= 0.16;                                            // side outline depth
        }
        return q5(lkShade($c, $shade));
    };
    $cells = [];                            // "col,row" => [glyph, fg, bg]
    for ($row = 0; $row < ROWS; $row++) {
        for ($col = 0; $col < COLS; $col++) {
            if (!$own[2 * $row][$col] && !$own[2 * $row + 1][$col]) {
                continue;
            }
            $t = $pxCol(2 * $row, $col);
            $b = $pxCol(2 * $row + 1, $col);
            $bgc = q5(bgRow($row));
            $cells["$col,$row"] = match (true) {
                $t !== null && $b !== null => ['▀', $t, $b],
                $t !== null                => ['▀', $t, $bgc],
                default                    => ['▄', $b, $bgc],
            };
        }
    }
    return $cells;
}

/** PHP / FINDER final cell maps: "col,row" => [glyph, fg, bg]. Mask is at final position. */
function sideFinalCells(array $mask, array $grad): array
{
    $xs = []; $ys = [SUB, 0];
    for ($sub = 0; $sub < SUB; $sub++) {
        for ($col = 0; $col < COLS; $col++) {
            if ($mask[$sub][$col]) { $xs[] = $col; $ys[0] = min($ys[0], $sub); $ys[1] = max($ys[1], $sub); }
        }
    }
    if (!$xs) { return []; }
    $x0 = min($xs); $x1 = max($xs);
    $w = max(1, $x1 - $x0);
    $cells = [];
    for ($row = 0; $row < ROWS; $row++) {
        for ($col = 0; $col < COLS; $col++) {
            $t = $mask[2 * $row][$col];
            $b = $mask[2 * $row + 1][$col];
            if (!$t && !$b) { continue; }
            $bgc = q5(bgRow($row));
            $f = ($col - $x0) / $w;
            $shadeT = 0.18 - 0.42 * (($row * 2 - $ys[0]) / max(1, $ys[1] - $ys[0]));
            $mk = static fn (bool $top) => q5(lkShade(lkGradient($grad, $f), $top ? $shadeT : $shadeT - 0.14));
            $cells["$col,$row"] = match (true) {
                $t && $b => ['▀', $mk(true), $mk(false)],
                $t       => ['▀', $mk(true), $bgc],
                default  => ['▄', $mk(false), $bgc],
            };
        }
    }
    return $cells;
}

// ================================================================ particles

/**
 * Build the particle system.
 * homes:  one per inked DUP cell (fly to that cell, snap to final block)
 * halo:   wanderers that converge near the centre and dissolve
 * @return list<array<string, float|array|string>>
 */
function buildParticles(array $own, array $dupBbox, array $dupCells): array
{
    [$dx0, $dx1, $dy0, $dy1] = $dupBbox;
    $P = [];
    $i = 0;
    // ---------- homes (every DUP cell)
    for ($row = intdiv($dy0, 2); $row <= intdiv($dy1, 2); $row++) {
        for ($col = $dx0; $col <= $dx1; $col++) {
            $key = "$col,$row";
            if (!isset($dupCells[$key])) {
                continue;
            }
            $letter = $own[2 * $row][$col]
                ?: $own[2 * $row + 1][$col]
                ?: ($own[2 * $row - 1][$col] ?? 0)
                ?: ($own[2 * $row + 2][$col] ?? 0);
            $sc = SCATTER[(int) (nz($i, 1) * count(SCATTER)) % count(SCATTER)];
            $P[] = [
                'kind'   => 'home',
                'ch'     => ['D', 'U', 'P'][$letter - 1] ?? 'P',
                'target' => [$col, $row],
                'start'  => [nz($i, 2) * COLS, nz($i, 3) * ROWS],
                'curl'   => (nz($i, 4) - 0.5) * 26,
                'a0'     => nz($i, 5) * 0.30,
                'a1'     => 0.62 + nz($i, 6) * 0.26,
                'color'  => litFloor(q5(lkShade(lkHex($sc), (nz($i, 7) - 0.5) * 0.5))),
                'wobX'   => 0.2 + nz($i, 8) * 0.7,
                'wobY'   => 0.15 + nz($i, 9) * 0.55,
                'wobF'   => 9 + nz($i, 10) * 16,
                'wobP'   => nz($i, 11) * 6.283,
            ];
            $i++;
        }
    }
    // ---------- halo wanderers
    for ($k = 0; $k < 300; $k++) {
        $ang = nz($k, 20) * 6.283;
        $rr  = sqrt(nz($k, 21));
        $sc  = SCATTER[(int) (nz($k, 22) * count(SCATTER)) % count(SCATTER)];
        $off = nz($k, 23) < 0.18;
        $P[] = [
            'kind'   => 'halo',
            'ch'     => ['D', 'U', 'P'][(int) (nz($k, 24) * 3) % 3],
            'target' => [48 + cos($ang) * $rr * 44, 7 + sin($ang) * $rr * 11.5],
            'start'  => $off
                ? [(int) (nz($k, 25) < 0.5 ? -14 - nz($k, 26) * 8 : COLS + 4 + nz($k, 26) * 8), nz($k, 27) * ROWS]
                : [nz($k, 25) * COLS, nz($k, 27) * ROWS],
            'curl'   => (nz($k, 4) - 0.5) * 20,
            'a0'     => 0.02 + nz($k, 5) * 0.32,
            'a1'     => 0.55 + nz($k, 6) * 0.23,
            'color'  => litFloor(q5(lkShade(lkHex($sc), (nz($k, 7) - 0.6) * 0.55))),
            'wobX'   => 0.3 + nz($k, 8) * 0.9,
            'wobY'   => 0.2 + nz($k, 9) * 0.7,
            'wobF'   => 8 + nz($k, 10) * 18,
            'wobP'   => nz($k, 11) * 6.283,
        ];
        $i++;
    }
    return $P;
}

/** Particle glyph — per-particle size class fixes its visual weight; small
 *  particles grow into letters as they near their target, big stay uppercase. */
function partGlyph(array $p, float $t, int $idx): string
{
    $r = lkNoise($idx, 31, 7);
    $sz = $p['kind'] === 'home'
        ? ($r < 0.45 ? 2 : ($r < 0.80 ? 1 : 0))
        : ($r < 0.40 ? 2 : ($r < 0.72 ? 1 : 0));
    if ($sz === 2) {
        return $p['ch'];
    }
    if ($sz === 1) {
        return $t > 0.75 ? $p['ch'] : strtolower($p['ch']);
    }
    if ($t > 0.50) {
        return strtolower($p['ch']);
    }
    $pool = ['·', '∙', '.', '*', '+', '`', '-'];
    return $pool[(int) (lkNoise($idx, 33, 7) * count($pool)) % count($pool)];
}

/** Keep scatter colours visible on the dark canvas (brightness floor). */
function litFloor(array $c, int $min = 96): array
{
    $m = max($c);
    return $m >= $min ? $c : q5(array_map(static fn (int $v): int => (int) min(255, round($v * $min / max(1, $m))), $c));
}

// ================================================================ frame compositing

function easeIO(float $u): float
{
    return $u < 0.5 ? 2 * $u * $u : 1 - (2 - 2 * $u) ** 2 / 2;
}

function easeOut3(float $u): float
{
    return 1 - (1 - $u) ** 3;
}

function slideWindow(float $u, float $from, float $to): float
{
    return max(0.0, min(1.0, ($u - $from) / ($to - $from)));
}

/**
 * Composite one animation frame into a cell grid.
 * $arrival: "col,row" => u at which that DUP cell's home particle lands.
 * $phpBox/$fndBox: final ink bbox [x0,x1,y0,y1] of each sliding word.
 * @return list<list<array{string, array|null, array|null}>>
 */
function renderFrame(
    int $f,
    array $particles,
    array $dupCells,
    array $arrival,
    array $phpCells,
    array $phpBox,
    array $fndCells,
    array $fndBox
): array {
    $u = $f / (FRAMES - 1);
    $cells = [];
    $bgGrid = [];
    for ($row = 0; $row < ROWS; $row++) {
        $bgc = $bgGrid[$row] = q5(bgRow($row));
        $cells[$row] = array_fill(0, COLS, [' ', null, $bgc]);
    }
    $put = static function (int $col, int $row, string $g, ?array $fg, ?array $bg) use (&$cells): void {
        if ($row >= 0 && $row < ROWS && $col >= 0 && $col < COLS && $g !== '') {
            $cells[$row][$col] = [$g, $fg, $bg];
        }
    };

    // ---------- halo wanderers (drawn first → homes win the cells)
    $idx = -1;
    foreach ($particles as $p) {
        $idx++;
        if ($p['kind'] === 'home') {
            continue;
        }
        $t = easeIO(slideWindow($u, $p['a0'], $p['a1']));
        [$x, $y] = bezierPos($p, $u, $t);
        $col = (int) round($x); $row = (int) round($y);
        if ($col < 0 || $col >= COLS || $row < 0 || $row >= ROWS) {
            continue;
        }
        $fade = slideWindow($u, max($p['a1'] + 0.02, 0.50), 0.88);  // dissolve before the hold
        $g = partGlyph($p, $t, $idx);
        $c = $fade > 0 ? q5(lkMix($p['color'], $bgGrid[$row], $fade)) : $p['color'];
        if ($c === $bgGrid[$row]) {
            continue;                                              // fully dissolved / invisible
        }
        $put($col, $row, $g, $c, $bgGrid[$row]);                    // match canvas bg exactly
    }
    // ---------- home particles still in flight
    $idx = -1;
    foreach ($particles as $p) {
        $idx++;
        if ($p['kind'] !== 'home') {
            continue;
        }
        $t = easeIO(slideWindow($u, $p['a0'], $p['a1']));
        $col = (int) $p['target'][0]; $row = (int) $p['target'][1];
        if ($t >= 1.0) {
            continue;                                             // painted via dupCells below
        }
        [$x, $y] = bezierPos($p, $u, $t);
        $gc = (int) round($x); $gr = (int) round($y);
        if ($gc < 0 || $gc >= COLS || $gr < 0 || $gr >= ROWS) {
            continue;
        }
        $target = $dupCells["$col,$row"][1];
        $c = q5(lkMix($p['color'], $target, max(0.0, ($t - 0.5) / 0.5)));
        $put($gc, $gr, partGlyph($p, $t, $idx), $c, $bgGrid[$gr]);
    }

    // ---------- settled DUP cells (+ glint sweep while holding)
    foreach ($dupCells as $key => [$g, $fg, $bg]) {
        if ($u < $arrival[$key]) {
            continue;                                              // home still in flight
        }
        [$col, $row] = array_map('intval', explode(',', $key));
        if ($u >= 0.84) {
            $s = 5 + ($u - 0.84) / 0.16 * 165;
            if (abs($col + $row * 2.2 - $s) < 1.7) {
                $fg = q5(lkMix($fg, [255, 255, 255], 0.55));
            }
        }
        $put($col, $row, $g, $fg, $bg);
    }

    // ---------- sliding PHP (from left) and FINDER (from right)
    $pu = easeOut3(slideWindow($u, 0.28, 0.66));
    $fu = easeOut3(slideWindow($u, 0.31, 0.70));
    $pdx = (int) round(-(1 - $pu) * ($phpBox[1] + 3));              // right edge just off-screen at start
    $fdx = (int) round((1 - $fu) * (COLS - $fndBox[0] + 3));         // left edge just off-screen at start
    paintWord($put, $phpCells, $pdx, $pu);
    paintWord($put, $fndCells, $fdx, $fu);
    return $cells;
}

/** Quadratic bezier start→(control)→target with decaying wobble. */
function bezierPos(array $p, float $u, float $t): array
{
    [$sx, $sy] = $p['start'];
    [$tx, $ty] = $p['target'];
    $mx = ($sx + $tx) / 2; $my = ($sy + $ty) / 2;
    $dx = $tx - $sx; $dy = $ty - $sy;
    $len = max(1e-6, sqrt($dx * $dx + $dy * $dy));
    $px = -$dy / $len; $py = $dx / $len;                    // perpendicular
    $cx = $mx + $px * $p['curl']; $cy = $my + $py * $p['curl'];
    $w = 1 - $t;
    $x = $w * $w * $sx + 2 * $w * $t * $cx + $t * $t * $tx;
    $y = $w * $w * $sy + 2 * $w * $t * $cy + $t * $t * $ty;
    $x += sin($u * $p['wobF'] + $p['wobP']) * $p['wobX'] * $w ** 1.5;
    $y += cos($u * $p['wobF'] * 0.83 + $p['wobP']) * $p['wobY'] * $w ** 1.5;
    return [$x, $y];
}

/** Stamp a word's baked cells shifted horizontally by dx. */
function paintWord(callable $put, array $cells, int $dx, float $prog): void
{
    foreach ($cells as $key => [$g, $fg, $bg]) {
        [$col, $row] = array_map('intval', explode(',', $key));
        $c = $col + $dx;
        if ($c < 0 || $c >= COLS) {
            continue;
        }
        $dim = $prog < 1 ? lkMix($fg, $bg, (1 - $prog) * 0.25) : $fg;   // slightly hazy while sliding
        $put($c, $row, $g, q5($dim), $bg);
    }
}

// ================================================================ MAIN

// 1. big DUP mask + final cells
[$dupOwn, $dupBbox] = dupMask();
$dupCells = dupFinalCells($dupOwn, $dupBbox);

// 2. side words: build masks, measure, lay out "PHP DUP FINDER" as a group
$phpChars = ['P', 'H', 'P'];
$fndChars = ['F', 'I', 'N', 'D', 'E', 'R'];
$capSide = 7.0;
// place side words at provisional centres, then translate by ink bbox
[$phpM0, $phpBB0] = textMask($phpChars, $capSide, 16, ROWS);     // provisional centre cols 16
[$fndM0, $fndBB0] = textMask($fndChars, $capSide, COLS - 17, ROWS);
$phpW = $phpBB0[1] - $phpBB0[0] + 1;
$fndW = $fndBB0[1] - $fndBB0[0] + 1;
$dupW = $dupBbox[1] - $dupBbox[0] + 1;
$gap = 7;
$total = $phpW + $gap + $dupW + $gap + $fndW;
$startX = intdiv(COLS - $total, 2);
// bring each mask so its ink starts at its slot
$phpDx  = $startX - $phpBB0[0];
$dupDx  = $startX + $phpW + $gap - $dupBbox[0];
$fndDx  = $startX + $phpW + $gap + $dupW + $gap - $fndBB0[0];
$phpM = shiftMaskX($phpM0, $phpDx);
$fndM = shiftMaskX($fndM0, $fndDx);
// DUP shift + rebuild final cells at the composed position
$dupOwn = shiftMaskX($dupOwn, $dupDx);
$dupBbox = [$dupBbox[0] + $dupDx, $dupBbox[1] + $dupDx, $dupBbox[2], $dupBbox[3]];
$dupCells = dupFinalCells($dupOwn, $dupBbox);
$phpCells = sideFinalCells($phpM, PHP_GRAD);
$fndCells = sideFinalCells($fndM, FND_GRAD);
$phpBox = [$phpBB0[0] + $phpDx, $phpBB0[1] + $phpDx, $phpBB0[2], $phpBB0[3]];
$fndBox = [$fndBB0[0] + $fndDx, $fndBB0[1] + $fndDx, $fndBB0[2], $fndBB0[3]];

// 3. particles + arrival map (cell key => u when its home lands)
$particles = buildParticles($dupOwn, $dupBbox, $dupCells);
$arrival = [];
foreach ($particles as $p) {
    if ($p['kind'] === 'home') {
        $arrival["{$p['target'][0]},{$p['target'][1]}"] = $p['a1'];
    }
}

fwrite(STDERR, sprintf(
    "particles: %d (%d homes into %d DUP cells, %d halo) | canvas %dx%d | frames %d | group w=%d at x=%d\n",
    count($particles),
    count(array_filter($particles, static fn ($p) => $p['kind'] === 'home')),
    count($dupCells),
    count(array_filter($particles, static fn ($p) => $p['kind'] === 'halo')),
    COLS, ROWS, FRAMES, $total, $startX
));

// 4. frames → tc ANSI
$frames = [];
$static = '';
for ($f = 0; $f < FRAMES; $f++) {
    $cells = renderFrame($f, $particles, $dupCells, $arrival, $phpCells, $phpBox, $fndCells, $fndBox);
    $enc = lkEncode($cells, 'tc');
    [$vw, $vh] = lkVerify($enc, "frame$f");
    if ($vw !== COLS || $vh !== ROWS) {
        throw new RuntimeException("frame $f size $vw x $vh");
    }
    $frames[] = $enc;
}
$static = $frames[FRAMES - 1];
$anim = lkAnim(array_slice($frames, 0, FRAMES - 1), $static);
file_put_contents(__DIR__ . '/logo.ansi', $anim);
file_put_contents(__DIR__ . '/logo-static.ansi', $static);
fwrite(STDERR, 'wrote logo.ansi (' . strlen($anim) . " bytes), logo-static.ansi\n");
