<?php

declare(strict_types=1);

/**
 * preview.php — debug viewer: dumps selected ticks as plain-styled text
 * (ANSI stripped by frame-mode) so the choreography can be eyeballed fast.
 *   php preview.php 0 10 22 31 34 40 44
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Logo\DupBanner;
use Phpdup\Logo\DupColorMode;

$ticks = array_slice($argv, 1);
$ticks = $ticks === [] ? [0, 5, 10, 15, 19, 22, 25, 28, 31, 34, 36, 39, 42, 44] : array_map('intval', $ticks);

foreach ($ticks as $tick) {
    printf("--- tick %d (t=%.3fs) ---\n", $tick, $tick / DupBanner::FPS);
    $plain = DupBanner::frame($tick, DupColorMode::Plain);
    foreach (explode("\n", $plain) as $row) {
        printf("|%s|\n", str_pad(substr($row, 0, DupBanner::COLS), DupBanner::COLS));
    }
    echo "\n";
}
