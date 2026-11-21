<?php

declare(strict_types=1);

/**
 * debug-dots.php — dev tool: print the private 112x28 dot grid of a tick as
 * literal dots (2:1 aspect like a cell) so the letters can be read directly.
 *
 *   php debug-dots.php [tick]
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Reporting\DupBanner;

$tick = (int) ($argv[1] ?? 44);
$b = new DupBanner();
$ref = new ReflectionMethod(DupBanner::class, 'dotGrid');
$grid = $ref->invoke($b, $tick);

for ($y = 0; $y < DupBanner::DOT_H; $y++) {
    $line = '';
    for ($x = 0; $x < DupBanner::DOT_W; $x++) {
        $line .= $grid['prio'][$y][$x] === 0 ? ' ' : ($grid['prio'][$y][$x] >= 4 ? '@' : ($grid['prio'][$y][$x] === 3 ? '*' : '.'));
    }
    printf("%2d %s\n", $y, $line);
}
