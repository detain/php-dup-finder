<?php

declare(strict_types=1);

/**
 * ansi-anim-to-png — rasterize EVERY frame of a truecolor ANSI animation to PNG.
 *
 * Splits the animation on the frame separator \e[<H>A\r (as produced by
 * logo-kit.php's lkAnim), then renders each frame as a cell grid with GD using
 * a real monospace TTF (DejaVu Sans Mono) — the same approach as candy-top's
 * ansi-ttf-preview.php, but block glyphs (█ ▀ ▄ ▌ ▐ ░ ▒ ▓) are drawn as exact
 * rectangle fills instead of TTF glyphs: seam-free at animation frame rates.
 *
 *   php ansi-anim-to-png.php <in.ansi> <outdir> [cellW=10] [cellH=20] [font]
 */

const DEF_FG = [229, 229, 229];

require __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php ansi-anim-to-png.php <in.ansi> <outdir> [cellW] [cellH] [font.ttf]\n");
$outDir = $argv[2] ?? 'frames';
$cw = (int) ($argv[3] ?? 10);
$ch = (int) ($argv[4] ?? 20);
$font = $argv[5] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    exit("cannot create $outDir\n");
}
foreach (glob("$outDir/frame_*.png") ?: [] as $stale) {
    unlink($stale);
}

$pal16 = lkAnsi16();
$x256 = lkXterm256();
foreach ([30, 31, 32, 33, 34, 35, 36, 37] as $i => $c) {
    $x256[$i] = $pal16[$c];
    $x256[$i + 8] = $pal16[$c + 60];
}

/** ANSI SGR text -> cell grid rows of [glyph, fg, bg] (bg null = default). */
function parseFrame(string $frame): array
{
    global $pal16, $x256;
    $grid = [];
    foreach (explode("\n", rtrim($frame, "\n")) as $y => $line) {
        $fg = DEF_FG;
        $bg = null;
        $x = 0;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0) {
                        $fg = DEF_FG;
                        $bg = null;
                    } elseif ($v === 39) {
                        $fg = DEF_FG;
                    } elseif ($v === 49) {
                        $bg = null;
                    } elseif (($v >= 30 && $v <= 37) || ($v >= 90 && $v <= 97)) {
                        $fg = $pal16[$v];
                    } elseif (($v >= 40 && $v <= 47) || ($v >= 100 && $v <= 107)) {
                        $bg = $pal16[$v - 10];
                    } elseif ($v === 38 || $v === 48) {
                        if (($p[$i + 1] ?? 0) === 5) {
                            $c = $x256[$p[$i + 2]];
                            $i += 2;
                        } else {
                            $c = [$p[$i + 2], $p[$i + 3], $p[$i + 4]];
                            $i += 4;
                        }
                        $v === 38 ? $fg = $c : $bg = $c;
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $grid[$y][$x] = [$g, $fg, $bg];
                $x += max(1, mb_strwidth($g));
            }
        }
    }
    return $grid;
}

$anim = preg_replace('/\e\[\?25[hl]/', '', file_get_contents($in));
$chunks = preg_split('/\e\[\d+A\r/', $anim);
if (count($chunks) <= 1 && preg_match('/\e\[0m\n\e\[0m\n/', $anim)) {
    // not a cursor-up animation: treat as one static frame
    $chunks = [$anim];
}

$size = $ch * 0.72;
$made = 0;
foreach ($chunks as $i => $frame) {
    $grid = parseFrame($frame);
    $w = max(array_map(static fn (array $r): int => max(array_keys($r)) + 1, $grid));
    $h = count($grid);
    $im = imagecreatetruecolor($w * $cw, $h * $ch);
    $memo = [];
    $col = static function (array $c) use ($im, &$memo): int {
        $k = "{$c[0]},{$c[1]},{$c[2]}";
        return $memo[$k] ??= imagecolorallocate($im, $c[0], $c[1], $c[2]);
    };
    foreach ($grid as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg]) {
            $X = $x * $cw;
            $Y = $y * $ch;
            if ($bg !== null) {
                imagefilledrectangle($im, $X, $Y, $X + $cw - 1, $Y + $ch - 1, $col($bg));
            }
            if ($g === ' ') {
                continue;
            }
            match ($g) {
                '█' => imagefilledrectangle($im, $X, $Y, $X + $cw - 1, $Y + $ch - 1, $col($fg)),
                '▀' => imagefilledrectangle($im, $X, $Y, $X + $cw - 1, $Y + intdiv($ch, 2) - 1, $col($fg)),
                '▄' => imagefilledrectangle($im, $X, $Y + intdiv($ch, 2), $X + $cw - 1, $Y + $ch - 1, $col($fg)),
                '▌' => imagefilledrectangle($im, $X, $Y, $X + intdiv($cw, 2) - 1, $Y + $ch - 1, $col($fg)),
                '▐' => imagefilledrectangle($im, $X + intdiv($cw, 2), $Y, $X + $cw - 1, $Y + $ch - 1, $col($fg)),
                '▓' => quadFill($im, $X, $Y, $cw, $ch, $col($fg), 3),
                '▒' => quadFill($im, $X, $Y, $cw, $ch, $col($fg), 2),
                '░' => quadFill($im, $X, $Y, $cw, $ch, $col($fg), 1),
                default => imagettftext($im, $size, 0, $X, (int) ($Y + $ch * 0.78), $col($fg), $font, $g),
            };
        }
    }
    imagepng($im, sprintf("%s/frame_%03d.png", $outDir, $i));
    imagedestroy($im);
    $made++;
}
echo "rendered $made frames ({$w}x{$h} cells, {$cw}x{$ch}px) into $outDir\n";

/** Bayer-style density fill: level 1=25% 2=50% 3=75% coverage. */
function quadFill($im, int $X, int $Y, int $cw, int $ch, int $color, int $level): void
{
    for ($py = 0; $py < $ch; $py++) {
        for ($px = 0; $px < $cw; $px++) {
            $cell = (($py >> 1) & 1) * 2 + (($px >> 1) & 1);   // 0..3 checker of 2px blocks
            $hit = match (true) {
                $level >= 3 => $cell !== 3,
                $level === 2 => ($cell & 1) === ($cell >> 1) ^ false && in_array($cell, [0, 3], true),
                default => $cell === 0,
            };
            if ($hit) {
                imagesetpixel($im, $X + $px, $Y + $py, $color);
            }
        }
    }
}
