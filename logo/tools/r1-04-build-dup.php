<?php

declare(strict_types=1);

/**
 * build-dup.php — "Clone Collapse" animated truecolor ANSI logo for phpdup.
 *
 * Concept: a finder beam sweeps a dark field of identical chip PAIRS (duplicated
 * code). Each crossed pair flashes, links, then its 2x2-px pixels fly along arcs
 * into the gradient wordmark DUP; tagline types out, a glint crosses the word,
 * everything recalls home and the loop is seamless (frame 44 == frame 0).
 *
 * Single source of truth = a 90x30 pixel grid per frame (half-block: 2 px per
 * terminal cell row). Emits:
 *   logo.ansi            truecolor ANSI animation (44 frames + held static)
 *   logo-static.ansi     the finished-state single frame
 *   frames/f_NN.png      one PNG per frame (same grid rasterized at 8 px/px)
 *   logo-still.png       preview of the static frame
 *
 * Deterministic: fixed seed. Requires ./logo-kit.php (copy of candy-top kit).
 *   php build-dup.php
 */

require __DIR__ . '/logo-kit.php';

mt_srand(20261120);

// ---------------------------------------------------------------- canvas
const PW = 90;                    // pixel columns
const PH = 30;                    // pixel rows (15 cell rows)
const PS = 8;                     // gif px per logo-px
const FRAMES = 44;
const PAGE = '#0A0E1A';

// ---------------------------------------------------------------- wordmark
/** 5x7 stroke font, rendered at x2 scale → units are 2x2-px blocks. */
const FONT = [
    'D' => ['####.', '#...#', '#...#', '#...#', '#...#', '#...#', '####.'],
    'U' => ['.###.', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
    'P' => ['####.', '#...#', '#...#', '####.', '##...', '#....', '#....'],
];
const WORD = ['D', 'U', 'P'];
const LETTER_W = 5;               // font cells
const LETTER_GAP = 3;             // font cells (x2 → 6 px)
const WORD_Y = 8;                 // pixel row of word top
const WORD_SCALE = 2;

$wordLeft = (int) round((PW - (count(WORD) * LETTER_W * WORD_SCALE + (count(WORD) - 1) * LETTER_GAP * WORD_SCALE)) / 2);
$wordRight = $wordLeft + count(WORD) * LETTER_W * WORD_SCALE + (count(WORD) - 1) * LETTER_GAP * WORD_SCALE - 1;

/** vertical gradient for landed word units */
const WORD_GRAD = ['#B18CFF', '#777BB4', '#FFC46B'];   // PHP violet → indigo → amber

// ---------------------------------------------------------------- pairs (clones)
// Each pair = two geometrically identical chips (the duplication metaphor).
// anchor [x, y] = pixel top-left of the chip.
const PAIRS = [
    [[[5, 2], [15, 3]], '#8E7CFF'],
    [[[26, 1], [36, 2]], '#5CC8FF'],
    [[[47, 2], [57, 1]], '#7CE38B'],
    [[[66, 3], [76, 2]], '#FFB35C'],
    [[[6, 23], [16, 22]], '#FF7DA2'],
    [[[13, 13], [16, 19]], '#6FE0D2'],
    [[[70, 12], [74, 18]], '#C9A0FF'],
    [[[69, 24], [79, 23]], '#FFD166'],
];
const PAIR_CAP = [6, 6, 6, 6, 6, 6, 6, 8];             // units per pair (even)
const SUPPLY = ['D' => [0, 1, 2], 'U' => [3, 4, 5], 'P' => [5, 6, 7]];
const SLOT3 = [[0, 0], [0, 1], [1, 0]];
const SLOT4 = [[0, 0], [1, 0], [0, 1], [1, 1]];

// ---------------------------------------------------------------- beam / schedule
const BEAM_X0 = 2;
const BEAM_X1 = 86;
const BEAM_SWEEP = 20;            // frames for the full sweep
const FLIGHT_BASE = 8.5;

// ---------------------------------------------------------------- tagline
const TAGLINE = 'phpdup · AST clone detector';
const TAG_ROW = 13;                                    // cell row
const TAG_COL = 31;                                    // centered: 27 glyphs
$TAG_G = mb_str_split(TAGLINE);                        // 27 display glyphs

// ================================================================ build units
/** Each 2x2-px stroke block of the wordmark is one flying unit. */
$letterUnits = [];
foreach (WORD as $li => $ch) {
    $gx = $wordLeft + $li * (LETTER_W + LETTER_GAP) * WORD_SCALE;
    $list = [];
    foreach (FONT[$ch] as $fy => $row) {
        foreach (str_split($row) as $fx => $c) {
            if ($c === '#') {
                $list[] = [$gx + $fx * WORD_SCALE, WORD_Y + $fy * WORD_SCALE];
            }
        }
    }
    $letterUnits[$ch] = $list;
}
$totalUnits = array_sum(array_map('count', $letterUnits));
$capSum = array_sum(PAIR_CAP);
if ($totalUnits !== $capSum) {
    exit("unit count {$totalUnits} != chip capacity {$capSum}\n");
}

// Sequential assignment: letters in order, each letter's supply pairs in order.
$units = [];
$fill = array_fill(0, count(PAIRS), 0);
$unitId = 0;
foreach (WORD as $ch) {
    $list = $letterUnits[$ch];
    foreach (SUPPLY[$ch] as $pi) {
        $room = PAIR_CAP[$pi] - $fill[$pi];
        for ($k = 0; $k < $room && $list !== []; $k++) {
            [$tx, $ty] = array_shift($list);
            $slot = $fill[$pi]++;                       // 0..cap-1 within pair
            $chip = $slot % 2;                          // alternate chip A/B
            $slotIn = intdiv($slot, 2);
            $mask = PAIR_CAP[$pi] === 8 ? SLOT4 : SLOT3;
            [$ax, $ay] = PAIRS[$pi][0][$chip];
            [$ox, $oy] = $mask[$slotIn];
            $units[] = [
                'id' => $unitId++,
                'pair' => $pi,
                'home' => [$ax + $ox * 2, $ay + $oy * 2],
                'target' => [$tx, $ty],
                'amp' => (mt_rand(0, 1) ? 1 : -1) * (6 + mt_rand(0, 8)),
                'jit' => mt_rand(0, 4) / 10,
            ];
        }
    }
    if ($list !== []) {
        exit("supply overflow for letter {$ch}\n");
    }
}

// precompute pair → units (ordered), per-unit flight schedule
$pairUnits = array_fill(0, count(PAIRS), []);
foreach ($units as $i => $u) {
    $pairUnits[$u['pair']][] = $i;
}
$pairMid = [];
foreach (PAIRS as $pi => [$anch]) {
    $a = $anch[0][0] + 2;
    $b = $anch[1][0] + 2;
    $pairMid[$pi] = ($a + $b) / 2;
}
$pairCross = array_map(static fn (float $m): float => max(0, ($m - BEAM_X0) / (BEAM_X1 - BEAM_X0) * BEAM_SWEEP), $pairMid);

foreach ($units as $i => $u) {
    $k = array_search($i, $pairUnits[$u['pair']], true);
    $dep = min(18.5, 3.5 + $pairCross[$u['pair']] / BEAM_SWEEP * 12.5 + 0.3 * $k) + $u['jit'];
    $dur = FLIGHT_BASE + mt_rand(0, 15) / 10;
    [$hx, $hy] = $u['home'];
    [$tx, $ty] = $u['target'];
    $mx = ($hx + $tx) / 2;
    $my = ($hy + $ty) / 2;
    // perpendicular arc control point
    $dx = $tx - $hx;
    $dy = $ty - $hy;
    $len = max(1.0, sqrt($dx * $dx + $dy * $dy));
    $nx = -$dy / $len;
    $ny = $dx / $len;
    $units[$i]['depart'] = $dep;
    $units[$i]['arrive'] = $dep + $dur;
    $units[$i]['ctrl'] = [$mx + $nx * $u['amp'], $my + $ny * $u['amp']];
    $units[$i]['recallStart'] = 40 + 0.09 * $k;
    $units[$i]['targetColor'] = lkGradient(WORD_GRAD, ($ty - WORD_Y) / 13);
    $units[$i]['pairColor'] = lkHex(PAIRS[$u['pair']][1]);
}

// background stars
$stars = [];
for ($s = 0; $s < 30; $s++) {
    $stars[] = [mt_rand(0, PW - 1), mt_rand(0, PH - 1), mt_rand(0, 628) / 100];
}

// ================================================================ per-frame pixel grid
function eio(float $t): float
{
    return $t < 0.5 ? 4 * $t * $t * $t : 1 - pow(-2 * $t + 2, 3) / 2;
}

function bez(array $p0, array $pc, array $p1, float $t): array
{
    $u = 1 - $t;
    return [
        $u * $u * $p0[0] + 2 * $u * $t * $pc[0] + $t * $t * $p1[0],
        $u * $u * $p0[1] + 2 * $u * $t * $pc[1] + $t * $t * $p1[1],
    ];
}

/** @return list<list<array>> rows of rgb */
function framePx(int $f, array $units, array $pairUnits, array $pairCross, array $stars, bool $assembled = false): array
{
    $page = lkHex(PAGE);
    if ($assembled) {
        $f = 45;                                       // after everything lands, before anything resets
    }
    $px = array_fill(0, PH, array_fill(0, PW, $page));

    $put = static function (int $x, int $y, array $c) use (&$px): void {
        if ($x < 0 || $y < 0 || $x >= PW || $y >= PH) {
            return;
        }
        $px[$y][$x] = [
            min(255, max(0, (int) round($c[0]))),
            min(255, max(0, (int) round($c[1]))),
            min(255, max(0, (int) round($c[2]))),
        ];
    };
    $mix = static function (int $x, int $y, array $c, float $a) use (&$px, $put): void {
        if ($a <= 0) {
            return;
        }
        $cur = $px[$y][$x] ?? null;
        if ($cur === null) {
            return;
        }
        $put($x, $y, lkMix($cur, $c, $a));
    };

    // stars
    foreach ($stars as $i => [$sx, $sy, $ph]) {
        $tw = 0.10 + 0.05 * sin($f * 0.7 + $ph);
        $px[$sy][$sx] = lkMix($px[$sy][$sx], [150, 175, 225], $tw);
    }

    // beam position
    $beamX = BEAM_X0 + min(1, $f / BEAM_SWEEP) * (BEAM_X1 - BEAM_X0);
    $beamA = $f <= BEAM_SWEEP ? 1.0 : max(0.0, (24 - $f) / 4);

    // links (dotted, A-center → B-center) while freshly crossed
    foreach (PAIRS as $pi => [$anch, $hex]) {
        $cross = $pairCross[$pi];
        if ($f < $cross || $f > $cross + 9) {
            continue;
        }
        $a = [$anch[0][0] + 2, $anch[0][1] + 2];
        $b = [$anch[1][0] + 2, $anch[1][1] + 2];
        $steps = (int) ceil(max(abs($b[0] - $a[0]), abs($b[1] - $a[1])));
        $alpha = 0.6;
        if ($f < $cross + 2) {
            $alpha *= ($f - $cross) / 2;
        } elseif ($f > $cross + 6.5) {
            $alpha *= max(0, ($cross + 9 - $f) / 2.5);
        }
        $pc = lkScale(lkHex($hex), 1.1);
        for ($s = 0; $s <= $steps; $s++) {
            if ($s % 2) {
                continue;
            }
            $t = $steps ? $s / $steps : 0;
            $lx = (int) round($a[0] + ($b[0] - $a[0]) * $t);
            $ly = (int) round($a[1] + ($b[1] - $a[1]) * $t);
            $mix($lx, $ly, $pc, $alpha);
        }
    }

    // units: home / flight / landed / recall
    foreach ($units as $u) {
        [$hx, $hy] = $u['home'];
        [$tx, $ty] = $u['target'];
        $pc = $u['pairColor'];
        $cross = $pairCross[$u['pair']];
        $lit = $beamA > 0 && $f >= $cross;
        $flash = $lit && abs($f - $cross) < 1.5 ? 0.45 : 0.0;

        if (!$assembled && $f >= $u['recallStart']) {                                  // flying home
            $r = eio(max(0.0, min(1.0, ($f - $u['recallStart']) / 2.95)));
            $pos = [
                $tx + ($hx - $tx) * $r,
                $ty + ($hy - $ty) * $r,
            ];
            $col = lkMix($u['targetColor'], lkScale($pc, 0.35), $r);
            $paint = [$pos[0], $pos[1], $col];
        } elseif ($f >= $u['depart'] + ($u['arrive'] - $u['depart'])) { // landed
            $age = $f - $u['arrive'];
            $col = $u['targetColor'];
            if ($age < 2.5) {
                $col = lkMix($col, [255, 255, 255], 0.85 * (1 - $age / 2.5));
            }
            $paint = [$tx, $ty, $col];
            // landing bloom
            $bl = max(0.0, 1 - $age / 5) * 0.14;
            for ($oy = -1; $oy <= 2; $oy++) {
                for ($ox = -1; $ox <= 2; $ox++) {
                    if ($oy >= 0 && $oy <= 1 && $ox >= 0 && $ox <= 1) {
                        continue;
                    }
                    $mix($tx + $ox, $ty + $oy, [190, 200, 255], $bl);
                }
            }
        } elseif ($f >= $u['depart']) {                                 // in flight
            $t = ($f - $u['depart']) / ($u['arrive'] - $u['depart']);
            $pos = bez([$hx, $hy], $u['ctrl'], [$tx, $ty], eio($t));
            $col = lkMix(lkScale($pc, $lit ? 1.0 : 0.85), $u['targetColor'], eio($t));
            // trail ghosts
            foreach ([[0.07, 0.45], [0.14, 0.2]] as [$back, $ga]) {
                $tp = max(0.0, $t - $back);
                $g = bez([$hx, $hy], $u['ctrl'], [$tx, $ty], eio($tp));
                $mix((int) round($g[0]), (int) round($g[1]), $col, $ga);
            }
            $paint = [$pos[0], $pos[1], $col];
        } else {                                                        // at home (idle chip)
            $scale = $lit ? 1.0 : 0.35;
            $paint = [$hx, $hy, lkScale($pc, $scale + $flash)];
        }

        [$pxl, $pyl, $col] = $paint;
        $x0 = (int) round($pxl);
        $y0 = (int) round($pyl);
        for ($oy = 0; $oy < 2; $oy++) {
            for ($ox = 0; $ox < 2; $ox++) {
                $put($x0 + $ox, $y0 + $oy, $col);
            }
        }
        // empty-socket dot while chip is draining
        if ($f > $u['depart'] + 0.5 && $f < $u['recallStart']) {
            $mix($hx + 1, $hy + 1, lkScale($pc, 0.5), 0.35);
        }
    }

    // word drop shadow (fades in after assembly starts, out during recall)
    $sA = $assembled ? 0.8 : max(0.0, min(1.0, ($f - 24) / 6)) * max(0.0, min(1.0, (43 - $f) / 3));
    if ($sA > 0.01) {
        foreach ($units as $u) {
            if ($f < $u['arrive'] || $f >= $u['recallStart']) {
                continue;
            }
            [$tx, $ty] = $u['target'];
            for ($oy = 0; $oy < 2; $oy++) {
                for ($ox = 0; $ox < 2; $ox++) {
                    $mix($tx + 1 + $ox, $ty + 2 + $oy, [52, 42, 78], 0.55 * $sA);
                }
            }
        }
    }

    // glint across the word
    if ($f >= 31 && $f <= 39) {
        $g = 6 + ($f - 31) * 6.4;
        foreach ($units as $u) {
            if ($f < $u['arrive']) {
                continue;
            }
            [$tx, $ty] = $u['target'];
            for ($oy = 0; $oy < 2; $oy++) {
                for ($ox = 0; $ox < 2; $ox++) {
                    if (abs($tx + $ox - ($g + $ty + $oy)) < 2) {
                        $mix($tx + $ox, $ty + $oy, [255, 255, 255], 0.7);
                    }
                }
            }
        }
    }

    // beam on top (sweep glow through the field)
    if ($beamA > 0) {
        $c0 = [190, 250, 255];
        for ($y = 1; $y <= 25; $y++) {
            $ve = 1.0;
            if ($y <= 2) {
                $ve = 0.4 + 0.3 * ($y - 1);
            } elseif ($y >= 24) {
                $ve = 0.5;
            }
            $bx0 = (int) round($beamX);
            for ($d = -2; $d <= 3; $d++) {
                $fall = match (abs($d)) {
                    0, 1 => 0.95,
                    2 => 0.5,
                    default => 0.16,
                };
                $mix($bx0 + $d, $y, $c0, $beamA * $fall * $ve * 0.75);
            }
        }
    }

    return $px;
}

// ================================================================ px grid → cells → ANSI
function cellsFromPx(array $px): array
{
    $page = lkHex(PAGE);
    $nullIfPage = static fn (array $c): ?array => $c === $page ? null : $c;
    $grid = [];
    foreach ($px as $row) {
        $out = [];
        foreach ($row as $c) {
            $out[] = $nullIfPage($c);
        }
        $grid[] = $out;
    }
    $cells = lkHalfBlock($grid);
    foreach ($cells as &$r) {
        foreach ($r as &$c) {
            $c[2] = $c[2] ?? $page;                     // painted page bg everywhere
        }
    }
    return $cells;
}

function paintTagline(array &$cells, int $nTyped): void
{
    global $TAG_G;
    $col = [122, 138, 182];
    foreach ($TAG_G as $i => $g) {
        $ch = $i < $nTyped ? $g : ' ';
        $cells[TAG_ROW][TAG_COL + $i] = [$ch, $ch === ' ' ? null : $col, lkHex(PAGE)];
    }
}

$typed = static fn (int $f): int => (int) max(0, min(27, min(
    (int) floor(($f - 25) * 2),
    27 - (int) floor(($f - 39) * 7),
)));

// ================================================================ rasterize GIF frames
function raster(array $px, string $taglineStr, int $tagCols, int $f, string $outPath): void
{
    $page = lkHex(PAGE);
    $im = imagecreatetruecolor(PW * PS, PH * PS);
    imagefilledrectangle($im, 0, 0, PW * PS, PH * PS, imagecolorallocate($im, $page[0], $page[1], $page[2]));
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            $c = $px[$y][$x];
            if ($c === $page) {
                continue;
            }
            imagefilledrectangle($im, $x * PS, $y * PS, ($x + 1) * PS - 1, ($y + 1) * PS - 1,
                imagecolorallocate($im, $c[0], $c[1], $c[2]));
        }
    }
    if ($taglineStr !== '') {
        $size = 11.0;
        $fontFile = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
        $cIdx = imagecolorallocate($im, 122, 138, 182);
        imagettftext($im, $size, 0, $tagCols * PS, TAG_ROW * 2 * PS + PS * 2 - 4, $cIdx, $fontFile, $taglineStr);
    }
    imagepng($im, $outPath);
    imagedestroy($im);
}

@mkdir(__DIR__ . '/frames', 0777, true);
array_map('unlink', glob(__DIR__ . '/frames/f_*.png') ?: []);

$frameStrs = [];
for ($f = 0; $f < FRAMES; $f++) {
    $px = framePx($f, $units, $pairUnits, $pairCross, $stars);
    $cells = cellsFromPx($px);
    $nT = $typed($f);
    paintTagline($cells, $nT);
    $frameStrs[$f] = lkEncode($cells, 'tc');
    if (getenv('DUP_PROBE') && $f === (int) getenv('DUP_PROBE')) {
        fwrite(STDERR, "PROBE frame $f, rows 8-19 cols 40-50:\n");
        for ($y = 8; $y <= 19; $y++) {
            $ln = "  r$y ";
            for ($x = 40; $x <= 50; $x++) {
                $c = $px[$y][$x];
                $ln .= ($c === [10,14,26]) ? ' .' : sprintf(' %02X%02X%02X', $c[0], $c[1], $c[2]);
            }
            fwrite(STDERR, $ln . "\n");
        }
    }
    raster($px, implode('', array_slice($TAG_G, 0, $nT)), TAG_COL, $f, sprintf(__DIR__ . '/frames/f_%02d.png', $f));
}

// held static frame: fully assembled (no beam/glint/recall states)
$pxS = framePx(45, $units, $pairUnits, $pairCross, $stars, true);
if (getenv('DUP_DEBUG')) {
    fwrite(STDERR, "row14 cols38-50:\n");
    for ($x = 38; $x <= 50; $x++) {
        $c = $pxS[14][$x];
        fwrite(STDERR, "  x$x = " . implode(',', $c) . "\n");
    }
    fwrite(STDERR, "units targeting col 42..46:\n");
    foreach ($units as $u) {
        if ($u['target'][0] >= 42 && $u['target'][0] <= 46) {
            fwrite(STDERR, "  id{$u['id']} pair{$u['pair']} t={$u['target'][0]},{$u['target'][1]} h={$u['home'][0]},{$u['home'][1]} arr={$u['arrive']}\n");
        }
    }
}
$cellsS = cellsFromPx($pxS);
paintTagline($cellsS, 27);
$staticCellsStr = lkEncode($cellsS, 'tc');
raster($pxS, TAGLINE, TAG_COL, 45, __DIR__ . '/logo-still.png');

$anim = lkAnim($frameStrs, $staticCellsStr);
file_put_contents(__DIR__ . '/logo.ansi', $anim);
file_put_contents(__DIR__ . '/logo-static.ansi', $staticCellsStr);

[$w, $h] = lkVerify(lkLastFrame($anim), 'dup/tc');
fwrite(STDERR, "units: " . count($units) . "  frames: " . FRAMES . "  canvas: {$w}x{$h} cells  ok\n");
fwrite(STDERR, "total unit check D18+U16+P16=" . (18 + 16 + 16) . "\n");
