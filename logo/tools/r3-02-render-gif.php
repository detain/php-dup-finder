<?php

declare(strict_types=1);

/**
 * render-gif.php — rasterise logo.ansi (truecolor ANSI animation) to PNG
 * frames, one per animation tick, using a real monospace TTF.
 *
 * Adapted from the round-2 lab renderer into THIS lane's directory (originals
 * untouched). Understands the frame join \e[<rows>A\r, resets, and paints the
 * half/block glyphs (█▓░) as exact pixel rects so the beam bands and the
 * wordmark land crisply; every other glyph goes through the TTF.
 *
 *   php render-gif.php [in.ansi] [outDir] [cellW] [cellH] [font.ttf]
 */

$in = $argv[1] ?? __DIR__ . '/logo.ansi';
$outDir = $argv[2] ?? __DIR__ . '/frames';
$cw = (int) ($argv[3] ?? 12);
$ch = (int) ($argv[4] ?? 24);
$font = $argv[5] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
$fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf';

$raw = file_get_contents($in) or exit("cannot read $in\n");
@mkdir($outDir, 0o777, true);
array_map('unlink', glob("$outDir/*.png") ?: []);

$raw = preg_replace('/\e\[\?25[hl]/', '', $raw) ?? '';
$framesText = preg_split('/\e\[\d+A\r/', $raw) ?: [];

$defaultFg = [248, 250, 252];
$defaultBg = [11, 17, 32];           // #0B1120 canvas (matches DupBanner)
$CANVAS_W = 56;
$CANVAS_H = 7;

foreach ($framesText as $fi => $ansi) {
    $lines = explode("\n", rtrim($ansi, "\n"));
    $lines = array_pad($lines, $CANVAS_H, '');
    $grid = [];
    foreach ($lines as $y => $line) {
        $fg = $defaultFg;
        $bg = null;
        $x = 0;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0) {
                        $fg = $defaultFg;
                        $bg = null;
                    } elseif ($v === 39) {
                        $fg = $defaultFg;
                    } elseif ($v === 49) {
                        $bg = null;
                    } elseif ($v === 38 || $v === 48) {
                        if (($p[$i + 1] ?? 0) === 5) {
                            $i += 2;      // 38;5;n - palette path not painted here
                            continue;
                        }
                        $c = [$p[$i + 2] ?? 0, $p[$i + 3] ?? 0, $p[$i + 4] ?? 0];
                        $i += 4;
                        if ($v === 38) {
                            $fg = $c;
                        } else {
                            $bg = $c;
                        }
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $grid[$y][$x] = [$g, $fg, $bg];
                $x += max(1, mb_strwidth($g, 'UTF-8'));
            }
        }
    }
    $w = $CANVAS_W;
    $h = $CANVAS_H;
    $im = imagecreatetruecolor($w * $cw, $h * $ch);
    $col = static fn (array $c): int => imagecolorallocate($im, $c[0], $c[1], $c[2]);
    imagefilledrectangle($im, 0, 0, $w * $cw, $h * $ch, $col($defaultBg));
    $size = $ch * 0.62;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            [$g, $fg, $cellBg] = $grid[$y][$x] ?? [' ', $defaultFg, null];
            $px = $x * $cw;
            $py = $y * $ch;
            $bgc = $cellBg ?? $defaultBg;
            imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, $col($bgc));
            switch ($g) {
                case ' ':
                    break;
                case '█':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, $col($fg));
                    break;
                case '▓':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + intdiv($ch * 3, 4) - 1, $col($fg));
                    break;
                case '▒':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + intdiv($ch * 2, 3) - 1, $col($fg));
                    break;
                case '░':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + intdiv($ch, 3) - 1, $col($fg));
                    break;
                default:
                    $f = preg_match('/[A-Za-z]/u', $g) ? $fontBold : $font;
                    imagettftext($im, $size, 0, $px, (int) ($py + $ch * 0.78), $col($fg), $f, $g);
            }
        }
    }
    imagepng($im, sprintf('%s/f%03d.png', $outDir, $fi));
    fwrite(STDERR, sprintf("frame %d/%d\n", $fi + 1, count($framesText)));
}
echo 'rendered ' . count($framesText) . " frames -> $outDir\n";
