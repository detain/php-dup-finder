<?php

declare(strict_types=1);

/**
 * build-frames — render the LensBanner ticks to PNG frames + logo.ansi.
 *
 * The class (LensBanner.php) is the single source of truth: every frame here
 * comes from LensBanner::cells()/render(). Emits:
 *   frames/f-TT.png   raster of each tick (for the GIF)
 *   logo.ansi         raw truecolor byte stream (one-shot, ends on hold)
 *   logo-256.ansi     xterm-256 variant (reference/fallback evidence)
 *   logo-final.png    final hold frame as a standalone PNG
 *
 *   php build-frames.php
 */

require __DIR__ . '/LensBanner.php';
require __DIR__ . '/raster.php';

$dir = __DIR__;
$frameDir = $dir . '/frames';
if (!is_dir($frameDir)) {
    mkdir($frameDir, 0777, true);
}
foreach (glob($frameDir . '/f-*.png') ?: [] as $old) {
    unlink($old);
}

$page = [11, 14, 20];
for ($t = 0; $t <= LensBanner::LAST_TICK; $t++) {
    cells_to_png(LensBanner::cells($t), sprintf($frameDir . '/f-%02d.png', $t), $page);
}

file_put_contents($dir . '/logo.ansi', LensBanner::render(LensBanner::DEPTH_TRUECOLOR));
file_put_contents($dir . '/logo-256.ansi', LensBanner::render(LensBanner::DEPTH_256));
cells_to_png(LensBanner::cells(LensBanner::LAST_TICK), $dir . '/logo-final.png', $page);

// family light-up order (deterministic sanity read-out for the build log)
$ref = new ReflectionMethod(LensBanner::class, 'triggerTick');
$order = [];
foreach (array_keys(LensBanner::CLUSTER_PALETTE) as $fam) {
    $order[$fam] = $ref->invoke(null, $fam);
}
asort($order);
fwrite(STDERR, 'lens path family order: ' . implode(' -> ', $order) . ' (ticks)' . PHP_EOL);
fwrite(STDERR, 'frames: ' . (LensBanner::TICKS) . ' ; logo.ansi: '
    . filesize($dir . '/logo.ansi') . ' bytes' . PHP_EOL);
