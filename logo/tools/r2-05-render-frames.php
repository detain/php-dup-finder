<?php

declare(strict_types=1);

/**
 * render-frames.php — rasterise a DupBanner truecolor ANSI stream (logo.ansi)
 * to PNG frames using DejaVu Sans Mono. Adapted from the round-1 lab renderer
 * (tools/render-gif.orig.php), frame-splitting reworked for this project's
 * cursor-home framing ("\e[H") instead of cursor-up joins.
 *
 * Cell-grid renderer: exact pixel sub-rects for half/full blocks (█▀▄), TTF
 * for everything else; SGR runs parsed per cell; bold picks the bold face.
 *
 *   php render-frames.php [in.ansi] [outDir] [cellW] [cellH] [font.ttf]
 */

/** @var array<int, mixed> $argvIn */
$argvIn = (array) ($_SERVER['argv'] ?? []);
$in = (string) ($argvIn[1] ?? __DIR__ . '/../logo.ansi');
$outDir = (string) ($argvIn[2] ?? __DIR__ . '/../frames');
$cw = (int) ($argvIn[3] ?? 12);
$ch = (int) ($argvIn[4] ?? 24);
$font = (string) ($argvIn[5] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf');
$fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf';
if (!is_file($fontBold)) {
    $fontBold = $font;
}

$rawIn = file_get_contents($in);
if (!is_string($rawIn)) {
    fwrite(STDERR, "cannot read $in\n");
    exit(1);
}
$raw = $rawIn;
@mkdir($outDir, 0o777, true);
array_map('unlink', glob("$outDir/*.png") ?: []);

$stripped = preg_replace('/\e\[\?25[hl]/', '', $raw);
$split = preg_split('/\e\[H/', is_string($stripped) ? $stripped : $raw);
$framesText = array_values(array_filter(
    is_array($split) ? $split : [],
    static fn(string $f): bool => trim($f) !== '',
));

$defaultFg = [229, 229, 229];
$defaultBg = [10, 10, 16]; // dark page bg for GIF render only
$w = 0;
$h = 0;

foreach ($framesText as $fi => $ansi) {
    $lines = explode("\n", rtrim($ansi, "\n"));
    $grid = [];
    foreach ($lines as $y => $line) {
        $fg = null; // null = default fg
        $bold = false;
        $x = 0;
        foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $tok, $m)) {
                $p = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($p); $i++) {
                    $v = $p[$i];
                    if ($v === 0) {
                        $fg = null;
                        $bold = false;
                    } elseif ($v === 1) {
                        $bold = true;
                    } elseif ($v === 22) {
                        $bold = false;
                    } elseif ($v === 39) {
                        $fg = null;
                    } elseif ($v === 38 && ($p[$i + 1] ?? 0) === 5) {
                        $fg = xterm256($p[$i + 2] ?? 7);
                        $i += 2;
                    } elseif ($v === 38 && ($p[$i + 1] ?? 0) === 2) {
                        $fg = [$p[$i + 2] ?? 0, $p[$i + 3] ?? 0, $p[$i + 4] ?? 0];
                        $i += 4;
                    }
                }
                continue;
            }
            foreach (mb_str_split($tok) as $g) {
                $grid[$y][$x] = [$g, $fg ?? $defaultFg, $bold];
                $x += max(1, mb_strwidth($g, 'UTF-8'));
            }
        }
    }
    $rows = array_values($grid);
    $h = count($rows);
    $w = max(array_map(static fn(array $r): int => max(array_keys($r)) + 1, $rows));
    $im = imagecreatetruecolor($w * $cw, $h * $ch);
    $col = static fn(array $c): int => imagecolorallocate($im, $c[0], $c[1], $c[2]);
    imagefilledrectangle($im, 0, 0, $w * $cw, $h * $ch, $col($defaultBg));
    $size = $ch * 0.62;
    foreach ($rows as $y => $row) {
        for ($x = 0; $x < $w; $x++) {
            [$g, $fg, $bold] = $row[$x] ?? [' ', $defaultFg, false];
            $px = $x * $cw;
            $py = $y * $ch;
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
                    $f = $bold || preg_match('/[A-Za-z]/u', $g) ? $fontBold : $font;
                    imagettftext($im, $size, 0, $px + 1, (int) ($py + $ch * 0.78), $col($fg), $f, $g);
            }
        }
    }
    imagepng($im, sprintf('%s/f%03d.png', $outDir, $fi));
}

/**
 * @return array{int, int, int}
 */
function xterm256(int $n): array
{
    if ($n < 16) {
        static $basic = [[0, 0, 0], [128, 0, 0], [0, 128, 0], [128, 128, 0], [0, 0, 128], [128, 0, 128], [0, 128, 128], [192, 192, 192],
            [128, 128, 128], [255, 0, 0], [0, 255, 0], [255, 255, 0], [0, 0, 255], [255, 0, 255], [0, 255, 255], [255, 255, 255],];
        return $basic[$n];
    }
    if ($n < 232) {
        $i = $n - 16;
        return [
            (int) round(((intdiv($i, 36)) % 6) * 51),
            (int) round(((intdiv($i, 6)) % 6) * 51),
            (int) round(($i % 6) * 51),
        ];
    }
    $g = 8 + ($n - 232) * 10;
    return [$g, $g, $g];
}

fwrite(STDERR, sprintf("rendered %d frames -> %s (%dx%d px)\n", count($framesText), $outDir, $w, $h));
echo 'rendered ' . count($framesText) . " frames\n";
