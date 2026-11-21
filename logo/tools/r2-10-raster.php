<?php

declare(strict_types=1);

/**
 * raster — render a logo-kit cell grid ([glyph, fg rgb|null, bg rgb|null])
 * straight to a truecolor PNG with a real monospace TTF.
 *
 * Port of candy-top's ansi-ttf-preview.php cell math, but rasterizing frames
 * directly from the cell grid (no ANSI re-parse) and, crucially, drawing all
 * Unicode block-element glyphs (█ ▀ ▄ ▌ ▐ ░ ▒ ▓) GEOMETRICALLY with GD rects
 * instead of via font glyphs — so half-blocks and dithers land pixel-exact at
 * the cell midline. Box-drawing / text glyphs go through imagettftext:
 * box chars are advance-anchored (lines stay continuous), everything else is
 * optically centered in its cell.
 *
 * Type-annotated copy in the idea-10 lab (upstream agent-10 copy untouched).
 */

const RS_FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
const RS_CW = 12;
const RS_CH = 24;

/** @param array{0:int,1:int,2:int} $rgb */
function rsColor(GdImage $im, array $rgb): int
{
    /** @var array<string, int> $memo */
    static $memo = [];
    $k = $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2];
    return $memo[$k] ??= imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
}

/** @param array{0:int,1:int,2:int} $rgb */
function rsColorAlpha(GdImage $im, array $rgb, int $alpha): int
{
    /** @var array<string, int> $memo */
    static $memo = [];
    $k = $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ",$alpha";
    return $memo[$k] ??= imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], $alpha);
}

/**
 * @param list<list<array{0:string,1:?array{0:int,1:int,2:int},2:?array{0:int,1:int,2:int}}>> $cells
 * @param array{0:int,1:int,2:int} $page
 */
function cells_to_png(array $cells, string $file, array $page = [11, 14, 20]): void
{
    [$cw, $ch, $font] = [RS_CW, RS_CH, RS_FONT];
    $rows = count($cells);
    $cols = count($cells[0]);
    $im = imagecreatetruecolor($cols * $cw, $rows * $ch);
    if (!$im instanceof GdImage) {
        throw new RuntimeException('GD: imagecreatetruecolor failed');
    }
    imagefilledrectangle($im, 0, 0, $cols * $cw, $rows * $ch, rsColor($im, $page));

    $size = $ch * 0.62;
    $advB = imagettfbbox($size, 0, $font, 'M');
    $adv = $advB[2] - $advB[0];                       // mono advance in px
    $xAdv = (int) round(($cw - $adv) / 2);            // advance-anchor offset
    $baselineFrac = 0.78;

    /** @var array<string, string|float> $BLOCKS */
    $BLOCKS = ['█' => 'full', '▀' => 'top', '▄' => 'bot', '▌' => 'left', '▐' => 'right',
               '░' => 0.25, '▒' => 0.5, '▓' => 0.75];

    foreach ($cells as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg]) {
            $px = $x * $cw;
            $py = $y * $ch;
            if ($bg !== null) {
                imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1, rsColor($im, $bg));
            }
            if ($g === ' ' || $g === '') {
                continue;
            }
            $f = $fg ?? [229, 229, 229];
            if (isset($BLOCKS[$g])) {
                $b = $BLOCKS[$g];
                $half = intdiv($ch, 2);
                if (is_string($b)) {
                    /** @var array{0:int,1:int,2:int,3:int} $rect */
                    $rect = match ($b) {
                        'full'  => [$px, $py, $px + $cw - 1, $py + $ch - 1],
                        'top'   => [$px, $py, $px + $cw - 1, $py + $half - 1],
                        'bot'   => [$px, $py + $ch - $half, $px + $cw - 1, $py + $ch - 1],
                        'left'  => [$px, $py, $px + intdiv($cw, 2) - 1, $py + $ch - 1],
                        default => [$px + intdiv($cw, 2), $py, $px + $cw - 1, $py + $ch - 1],
                    };
                    imagefilledrectangle($im, $rect[0], $rect[1], $rect[2], $rect[3], rsColor($im, $f));
                } else {
                    // 25/50/75 % dither of the full cell
                    imagefilledrectangle($im, $px, $py, $px + $cw - 1, $py + $ch - 1,
                        rsColorAlpha($im, $f, (int) round((1 - $b) * (127 / 1.2))));
                }
                continue;
            }
            $cp = mb_ord($g);
            $box = imagettfbbox($size, 0, $font, $g);
            $isBoxLine = $cp >= 0x2500 && $cp <= 0x257F;
            $xpos = $isBoxLine
                ? $px + $xAdv - (int) round($box[0])
                : $px + (int) round(($cw - ($box[2] - $box[0])) / 2) - (int) round($box[0]);
            imagettftext($im, $size, 0, $xpos, (int) round($py + $ch * $baselineFrac), rsColor($im, $f), $font, $g);
        }
    }
    imagepng($im, $file);
    imagedestroy($im);
}
