<?php

declare(strict_types=1);

/**
 * render-gif.php — rasterise an ANSI animation (logo.ansi) into per-frame PNGs.
 *
 * Adapted (copied+modified) from /tmp/opencode/dup-logo-lab2/idea-04/render-gif.php,
 * itself adapted from /tmp/opencode/dup-logo-lab/agent-01/render-gif.php, in the
 * spirit of sugarcraft/candy-top ansi-ttf-preview.php. Cell-grid renderer: parses
 * SGR state (38;2/48;2, 38;5/48;5, resets), splits frames on the cursor-up join
 * (\e[<h>A\r), draws full blocks as exact pixel rects, ◉/●/○ as circles, box-
 * drawing + everything else via DejaVu Mono. Modifications for idea-10: the page
 * background is the concept's #030712, and trailing reset/newline tails on the
 * last ANSI part are trimmed so the grid keeps exactly ROWS lines.
 *
 *   php render-gif.php [in.ansi] [outDir] [cellW] [cellH]
 */

$in = $argv[1] ?? __DIR__ . '/logo.ansi';
$outDir = $argv[2] ?? __DIR__ . '/frames';
$cw = (int) ($argv[3] ?? 10);
$ch = (int) ($argv[4] ?? 20);
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
$fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf';

$raw = file_get_contents($in) or exit("cannot read $in\n");
@mkdir($outDir, 0o777, true);
array_map('unlink', glob("$outDir/*.png") ?: []);

$raw = preg_replace('/\e\[\?25[hl]/', '', $raw);
$framesText = preg_split('/\e\[\d+A\r/', $raw) ?: [];
$framesText = array_map(static fn (string $p): string => rtrim($p, "\n"), $framesText);
$framesText = array_values(array_filter($framesText,
    static fn (string $p): bool => trim((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\r/', '', $p)) !== ''));

$defaultFg = [229, 229, 229];
$defaultBg = [3, 7, 18];   // #030712 — the concept's background

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
                    } elseif ($v === 38 || $v === 48) {
                        if (($p[$i + 1] ?? 0) === 5) {          // 256-colour
                            $pal = xterm256()[$p[$i + 2] ?? 0] ?? [255, 0, 255];
                            $v === 38 ? $fg = $pal : $bg = $pal;
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
                case '█':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, $col($fg));
                    break;
                case '▀':
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + intdiv($ch, 2) - 1, $col($fg));
                    break;
                case '▄':
                    imagefilledrectangle($im, $px, $py + intdiv($ch, 2), $px + $cw - 1, $py + $ch - 1, $col($fg));
                    break;
                case '●':
                    $fgc = imagecolorallocate($im, $fg[0], $fg[1], $fg[2]);
                    imagefilledellipse($im, (int) ($px + $cw / 2), (int) ($py + $ch / 2), (int) ($cw * 0.72), (int) ($ch * 0.62), $fgc);
                    break;
                case '○':
                    $fgc = imagecolorallocate($im, $fg[0], $fg[1], $fg[2]);
                    imagefilledellipse($im, (int) ($px + $cw / 2), (int) ($py + $ch / 2), (int) ($cw * 0.72), (int) ($ch * 0.62), $fgc);
                    $bgc = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
                    imagefilledellipse($im, (int) ($px + $cw / 2), (int) ($py + $ch / 2), (int) ($cw * 0.42), (int) ($ch * 0.34), $bgc);
                    break;
                case '◉':
                    imagefilledrectangle($im, $px + 1, $py + 1, $px + $cw - 2, $py + $ch - 2, $col($fg));
                    $bgc = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
                    imagefilledellipse($im, (int) ($px + $cw / 2), (int) ($py + $ch / 2), (int) ($cw * 0.55), (int) ($ch * 0.55), $bgc);
                    $fgc = imagecolorallocate($im, $fg[0], $fg[1], $fg[2]);
                    imagefilledellipse($im, (int) ($px + $cw / 2), (int) ($py + $ch / 2), (int) ($cw * 0.28), (int) ($ch * 0.28), $fgc);
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

/** @return array<int, array{0: int, 1: int, 2: int}> */
function xterm256(): array
{
    static $pal = null;
    if ($pal !== null) {
        return $pal;
    }
    $pal = [
        [0, 0, 0], [205, 0, 0], [0, 205, 0], [205, 205, 0], [0, 0, 238], [205, 0, 205],
        [0, 205, 205], [229, 229, 229], [127, 127, 127], [255, 0, 0], [0, 255, 0],
        [255, 255, 0], [92, 92, 255], [255, 0, 255], [0, 255, 255], [255, 255, 255],
    ];
    $s = [0, 95, 135, 175, 215, 255];
    $idx = 16;
    for ($r = 0; $r < 6; $r++) {
        for ($g = 0; $g < 6; $g++) {
            for ($b = 0; $b < 6; $b++) {
                $pal[$idx++] = [$s[$r], $s[$g], $s[$b]];
            }
        }
    }
    for ($i = 0; $i < 24; $i++) {
        $v = 8 + $i * 10;
        $pal[232 + $i] = [$v, $v, $v];
    }
    return $pal;
}
