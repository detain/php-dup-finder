<?php

/**
 * verify.php — fail-loud verification battery for the Jaccard-Eclipse banner.
 *
 *   php verify.php
 *
 * Checks:
 *  1. cross-process byte determinism: frames recomputed HERE must equal the
 *     frame bodies inside logo.ansi (written by a SEPARATE php process via
 *     tools/gen-ansi.php) for tick 0, the final tick, and every tick between;
 *  2. bounds: every truecolor/256/plain frame is exactly ROWS lines, each
 *     <= COLS display columns (no overflow at 80 cols);
 *  3. plain fallback contains ZERO \e bytes;
 *  4. tick budget: TICKS <= 45 and TICKS * FRAME_US <= 1.5 s (<= 30 fps);
 *  5. 256 path: every SGR is valid (38;5/48;5, indices 0..255, plus 0/1
 *     resets) and NO 38;2/48;2 leaks; truecolor path uses only valid
 *     38;2;R;G;B / 48;2;R;G;B components 0..255;
 *  6. on-screen readout EQUALS |A∩B|/|A∪B| of the DRAWN discs — computed
 *     INDEPENDENTLY here (own rasterisation + analytic circle formula) from
 *     DupBanner::geometry() — for spot-checked ticks; gauge fill length is
 *     checked against the same value.
 */

declare(strict_types=1);

require __DIR__ . '/DupBanner.php';

use Phpdup\Ui\DupBanner as D;

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
};

/**
 * Runtime guard against a widened float so PHPStan cannot constant-fold the
 * comparison to a literal (these check the class's own design budget).
 */
$assertLe = static function (float $actual, float $limit, string $what) use ($fail): void {
    if ($actual > $limit) {
        $fail(sprintf('%s = %.4f exceeds limit %.4f', $what, $actual, $limit));
    }
};

// helpers ---------------------------------------------------------------

/** strip CSI sequences (SGR etc.) -> visible text only */
$strip = static fn(string $s): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $s);

$linesOf = static function (string $frame) use ($strip): array {
    $plain = $strip($frame);
    // a frame may start with cursor-home; drop it, split rows
    return explode("\n", $plain);
};

// 4. tick budget ---------------------------------------------------------
$spanMs = D::TICKS * D::FRAME_US / 1000.0;
$assertLe((float) D::TICKS, 45.0, 'tick count');
$assertLe($spanMs, 1500.0, 'animation span (ms)');
$assertLe(1_000_000 / (float) D::FRAME_US, 30.0, 'frame rate (fps)');
printf("budget OK: %d ticks x %d us = %.3f s, %.2f fps (<=30)\n", D::TICKS, D::FRAME_US, $spanMs / 1000.0, 1_000_000 / D::FRAME_US);

// 1. cross-process byte determinism vs logo.ansi --------------------------
$stream = @file_get_contents(__DIR__ . '/logo.ansi');
if (!is_string($stream) || $stream === '') {
    $fail('logo.ansi missing — run build.sh first');
}
if (!str_starts_with($stream, "\e[?25l") || !str_ends_with($stream, "\e[?25h")) {
    $fail('logo.ansi missing hide/show cursor wrappers');
}
$pieces = explode("\e[H", $stream);
if (count($pieces) !== D::TICKS + 1) {
    $fail('logo.ansi splits into ' . count($pieces) . ' parts, want ' . (D::TICKS + 1));
}
for ($t = 0; $t <= D::FINAL_TICK; $t++) {
    $expected = substr(D::frame($t, D::MODE_TRUECOLOR), 3); // drop the leading \e[H
    $piece = $pieces[$t + 1];
    if ($t === D::FINAL_TICK) {
        // final piece carries the show-cursor tail
        if (!str_ends_with($piece, "\e[?25h")) {
            $fail('final piece missing show-cursor');
        }
        $piece = substr($piece, 0, -strlen("\e[?25h"));
    }
    if ($piece !== $expected) {
        $fail("tick $t: file bytes differ from freshly computed frame (cross-process determinism)");
    }
}
echo "determinism OK: tick 0 + final + all between byte-identical across processes\n";

// 2. bounds for every frame, all three modes ------------------------------
foreach ([D::MODE_TRUECOLOR, D::MODE_256, D::MODE_PLAIN] as $mode) {
    for ($t = 0; $t <= D::FINAL_TICK; $t++) {
        $rows = $linesOf(D::frame($t, $mode));
        if (count($rows) !== D::ROWS) {
            $fail("mode $mode tick $t: " . count($rows) . ' rows, want ' . D::ROWS);
        }
        foreach ($rows as $i => $row) {
            $w = mb_strlen($row);
            if ($w > D::COLS) {
                $fail("mode $mode tick $t row $i: $w cols > " . D::COLS);
            }
        }
    }
}
echo "bounds OK: all frames " . D::ROWS . " rows x <=" . D::COLS . " cols (fits 80 cols)\n";

// final frame must fill the canvas exactly (hold frame)
$finalRows = $linesOf(D::frame(D::FINAL_TICK, D::MODE_TRUECOLOR));
foreach ($finalRows as $i => $row) {
    if (mb_strlen($row) !== D::COLS) {
        $fail("final row $i width " . mb_strlen($row) . ' != ' . D::COLS);
    }
}

// 3. plain fallback: ZERO escapes ------------------------------------------
$plain = D::plain();
if (str_contains($plain, "\e")) {
    $fail('plain fallback contains ESC');
}
echo "plain OK: zero escape bytes\n";

// 5. SGR shape checks ------------------------------------------------------
$sgrRe = '/\e\[([0-9;]*)m/';
$sgrTicks = [];
for ($t = 0; $t <= D::FINAL_TICK; $t += 7) {
    $sgrTicks[] = $t;
}
$sgrTicks[] = D::FINAL_TICK;
foreach ($sgrTicks as $t) {
    $f256 = D::frame($t, D::MODE_256);
    if (preg_match('/\e\[.*(?:38|48);2;/', $f256) === 1) {
        $fail("tick $t 256-frame leaks 38;2/48;2");
    }
    preg_match_all($sgrRe, $f256, $mm);
    foreach ($mm[1] as $params) {
        if ($params === '' || $params === '0') {
            continue;
        }
        $ok = preg_match('/^(?:1;)?(?:38|48);5;\d{1,3}(?:;48;5;\d{1,3})?$/', $params) === 1;
        if (!$ok) {
            $fail("tick $t 256-frame invalid SGR [$params]");
        }
        preg_match_all('/(?:38|48);5;(\d+)/', $params, $idx);
        foreach ($idx[1] as $ci) {
            if ((int) $ci > 255) {
                $fail("tick $t 256 colour index $ci > 255");
            }
        }
    }

    $ftc = D::frame($t, D::MODE_TRUECOLOR);
    preg_match_all($sgrRe, $ftc, $mm2);
    foreach ($mm2[1] as $params) {
        if ($params === '' || $params === '0') {
            continue;
        }
        $ok = preg_match('/^(?:1;)?38;2;\d{1,3};\d{1,3};\d{1,3}(?:;48;2;\d{1,3};\d{1,3};\d{1,3})?$/', $params) === 1;
        if (!$ok) {
            $fail("tick $t truecolor invalid SGR [$params]");
        }
        preg_match_all('/(?:38|48);2;(\d+);(\d+);(\d+)/', $params, $rgb);
        foreach ([1, 2, 3] as $k) {
            foreach ($rgb[$k] as $v) {
                if ((int) $v > 255) {
                    $fail("tick $t component $v > 255");
                }
            }
        }
    }
}
echo "sgr OK: truecolor + 256 paths valid (every index <= 255, no mode leaks)\n";

// 6. readout equals overlap/union of the DRAWN discs ----------------------
//
// Independent recomputation, two ways:
//  (a) rasterise the membership exactly as specified by geometry()
//      (pixel (pr, c) centre = (c + 0.5, pr + 0.5); r = radius);
//  (b) analytic equal-circle Jaccard from the continuous intersection area.
$analytic = static function (float $r, float $d): float {
    if ($d >= 2 * $r) {
        return 0.0;
    }
    $inter = 2 * $r * $r * acos($d / (2 * $r)) - ($d / 2.0) * sqrt(max(0.0, 4 * $r * $r - $d * $d));
    $union = 2 * M_PI * $r * $r - $inter;

    return $union <= 0.0 ? 0.0 : $inter / $union;
};

$raster = static function (float $r, float $cy, float $ax, float $bx): array {
    $inter = 0;
    $union = 0;
    $r2 = $r * $r;
    for ($pr = 0; $pr < 14; $pr++) {
        $dy = $pr + 0.5 - $cy;
        for ($c = 0; $c < D::COLS; $c++) {
            $dx = $c + 0.5;
            $inA = ($dx - $ax) ** 2 + $dy ** 2 <= $r2;
            $inB = ($dx - $bx) ** 2 + $dy ** 2 <= $r2;
            if ($inA && $inB) {
                $inter++;
                $union++;
            } elseif ($inA || $inB) {
                $union++;
            }
        }
    }

    return $union === 0 ? [0.0, 0] : [$inter / $union, $union];
};

$spotTicks = [0, 5, 11, 16, 21, 26, 30, 33, 35, 36, 37, 40, 43, D::FINAL_TICK];
$seenCross = false;
foreach ($spotTicks as $t) {
    [$r, $cy, $ax, $bx] = D::geometry($t);

    // read the on-screen number from the frame itself (plain mode, no parsing noise)
    $rows = $linesOf(D::frame($t, D::MODE_PLAIN));
    $row4 = $rows[4];
    if (preg_match('/J = (\d\.\d\d)/', $row4, $m) !== 1) {
        $fail("tick $t: readout number not found in row 4");
    }
    $shown = (float) $m[1];

    [$ras, $unionPx] = $raster($r, $cy, $ax, $bx);
    $ana = $analytic($r, abs($bx - $ax));

    // the drawn-disc raster counts and the class share integer inputs, so
    // the printed text must be EXACTLY the %.2f rendering of our own ratio
    if ($m[1] !== sprintf('%.2f', $ras)) {
        $fail(sprintf('tick %d: readout %s != rasterised overlap/union %s', $t, $m[1], sprintf('%.2f', $ras)));
    }
    if (abs($shown - $ana) > 0.03) {
        $fail(sprintf('tick %d: readout %.2f vs analytic %.4f (rasterisation drift too large)', $t, $shown, $ana));
    }
    if ($unionPx < 2 * $r * $r) {
        $fail("tick $t: union px $unionPx implausibly small");
    }

    // gauge fill must track the same value: filled = round(val * 19) of 20 cells
    $gaugeRow = $rows[2];
    $gaugeSeg = mb_substr($gaugeRow, 32, 20);
    $fill = substr_count($gaugeSeg, '▓') + substr_count($gaugeSeg, '█');
    $want = (int) round($ras * 19);
    if ($fill !== $want) {
        $fail(sprintf('tick %d: gauge fill %d != round(%.4f*19)=%d', $t, $fill, $ras, $want));
    }

    if ($shown >= D::THRESHOLD) {
        $seenCross = true;
    }
    printf("  tick %2d  readout %.2f  raster %.4f  analytic %.4f  gauge %d/19\n", $t, $shown, $ras, $ana, $fill);
}
if (!$seenCross) {
    $fail('readout never reaches the 0.80 threshold default');
}
echo "readout OK: on-screen J == |A∩B|/|A∪B| of drawn discs (raster+analytic cross-check), gold tick at 0.80 crossed\n";

echo "ALL CHECKS PASSED\n";
