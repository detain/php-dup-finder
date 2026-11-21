<?php

declare(strict_types=1);

/**
 * render-frames — rasterize EVERY frame of an ANSI animation to PNG with a
 * real monospace TTF (cell-grid method from candy-top's ansi-ttf-preview.php,
 * extended to split frames on the cursor-up seam).
 *
 *   php render-frames.php <in.ansi> <outDir> [pageBg=#000000] [cellW=12] [cellH=24] [font.ttf]
 *
 * Writes outDir/f-00.png, f-01.png, … and a hardlink-friendly copy of the LAST
 * frame as logo-still.png in the input's directory.
 */

require __DIR__ . '/logo-kit.php';

$in = $argv[1] ?? exit("usage: php render-frames.php <in.ansi> <outDir> [bg] [cellW] [cellH] [font]\n");
$outDir = $argv[2] ?? 'png';
$page = lkHex($argv[3] ?? '#000000');
$cw = (int) ($argv[4] ?? 12);
$ch = (int) ($argv[5] ?? 24);
$font = $argv[6] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

$raw = file_get_contents($in);
$raw = preg_replace('/\e\[\?25[hl]/', '', $raw);
$parts = preg_split('/\e\[\d+A\r/', $raw);
@mkdir($outDir, 0o777, true);

$pal16 = lkAnsi16();
$x256 = lkXterm256();
foreach ([30, 31, 32, 33, 34, 35, 36, 37] as $i => $c) {
    $x256[$i] = $pal16[$c];
    $x256[$i + 8] = $pal16[$c + 60];
}

/** @return list<list<?array>> cell grid parsed from one encoded frame */
function parseFrame(string $ansi, array $pal16, array $x256): array
{
    $grid = [];
    foreach (explode("\n", rtrim($ansi, "\n")) as $y => $line) {
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
    return $grid;
}

$size = $ch * 0.62;
$n = 0;
foreach ($parts as $ansi) {
    if (trim(lkStrip($ansi)) === '' && $ansi === '') {
        continue;
    }
    $grid = parseFrame($ansi, $pal16, $x256);
    $w = 0;
    foreach ($grid as $row) {
        $w = max($w, max(array_keys($row)) + 1);
    }
    $h = count($grid);
    $im = imagecreatetruecolor($w * $cw, $h * $ch);
    $col = static fn (array $c): int => imagecolorallocate($im, $c[0], $c[1], $c[2]);
    imagefilledrectangle($im, 0, 0, $w * $cw, $h * $ch, $col($page));
    foreach ($grid as $y => $row) {
        for ($x = 0; $x < $w; $x++) {
            [$g, $fg, $bg] = $row[$x] ?? [' ', null, null];
            if ($bg !== null) {
                imagefilledrectangle($im, $x * $cw, $y * $ch, ($x + 1) * $cw - 1, ($y + 1) * $ch - 1, $col($bg));
            }
            if ($g !== ' ') {
                imagettftext($im, $size, 0, $x * $cw, (int) ($y * $ch + $ch * 0.78), $col($fg), $font, $g);
            }
        }
    }
    $file = sprintf('%s/f-%02d.png', $outDir, $n);
    imagepng($im, $file);
    $lastIm = $im;
    $n++;
}
imagepng($lastIm, dirname($in) . '/logo-still.png');
fwrite(STDERR, "rendered $n frames ({$w}x{$h} cells, " . $w * $cw . 'x' . $h * $ch . " px) → $outDir + logo-still.png\n");
