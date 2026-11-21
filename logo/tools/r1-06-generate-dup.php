<?php

declare(strict_types=1);

/**
 * generate-dup — "MIRROR MATCH" animated truecolor ANSI logo for phpdup.
 *
 * 3 acts in 36 frames: a cyan scanner sweep reveals the DUP wordmark
 * (violet→magenta half-block gradient under an AST branch tree), a flickering
 * red clone materializes offset to the right, then slides home; on registration
 * the wordmark flashes and the caption flips to a green diff `+` match line.
 *
 * Outputs: logo.ansi (playable animation) + frames/fNN.png per frame.
 * Requires logo-kit.php (candy-top toolkit copy). Stitch with build.sh.
 */

require __DIR__ . '/logo-kit.php';

/* ================================================================ canvas */

const COLS = 92;
const ROWS = 17;
const FRAMES = 36;               // 0..35
const PAGE_BG = [11, 8, 18];
const FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
const CELL_W = 10;
const CELL_H = 20;

/* wordmark geometry */
const X0 = 27;                   // first pixel/col of the word
const Y_TOP_PX = 14;             // pixel row of word top (cell row 7)
const WORD_W = 38;               // 3 letters ×10 + 2 gaps ×4
const WORD_H = 14;               // pixel rows (=7 cells)
const MAX_OFF = 9;               // clone offset start (px cols)
const GRID_W = WORD_W + MAX_OFF; // big grid width incl. clone travel

/* phase boundaries */
const A_END = 11;                // reveal   f0..11
const B_END = 17;                // clone in f12..17
const C_END = 25;                // slide    f18..25  (off→0)
const FLASH = 26;                // white pulse
const SETTLE = 27;               // blend settle
const HOLD_END = 33;             // final state, cursor blink
const FADE = 34;                 // loop fade-out (f35 = f0)

/* ================================================================ palette */

const VIOLET = ['#6D7CC4', '#8B5CF6', '#A855F7', '#E14BFF'];  // base ramp
const CLONE_WARM = ['#FF7A59', '#F5A623', '#EF4444'];         // clone ramp
const CYAN = [34, 211, 238];
const GHOST_K = 0.07;

const C_DIM = [87, 80, 114];
const C_DARK = [63, 58, 90];
const C_TREE_DIM = [51, 43, 74];
const C_AMBER = [251, 191, 36];
const C_RED = [255, 87, 87];
const C_GREEN = [74, 222, 128];
const C_WHITE = [255, 255, 255];

/* ================================================================ word mask */

/** @return list<list<int>> WORD_H rows × WORD_W cols of 0/1 */
function wordMask(): array
{
    $m = [
        'D' => ['####.', '#...#', '#...#', '#...#', '#...#', '#...#', '####.'],
        'U' => ['#...#', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
        'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
    ];
    $gap = '..';                    // 2 native cols → 4 scaled px
    $native = array_fill(0, 7, '');
    foreach (['D', 'U', 'P'] as $i => $ch) {
        foreach ($m[$ch] as $y => $row) {
            $native[$y] .= $i > 0 ? $gap . $row : $row;
        }
    }
    $out = [];
    foreach ($native as $y => $row) {
        for ($k = 0; $k < 2; $k++) {
            $r = [];
            foreach (str_split($row) as $px) {
                $v = $px === '#' ? 1 : 0;
                $r[] = $v;
                $r[] = $v;
            }
            $out[] = $r;
        }
    }
    return $out;
}

/* ================================================================ per-frame params */

function easeInOut(float $t): float
{
    return $t < 0.5 ? 2 * $t * $t : 1 - (-2 * $t + 2) ** 2 / 2;
}

/** @return array{scanX:float,beam:bool,cloneOp:float,cloneOff:int,skipP:float,flashMix:float,baseLit:bool,phase:int} */
function frameParams(int $f): array
{
    if ($f <= A_END) {
        return [
            'scanX' => 24 + 44 * $f / A_END, 'beam' => true,
            'cloneOp' => 0, 'cloneOff' => MAX_OFF, 'skipP' => 0, 'flashMix' => 0,
            'baseLit' => false, 'phase' => 0,
        ];
    }
    if ($f <= B_END) {
        return [
            'scanX' => 1000, 'beam' => false,
            'cloneOp' => ($f - A_END) / (B_END - A_END), 'cloneOff' => MAX_OFF,
            'skipP' => 0, 'flashMix' => 0, 'baseLit' => true, 'phase' => 1,
        ];
    }
    if ($f <= C_END) {
        $t = ($f - B_END - 1) / (C_END - B_END - 1);
        return [
            'scanX' => 1000, 'beam' => false, 'cloneOp' => 1,
            'cloneOff' => (int) round(MAX_OFF * (1 - easeInOut($t))),
            'skipP' => max(0.0, 0.26 - 0.065 * ($f - B_END - 1)), 'flashMix' => 0,
            'baseLit' => true, 'phase' => 2,
        ];
    }
    if ($f === FLASH) {
        return ['scanX' => 1000, 'beam' => false, 'cloneOp' => 1, 'cloneOff' => 0, 'skipP' => 0, 'flashMix' => 0.85, 'baseLit' => true, 'phase' => 3];
    }
    if ($f === SETTLE) {
        return ['scanX' => 1000, 'beam' => false, 'cloneOp' => 1, 'cloneOff' => 0, 'skipP' => 0, 'flashMix' => 0.3, 'baseLit' => true, 'phase' => 3];
    }
    if ($f <= HOLD_END) {
        return ['scanX' => 1000, 'beam' => false, 'cloneOp' => 0, 'cloneOff' => 0, 'skipP' => 0, 'flashMix' => 0, 'baseLit' => true, 'phase' => 4];
    }
    return ['scanX' => 1000, 'beam' => false, 'cloneOp' => 0, 'cloneOff' => 0, 'skipP' => 0, 'flashMix' => 0, 'baseLit' => true, 'phase' => 5]; // FADE
}

/* ================================================================ pixel colouring */

/** Shaded gradient word pixel; null = outside glyph. */
function shadePixel(array $c, int $y): array
{
    $c = lkShade($c, 0.18 - 0.40 * ($y / (WORD_H - 1)));
    if ($y % 2 === 1) {
        $c = lkScale($c, 0.9);      // CRT scanline texture
    }
    return $c;
}

/** Base DUP pixel at word coords (x,y). */
function basePixel(array $word, int $x, int $y, array $p): ?array
{
    if (!$word[$y][$x]) {
        return null;
    }
    $c = shadePixel(lkGradient(VIOLET, $x / (WORD_W - 1)), $y);
    if ($p['baseLit']) {
        return $p['flashMix'] > 0 ? lkMix($c, C_WHITE, $p['flashMix']) : $c;
    }
    $g = X0 + $x;
    $sx = (int) $p['scanX'];
    if ($g < $sx) {
        return $c;
    }
    if ($g === $sx) {
        return lkMix($c, [225, 255, 255], 0.85);
    }
    if ($g === $sx + 1) {
        return lkMix($c, CYAN, 0.5);
    }
    return lkScale($c, GHOST_K);
}

/** Clone DUP pixel at word coords (x,y); null = hidden (flicker). */
function clonePixel(array $word, int $x, int $y, int $f, array $p): ?array
{
    if (!$word[$y][$x] || $p['cloneOp'] <= 0) {
        return null;
    }
    $skip = $p['phase'] === 1 ? (1 - $p['cloneOp']) * 0.9 : $p['skipP'];
    if ($skip > 0 && lkNoise($x, $y, $f) < $skip) {
        return null;
    }
    $c = shadePixel(lkGradient(CLONE_WARM, $x / (WORD_W - 1)), $y);
    if ($p['phase'] >= 3) {
        return lkScale($c, 0.85);     // registered: steady, noise-free
    }
    $bright = 0.62 + 0.5 * lkNoise($x, $y, $f + 91);
    return lkScale($c, min(1.15, $bright));
}

/* ================================================================ frame assembly */

/** @return list<list<array{string,array|null,array|null}>> ROWS×COLS cell grid */
function frameCells(int $f, array $word): array
{
    $p = frameParams($f);
    $cells = lkBlankCells(COLS, ROWS, null);

    /* ---- row 0: chrome ---- */
    segText($cells, 0, 1, [
        ['phpdup', C_DIM],
        [' · AST duplicate-logic finder', [70, 66, 92]],
    ], COLS);
    segText($cells, 0, 74, [['--scorer=ast', [79, 73, 106]]], COLS);

    /* ---- rows 3-5: AST tree (● / ┌─┴─┐ / │) ---- */
    $treeLit = $p['baseLit'] || $p['beam'];
    foreach ([3 => [45 => '●'], 4 => [32 => '┌', 59 => '┐', 45 => '┴'], 5 => [32 => '│', 45 => '│', 59 => '│']] as $row => $marks) {
        foreach ($marks as $col => $g) {
            $lit = $col < $p['scanX'] + 1;
            lkPut($cells, $col, $row, $g, $lit && $treeLit ? [139, 92, 246] : C_TREE_DIM);
        }
    }
    for ($col = 33; $col <= 58; $col++) {
        if ($col === 45) {
            continue;
        }
        $lit = $col < $p['scanX'] + 1 && $treeLit;
        lkPut($cells, $col, 4, '─', $lit ? [109, 92, 180] : C_TREE_DIM);
    }

    /* ---- rows 7-13: wordmark (base + travelling clone) ---- */
    $bigPx = [];
    for ($y = 0; $y < WORD_H; $y++) {
        $row = [];
        for ($b = 0; $b < GRID_W; $b++) {
            $bc = $b < WORD_W ? basePixel($word, $b, $y, $p) : null;
            $cc = null;
            if ($b >= $p['cloneOff'] && $b - $p['cloneOff'] < WORD_W) {
                $cc = clonePixel($word, $b - $p['cloneOff'], $y, $f, $p);
            }
            $row[] = match (true) {
                $bc !== null && $cc !== null => lkMix($bc, $cc, $p['phase'] >= 3 ? 0.35 : 0.55),
                $bc !== null => $bc,
                default => $cc,
            };
        }
        $bigPx[] = $row;
    }
    $letterCells = lkHalfBlock($bigPx);
    foreach ($letterCells as $r => $crow) {
        foreach ($crow as $i => [$g, $fg, $bg]) {
            if ($g !== ' ') {
                lkPut($cells, X0 + $i, 7 + $r, $g, $fg, $bg);
            }
        }
    }

    /* ---- col 18: diff gutter (+± marks) ---- */
    $gutter = null;
    if ($p['cloneOp'] > 0 && $f <= C_END) {
        $gutter = C_RED;
    } elseif ($f >= FLASH && $f <= FADE) {
        $gutter = C_GREEN;
    }
    if ($gutter !== null) {
        foreach ([7, 10, 13] as $r) {
            lkPut($cells, 18, $r, '+', $gutter);
        }
    }

    /* ---- beam: vertical scan stripe behind/around the word ---- */
    if ($p['beam']) {
        $sx = (int) $p['scanX'];
        foreach ([$sx, $sx + 1] as $k => $bx) {
            for ($r = 2; $r <= 15; $r++) {
                if ($bx >= 0 && $bx < COLS && $cells[$r][$bx][0] === ' ' && $cells[$r][$bx][2] === null) {
                    lkPut($cells, $bx, $r, ' ', null, lkMix(PAGE_BG, CYAN, $k === 0 ? 0.30 : 0.12));
                }
            }
        }
    }

    /* ---- row 14: scan progress bar ---- */
    $pct = $p['beam'] ? max(0.0, min(1.0, ($p['scanX'] - 24) / 44))
        : ($p['phase'] === 2 ? 1 - $p['cloneOff'] / MAX_OFF : 1.0);
    if ($p['phase'] <= 1) {
        $pct = $p['beam'] ? $pct : 1.0;
    }
    if ($p['phase'] === 0) {
        $barStops = ['#22D3EE', '#3B82F6'];
    } elseif ($p['phase'] === 4) {
        $barStops = ['#4ADE80', '#22C55E'];
    } else {
        $barStops = ['#F59E0B', '#EF4444'];
    }
    $label = match (true) {
        $p['phase'] === 0 => 'SCAN',
        $p['phase'] === 1 => 'CLONE',
        $p['phase'] === 2 => 'MERGE',
        $p['phase'] >= 4 => ' DONE',
        default => 'MERGE',
    };
    segText($cells, 14, 19, [[$label, lkScale([34, 211, 238], 0.55)]], COLS);
    lkPut($cells, 26, 14, '[', C_DARK);
    lkPut($cells, 65, 14, ']', C_DARK);
    $filled = (int) floor($pct * WORD_W);
    for ($i = 0; $i < WORD_W; $i++) {
        $col = X0 + $i;
        if ($i < $filled) {
            lkPut($cells, $col, 14, '█', lkGradient($barStops, $i / (WORD_W - 1)));
        } else {
            lkPut($cells, $col, 14, '░', [42, 36, 64]);
        }
    }
    $pctTxt = sprintf('%3d%%', (int) round($pct * 100));
    segText($cells, 14, 67, [[$pctTxt, $barStops[0] === '#22D3EE' ? CYAN : lkHex($barStops[0])]], COLS);

    /* ---- row 15: caption strip ---- */
    buildCaption($cells, $f, $p);

    /* ---- loop fade-out: dim everything on FADE frame ---- */
    if ($f === FADE) {
        for ($r = 0; $r < ROWS; $r++) {
            for ($c = 0; $c < COLS; $c++) {
                $cells[$r][$c][1] = $cells[$r][$c][1] !== null ? lkScale($cells[$r][$c][1], 0.42) : null;
                $cells[$r][$c][2] = $cells[$r][$c][2] !== null ? lkScale($cells[$r][$c][2], 0.42) : null;
            }
        }
    }

    return $cells;
}

/** Place colour segments starting at (col,row), trimming to $limit chars. */
function segText(array &$cells, int $row, int $col, array $segs, int $limit): void
{
    $remaining = max(0, $limit - $col);
    foreach ($segs as [$str, $fg]) {
        foreach (mb_str_split($str) as $ch) {
            if ($remaining-- <= 0 || $col >= COLS) {
                return;
            }
            lkPut($cells, $col++, $row, $ch, $fg);
        }
    }
}

function buildCaption(array &$cells, int $f, array $p): void
{
    if ($p['phase'] === 0) {
        $n = 1 + (int) round(20 * min(1.0, $f / 9));
        $segs = [['$ ', [86, 80, 120]], ['phpdup', [183, 155, 255]], [' analyze src/', [142, 138, 168]]];
        segText($cells, 15, 20, $segs, 20 + $n);
        if ($f >= 9 && $f % 2 === 1) {
            lkPut($cells, 20 + 21, 15, '▮', CYAN);
        }
        return;
    }
    if ($p['phase'] === 1) {
        segText($cells, 15, 20, [
            ['2 similar blocks · comparing ASTs', C_AMBER],
            [str_repeat('.', 1 + $f % 3), [166, 127, 24]],
        ], COLS);
        return;
    }
    if ($p['phase'] === 2) {
        segText($cells, 15, 20, [[
            sprintf('aligning duplicate · %3d%%', (int) round((1 - $p['cloneOff'] / MAX_OFF) * 100)),
            C_AMBER,
        ]], COLS);
        return;
    }
    if ($f === FLASH || $f === SETTLE) {
        segText($cells, 15, 20, [['+ CLONE FOUND — 100% MATCH', C_WHITE]], COLS);
        return;
    }
    /* hold + fade: green diff line with blinking cursor */
    $segs = [
        ['+ duplicate cluster · 100% similarity', C_GREEN],
        [' · 2 blocks', [99, 166, 123]],
    ];
    segText($cells, 15, 20, $segs, COLS);
    if ($f <= HOLD_END && $f % 2 === 0) {
        $len = array_sum(array_map(static fn (array $s): int => mb_strlen($s[0]), $segs));
        lkPut($cells, min(COLS - 1, 20 + $len + 1), 15, '▮', C_GREEN);
    }
}

/* ================================================================ PNG renderer */

/** Draw one cell's glyph; block/box glyphs are vectorised for seamless joins. */
function drawGlyph($im, string $g, int $x0, int $y0, array $col): void
{
    $c = imagecolorallocate($im, $col[0], $col[1], $col[2]);
    $cw = CELL_W;
    $ch = CELL_H;
    $midX = $x0 + intdiv($cw, 2);
    $midY = $y0 + intdiv($ch, 2);
    switch ($g) {
        case '█':
            imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $c);
            return;
        case '▀':
            imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + intdiv($ch, 2) - 1, $c);
            return;
        case '▄':
            imagefilledrectangle($im, $x0, $y0 + intdiv($ch, 2), $x0 + $cw - 1, $y0 + $ch - 1, $c);
            return;
        case '░': {
            $dim = imagecolorallocate($im, (int) ($col[0] * .55), (int) ($col[1] * .55), (int) ($col[2] * .55));
            for ($dy = 1; $dy < $ch; $dy += 4) {
                for ($dx = 1; $dx < $cw; $dx += 4) {
                    imagesetpixel($im, $x0 + $dx, $y0 + $dy, $dim);
                }
            }
            return;
        }
        case '─':
            imagefilledrectangle($im, $x0, $midY, $x0 + $cw - 1, $midY + 1, $c);
            return;
        case '│':
            imagefilledrectangle($im, $midX, $y0, $midX + 1, $y0 + $ch - 1, $c);
            return;
        case '┌':
            imagefilledrectangle($im, $midX, $midY, $x0 + $cw - 1, $midY + 1, $c);
            imagefilledrectangle($im, $midX, $midY, $midX + 1, $y0 + $ch - 1, $c);
            return;
        case '┐':
            imagefilledrectangle($im, $x0, $midY, $midX, $midY + 1, $c);
            imagefilledrectangle($im, $midX, $midY, $midX + 1, $y0 + $ch - 1, $c);
            return;
        case '┴':
            imagefilledrectangle($im, $x0, $midY, $x0 + $cw - 1, $midY + 1, $c);
            imagefilledrectangle($im, $midX, $y0, $midX + 1, $midY + 1, $c);
            return;
        case '●': {
            $r = (int) round($cw * 0.32);
            imagefilledellipse($im, $midX, $midY, 2 * $r + 1, 2 * $r + 1, $c);
            return;
        }
        case '▮':
            imagefilledrectangle($im, $x0 + 2, $y0 + 3, $x0 + $cw - 3, $y0 + $ch - 3, $c);
            return;
    }
    static $bbox = [];
    $size = $ch * 0.62;
    if (!isset($bbox[$g])) {
        $b = imagettfbbox($size, 0, FONT_PATH, $g);
        $bbox[$g] = [$b[2] - $b[0], $b[0]];
    }
    [$w, $xoff] = $bbox[$g];
    imagettftext($im, $size, 0, (int) ($x0 + ($cw - $w) / 2 - $xoff), (int) ($y0 + $ch * 0.78), $c, FONT_PATH, $g);
}

function renderPng(array $cells, string $path): void
{
    $im = imagecreatetruecolor(COLS * CELL_W, ROWS * CELL_H);
    $bg = imagecolorallocate($im, PAGE_BG[0], PAGE_BG[1], PAGE_BG[2]);
    imagefilledrectangle($im, 0, 0, COLS * CELL_W, ROWS * CELL_H, $bg);
    foreach ($cells as $r => $row) {
        foreach ($row as $c => [$g, $fg, $bgc]) {
            $x0 = $c * CELL_W;
            $y0 = $r * CELL_H;
            if ($bgc !== null) {
                imagefilledrectangle($im, $x0, $y0, $x0 + CELL_W - 1, $y0 + CELL_H - 1,
                    imagecolorallocate($im, $bgc[0], $bgc[1], $bgc[2]));
            }
            if ($g !== ' ' && $fg !== null) {
                drawGlyph($im, $g, $x0, $y0, $fg);
            }
        }
    }
    imagepng($im, $path);
    imagedestroy($im);
}

/* ================================================================ main */

$word = wordMask();
@mkdir(__DIR__ . '/frames', 0o777, true);
$encoded = [];
$staticFrame = '';
for ($f = 0; $f < FRAMES - 1; $f++) {
    $cells = frameCells($f, $word);
    $enc = lkEncode($cells, 'tc');
    $encoded[] = $enc;
    if ($f === HOLD_END) {
        $staticFrame = $enc;
    }
    renderPng($cells, __DIR__ . "/frames/f" . sprintf('%02d', $f) . ".png");
}
/* f35 mirrors f0 for a seamless GIF loop */
renderPng(frameCells(0, $word), __DIR__ . '/frames/f35.png');

file_put_contents(__DIR__ . '/logo.ansi', lkAnim($encoded, $staticFrame));
[$w, $h] = lkVerify(lkLastFrame(file_get_contents(__DIR__ . '/logo.ansi')), 'dup/tc');
fwrite(STDERR, "logo.ansi ok {$w}x{$h}, " . filesize(__DIR__ . '/logo.ansi') . " bytes, " . FRAMES . " gif frames\n");
