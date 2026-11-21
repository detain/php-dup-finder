<?php

declare(strict_types=1);

/**
 * render.php — rasterise an ANIMATED .ansi (frames joined by \e[<H>A\r,
 * produced by DupBanner::play) into numbered PNGs for GIF stitching.
 *
 * Forked (into this lab, read-only upstream) from round-1 agent-07's
 * ansi2gif.php; extended to: understand 38;5/48;5 (256) as well as 38;2,
 * name outputs frame_NN.png, and pad every frame to a fixed canvas width.
 *
 * Usage: php render.php <in.ansi> <outDir> [cellW=10] [cellH=20] [pageBg=#0F1117]
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Cli\DupBanner;

$in     = $argv[1] ?? exit("usage: php render.php <in.ansi> <outDir> [cw] [ch] [pageBg]\n");
$outDir = rtrim($argv[2] ?? 'frames', '/');
$cw     = (int) ($argv[3] ?? 10);
$ch     = (int) ($argv[4] ?? 20);
$page   = $argv[5] ?? '#111827';

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    exit("cannot create $outDir\n");
}
foreach (glob("$outDir/frame_*.png") ?: [] as $stale) {
    unlink($stale);
}

[$pR, $pG, $pB] = array_map('hexdec', str_split(ltrim($page, '#'), 2));
$pageRgb = [(int) $pR, (int) $pG, (int) $pB];

$xterm = static function (int $n): array {
    if ($n < 16) {
        $basic = [0, 0, 0, 205, 0, 0, 0, 205, 0, 205, 205, 0, 0, 0, 238, 205, 0, 205, 0, 205, 205, 229, 229, 229,
                  127, 127, 127, 255, 0, 0, 0, 255, 0, 255, 255, 0, 92, 92, 255, 255, 0, 255, 0, 255, 255, 255, 255, 255];
        return array_slice($basic, $n * 3, 3);
    }
    if ($n >= 232) {
        $v = 8 + ($n - 232) * 10;
        return [$v, $v, $v];
    }
    $s = [0, 95, 135, 175, 215, 255];
    $j = $n - 16;
    return [$s[intdiv($j, 36)], $s[min(5, abs(intdiv($j, 6) % 6))], $s[$j % 6]];
};

$ansi   = (string) file_get_contents($in);
$frames = preg_split('/\e\[\d+A\r/', $ansi) ?: [];
$frames[0] = preg_replace('/^\e\[\?25l(\e\[H)?/', '', (string) $frames[0]);
$lastIdx   = count($frames) - 1;
$frames[$lastIdx] = (string) preg_replace('/\e\[\?25h\s*$/', '', (string) $frames[$lastIdx]);

$fontSize = $cw / 0.6024;
$font     = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

$n = 0;
$wCells = DupBanner::COLS;
$hCells = 0;
foreach ($frames as $ansiFrame) {
    $n++;
    $lines = explode("\n", rtrim((string) $ansiFrame, "\n"));
    $grid  = [];
    foreach ($lines as $y => $line) {
        $fg = [229, 229, 229];
        $bg = null;
        $ul = false;
        $x  = 0;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', (string) $tok, $m) === 1) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0 || $v === 24) {
                        if ($v === 0) {
                            $fg = [229, 229, 229];
                            $bg = null;
                        }
                        $ul = false;
                    } elseif ($v === 4) {
                        $ul = true;
                    } elseif ($v === 39) {
                        $fg = [229, 229, 229];
                    } elseif ($v === 49) {
                        $bg = null;
                    } elseif ($v === 38 || $v === 48) {
                        if (($p[$i + 1] ?? 0) === 5) {
                            $rgb = $xterm((int) ($p[$i + 2] ?? 0));
                            $v === 38 ? $fg = $rgb : $bg = $rgb;
                            $i += 2;
                        } else {
                            $rgb = [(int) ($p[$i + 2] ?? 0), (int) ($p[$i + 3] ?? 0), (int) ($p[$i + 4] ?? 0)];
                            $v === 38 ? $fg = $rgb : $bg = $rgb;
                            $i += 4;
                        }
                    }
                }
                continue;
            }
            foreach (mb_str_split((string) $tok) as $g) {
                $grid[$y][$x] = [$g, $fg, $bg, $ul];
                $x += max(1, mb_strwidth($g));
            }
        }
    }

    $wCells = DupBanner::COLS; // fixed canvas so every GIF frame is full size
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
        foreach ($row as $x => $cell) {
            [$g, $fg, $bg, $ul] = $cell;
            $x0 = $x * $cw;
            $y0 = $y * $ch;
            if ($bg !== null) {
                imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $alloc($bg));
            }
            if ($g === '█') {
                imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $alloc($fg));
            } elseif ($g === '▀') {
                imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + intdiv($ch, 2) - 1, $alloc($fg));
            } elseif ($g === '▄') {
                imagefilledrectangle($im, $x0, $y0 + intdiv($ch, 2), $x0 + $cw - 1, $y0 + $ch - 1, $alloc($fg));
            } elseif ($g !== ' ') {
                imagettftext($im, $fontSize, 0, $x0 + (int) round(($cw - $fontSize * 0.6024) / 2), (int) round($y0 + $ch * 0.765), $alloc($fg), $font, $g);
            }
            if ($ul) {
                imagefilledrectangle($im, $x0, $y0 + $ch - 3, $x0 + $cw - 1, $y0 + $ch - 1, $alloc($fg));
            }
        }
    }
    imagepng($im, sprintf('%s/frame_%02d.png', $outDir, $n - 1));
    imagedestroy($im);
}
fwrite(STDERR, sprintf("rendered %d frames -> %s/frame_*.png (%dx%d cells @ %dx%d)\n", $n, $outDir, $wCells, $hCells, $cw, $ch));
