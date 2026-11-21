<?php

declare(strict_types=1);

/**
 * render-gif.php — rasterise the truecolor logo.ansi into per-frame PNGs.
 *
 * Adapted (copy, not edit) from the proven round-1 renderer in
 * /tmp/opencode/dup-logo-lab/agent-01/render-gif.php. Changes for idea-09:
 *   - page background = #0D1117 (the DupBanner terminal ground)
 *   - real bold tracking: SGR 1/22 selects DejaVu Sans Mono Bold vs Book
 *     (the old version bolded every A-Za-z glyph unconditionally)
 *   - xterm-256 (38;5/48;5) decoding added so logo.256.ansi also renders
 *   - half-block glyph support kept verbatim
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

// ---------- split into frames on the cursor-up join; strip cursor show/hide
$raw = preg_replace('/\e\[\?25[hl]/', '', $raw);
$framesText = preg_split('/\e\[\d+A\r/', $raw) ?: [];

$defaultFg = [229, 229, 229];
$defaultBg = [13, 17, 23];        // DupBanner page ground

/** decode an xterm-256 slot to rgb (16 system + 6x6x6 cube + grey ramp) */
$x256 = static function (int $n): array {
    $base = [
        0 => [0, 0, 0], 1 => [135, 0, 0], 2 => [0, 135, 0], 3 => [175, 175, 0],
        4 => [0, 0, 215], 5 => [175, 0, 175], 6 => [0, 175, 175], 7 => [215, 215, 215],
        8 => [95, 95, 95], 9 => [255, 95, 95], 10 => [0, 215, 0], 11 => [255, 255, 0],
        12 => [95, 95, 255], 13 => [255, 95, 255], 14 => [0, 255, 255], 15 => [255, 255, 255],
    ];
    if (isset($base[$n])) {
        return $base[$n];
    }
    if ($n >= 16 && $n <= 231) {
        $n -= 16;
        $lv = [0, 95, 135, 195, 235, 255];
        return [$lv[intdiv($n, 36)], $lv[intdiv($n % 36, 6)], $lv[$n % 6]];
    }
    if ($n >= 232) {
        $v = 8 + ($n - 232) * 10;
        return [$v, $v, $v];
    }
    return $base[0];
};

foreach ($framesText as $fi => $ansi) {
    $lines = explode("\n", rtrim($ansi, "\n"));
    $grid = [];
    foreach ($lines as $y => $line) {
        $fg = $defaultFg;
        $bg = null;
        $bold = false;
        $x = 0;
        foreach (preg_split('/(\e\[[0-9;?]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\e\[([0-9;?]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0) {
                        $fg = $defaultFg; $bg = null; $bold = false;
                    } elseif ($v === 1) {
                        $bold = true;
                    } elseif ($v === 22) {
                        $bold = false;
                    } elseif ($v === 39) {
                        $fg = $defaultFg;
                    } elseif ($v === 49) {
                        $bg = null;
                    } elseif ($v === 38 || $v === 48) {
                        if (($p[$i + 1] ?? 0) === 5) {          // 38;5;N / 48;5;N
                            $c = $x256((int) ($p[$i + 2] ?? 7));
                            $i += 2;
                        } else {                               // 38;2;r;g;b / 48;2
                            $c = [(int) $p[$i + 2], (int) $p[$i + 3], (int) $p[$i + 4]];
                            $i += 4;
                        }
                        $v === 38 ? $fg = $c : $bg = $c;
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $grid[$y][$x] = [$g, $fg, $bg, $bold];
                $x += max(1, mb_strwidth($g, 'UTF-8'));
            }
        }
    }
    $w = max(array_map(static fn(array $r): int => max(array_keys($r)) + 1, $grid));
    $h = count($lines);
    $im = imagecreatetruecolor($w * $cw, $h * $ch);
    $col = static fn(array $c): int => imagecolorallocate($im, $c[0], $c[1], $c[2]);
    imagefilledrectangle($im, 0, 0, $w * $cw, $h * $ch, $col($defaultBg));
    $size = $ch * 0.62;
    foreach ($grid as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg, $bold]) {
            $px = $x * $cw;
            $py = $y * $ch;
            $bg ??= $defaultBg;
            imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, $col($bg));
            switch ($g) {
                case ' ':
                    break 1;
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
                    $f = $bold ? $fontBold : $font;
                    imagettftext($im, $size, 0, $px, (int) ($py + $ch * 0.78), $col($fg), $f, $g);
            }
        }
    }
    imagepng($im, sprintf('%s/f%03d.png', $outDir, $fi));
    fwrite(STDERR, sprintf("frame %d/%d: %dx%d cells\n", $fi + 1, count($framesText), $w, $h));
}
echo 'rendered ' . count($framesText) . " frames -> $outDir\n";
