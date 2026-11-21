<?php

declare(strict_types=1);

/**
 * render-gif.php — rasterise logo.ansi (truecolor ANSI animation) to PNG
 * frames, one per animation frame, using a real monospace TTF.
 *
 * Cell-grid renderer in the spirit of candy-top's ansi-ttf-preview.php, but
 * frame-aware: it understands the lkAnim cursor-up framing (\e[<h>A\r) and
 * draws half-block glyphs (█▀▄) as EXACT pixel sub-rects so gradient letter
 * art lands crisply, while every other glyph goes through the TTF.
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

// ---------- split into frames on the cursor-up join, strip cursor show/hide
$raw = preg_replace('/\e\[\?25[hl]/', '', $raw);
$framesText = preg_split('/\e\[\d+A\r/', $raw);

$defaultFg = [241, 245, 249];
$defaultBg = [13, 2, 33];         // #0D0221 concept-6 canvas

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
                    } elseif ($v === 38 || $v === 48) {       // 38;2;r;g;b / 48;2;r;g;b
                        if (($p[$i + 1] ?? 0) === 5) {          // 256-colour: not used by our pipeline
                            $i += 2;
                            continue;
                        }
                        $c = [$p[$i + 2], $p[$i + 3], $p[$i + 4]];
                        $i += 4;
                        $v === 38 ? $fg = $c : $bg = $c;
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
                    break 1;
                    // no fallthrough intended
                case '█':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, $col($fg));
                    break;
                case '▀':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + intdiv($ch, 2) - 1, $col($fg));
                    break;
                case '▄':
                    imagefilledrectangle($im, $px, $py + intdiv($ch, 2), $px + $cw - 1, $py + $ch - 1, $col($fg));
                    break;
                default:
                    $f = preg_match('/[A-Za-z]/u', $g) ? $fontBold : $font;
                    imagettftext($im, $size, 0, $px, (int) ($py + $ch * 0.78), $col($fg), $f, $g);
            }
        }
    }
    imagepng($im, sprintf('%s/f%03d.png', $outDir, $fi));
    fwrite(STDERR, sprintf("frame %d/%d: %dx%d cells\n", $fi + 1, count($framesText), $w, $h));
}
echo 'rendered ' . count($framesText) . " frames → $outDir\n";
