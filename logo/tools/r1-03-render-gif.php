<?php

declare(strict_types=1);

/**
 * render-gif.php — rasterize EVERY frame of a truecolor ANSI animation to PNG.
 *
 * Derived from candy-top tools/ansi-ttf-preview.php (cell-grid approach,
 * DejaVu Sans Mono TTF) but splits the joined animation on its cursor-up
 * marker "\e[<rows>A\r" and renders each frame rather than only the last.
 * Understanding SGR 0, 39/49, 30-37/90-97, 40-47/100-107, 38;5/48;5, 38;2/48;2
 * is copied semantics from the original.
 *
 *   php render-gif.php <in.ansi> <outDir> [cellW=12] [cellH=24] [rows=16] [font.ttf]
 *
 * Produces <outDir>/fNN.png for each animation frame (the trailing static
 * duplicate frame appended by lkAnim is skipped). Stitch with:
 *   convert -delay 4 -loop 0 outDir/f*.png logo.gif
 */

require __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php render-gif.php <in.ansi> <outDir> [cellW] [cellH] [rows] [font.ttf]\n");
$outDir = $argv[2] ?? 'png';
$cw = (int) ($argv[3] ?? 12);
$ch = (int) ($argv[4] ?? 24);
$rows = (int) ($argv[5] ?? 16);
$font = $argv[6] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
@mkdir($outDir, 0o755, true);

$raw = preg_replace('/\e\[\?25[hl]/', '', file_get_contents($in));
$parts = preg_split('/\e\[' . $rows . 'A\r/', $raw);
if (count($parts) < 2) {
    $parts = [$raw];                                // plain single-frame file
} else {
    array_pop($parts);                              // skip trailing static dupe
}

$pal16 = lkAnsi16();
$x256 = lkXterm256();
foreach ([30, 31, 32, 33, 34, 35, 36, 37] as $i => $c) {
    $x256[$i] = $pal16[$c];
    $x256[$i + 8] = $pal16[$c + 60];
}

foreach ($parts as $fi => $ansi) {
    $lines = explode("\n", rtrim($ansi, "\n"));
    $grid = [];
    foreach ($lines as $y => $line) {
        $fg = [229, 229, 229];
        $bg = null;
        $x = 0;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0) {
                        $fg = [229, 229, 229];
                        $bg = null;
                    } elseif ($v === 39) {
                        $fg = [229, 229, 229];
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

    $w = max(array_map(static fn (array $r): int => max(array_keys($r)) + 1, $grid));
    $h = count($lines);
    $im = imagecreatetruecolor($w * $cw, $h * $ch);
    $colorCache = [];                                   // GD color ids are per-image
    $col = static function (array $c) use ($im, &$colorCache): int {
        $key = "{$c[0]},{$c[1]},{$c[2]}";
        return $colorCache[$key] ??= imagecolorallocate($im, $c[0], $c[1], $c[2]);
    };
    imagefilledrectangle($im, 0, 0, $w * $cw, $h * $ch, $col([5, 5, 12]));
    $size = $ch * 0.62;
    foreach ($grid as $y => $r) {
        foreach ($r as $x => [$g, $fg, $bg]) {
            if ($bg !== null) {
                imagefilledrectangle($im, $x * $cw, $y * $ch, ($x + 1) * $cw - 1, ($y + 1) * $ch - 1, $col($bg));
            }
            if ($g !== ' ') {
                imagettftext($im, $size, 0, $x * $cw, (int) ($y * $ch + $ch * 0.78), $col($fg), $font, $g);
            }
        }
    }
    $out = sprintf('%s/f%02d.png', $outDir, $fi);
    imagepng($im, $out);
    imagedestroy($im);
}
fwrite(STDERR, sprintf("rendered %d frames (%dx%d cells, %dx%d px) → %s/\n", count($parts), $w, $h, $w * $cw, $h * $ch, $outDir));
