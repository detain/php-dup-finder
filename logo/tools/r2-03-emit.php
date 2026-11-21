<?php

declare(strict_types=1);

/**
 * emit.php — write logo.ansi (+ static plain sheet) FROM the class.
 * The class is the single source of truth; build.sh calls this first.
 *
 * Usage: php emit.php [seed]   (default seed 0 — canonical inner-matched layout)
 */

require __DIR__ . '/TreeGrowthBanner.php';

$seed = (int) ($argv[1] ?? 0);
$banner = new TreeGrowthBanner($seed);

file_put_contents(__DIR__ . '/logo.ansi', $banner->stream(TreeGrowthBanner::MODE_TRUECOLOR));
file_put_contents(__DIR__ . '/logo-256.ansi', $banner->stream(TreeGrowthBanner::MODE_256));
file_put_contents(__DIR__ . '/logo-static.txt', $banner->staticPlain());

printf(
    "seed %d | frames %d | playback %.2fs | hold frame %dx%d | %s bytes\n",
    $seed,
    $banner->frameCount(),
    $banner->finalTick() * TreeGrowthBanner::FRAME_INTERVAL_US / 1_000_000,
    TreeGrowthBanner::ROWS,
    TreeGrowthBanner::COLS,
    strlen((string) file_get_contents(__DIR__ . '/logo.ansi')),
);

if (PHP_SAPI === 'cli' && getenv('EMIT_PREVIEW') === '1') {
    echo $banner->staticPlain();
}
