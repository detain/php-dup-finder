<?php

declare(strict_types=1);

/**
 * gen-dup — "Clone Collapse" animated truecolor ANSI logo for phpdup.
 *
 * Concept: code fragments (amber/cyan "clone pairs") drift while a magenta
 * scanner beam sweeps them. Matched pairs snap out of drift, fly together and
 * slam into one slot of a ghosted wordmark, igniting D, then U, then P of
 * "DUP" from ghost-dim to a PHP-indigo→cyan gradient. A glint sweeps the
 * finished letters, "[ AST CLONE DETECTOR ]" reveals, and the final frame is
 * the clean static logo. See NOTES.md.
 *
 *   php gen-dup.php            build + verify + write logo.ansi
 *   php gen-dup.php --preview N  echo frame N (0-40) to stdout, nothing written
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ design data

const COLS = 88;                    // terminal cells
const ROWS = 17;                    // terminal cells
const PXM = 34;                     // pixel rows (ROWS * 2)
const FRAMES = 40;

const BG = '#0C0B14';
const GHOST = '#26233A';
const WORD_GRAD = ['#B18CFF', '#777BB4', '#5CC8FF', '#7CE38B'];
const AMBER = '#FFB86C';
const CYAN = '#5CC8FF';
const MAGENTA = '#FF79C6';
const DIM_MAG = '#571F44';
const SUB = '#9A9AB8';

/** 5x7 masks, supersampled 2x2 → 10x14 px each. */
const MASK = [
    'D' => ['####.', '#...#', '#...#', '#...#', '#...#', '#...#', '#####'],
    'U' => ['#...#', '#...#', '#...#', '#...#', '#...#', '#...#', '#####'],
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
];
const W0 = 25;          // wordmark left px col (D 25-34, U 39-48, P 52-62)
const R0 = 8;           // wordmark top px row (14 tall → rows 8..21)

const LAND = [12, 16, 20];   // impact frame per letter D,U,P
const FLIGHT = 7;            // frames of flight before landing

/** [x0,y0,w,h, pairIdx, letterIdx] of the 6 code fragments. */
const FRAGS = [
    [14, 3,  8, 4, 0, 0],
    [66, 9,  8, 4, 0, 0],
    [10, 23, 8, 4, 1, 1],
    [72, 4,  8, 4, 1, 1],
    [20, 17, 8, 4, 2, 2],
    [60, 26, 8, 4, 2, 2],
];
/** Bar widths (the "lines of code" inside each fragment). */
const BARS = [[8, 5, 8, 4], [6, 8, 4, 8], [8, 6, 8, 5], [5, 8, 6, 8], [7, 8, 5, 8], [8, 4, 8, 6]];

/** @return array [r,g,b] wordmark gradient colour at px column x */
function wordColor(int $x): array
{
    return lkGradient(WORD_GRAD, ($x - W0) / (63 - W0));
}

/** Letter pixel coords as list of [x, y] (supersampled 2x2). */
function letterPixels(string $ch): array
{
    $idx = array_search($ch, array_keys(MASK), true);
    $x0 = W0 + $idx * 14;
    $pts = [];
    foreach (MASK[$ch] as $my => $row) {
        foreach (str_split($row) as $mx => $c) {
            if ($c === '#') {
                for ($dy = 0; $dy < 2; $dy++) {
                    for ($dx = 0; $dx < 2; $dx++) {
                        $pts[] = [$x0 + $mx * 2 + $dx, R0 + $my * 2 + $dy];
                    }
                }
            }
        }
    }
    return $pts;
}

function ease(float $t): float
{
    $t = max(0.0, min(1.0, $t));
    return $t * $t * (3 - 2 * $t);
}

// ================================================================ frame pixel layer

/**
 * Build the RGB|null pixel grid for one frame.
 * @return array{0: list<list<array>>, 1: array<int,array>} [px, wordColors]
 */
function framePixels(int $f): array
{
    $px = array_fill(0, PXM, array_fill(0, COLS, null));

    // solid atmosphere
    for ($y = 0; $y < PXM; $y++) {
        for ($x = 0; $x < COLS; $x++) {
            $px[$y][$x] = lkHex(BG);
        }
    }

    // precompute per-letter brightness
    $lit = [];   // letterIdx => s
    $flash = []; // letterIdx => flash strength 0..1
    for ($L = 0; $L < 3; $L++) {
        $land = LAND[$L];
        $s = 0.16; // ghost
        if ($f >= $land) {
            $r = min(1.0, ($f - $land + 1) / 4);
            $s = 0.16 + (1.0 - 0.16) * $r;
        }
        if ($f > 31) {
            $s = 1.0; // settled
        }
        $lit[$L] = $s;
        $flash[$L] = match (true) {
            $f === $land => 0.85,
            $f === $land + 1 => 0.50,
            $f === $land + 2 => 0.26,
            $f === $land + 3 => 0.10,
            default => 0.0,
        };
    }

    // scanner beam (full sweep f0..10, fade 11..13)
    if ($f <= 13) {
        $bx = (int) round(6 + (82 - 6) * min(1.0, $f / 10));
        $fade = $f <= 10 ? 1.0 : max(0.0, 1.0 - ($f - 10) / 3);
        for ($y = 1; $y <= 28; $y++) {
            $px[$y][$bx] = lkMix($px[$y][$bx], lkHex(MAGENTA), 0.95 * $fade);
            foreach ([$bx - 1, $bx + 1] as $g) {
                if ($g >= 0 && $g < COLS) {
                    $px[$y][$g] = lkMix($px[$y][$g], lkHex(DIM_MAG), 0.8 * $fade);
                }
            }
        }
    }

    // wordmark (ghost → lit, glint, flash)
    $glint = ($f >= 23 && $f <= 30) ? -8 + (46 + 8) * ($f - 23) / 7 : null;
    foreach (['D', 'U', 'P'] as $L => $ch) {
        $s = $lit[$L];
        $fl = $flash[$L];
        $pts = letterPixels($ch);
        foreach ($pts as [$x, $y]) {
            $c = wordColor($x);
            $c = lkShade($c, 0.28 - 0.5 * ($y - R0) / 14);          // lit top, dark base
            $c = lkScale($c, max($s, 0.16));
            if ($glint !== null && $s > 0.9 && abs($x - $glint + ($y - R0) * 0.6) < 2) {
                $c = lkMix($c, [255, 255, 255], 0.7);
            }
            if ($fl > 0.0) {
                $edge = !in_edge_free($pts, $x, $y);
                if ($edge) {
                    $c = lkMix($c, [255, 255, 255], $fl);
                }
            }
            $px[$y][$x] = $c;
        }
    }

    // fragments: drift → flight → crossfade into letter
    foreach (FRAGS as $i => [$fx, $fy, $fw, $fh, $pair, $letter]) {
        $land = LAND[$letter];
        $t0 = $land - FLIGHT;
        [$tx, $ty] = letter_center($letter);
        $tx -= intdiv($fw, 2);
        $ty -= intdiv($fh, 2);
        $alpha = 1.0;
        $x = $fx + 2.2 * sin($f / 5.0 + $i * 1.7);
        $y = $fy + 1.4 * sin($f / 3.5 + $i * 2.3);
        if ($f >= $t0 && $f < $land) {
            $e = ease(($f - $t0) / ($land - $t0));
            $x = $x + ($tx - $x) * $e;
            $y = $y + ($ty - $y) * $e;
            $alpha = 1.0 - 0.35 * $e;
        } elseif ($f >= $land) {
            $alpha = max(0.0, 1.0 - ($f - $land + 1) / 3);
            $x = $tx;
            $y = $ty;
            if ($alpha <= 0.0) {
                continue;
            }
        }
        $col = lkHex($i % 2 === 0 ? AMBER : CYAN);
        $bars = BARS[$i];
        for ($r = 0; $r < $fh; $r++) {
            $py = (int) round($y) + $r;
            if ($py < 0 || $py >= PXM) {
                continue;
            }
            for ($cix = 0; $cix < $bars[$r]; $cix++) {
                $pxx = (int) round($x) + $cix;
                if ($pxx < 0 || $pxx >= COLS) {
                    continue;
                }
                $px[$py][$pxx] = lkMix($px[$py][$pxx], $col, $alpha * 0.92);
            }
        }
    }

    return $px;
}

/** Is pixel (x,y) NOT fully surrounded by ink (i.e. an edge pixel)? */
function in_edge_free(array $pts, int $x, int $y): bool
{
    $set = [];
    foreach ($pts as [$px, $py]) {
        $set[$px . ',' . $py] = true;
    }
    foreach ([[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [1, -1], [-1, 1], [1, 1]] as [$dx, $dy]) {
        if (!isset($set[($x + $dx) . ',' . ($y + $dy)])) {
            return false;
        }
    }
    return true;
}

/** Center px of letter L (slot the fragments fly into). */
function letter_center(int $L): array
{
    return [W0 + $L * 14 + 5, R0 + 7];
}

// ================================================================ text-cell layer

const RULE_GLYPH = '·';
const TAG = '[ AST CLONE DETECTOR ]';

/** Overlay rule + revealing subtitle onto a cell grid. */
function decorate(array &$cells, int $f): void
{
    // dotted cluster-rule under the wordmark (cell row 10)
    $dim = lkMix(lkHex(GHOST), [255, 255, 255], 0.25);
    for ($x = W0; $x <= 63; $x++) {
        lkPut($cells, $x, 11, RULE_GLYPH, $dim, null);
    }
    // subtitle reveal
    $start = 14;
    $shown = max(0, min(strlen(TAG), (int) floor(($f - $start) / 0.8) + ($f >= $start ? 1 : 0)));
    if ($f > 31) {
        $shown = strlen(TAG);
    }
    if ($shown > 0) {
        $x0 = intdiv(COLS - strlen(TAG), 2);
        $sub = substr(TAG, 0, $shown);
        for ($i = 0; $i < strlen($sub); $i++) {
            $g = $sub[$i];
            $col = ($g === '[' || $g === ']') ? lkHex(AMBER)
                : ($g === 'C' && strpos($sub, 'CLONE') !== false && $i >= strpos(TAG, 'CLONE') && $i < strpos(TAG, 'CLONE') + 5
                    ? lkHex(CYAN) : lkHex(SUB));
            lkPut($cells, $x0 + $i, 13, $g, $col, null);
        }
    }
}

// ================================================================ build

/** @return list<array> cell grids (one per frame, incl. final hold) */
function buildFrames(): array
{
    $frames = [];
    for ($f = 0; $f < FRAMES; $f++) {
        $cells = lkHalfBlock(framePixels($f));
        decorate($cells, $f);
        $frames[] = $cells;
    }
    return $frames;
}

// ================================================================ CLI

$preview = null;
foreach ($argv as $i => $a) {
    if ($a === '--preview' && isset($argv[$i + 1])) {
        $preview = (int) $argv[$i + 1];
    }
}

$frames = buildFrames();
$enc = array_map(static fn (array $c): string => lkEncode($c, 'tc'), $frames);
$static = end($enc);
$anim = lkAnim(array_slice($enc, 0, -1) ?: [], $static);

if ($preview !== null) {
    echo $enc[max(0, min(FRAMES - 1, $preview))];
    exit(0);
}

// verify every frame + the static
foreach ($enc as $i => $e) {
    [$w, $h] = lkVerify($e, "frame $i");
}
fwrite(STDERR, sprintf("frames: %d  cells: %dx%d  anim bytes: %d\n", count($enc), $w, $h, strlen($anim)));
file_put_contents(__DIR__ . '/logo.ansi', $anim);
fwrite(STDERR, "wrote logo.ansi\n");
