<?php

declare(strict_types=1);

/**
 * build.php — write every art artefact FROM DupBanner.php (single source of
 * truth): truecolor stream, 256-colour stream, static final frame, plain text.
 *
 *   php build.php
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Branding\DupBanner;

$dir = __DIR__;

$tc = new DupBanner('tc');
$x256 = new DupBanner('256');
$plain = new DupBanner('plain');

file_put_contents($dir . '/logo.ansi', $tc->play());
file_put_contents($dir . '/logo.256.ansi', $x256->play());
file_put_contents($dir . '/logo-static.ansi', $tc->render());
file_put_contents($dir . '/logo.plain', $plain->plain());

printf(
    "logo.ansi        %6d bytes  (%d ticks x %d ms = %.3f s, %dx%d canvas)\n",
    strlen($tc->play()),
    DupBanner::TICKS,
    DupBanner::TICK_MS,
    DupBanner::TICKS * DupBanner::TICK_MS / 1000,
    DupBanner::ROWS,
    DupBanner::COLS,
);
printf("logo.256.ansi    %6d bytes\n", strlen($x256->play()));
printf("logo-static.ansi %6d bytes\n", strlen($tc->render()));
$p = $plain->plain();
printf("logo.plain       %6d bytes  escapes: %d\n", strlen($p), substr_count($p, "\e"));
