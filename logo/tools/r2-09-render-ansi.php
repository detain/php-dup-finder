<?php

declare(strict_types=1);

/**
 * render-ansi.php — emit the deliverable byte streams strictly FROM the
 * DupBanner class (single source of truth). Invoked by build.sh.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Logo\DupBanner;
use Phpdup\Logo\DupColorMode;

$here = __DIR__;
file_put_contents($here . '/logo.ansi', DupBanner::play(DupColorMode::Truecolor));
file_put_contents($here . '/logo.256.ansi', DupBanner::play(DupColorMode::Xterm256));
file_put_contents($here . '/logo-static.ansi', DupBanner::finalFrame(DupColorMode::Truecolor) . "\n");
file_put_contents($here . '/logo-static.txt', DupBanner::staticText());

printf(
    "emitted logo.ansi (%d B), logo.256.ansi, logo-static.ansi, logo-static.txt\n",
    filesize($here . '/logo.ansi') ?: 0
);
