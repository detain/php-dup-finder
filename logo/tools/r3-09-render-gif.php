<?php

declare(strict_types=1);

/**
 * render-gif.php — rasterise logo.ansi (truecolor ANSI animation) to PNG
 * frames, one per animation tick, using a real monospace TTF.
 *
 * Cell-grid renderer adapted from candy-top's ansi-ttf-preview / round-2
 * proven renderers: it understands the cursor-up frame join (\e[<h>A\r) and
 * draws block glyphs (█▀▄) as EXACT pixel sub-rects so the half-block dusk
 * gradient lands crisply; shade glyphs (░▒▓) composite over the bg at their
 * coverage; everything else goes through the TTF.
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

// split into frames on the cursor-up join; strip cursor show/hide
$raw = preg_replace('/\e\[\?25[hl]/', '', $raw);
$framesText = preg_split('/\e\[\d+A\r/', $raw) ?: [];

$defaultFg = [229, 229, 229];
$defaultBg = [0, 0, 0];
$coverage = ['░' => 0.28, '▒' => 0.55, '▓' => 0.78];

foreach ($framesText as $fi => $ansi) {
    $lines = explode("\n", rtrim($ansi, "\n"));
    $grid = [];
    foreach ($lines as $y => $line) {
        $fg = $defaultFg;
        $bg = null;
        $x = 0;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
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
                    } elseif (($v === 38 || $v === 48) && ($p[$i + 1] ?? 0) === 2) {
                        $c = [$p[$i + 2], $p[$i + 3], $p[$i + 4]];
                        $i += 4;
                        $v === 38 ? $fg = $c : $bg = $c;
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $grid[$y][$x] = [$g, $fg, $bg];
                $x++;
            }
        }
    }
    $w = max(array_map(static fn (array $r): int => max(array_keys($r)) + 1, $grid));
    $h = count($lines);
    $im = imagecreatetruecolor($w * $cw, $h * $ch);
    $col = static fn (array $c): int => imagecolorallocate($im, $c[0], $c[1], $c[2]);
    imagefilledrectangle($im, 0, 0, $w * $cw, $h * $ch, $col($defaultBg));
    $size = $ch * 0.62;
    foreach ($grid as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg]) {
            $px = $x * $cw;
            $py = $y * $ch;
            $bg ??= $defaultBg;
            imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, $col($bg));
            switch ($g) {
                case ' ':
                    break;
                case '█':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, $col($fg));
                    break;
                case '▀':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + intdiv($ch, 2) - 1, $col($fg));
                    break;
                case '▄':
                    imagefilledrectangle($im, $px, $py + intdiv($ch, 2), $px + $cw - 1, $py + $ch - 1, $col($fg));
                    break;
                case '░':
                case '▒':
                case '▓':
                    // stipple the fg over the bg at the glyph's coverage
                    $cov = $coverage[$g];
                    $mixc = [
                        (int) round($bg[0] + ($fg[0] - $bg[0]) * $cov),
                        (int) round($bg[1] + ($fg[1] - $bg[1]) * $cov),
                        (int) round($bg[2] + ($fg[2] - $bg[2]) * $cov),
                    ];
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, $col($mixc));
                    break;
                default:
                    $f = preg_match('/[A-Za-z]/u', $g) ? $fontBold : $font;
                    imagettftext($im, $size, 0, $px, (int) ($py + $ch * 0.78), $col($fg), $f, $g);
            }
        }
    }
    imagepng($im, sprintf('%s/f%03d.png', $outDir, $fi));
}
echo 'rendered ' . count($framesText) . " frames → $outDir\n";
