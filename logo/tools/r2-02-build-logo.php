<?php

declare(strict_types=1);

/**
 * build-logo.php — regenerate logo.ansi + PNG frames FROM DupBanner (the single
 * source of truth). Copied/adapted from the proven round-1 agent-05 renderer.
 *
 * Run:  php tools/build-logo.php   (from the idea dir)
 */

require_once dirname(__DIR__) . '/DupBanner.php';

const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
const CW   = 14;   // px per cell col
const CH   = 28;   // px per cell row

$dir = dirname(__DIR__);
@mkdir("$dir/frames", 0777, true);

// --- logo.ansi (truecolor one-shot stream) ---------------------------------
file_put_contents("$dir/logo.ansi", DupBanner::ansi());

// --- PNG frames (motion + hold tail) ----------------------------------------
/**
 * @param array<int, array<int, array{0:string,1:array{0:int,1:int,2:int}|null,2:array{0:int,1:int,2:int}}>> $cells
 */
function renderPng(array $cells, string $path): void
{
    $im = imagecreatetruecolor(DupBanner::COLS * CW, DupBanner::ROWS * CH);
    if ($im === false) {
        fwrite(STDERR, "imagecreatetruecolor failed\n");
        exit(1);
    }

    $col = static function (array $c) use ($im): int {
        $idx = imagecolorallocate($im, $c[0], $c[1], $c[2]);

        return $idx === false ? 0 : $idx;
    };

    $size = CH * 0.62;

    foreach ($cells as $r => $row) {
        foreach ($row as $c => [$g, $fg, $bg]) {
            $x  = $c * CW;
            $y  = $r * CH;
            $bc = $bg;
            $fc = $fg ?? [229, 229, 229];

            imagefilledrectangle($im, $x, $y, $x + CW - 1, $y + CH - 1, $col($bc));

            if ($g === ' ' || $g === '█') {
                if ($g === '█') {
                    imagefilledrectangle($im, $x, $y, $x + CW - 1, $y + CH - 1, $col($fc));
                }

                continue;
            }

            imagettftext($im, $size, 0, $x + 1, (int) ($y + CH * 0.78), $col($fc), FONT, $g);
        }
    }

    imagepng($im, $path);
    imagedestroy($im);
}

for ($t = 0; $t < DupBanner::TICKS; $t++) {
    renderPng(DupBanner::cellsPublic($t), sprintf('%s/frames/frame_%04d.png', $dir, $t));
}

// hold tail: final frame repeated so the GIF plays once then holds before looping
for ($h = 0; $h < 10; $h++) {
    renderPng(DupBanner::cellsPublic(DupBanner::finalTick()), sprintf('%s/frames/frame_%04d.png', $dir, DupBanner::TICKS + $h));
}

fwrite(STDERR, sprintf(
    "wrote logo.ansi (%d bytes) + %d PNG frames (%dx%d cells) for %d ticks @ %d fps\n",
    (int) filesize("$dir/logo.ansi"),
    DupBanner::TICKS + 10,
    DupBanner::COLS,
    DupBanner::ROWS,
    DupBanner::TICKS,
    DupBanner::FPS,
));
