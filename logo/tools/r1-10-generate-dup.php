<?php

declare(strict_types=1);

/**
 * generate-dup — "Clone Collapse" animated truecolor ANSI logo for phpdup.
 *
 * Story arc (40 frames, ~1.6 s loop):
 *   0-7    two editor panes show the SAME PHP source; a cyan scan-beam sweeps
 *          down and strobes matching rows amber (detection)
 *   8-15   panes dissolve; DUP materializes twice — indigo clone (left),
 *          magenta clone (right) — with signal-lock glitch jitter
 *   16-27  the two clones slide together on an ease-in-out curve
 *   28-29  overlap → white flash + spark ring (they are identical!)
 *   30-39  merged DUP: PHP-violet→pink→amber gradient, diff-gutter bars,
 *          similarity bar at 100%, tagline, glint sweep → held static frame
 *
 * Emits: logo.ansi (raw tc animation), frames/f-XX.png (raster),
 *        logo-still.png (final frame).
 *
 *   php generate-dup.php
 */

require __DIR__ . '/logo-kit.php';
require __DIR__ . '/raster.php';

// ================================================================ stage data

const COLS = 84;
const ROWS = 16;
const NFRAMES = 40;

const LR0 = 4;        // letter art top cell-row (7 rows = 14 px)
const LW = 38;        // letter art width in px-cols
const FINAL_X = 23;   // centered letter x

const PAGE = '#0B0E14';

// chunky 5x7 pixel font, column-doubled AND row-doubled at paint time
const FONT5 = [
    'D' => ['.###.', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
    'U' => ['#...#', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
];
const WORD = 'DUP';
const LETTER_GAP = 2; // in base columns (x2 = 4 px)

const FINAL_STOPS = ['#7C3AED', '#C026D3', '#F472B6', '#FBBF24'];
const GHOST_A     = ['#4338CA', '#6366F1', '#38BDF8'];
const GHOST_B     = ['#A21CAF', '#D946EF', '#FB7185'];

const BEAM  = '#22D3EE';
const AMBER = '#F59E0B';

const CODE_LINES = [
    '<?php',
    'function findDup($a, $b)',
    '{',
    '    $x = ast_hash($a);',
    '    $y = ast_hash($b);',
    '    if ($x === $y) {',
    '        return "DUP";',
    '    }',
    '    return null;',
    '}',
    '// clones: identical AST',
    '$pool = [$x, $y, $z];',
    'foreach ($pool as $p) {',
    '    mark($p);',
    '}',
];

// ================================================================ easing

function easeOutQuad(float $t): float { return 1 - (1 - $t) * (1 - $t); }
function easeInOutCubic(float $t): float
{
    return $t < 0.5 ? 4 * $t * $t * $t : 1 - pow(-2 * $t + 2, 3) / 2;
}
function clamp01(float $t): float { return max(0.0, min(1.0, $t)); }

// ================================================================ letter mask

/** @return list<string> 14 rows of 38 chars ('#'/'.'), doubled from FONT5 */
function letter_mask(): array
{
    $base = [];
    foreach (str_split(WORD) as $i => $ch) {
        $g = FONT5[$ch];
        foreach ($g as $y => $row) {
            $dbl = '';
            foreach (str_split($row) as $c) {
                $dbl .= $c . $c;
            }
            $base[$y] ??= '';
            $base[$y] .= $dbl . str_repeat('.', 2 * LETTER_GAP * ($i < strlen(WORD) - 1 ? 1 : 0));
        }
    }
    $rows = [];
    foreach ($base as $row) {
        $rows[] = $row;
        $rows[] = $row;
    }
    return $rows;
}

/** @var list<string> $MASK  (14 rows) */
$GLOBALS['MASK'] = null;

function mask_on(int $px, int $py): bool
{
    global $MASK;
    $MASK ??= letter_mask();
    return ($MASK[$py][$px] ?? '.') === '#';
}

// ================================================================ colour fns

function final_color(int $px, int $py, ?float $glint): array
{
    $c = lkGradient(FINAL_STOPS, $px / (LW - 1));
    $c = lkShade($c, 0.16 - 0.34 * $py / 13);
    if ($py <= 1) {
        $c = lkShade($c, 0.30);
    }
    if ($py >= 12) {
        $c = lkShade($c, -0.22);
    }
    if ($glint !== null && abs($px - ($glint - $py * 1.25)) < 1.9) {
        $c = lkMix($c, [255, 255, 255], 0.78);
    }
    return $c;
}

function ghost_color(array $stops, int $px, int $py, float $gain): array
{
    $c = lkGradient($stops, $px / (LW - 1));
    $c = lkShade($c, 0.10 - 0.30 * $py / 13);
    return lkMix(lkHex(PAGE), $c, $gain);
}

// ================================================================ paint

/**
 * Paint one ghost/final letter layer into a canvas colour map.
 * @param array<int, array<int, array>> $map  ref: [py][col] => rgb
 */
function paint_layer(array &$map, float $x, ?array $stops, float $gain, ?array $flat,
                     ?float $glint, ?int $jitterRow, int $jitterShift): void
{
    for ($py = 0; $py < 14; $py++) {
        $sx = $jitterRow === $py ? $jitterShift : 0;
        for ($px = 0; $px < LW; $px++) {
            if (!mask_on($px, $py)) {
                continue;
            }
            $col = (int) round($x) + $px + $sx;
            if ($col < 0 || $col >= COLS) {
                continue;
            }
            $map[$py][$col] = $flat ?? ghost_color($stops, $px, $py, $gain);
        }
    }
}

/** Merge two pixel maps; overlap gets a brighter "weld" colour. */
function merge_maps(array $a, array $b): array
{
    $out = $a;
    foreach ($b as $py => $row) {
        foreach ($row as $col => $c) {
            if (isset($out[$py][$col])) {
                $out[$py][$col] = lkShade(lkMix($out[$py][$col], $c, 0.5), 0.32);
            } else {
                $out[$py][$col] = $c;
            }
        }
    }
    return $out;
}

/** Emit half-block cells from a [py][col] colour map into $cells rows. */
function emit_halfblocks(array &$cells, array $map, int $row0): void
{
    for ($i = 0; $i < 7; $i++) {
        $t = $map[2 * $i] ?? [];
        $bo = $map[2 * $i + 1] ?? [];
        $y = $row0 + $i;
        foreach ($t + $bo as $col => $_) {
            $top = $t[$col] ?? null;
            $bot = $bo[$col] ?? null;
            if ($top !== null && $bot !== null && $top === $bot) {
                $cells[$y][$col] = [' ', $bot, $bot];
            } elseif ($top !== null && $bot !== null) {
                $cells[$y][$col] = ['▀', $top, $bot];
            } elseif ($top !== null) {
                $cells[$y][$col] = ['▀', $top, $cells[$y][$col][2]];
            } elseif ($bot !== null) {
                $cells[$y][$col] = ['▄', $bot, $cells[$y][$col][2]];
            }
        }
    }
}

function paint_code_row(array &$cells, int $y, int $x0, int $w, string $line, float $a): void
{
    $re = '~(\$\w+|<\?php|\b(?:function|if|return|foreach|as|null|new)\b|//.*|"[^"]*"|\b\d+\b|\b[a-zA-Z_]\w*(?=\()|\s+|.)~su';
    preg_match_all($re, $line, $mm);
    $x = $x0;
    foreach ($mm[0] as $tok) {
        $col = match (true) {
            (bool) preg_match('~^//~', $tok) => '#6A9955',
            (bool) preg_match('~^\$~', $tok) => '#9CDCFE',
            (bool) preg_match('~^<\?php|^(function|if|return|foreach|as|null|new)$~', $tok) => '#569CD6',
            (bool) preg_match('~^"~', $tok) => '#CE9178',
            (bool) preg_match('~^\d~', $tok) => '#B5CEA8',
            (bool) preg_match('~\($~', $tok) => '#DCDCAA',
            default => '#D4D4D4',
        };
        $col = lkScale(lkHex($col), 0.62);
        $col = lkMix(lkHex(PAGE), $col, $a);
        foreach (str_split($tok) as $g) {
            if ($g !== ' ' && $x < $x0 + $w) {
                lkPut($cells, $x, $y, $g, $col);
            }
            $x++;
        }
    }
}

function paint_panes(array &$cells, int $f): void
{
    $a = $f < 8 ? 1.0 : max(0.0, 1 - ($f - 8) / 2.0);   // quick dissolve under the ghosts
    if ($a <= 0.01) {
        return;
    }
    $paneBg   = lkMix(lkHex(PAGE), lkHex('#131A29'), $a);
    $borderC  = lkMix(lkHex(PAGE), [90, 105, 140], 0.85 * $a);
    $titleC   = lkMix(lkHex(PAGE), [148, 163, 205], $a);
    $shift    = intdiv($f, 4);
    $beamRow  = $f <= 7 ? 2 + (int) round(10 * easeOutQuad($f / 7)) : 12;

    foreach ([[3, 'a.php'], [53, 'a-copy.php']] as [$L, $title]) {
        $R = $L + 27;
        for ($r = 1; $r <= 13; $r++) {          // interior wash (every cell gets bg)
            for ($c = $L; $c <= $R; $c++) {
                $cells[$r][$c][2] = $paneBg;
            }
        }
        // frame
        lkPut($cells, $L, 1, '╭', $borderC);
        lkPut($cells, $R, 1, '╮', $borderC);
        lkPut($cells, $L, 13, '╰', $borderC);
        lkPut($cells, $R, 13, '╯', $borderC);
        for ($c = $L + 1; $c < $R; $c++) {
            lkPut($cells, $c, 1, '─', $borderC);
            lkPut($cells, $c, 13, '─', $borderC);
        }
        for ($r = 2; $r <= 12; $r++) {
            lkPut($cells, $L, $r, '│', $borderC);
            lkPut($cells, $R, $r, '│', $borderC);
        }
        lkText($cells, $L + 2, 1, $title, $titleC);
        // code
        for ($r = 2; $r <= 12; $r++) {
            $line = CODE_LINES[($r - 2 + $shift) % count(CODE_LINES)];
            paint_code_row($cells, $r, $L + 1, 26, $line, $a);
        }
        // clone-block highlight: rows 3..8 hold the duplicated method; as the
        // beam passes each clone row it flares amber, then settles to a soft
        // glow — both panes light on the SAME rows (that's the detection).
        for ($r = 2; $r <= 12; $r++) {
            if ($r >= 3 && $r <= 8 && $r <= $beamRow) {
                $d = $beamRow - $r;
                $strong = $d <= 0 ? 0.9 : ($d == 1 ? 0.6 : max(0.30, 0.44 - $d * 0.02));
                $amt = $strong * $a * (($f + $r) % 2 === 0 ? 1.0 : 0.7);
                for ($c = $L + 1; $c < $R; $c++) {
                    $cells[$r][$c][2] = lkMix($cells[$r][$c][2] ?? $paneBg, [236, 160, 46], $amt * 0.5);
                }
                $bar = lkMix(lkHex(PAGE), [251, 191, 36], $amt);
                lkPut($cells, $L, $r, '▌', $bar);
                lkPut($cells, $R, $r, '▐', $bar);
            }
        }
    }
    // scan beam (f 0..7)
    if ($f <= 7) {
        $br = $beamRow;
        for ($c = 2; $c <= 81; $c++) {
            $inGap = $c >= 31 && $c <= 52;
            if ($inGap) {
                lkPut($cells, $c, $br, '─', lkHex(BEAM));
            } else {
                $cells[$br][$c][2] = lkMix($cells[$br][$c][2] ?? lkHex(PAGE), lkHex(BEAM), 0.5);
            }
        }
        if ($br > 2) {                                  // glow trailing above
            for ($c = 2; $c <= 81; $c++) {
                $cells[$br - 1][$c][2] = lkMix($cells[$br - 1][$c][2] ?? lkHex(PAGE), lkHex(BEAM), 0.14);
            }
        }
    }
}

function paint_chrome(array &$cells, int $f): void
{
    $a = $f < 28 ? 0.38 : clamp01(0.38 + ($f - 28) * 0.35);
    $c = lkMix(lkHex(PAGE), [125, 140, 175], $a);
    $hi = lkMix(lkHex(PAGE), [167, 139, 250], $a);
    lkPut($cells, 1, 0, '┌', $hi);
    lkPut($cells, 82, 0, '┐', $hi);
    lkPut($cells, 1, 13, '└', $hi);
    lkPut($cells, 82, 13, '┘', $hi);
    for ($x = 2; $x <= 5; $x++) {
        lkPut($cells, $x, 0, '─', $c);
        lkPut($cells, $x, 13, '─', $c);
    }
    for ($x = 78; $x <= 81; $x++) {
        lkPut($cells, $x, 0, '─', $c);
        lkPut($cells, $x, 13, '─', $c);
    }

    // similarity meter — always visible, fills to the merge
    $p = clamp01($f / 28);
    lkText($cells, 2, 15, 'SIMILARITY', lkMix(lkHex(PAGE), [107, 122, 153], 0.9));
    lkPut($cells, 13, 15, '[', [107, 122, 153]);
    lkPut($cells, 54, 15, ']', [107, 122, 153]);
    $fill = (int) round(40 * easeOutQuad($p));
    for ($i = 0; $i < 40; $i++) {
        $x = 14 + $i;
        if ($i < $fill) {
            $g = lkGradient(['#F59E0B', '#F472B6', '#C026D3'], $i / 39);
            lkPut($cells, $x, 15, '═', lkMix($g, [255, 255, 255], 0.25));
        } else {
            lkPut($cells, $x, 15, '░', [55, 65, 81]);
        }
    }
    $pct = sprintf('%3d%%', (int) round(100 * $p));
    lkText($cells, 56, 15, $pct, $p >= 1 ? [250, 204, 21] : [148, 163, 184]);

    if ($f >= 30) {
        $ta = clamp01(($f - 30) / 3);
        $tc = lkMix(lkHex(PAGE), [199, 185, 255], $ta);
        $dt = lkMix(lkHex(PAGE), [110, 100, 160], $ta * 0.7);
        for ($x = 11; $x <= 27; $x++) {
            lkPut($cells, $x, 14, '─', $dt);
        }
        for ($x = 55; $x <= 71; $x++) {
            lkPut($cells, $x, 14, '─', $dt);
        }
        lkText($cells, 29, 14, 'PHPDUP · AST CLONE FINDER', $tc);
        lkText($cells, 62, 15, '45 clones', lkMix(lkHex(PAGE), [244, 114, 182], $ta));
        // diff-gutter bars beside the letters
        $red = lkMix(lkHex(PAGE), [229, 72, 77], 0.30 * $ta);
        $grn = lkMix(lkHex(PAGE), [46, 160, 67], 0.26 * $ta);
        for ($r = LR0; $r < LR0 + 7; $r++) {
            $cells[$r][20][2] = $red;
            $cells[$r][63][2] = $grn;
        }
        foreach ([[20, '-', '#F87171', [4, 6, 8]], [63, '+', '#4ADE80', [4, 6, 8]]] as [$x, $g, $hex, $rows]) {
            foreach ($rows as $r) {
                lkPut($cells, $x, $r, $g, lkMix(lkHex(PAGE), lkHex($hex), $ta));
            }
        }
    }
}

function paint_halo(array &$cells, int $f): void
{
    $a = $f < 28 ? 0 : clamp01(($f - 28) / 3);
    if ($a <= 0) {
        return;
    }
    $cy = LR0 + 3;
    $cx = FINAL_X + LW / 2;
    for ($r = LR0 - 1; $r <= LR0 + 7; $r++) {
        for ($c = FINAL_X - 4; $c <= FINAL_X + LW + 3; $c++) {
            $dv = 1 - pow(abs($r - $cy) / 4.6, 2);
            $dh = 1 - pow(abs($c - $cx) / 24, 2);
            $prof = max(0, min($dv, $dh));
            if ($prof > 0) {
                $cells[$r][$c][2] = lkMix($cells[$r][$c][2], lkHex('#2A1650'), 0.8 * $prof * $a);
            }
        }
    }
}

function paint_sparks(array &$cells, int $f): void
{
    if ($f < 28 || $f > 31) {
        return;
    }
    $k = $f - 27;
    for ($i = 0; $i < 16; $i++) {
        $ang = $i * M_PI / 8 + 0.25;
        $rr = $k * 3.4;
        $c = (int) round(41.5 + $rr * 2.1 * cos($ang));
        $r = (int) round(LR0 + 3 + $rr * 0.75 * sin($ang));
        if ($r < 0 || $r >= ROWS || $c < 0 || $c >= COLS) {
            continue;
        }
        $fade = max(0.0, 1 - $k / 4.4);
        $col = lkScale(lkMix([255, 255, 255], lkHex(BEAM), $k / 4), 0.35 + 0.65 * $fade);
        lkPut($cells, $c, $r, '*', $col);
    }
}

function build_frame(int $f): array
{
    $cells = lkBlankCells(COLS, ROWS, lkHex(PAGE));

    paint_panes($cells, $f);
    paint_chrome($cells, $f);

    // ---- letter layers -------------------------------------------------
    $glint = null;
    if ($f >= 30 && $f <= 37) {
        $glint = -6 + ($f - 30) * 6.8;
    }

    if ($f < 28) {
        // materialize + collapse of two ghosts
        if ($f >= 8) {
            $gain = $f <= 15 ? easeOutQuad(clamp01(($f - 8) / 7)) : 1.0;
            $e = $f >= 16 ? easeInOutCubic(clamp01(($f - 16) / 11)) : 0.0;
            $xA = 2 + (FINAL_X - 2) * $e;
            $xB = 44 - (44 - FINAL_X) * $e;
            $jA = $f <= 15 && lkNoise($f, 3, 7) > 0.42 ? [(int) (lkNoise($f, 5, 9) * 1000) % 14, ((int) (lkNoise($f, 9, 1) * 100) % 2) * 2 - 1] : [null, 0];
            $jB = $f <= 15 && lkNoise($f, 4, 8) > 0.42 ? [(int) (lkNoise($f, 6, 10) * 1000) % 14, ((int) (lkNoise($f, 8, 2) * 100) % 2) * 2 - 1] : [null, 0];
            $mapA = [];
            $mapB = [];
            paint_layer($mapA, $xA, GHOST_A, $gain, null, null, $jA[0], $jA[1]);
            paint_layer($mapB, $xB, GHOST_B, $gain, null, null, $jB[0], $jB[1]);
            $map = merge_maps($mapA, $mapB);
        } else {
            $map = [];
        }
    } elseif ($f === 28) {
        $map = [];
        paint_layer($map, FINAL_X, null, 1, [255, 255, 255], null, null, 0);
    } elseif ($f === 29) {
        $map = [];
        for ($py = 0; $py < 14; $py++) {
            for ($px = 0; $px < LW; $px++) {
                if (mask_on($px, $py)) {
                    $map[$py][FINAL_X + $px] = lkMix([255, 255, 255], final_color($px, $py, null), 0.45);
                }
            }
        }
    } else {
        $map = [];
        for ($py = 0; $py < 14; $py++) {
            for ($px = 0; $px < LW; $px++) {
                if (mask_on($px, $py)) {
                    $map[$py][FINAL_X + $px] = final_color($px, $py, $glint);
                }
            }
        }
    }

    paint_halo($cells, $f);
    emit_halfblocks($cells, $map, LR0);
    paint_sparks($cells, $f);

    return $cells;
}

// ================================================================ main

$dir = __DIR__;
$frameDir = "$dir/frames";
if (!is_dir($frameDir)) {
    mkdir($frameDir, 0777, true);
}
foreach (glob("$frameDir/f-*.png") ?: [] as $old) {
    unlink($old);
}

$page = lkHex(PAGE);
$encoded = [];
for ($f = 0; $f < NFRAMES; $f++) {
    $cells = build_frame($f);
    $encoded[$f] = lkEncode($cells, 'tc');
    cells_to_png($cells, sprintf("$frameDir/f-%02d.png", $f), $page);
}

$static = $encoded[NFRAMES - 1];
[$w, $h] = lkVerify($static, 'dup/static');
$anim = lkAnim(array_slice($encoded, 0, NFRAMES - 1), $static);
file_put_contents("$dir/logo.ansi", $anim);

cells_to_png(build_frame(NFRAMES - 1), "$dir/logo-still.png", $page);

fwrite(STDERR, "static {$w}x{$h}; frames=" . NFRAMES . " ; logo.ansi " . strlen($anim) . " bytes\n");
