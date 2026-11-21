<?php

declare(strict_types=1);

/**
 * generate-dup — "MATCH IGNITION": animated truecolor ANSI logo for phpdup.
 *
 * Concept: the wordmark DUP exists as two instances — a violet ORIGINAL and a
 * hot-magenta CLONE that slides in from the lower right. Wherever the two
 * masks intersect during the approach, pixels ignite WHITE (match pixels), so
 * the fused wordmark literally assembles out of detected duplication. On lock:
 * flash → glint sweep → diff-ruler + caption type-in, bookended by cyan <?php
 * and a dim ?> on a deep-indigo terminal gradient with token dust + twin AST
 * trees (dim until the detection pulse washes them cyan).
 *
 * Output (stdout): a cursor-up ANSI animation (38 frames, ends on static hold).
 * Also writes logo-static.ansi.
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const W = 88;                 // cell columns
const H = 14;                 // cell rows
const PH = 2 * H;             // pixel rows (half-block fold 2px -> 1 cell)
const FRAMES = 38;

/** Big-pixel 5x7 base, NN-doubled to 10x14 → chunky bold letterforms. */
const FONT5 = [
    'D' => ['####.', '#...#', '#...#', '#...#', '#...#', '#...#', '####.'],
    'U' => ['#...#', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
];

const WORD_X0 = 25;           // pixel col of wordmark left edge
const WORD_Y0 = 6;            // pixel row of wordmark top edge
const CLONE_DX0 = 24;         // slide start offsets (px)
const CLONE_DY0 = 6;

const CAPTION = 'phpdup · AST clone detector';

const BG_STOPS = ['#0A0A17', '#15152F', '#090914'];
const VIOLET = ['#A9B2F0', '#5F649E'];     // original (dimmed at runtime)
const CLONE_C = ['#F09BDD', '#8A4FB8'];    // hot magenta clone
const SETTLE = ['#B9C1FF', '#6A70B8'];     // fused wordmark after lock
const MATCH_C = '#EDEBFF';                 // match-pixel hot core
const CYAN = '#5CC8FF';
const SLATE = '#8B93C0';
const CAP_BOLD = '#C9CFF5';
const RULE = '#3A4079';
const DUST = '#232A52';

// ================================================================ MASKS

/** base 5x7 layout of "DUP" -> nearest-neighbour 2x double -> bool mask [y][x] */
function wordMask(): array
{
    [$rows] = lkLayout(FONT5, 'DUP', letterGap: 2, wordGap: 2, margin: 0);
    $mask = [];
    foreach ($rows as $r) {
        $dr = [];
        foreach (str_split($r) as $ch) {
            $dr[] = $ch === '#';
            $dr[] = $ch === '#';
        }
        $mask[] = $dr;
        $mask[] = $dr;
    }
    return $mask;
}

function maskAt(array $mask, int $x, int $y): bool
{
    return $mask[$y][$x] ?? false;
}

// ================================================================ BACKGROUND

/** @return list<list<array>> W x PH pixel grid of resolved background */
function background(): array
{
    $px = [];
    for ($y = 0; $y < PH; $y++) {
        $row = [];
        for ($x = 0; $x < W; $x++) {
            $c = lkGradient(BG_STOPS, $y / (PH - 1));
            $n = (lkNoise($x, $y, 7) - 0.5) * 6;                       // static dither
            $c = array_map(static fn (int $v): int => (int) max(0, min(255, round($v + $n))), $c);
            $d = sqrt((($x - W / 2) / 1.6) ** 2 + ($y - PH / 2 + 2) ** 2);  // soft centre glow
            $g = max(0.0, 1 - $d / 26) ** 2 * 0.20;
            $row[] = lkMix($c, lkHex('#3C4696'), $g);
        }
        $px[] = $row;
    }
    return $px;
}

// ================================================================ FRAME PIXELS

/**
 * Paint original + clone (+ match/flash/glint) onto the pixel canvas.
 *
 * @param array $mask wordmark bool mask [y][x]
 * @param array $px   background, mutated in place
 */
function paintMark(int $f, array $mask, array &$px): void
{
    $mw = count($mask[0]);
    $mh = count($mask);

    $slide = $f >= 15 ? 1.0 : max(0.0, ($f - 2) / 13);
    $e = 1 - (1 - $slide) ** 2.4;                       // ease-out
    $dx = $f < 2 ? CLONE_DX0 : (int) round(CLONE_DX0 * (1 - $e));
    $dy = $f < 2 ? CLONE_DY0 : (int) round(CLONE_DY0 * (1 - $e));
    $moving = $f >= 2 && $f <= 14;

    // motion smear: clone ghosts at earlier times, mixed into the background
    if ($moving) {
        foreach ([1 => 0.28, 2 => 0.13] as $k => $alpha) {
            $ts = max(0.0, $slide - 0.07 * $k);
            $es = 1 - (1 - $ts) ** 2.4;
            $sx = (int) round(CLONE_DX0 * (1 - $es));
            $sy = (int) round(CLONE_DY0 * (1 - $es));
            if ($sx === $dx && $sy === $dy) {
                continue;
            }
            for ($py = 0; $py < $mh; $py++) {
                for ($cx = 0; $cx < $mw; $cx++) {
                    if (!maskAt($mask, $cx, $py)) {
                        continue;
                    }
                    $X = WORD_X0 + $cx + $sx;
                    $Y = WORD_Y0 + $py + $sy;
                    if ($X < 0 || $X >= W || $Y < 0 || $Y >= PH) {
                        continue;
                    }
                    $c = lkGradient(CLONE_C, $py / ($mh - 1));
                    $px[$Y][$X] = lkMix($px[$Y][$X], lkScale($c, 0.5), $alpha);
                }
            }
        }
    }

    $flash = match (true) {
        $f === 15 => 0.50,
        $f === 16 => 0.20,
        default => 0.0,
    };
    $gc = null;                                         // glint centre (pixel col)
    if ($f >= 17 && $f <= 27) {
        $gc = WORD_X0 - 6 + ($f - 17) * (($mw + 12) / 10);
    }

    // canvas-space union pass: original sits at (WORD_X0, WORD_Y0), clone offset (dx, dy)
    for ($Y = WORD_Y0 + min(0, $dy); $Y <= WORD_Y0 + $mh - 1 + max(0, $dy); $Y++) {
        if ($Y < 0 || $Y >= PH) {
            continue;
        }
        for ($X = WORD_X0; $X <= WORD_X0 + $mw - 1 + max($dx, 0); $X++) {
            if ($X >= W) {
                break;
            }
            if ($X < 0) {
                continue;
            }
            $o = maskAt($mask, $X - WORD_X0, $Y - WORD_Y0);
            $c = $f >= 15 ? $o : maskAt($mask, $X - WORD_X0 - $dx, $Y - WORD_Y0 - $dy);

            if ($f >= 15) {                             // ---- settled regime
                if (!$o) {
                    continue;
                }
                $col = lkGradient(SETTLE, ($Y - WORD_Y0) / ($mh - 1));
                if ($Y - WORD_Y0 < 2) {
                    $col = lkShade($col, 0.14);         // lit top edge
                }
                if ($f >= 18 && ($Y - WORD_Y0) % 2 === 0 && !maskAt($mask, $X - WORD_X0, $Y - WORD_Y0 - 1)) {
                    $col = lkShade($col, 0.10);         // hairline top bevel
                }
                if ($flash > 0) {
                    $col = lkMix($col, [255, 255, 255], $flash);
                }
                if ($gc !== null) {
                    $band = abs(($X + $Y * 0.55) - ($gc + WORD_Y0 * 0.55));
                    if ($band < 3.4) {
                        $col = lkMix($col, [255, 255, 255], (1 - $band / 3.4) ** 2 * 0.85);
                    }
                }
                $px[$Y][$X] = $col;
                continue;
            }

            // ---- slide regime
            if ($o && $c) {                             // match ignition
                $spark = lkMix(lkHex(MATCH_C), [255, 255, 255], lkNoise($X, $Y, 3) * 0.5);
                $px[$Y][$X] = lkScale($spark, 0.92 + 0.08 * $e);
            } elseif ($o) {
                $col = lkGradient(VIOLET, ($Y - WORD_Y0) / ($mh - 1));
                $px[$Y][$X] = lkScale($col, 0.72);
            } elseif ($c) {
                $col = lkGradient(CLONE_C, ($Y - WORD_Y0 - $dy) / ($mh - 1));
                $px[$Y][$X] = lkScale($col, 0.55 + 0.45 * $slide);
            }
        }
    }
}

// ================================================================ CELL OVERLAYS

/** @return list<array{int,int,string,string}> static token-dust scatter */
function dustScatter(): array
{
    $pool = ['{', '}', '(', ')', ';', '=', '>', '*', '$', '/'];
    $out = [];
    for ($x = 0; $x < W; $x++) {
        for ($y = 0; $y < H; $y++) {
            $edge = in_array($y, [1, 2, 12], true) || $x < 5 || $x > 82;
            if (!$edge) {
                continue;
            }
            if (lkNoise($x, $y, 11) > 0.78) {
                $out[] = [$x, $y, $pool[(int) (lkNoise($x, $y, 12) * count($pool)) % count($pool)], DUST];
            }
        }
    }
    return $out;
}

/** twin AST trees (dim until the detection pulse washes them cyan) */
function paintTrees(array &$cells, int $f): void
{
    $dim = lkHex('#2E3468');
    $pulse = $f >= 16 && $f <= 24 ? sin(M_PI * ($f - 16) / 8) : 0.0;
    $node = $pulse > 0 ? lkMix($dim, lkHex(CYAN), min(1.0, $pulse * 1.1)) : $dim;
    $edge = $pulse > 0 ? lkMix($dim, $node, 0.5) : $dim;
    foreach ([11, 76] as $cx) {
        lkPut($cells, $cx, 4, '●', $node);
        lkPut($cells, $cx - 1, 5, "\u{2572}", $edge);
        lkPut($cells, $cx + 1, 5, "\u{2571}", $edge);
        lkPut($cells, $cx - 2, 6, '◆', $node);
        lkPut($cells, $cx + 2, 6, '◆', $node);
    }
}

function overlayText(array &$cells, int $f): void
{
    // <?php boot, typed in with a block cursor
    $n = min(5, $f + 1);
    lkText($cells, 2, 0, substr('<?php', 0, $n), lkHex(CYAN));
    if ($f < 5) {
        lkPut($cells, 2 + $n, 0, '▌', lkHex(CYAN));
    }
    // closing PHP tag, appears once the caption lands
    if ($f >= 28) {
        lkText($cells, W - 6, 13, '?>', lkHex('#3A4079'));
    }
    // diff ruler: grows from centre on lock
    if ($f >= 15) {
        $k = min(19, ($f - 15) * 5);
        for ($x = 44 - $k; $x <= 43 + $k; $x++) {
            lkPut($cells, $x, 10, '─', lkHex(RULE));
        }
        if ($k >= 2) {
            lkPut($cells, 44, 10, '◆', lkHex('#6BD5FF'));
        }
    }
    // caption typewriter
    if ($f >= 15) {
        $n2 = min(mb_strlen(CAPTION), ($f - 15) * 3);
        if ($n2 > 0) {
            $cap = mb_substr(CAPTION, 0, $n2);
            $x0 = intdiv(W - mb_strlen(CAPTION), 2) + 1;
            $bold = mb_substr($cap, 0, 6);
            $rest = mb_substr($cap, 6);
            if ($bold !== '') {
                lkText($cells, $x0, 11, $bold, lkHex(CAP_BOLD));
            }
            if ($rest !== '') {
                lkText($cells, $x0 + 6, 11, $rest, lkHex(SLATE));
            }
        }
    }
}

// ================================================================ FRAME BUILD

$mask = wordMask();
$bg = background();
$dust = dustScatter();

/** @return list<list<array>> cell grid for frame $f */
function frameCells(int $f, array $mask, array $bg, array $dust): array
{
    $px = $bg;
    paintMark($f, $mask, $px);
    $cells = lkHalfBlock($px);
    foreach ($dust as [$x, $y, $g, $hex]) {
        lkPut($cells, $x, $y, $g, lkScale(lkHex($hex), 0.7 + 0.6 * lkNoise($x, $y, 5)));
    }
    paintTrees($cells, $f);
    overlayText($cells, $f);
    return $cells;
}

$frames = [];
for ($f = 0; $f < FRAMES; $f++) {
    $enc = lkEncode(frameCells($f, $mask, $bg, $dust), 'tc');
    [$w, $h] = lkVerify($enc, "frame $f");
    if ($w !== W || $h !== H) {
        fwrite(STDERR, "frame $f: unexpected {$w}x{$h}\n");
        exit(1);
    }
    $frames[$f] = $enc;
}

$static = array_pop($frames);
file_put_contents(__DIR__ . '/logo-static.ansi', $static);
echo lkAnim($frames, $static);
fwrite(STDERR, FRAMES . " frames, {$w}x{$h}, tc animation written\n");
