<?php

declare(strict_types=1);

/**
 * generate.php — writes logo.ansi (truecolor), logo256.ansi and logo.txt
 * (plain fallback) straight from DupBanner. Input to build.sh.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Cli\DupBanner;

file_put_contents(__DIR__ . '/logo.ansi', DupBanner::play(DupBanner::DEPTH_TC));
file_put_contents(__DIR__ . '/logo256.ansi', DupBanner::play(DupBanner::DEPTH_256));
file_put_contents(__DIR__ . '/logo.txt', DupBanner::text());

fwrite(STDERR, sprintf(
    "logo.ansi %d bytes | logo256.ansi %d bytes | logo.txt %d bytes | frames=%d window=%.3fs\n",
    (int) filesize(__DIR__ . '/logo.ansi'),
    (int) filesize(__DIR__ . '/logo256.ansi'),
    (int) filesize(__DIR__ . '/logo.txt'),
    DupBanner::FRAMES,
    DupBanner::FRAMES * DupBanner::TICK_MS / 1000
));
