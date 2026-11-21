<?php

declare(strict_types=1);

/**
 * ansi2gif.php — render an ANIMATED truecolor .ansi (frames joined by
 * cursor-up sequences, e.g. built by logo-kit's lkAnim) to numbered PNGs.
 *
 * Fork of candy-top's ansi-ttf-preview.php, extended to:
 *   - render EVERY frame (split on \e[<H>A\r), not just the last;
 *   - rasterize block/shade glyphs (█▀▄▌▐░▒▓) as exact cell rects instead of
 *     font glyphs, so pixel art stays crisp and tiles seamlessly.
 *
 * Usage:
 *   php ansi2gif.php <in.ansi> <outDir> [cellW=10] [cellH=20] [pageBg=#000000] [font.ttf]
 */

$in     = $argv[1] ?? exit("usage: php ansi2gif.php <in.ansi> <outDir> [cw] [ch] [pageBg] [font]\n");
$outDir = rtrim($argv[2] ?? 'frames', '/');
$cw     = (int) ($argv[3] ?? 10);
$ch     = (int) ($argv[4] ?? 20);
$page   = $argv[5] ?? '#000000';
$font   = $argv[6] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    exit("cannot create $outDir\n");
}
foreach (glob("$outDir/f_*.png") ?: [] as $stale) {
    unlink($stale);
}

[$pR, $pG, $pB] = array_map('hexdec', str_split(ltrim($page, '#'), 2));
$pageRgb = [$pR, $pG, $pB];

$ansi   = file_get_contents($in);
$frames = preg_split('/\e\[\d+A\r/', $ansi) ?: [];
// lkAnim wraps the sequence in cursor hide/show codes
$frames[0] = preg_replace('/^\e\[\?25l/', '', $frames[0]);
$last      = count($frames) - 1;
$frames[$last] = preg_replace('/\e\[\?25h\s*$/', '', $frames[$last]);

$fontSize = $cw / 0.6024;   // DejaVu Sans Mono advance = 0.6024 em

$shadeMix = ['░' => 0.27, '▒' => 0.52, '▓' => 0.78];

$n = 0;
foreach ($frames as $ansiFrame) {
    $n++;
    $lines = explode("\n", rtrim($ansiFrame, "\n"));
    $grid  = [];
    foreach ($lines as $y => $line) {
        $fg = [229, 229, 229];
        $bg = null;
        $x  = 0;
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
                    } elseif ($v === 38 || $v === 48) {
                        if (($p[$i + 1] ?? 0) === 5) {
                            $i += 2;                        // 256-mode: unused here
                        } else {
                            $c = [$p[$i + 2] ?? 0, $p[$i + 3] ?? 0, $p[$i + 4] ?? 0];
                            $v === 38 ? $fg = $c : $bg = $c;
                            $i += 4;
                        }
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

    $wCells = max(array_map(static fn (array $r): int => max(array_keys($r)) + 1, $grid));
    $hCells = count($lines);
    $im     = imagecreatetruecolor($wCells * $cw, $hCells * $ch);
    $alloc  = static fn (array $c): int => imagecolorallocate(
        $im,
        max(0, min(255, (int) $c[0])),
        max(0, min(255, (int) $c[1])),
        max(0, min(255, (int) $c[2])),
    );
    imagefilledrectangle($im, 0, 0, $wCells * $cw, $hCells * $ch, $alloc($pageRgb));

    foreach ($grid as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg]) {
            $x0 = $x * $cw;
            $y0 = $y * $ch;
            if ($g === '█') {
                imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $alloc($fg));
                continue;
            }
            if ($bg !== null) {
                imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $alloc($bg));
            }
            if ($g === ' ' || $g === '') {
                continue;
            }
            if (isset($shadeMix[$g])) {
                $u = $bg ?? $pageRgb;
                $m = $shadeMix[$g];
                imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $alloc([
                    (int) round($fg[0] * $m + $u[0] * (1 - $m)),
                    (int) round($fg[1] * $m + $u[1] * (1 - $m)),
                    (int) round($fg[2] * $m + $u[2] * (1 - $m)),
                ]));
                continue;
            }
            if ($g === '▀') {
                imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + intdiv($ch, 2) - 1, $alloc($fg));
                continue;
            }
            if ($g === '▄') {
                imagefilledrectangle($im, $x0, $y0 + intdiv($ch, 2), $x0 + $cw - 1, $y0 + $ch - 1, $alloc($fg));
                continue;
            }
            if ($g === '▌') {
                imagefilledrectangle($im, $x0, $y0, $x0 + intdiv($cw, 2) - 1, $y0 + $ch - 1, $alloc($fg));
                continue;
            }
            if ($g === '▐') {
                imagefilledrectangle($im, $x0 + intdiv($cw, 2), $y0, $x0 + $cw - 1, $y0 + $ch - 1, $alloc($fg));
                continue;
            }
            imagettftext($im, $fontSize, 0, $x0 + (int) round(($cw - $fontSize * 0.6024) / 2), (int) round($y0 + $ch * 0.765), $alloc($fg), $font, $g);
        }
    }
    imagepng($im, sprintf('%s/f_%03d.png', $outDir, $n));
    imagedestroy($im);
}
echo "rendered $n frames -> $outDir/f_*.png ({$wCells}x{$hCells} cells, {$cw}x{$ch}px)\n";
