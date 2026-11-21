<?php

declare(strict_types=1);

/**
 * gen-ansi.php — driver: writes logo.ansi (truecolor) + logo-256.ansi from
 * DupBanner. The class is the single source of truth; see build.sh.
 */

require __DIR__ . '/DupBanner.php';

$banner = new \Phpdup\Support\DupBanner();
$tc = $banner->play();
$b256 = $banner->play256();

file_put_contents(__DIR__ . '/logo.ansi', $tc);
file_put_contents(__DIR__ . '/logo-256.ansi', $b256);

$final = $banner->frame(\Phpdup\Support\DupBanner::FINAL_TICK);
fwrite(STDERR, sprintf(
    "logo.ansi: %d bytes, %d ticks, final frame %d bytes / %d rows\n",
    strlen($tc),
    \Phpdup\Support\DupBanner::TICKS,
    strlen($final),
    substr_count($final, "\n"),
));
