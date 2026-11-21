<?php

declare(strict_types=1);

/**
 * make-logo.php — "Deduplicate: the clone convergence" animated truecolor
 * ANSI logo for phpdup (php-dup-finder).
 *
 * Builds FRAMES cell-grids [glyph, fg, bg] and emits:
 *   logo.ansi             raw 24-bit ANSI animation (cat-able)
 *   frames/frame_NNNN.png per-frame crisp PNG raster (GD + DejaVu Sans Mono)
 *
 * Standalone: no dependencies on the sugarcraft tree. Run:  php make-logo.php
 */

const COLS   = 84;
const ROWS   = 14;
const FRAMES = 36;

const MARK_Y = 2;          // cell row where the big wordmark sits (rows 2..7)
const XA     = 22;         // original mark left col
const XT     = 54;         // clone parked col (pair gap = 9 cols)
const FONT   = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

// ---------------------------------------------------------------- palettes
function hx(string $h): array
{
    $h = ltrim($h, '#');
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}
function mixc(array $a, array $b, float $t): array
{
    return [(int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t)];
}
function scalec(array $a, float $k): array
{
    return [min(255, (int) round($a[0] * $k)), min(255, (int) round($a[1] * $k)), min(255, (int) round($a[2] * $k))];
}
function addc(array $a, array $b): array
{
    return [min(255, $a[0] + $b[0]), min(255, $a[1] + $b[1]), min(255, $a[2] + $b[2])];
}
function alphaOver(?array $dst, array $src, float $a): array
{
    if ($dst === null) {
        return scalec($src, $a);
    }
    return mixc($dst, $src, $a);
}

const C_TEAL   = '#2DE1A4';
const C_CYAN   = '#5CC8FF';
const C_VIOLET = '#8B5CFF';
const C_PINK   = '#E26DE8';
const C_AMBER  = '#FFC65C';
const C_BG     = '#0B0E1A';
const C_BAND   = '#0E1324';
const C_GLOW   = '#1A2242';
const C_NOISE  = '#2A3B77';
const C_FRAME  = '#3A4A80';

/** 12 px tall x 7 px wide chunky bitmaps; 1 px letter gap → mark = 23 px. */
const BIG = [
    'D' => ['#####..','#####..','##..###','##...##','##...##','##...##','##...##','##...##','##..###','#####..','#####..','##.....'],
    'U' => ['##...##','##...##','##...##','##...##','##...##','##...##','##...##','##...##','##...##','##..##.','.#####.','..###..'],
    'P' => ['#####..','######.','##..###','##...##','##...##','##..###','######.','#####..','##.....','##.....','##.....','##.....'],
];

const MARK_W = 23;

/** assembled wordmark "DUP": 12 rows of 23 '#'/'.' */
function markRows(): array
{
    $rows = [];
    for ($y = 0; $y < 12; $y++) {
        $rows[$y] = BIG['D'][$y] . '.' . BIG['U'][$y] . '.' . BIG['P'][$y];
    }
    return $rows;
}

// ------------------------------------------------------------- pixel canvas
// Canvas = 2*ROWS pixel-rows (vertical half-block resolution) x COLS px-cols.
function blankPx(): array
{
    return array_fill(0, ROWS * 2, array_fill(0, COLS, null));
}

/**
 * Blit the DUP mark (or a clipped copy) into the pixel canvas.
 *
 * @param callable(int $relX, int $relY):array $colorFn  rgb for each ink pixel
 */
function blitMark(array &$px, int $x, float $alpha, callable $colorFn, int $clipW = MARK_W, bool $add = false, int $dx = 0, int $dy = 0): void
{
    $rows = markRows();
    $yTop = MARK_Y * 2;
    foreach ($rows as $relY => $line) {
        for ($relX = 0; $relX < min($clipW, MARK_W); $relX++) {
            if ($line[$relX] !== '#') {
                continue;
            }
            $cx = $x + $relX + $dx;
            $cy = $yTop + $relY + $dy;
            if ($cx < 0 || $cx >= COLS || $cy < 0 || $cy >= ROWS * 2) {
                continue;
            }
            $c = scalec($colorFn($relX, $relY), $alpha);
            if ($add) {
                $px[$cy][$cx] = $px[$cy][$cx] === null ? $c : addc($px[$cy][$cx], $c);
            } else {
                $px[$cy][$cx] = alphaOver($px[$cy][$cx], $c, $alpha);
            }
        }
    }
}

/** horizontal 2px-thick bar (the giant amber `=` match connector) */
function blitBar(array &$px, int $x0, int $x1, int $yPx, float $alpha, array $color): void
{
    for ($x = $x0; $x <= $x1; $x++) {
        for ($y = $yPx; $y < $yPx + 2; $y++) {
            $px[$y][$x] = alphaOver($px[$y][$x], $color, $alpha);
        }
    }
}

function grad(array $stops, float $t): array
{
    $t = max(0.0, min(1.0, $t));
    $n = count($stops) - 1;
    $seg = min($n - 1, (int) ($t * $n));
    return mixc($stops[$seg], $stops[$seg + 1], $t * $n - $seg);
}

function tealColor(int $relX, int $relY): array
{   // source copy: teal -> cyan, lit top, darker base
    $c = grad([hx(C_TEAL), hx(C_CYAN)], $relX / (MARK_W - 1));
    return scalec($c, 1.10 - 0.35 * ($relY / 11));
}
function violetColor(int $relX, int $relY): array
{   // xerox clone: violet -> pink
    $c = grad([hx(C_VIOLET), hx(C_PINK)], $relX / (MARK_W - 1));
    return scalec($c, 1.08 - 0.32 * ($relY / 11));
}
function unionColor(int $relX, int $relY): array
{   // dedup survivor: teal -> cyan -> violet -> pink (the two palettes fused)
    $c = grad([hx(C_TEAL), hx(C_CYAN), hx(C_VIOLET), hx(C_PINK)], $relX / (MARK_W - 1));
    return scalec($c, 1.12 - 0.36 * ($relY / 11));
}

function hash1(float $n): float
{
    $s = sin($n) * 43758.5453123;
    return $s - floor($s);
}
function easeOut(float $t): float
{
    $t = max(0.0, min(1.0, $t));
    return 1 - (1 - $t) ** 2;
}
function easeIn(float $t): float
{
    $t = max(0.0, min(1.0, $t));
    return $t ** 1.7;
}

/** clone mark x position at frame f (null when it does not exist) */
function markBX(int $f): ?int
{
    if ($f < 10 || $f > 31) {
        return null;
    }
    if ($f <= 25) {
        $t = min(1.0, ($f - 10) / 9);
        return XA + (int) round(easeOut($t) * (XT - XA));
    }
    // dedup fold: slides home with easeIn
    $m = min(1.0, ($f - 26) / 5);
    return XA + (int) round((1 - easeIn($m)) * (XT - XA));
}

// ----------------------------------------------------------------- timeline
// F0..7   original types in (col reveal + block cursor + static noise)
// F8..9   beat, cursor blinks
// F10..19 clone stamps out, xerox-jitters, slides right with echo ghosts
// F15..25 giant amber `=` connector bars grow across the pair gap
// F18..25 finder brackets snap in + amber scan line sweeps the pair
// F20..25 "match · 100%" badge under the pair
// F26..31 dedup: A fades, B slides back additively, white flash at merge
// F32..35 survivor repainted union gradient, tagline types in, hold
function framePx(int $f): array
{
    $px = blankPx();

    // --- original mark A -----------------------------------------------------
    if ($f < 26) {
        // letter-unit type-on: D@f2, U@f4, P@f6 (cursor follows the same table)
        $revealA = $f <= 7 ? [0, 0, 8, 8, 16, 16, 23, 23][$f] : MARK_W;
        blitMark($px, XA, 1.0, 'tealColor', $revealA);
    } elseif ($f < 32) {
        $t = ($f - 26) / 5;
        if ($t < 0.999) {
            blitMark($px, XA, 1 - $t, 'tealColor');   // consumed by the fold
        }
    } else {
        blitMark($px, XA, 1.0, 'unionColor');          // the survivor
    }

    // --- clone mark B ----------------------------------------------------------
    $xB = markBX($f);
    if ($xB !== null) {
        // echo ghosts trailing the slide (xerox after-images), lagging in frames
        foreach ([[2, 0.22], [4, 0.11]] as [$lag, $ga]) {
            $gx = markBX($f - $lag);
            if ($gx !== null && $gx !== $xB) {
                blitMark($px, $gx, $ga, 'violetColor', MARK_W, true);
            }
        }
        // chromatic-aberration xerox jitter on selected slide frames
        if (in_array($f, [12, 15, 17], true)) {
            blitMark($px, $xB, 0.32, static fn(): array => [255, 79, 216], MARK_W, true, 1, 0);
            blitMark($px, $xB, 0.32, static fn(): array => [79, 200, 255], MARK_W, true, -1, 0);
        }
        blitMark($px, $xB, 1.0, 'violetColor');
    }

    // --- amber `=` match connector ----------------------------------------------
    if ($f >= 15 && $f <= 25) {
        $a    = min(1.0, ($f - 15) / 2) * ($f >= 24 ? 0.55 : 1.0);
        $gapL = XA + MARK_W + 1;
        $gapR = $f >= 20 ? XT - 1 : max($gapL, ($xB ?? XT) - 1);
        blitBar($px, $gapL, $gapR, 8, $a, hx(C_AMBER));
        blitBar($px, $gapL, $gapR, 12, $a, hx(C_AMBER));
    }

    // --- white merge flash -------------------------------------------------------
    if ($f >= 28 && $f <= 30) {
        $fa = [28 => 0.35, 29 => 0.9, 30 => 0.55][$f];
        blitMark($px, XA, $fa, static fn(): array => [255, 255, 255], MARK_W, true);
    }

    return $px;
}

// ------------------------------------------------------------ cell compose
function composeCells(int $f): array
{
    $px    = framePx($f);
    $cells = [];

    $xB    = markBX($f);
    $scanX = null;
    if ($f >= 18 && $f <= 25) {
        $st    = ($f - 18) / 7;
        $scanX = (int) round(XA - 3 + easeOut($st) * (XT + MARK_W + 3 - (XA - 3)));
    }

    for ($r = 0; $r < ROWS; $r++) {
        for ($c = 0; $c < COLS; $c++) {
            $bg = hx(C_BG);
            if ($r >= MARK_Y && $r < MARK_Y + 6) {
                $bg = hx(C_BAND);
            }
            if ($r >= 1 && $r <= 8 && $f >= 32) {
                $bg = mixc($bg, hx(C_GLOW), 0.22);          // survivor aura
            }
            if ($scanX !== null && $r >= 1 && $r <= 8) {
                $d = $c - $scanX;
                $bg = mixc($bg, hx(C_GLOW), 0.75 * exp(-($d * $d) / 26));
            }
            $top = $px[2 * $r][$c];
            $bot = $px[2 * $r + 1][$c];

            if ($top === null && $bot === null) {
                $cells[$r][$c] = [' ', null, $bg];
            } elseif ($top !== null && $bot === null) {
                $cells[$r][$c] = ['▀', $top, $bg];
            } elseif ($top === null && $bot !== null) {
                $cells[$r][$c] = ['▄', $bot, $bg];
            } elseif ($top === $bot) {
                $cells[$r][$c] = ['█', $top, $bg];
            } else {
                $cells[$r][$c] = ['▄', $bot, $top];         // two-tone row
            }
        }
    }

    // --- static noise while typing on -------------------------------------------
    if ($f <= 7) {
        $pool = '{}[]<>=/\\|;:01*xz$q%&';
        $plen = strlen($pool);
        for ($r = 1; $r < ROWS - 1; $r++) {
            for ($c = 0; $c < COLS; $c++) {
                if ($cells[$r][$c][0] !== ' ') {
                    continue;
                }
                if (hash1($c * 127.1 + $r * 311.7 + $f * 74.7) < 0.055) {
                    $cells[$r][$c][0] = $pool[(int) (hash1($c * 3.3 + $r * 7.7) * $plen)];
                    $cells[$r][$c][1] = scalec(hx(C_NOISE), 0.5 + 0.6 * hash1($c * 13.7 + $r * 9.2 + $f));
                }
            }
        }
    }

    // --- block cursor during type-on ---------------------------------------------
    if ($f <= 9) {
        $reveal = $f <= 7 ? [0, 0, 8, 8, 16, 16, 23, 23][$f] : MARK_W;
        $show   = $f >= 8 ? ($f % 2 === 0) : true;
        if ($show) {
            $cells[MARK_Y + 5][XA + $reveal] = ['▄', hx(C_TEAL), $cells[MARK_Y + 5][XA + $reveal][2]];
        }
    }

    // --- finder brackets + scan line -------------------------------------------
    if ($f >= 18 && $f <= 31) {
        $amber = hx(C_AMBER);
        $xL    = XA - 2;
        $xR    = ($xB ?? XT) + MARK_W + 1;
        $out   = $f >= 26 ? ($f - 26) * 3 : 0;              // brackets fly off on merge
        if ($out < 8) {
            $dimd = $f >= 26 ? 0.5 : 1.0;
            for ($r = MARK_Y; $r < MARK_Y + 6; $r++) {
                $cells[$r][$xL - $out] = ['▐', scalec($amber, $dimd), $cells[$r][$xL - $out][2]];
                $cells[$r][$xR + $out] = ['▌', scalec($amber, $dimd), $cells[$r][$xR + $out][2]];
            }
        }
        if ($scanX !== null && $scanX >= 1 && $scanX < COLS - 1) {
            for ($r = 1; $r <= 8; $r++) {
                $lit = $r >= 2 && $r <= 7;
                $cells[$r][$scanX] = ['▌', scalec($amber, $lit ? 1.0 : 0.45), $cells[$r][$scanX][2]];
            }
        }
    }

    // --- match badge ------------------------------------------------------------
    if ($f >= 20 && $f <= 25) {
        $ab    = min(1.0, ($f - 20) / 2);
        $badge = 'match · 100%';
        $bx    = intdiv(XA + MARK_W + XT - mb_strlen($badge), 2) + 1;
        $col   = mixc(hx(C_BG), hx(C_AMBER), 0.25 + 0.75 * $ab);
        writeText($cells, $badge, $bx, 9, $col, null);
    }

    // --- terminal window dressing -----------------------------------------------
    if ($f >= 2) {
        writeFrameRow($cells, 0, '┌', '── phpdup · AST clone detection ──', '┐', hx(C_FRAME));
        writeFrameRow($cells, 13, '└', '─$ phpdup analyze src/ ─', '┘', hx(C_FRAME));
    }

    // --- tagline types in at the end ----------------------------------------------
    if ($f >= 32) {
        $chars   = mb_str_split('› php-dup-finder');   // glyph-per-cell: MB-safe
        $n       = count($chars);
        $reveal  = (int) round($n * min(1.0, ($f - 31) / 4));
        $tx      = XA + intdiv(MARK_W, 2) - intdiv($n, 2) - 2;
        for ($i = 0; $i < $reveal; $i++) {
            $col = grad([hx(C_TEAL), hx(C_CYAN), hx(C_VIOLET)], $i / max(1, $n - 1));
            $cells[9][$tx + $i] = [$chars[$i], $col, $cells[9][$tx + $i][2]];
        }
        if ($f % 2 === 0 && $reveal >= $n) {
            $cells[9][$tx + $n] = ['▌', hx(C_PINK), $cells[9][$tx + $n][2]];
        }
    }

    return $cells;
}

function writeText(array &$cells, string $s, int $x, int $r, array $fg, ?array $bg): void
{
    foreach (mb_str_split($s) as $i => $ch) {
        if ($x + $i >= 0 && $x + $i < COLS && $r >= 0 && $r < ROWS) {
            $cells[$r][$x + $i] = [$ch, $fg, $bg];
        }
    }
}

function writeFrameRow(array &$cells, int $r, string $cornerL, string $label, string $cornerR, array $col): void
{
    $inner = COLS - 2;
    $lbl   = mb_str_pad($label, $inner, '─', STR_PAD_BOTH);
    foreach (mb_str_split($cornerL . $lbl . $cornerR) as $i => $ch) {
        $cells[$r][$i] = [$ch, $col, hx(C_BG)];
    }
}

// ----------------------------------------------------------------- encoders
function encodeAnsi(array $framesCells): string
{
    $out = "\e[0m\e[?25l";
    foreach ($framesCells as $fi => $cells) {
        if ($fi > 0) {
            $out .= "\e[" . ROWS . "A\r";
        }
        foreach ($cells as $row) {
            $cf = null;
            $cb = null;
            $out .= "\e[0m";
            foreach ($row as [$g, $fg, $bg]) {
                if ($fg !== $cf) {
                    $out .= $fg === null ? "\e[39m" : sprintf("\e[38;2;%d;%d;%dm", ...$fg);
                    $cf = $fg;
                }
                if ($bg !== $cb) {
                    $out .= $bg === null ? "\e[49m" : sprintf("\e[48;2;%d;%d;%dm", ...$bg);
                    $cb = $bg;
                }
                $out .= $g;
            }
            $out .= "\n";
        }
    }
    return $out . "\e[0m\e[?25h";
}

function renderPng(array $cells, string $path, int $cw = 14, int $ch = 28): void
{
    $im    = imagecreatetruecolor(COLS * $cw, ROWS * $ch);
    $col   = static fn (array $c): int => imagecolorallocate($im, $c[0], $c[1], $c[2]);
    $defBg = hx(C_BG);
    $defFg = [229, 229, 229];
    $size  = $ch * 0.62;

    foreach ($cells as $r => $row) {
        foreach ($row as $c => [$g, $fg, $bg]) {
            $x  = $c * $cw;
            $y  = $r * $ch;
            $bc = $bg ?? $defBg;
            $fc = $fg ?? $defFg;
            switch ($g) {
                case '█':
                    imagefilledrectangle($im, $x, $y, $x + $cw - 1, $y + $ch - 1, $col($fc));
                    break;
                case '▀':
                    imagefilledrectangle($im, $x, $y, $x + $cw - 1, (int) ($y + $ch / 2) - 1, $col($fc));
                    imagefilledrectangle($im, $x, $y + intdiv($ch, 2), $x + $cw - 1, $y + $ch - 1, $col($bc));
                    break;
                case '▄':
                    imagefilledrectangle($im, $x, $y, $x + $cw - 1, $y + $ch - 1, $col($bc));
                    imagefilledrectangle($im, $x, $y + intdiv($ch, 2), $x + $cw - 1, $y + $ch - 1, $col($fc));
                    break;
                case '▌':
                    imagefilledrectangle($im, $x, $y, $x + $cw - 1, $y + $ch - 1, $col($bc));
                    imagefilledrectangle($im, $x, $y, $x + intdiv($cw, 2) - 1, $y + $ch - 1, $col($fc));
                    break;
                case '▐':
                    imagefilledrectangle($im, $x, $y, $x + $cw - 1, $y + $ch - 1, $col($bc));
                    imagefilledrectangle($im, $x + intdiv($cw, 2), $y, $x + $cw - 1, $y + $ch - 1, $col($fc));
                    break;
                case ' ':
                    imagefilledrectangle($im, $x, $y, $x + $cw - 1, $y + $ch - 1, $col($bc));
                    break;
                default:
                    imagefilledrectangle($im, $x, $y, $x + $cw - 1, $y + $ch - 1, $col($bc));
                    imagettftext($im, $size, 0, $x + 1, (int) ($y + $ch * 0.78), $col($fc), FONT, $g);
            }
        }
    }
    imagepng($im, $path);
    imagedestroy($im);
}

// --------------------------------------------------------------------- main
$dir = __DIR__;
@mkdir("$dir/frames", 0777, true);
$cells = [];
for ($f = 0; $f < FRAMES; $f++) {
    $cells[$f] = composeCells($f);
    renderPng($cells[$f], sprintf('%s/frames/frame_%04d.png', $dir, $f));
}
file_put_contents("$dir/logo.ansi", encodeAnsi($cells));
fwrite(STDERR, sprintf("wrote logo.ansi (%d frames, %dx%d cells, %d bytes) + %d PNGs\n",
    FRAMES, COLS, ROWS, filesize("$dir/logo.ansi"), FRAMES));
