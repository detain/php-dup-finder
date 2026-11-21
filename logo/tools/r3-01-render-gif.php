<?php

declare(strict_types=1);

/**
 * render-gif.php — rasterise DupBanner frames to PNG, one per tick.
 *
 * Adapted from the round-2 lab2/idea-01 renderer, but the glyph path is
 * replaced by EXACT braille dot rasterising: our canvas only ever contains
 * braille patterns (U+2800..U+28FF, where U+28FF is the solid wordmark block)
 * and spaces, so every cell is drawn as its true 2x4 dot matrix — no font
 * coverage risk, pixel-perfect dots at fixed geometry.
 *
 *   php render-gif.php
 *
 * Writes frames/f000.png .. frames/f0NN.png at DUP_* geometry below.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Branding\DupBanner;

const CELL_W = 10;
const CELL_H = 16;
const DOT_S = 4;
/** Braille dot bits for (dx, dy), Unicode numbering. */
const BR_BITS = [[0x01, 0x02], [0x04, 0x20], [0x10, 0x80], [0x40, 0x08]];

$banner = new DupBanner('tc');
$outDir = __DIR__ . '/frames';
@mkdir($outDir, 0777, true);
array_map('unlink', glob("$outDir/*.png") ?: []);

$rgb = static fn (GdImage $im, array $c): int => imagecolorallocate($im, $c[0], $c[1], $c[2]);

for ($t = 0; $t < DupBanner::TICKS; $t++) {
    $cells = $banner->cells($t);
    $im = imagecreatetruecolor(DupBanner::COLS * CELL_W, DupBanner::ROWS * CELL_H);
    $bg = $rgb($im, DupBanner::BG);
    imagefilledrectangle($im, 0, 0, imagesx($im), imagesy($im), $bg);

    foreach ($cells as $cy => $row) {
        foreach ($row as $cx => $cell) {
            $px = $cx * CELL_W;
            $py = $cy * CELL_H;
            imagefilledrectangle($im, $px, $py, $px + CELL_W - 1, $py + CELL_H - 1, $bg);
            $cp = $cell['cp'];
            if ($cp === 0) {
                continue;
            }
            $bits = $cp - 0x2800;
            $fg = $rgb($im, $cell['fg'] ?? DupBanner::PEAK);
            if ($bits === 0xFF) {
                imagefilledrectangle($im, $px, $py, $px + CELL_W - 1, $py + CELL_H - 1, $fg);
                continue;
            }
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    if ($bits & BR_BITS[$dy][$dx]) {
                        $dxPx = $px + $dx * 5 + 1;
                        $dyPx = $py + $dy * 4;
                        imagefilledrectangle($im, $dxPx, $dyPx, $dxPx + DOT_S - 1, $dyPx + DOT_S - 1, $fg);
                    }
                }
            }
        }
    }

    imagepng($im, sprintf('%s/f%03d.png', $outDir, $t));
    imagedestroy($im);
}

echo 'rendered ' . DupBanner::TICKS . ' frames -> ' . $outDir . "\n";
