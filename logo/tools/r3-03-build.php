<?php

declare(strict_types=1);

/**
 * build.php — regenerate every art artefact FROM DupBanner.php (single
 * source of truth): the truecolor animation stream, the 256-colour twin,
 * the static truecolor frame and the zero-escape plain fallback.
 *
 *   php build.php
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Branding\DupBanner;

$dir = __DIR__;

$tc    = DupBanner::play(DupBanner::MODE_TRUECOLOR);
$x256  = DupBanner::play(DupBanner::MODE_256);
$plain = DupBanner::play(DupBanner::MODE_PLAIN);

file_put_contents($dir . '/logo.ansi', $tc);
file_put_contents($dir . '/logo.256.ansi', $x256);
file_put_contents($dir . '/logo.plain', $plain);
file_put_contents($dir . '/logo-static.ansi', DupBanner::render(DupBanner::MODE_TRUECOLOR) . "\n");

printf(
    "logo.ansi: %d bytes (%d ticks @ %d ms = %.3f s, %dx%d canvas)\n",
    strlen($tc),
    DupBanner::TICKS,
    DupBanner::TICK_MS,
    DupBanner::TICKS * DupBanner::TICK_MS / 1000,
    DupBanner::ROWS,
    DupBanner::COLS,
);
printf("logo.256.ansi: %d bytes\n", strlen($x256));
printf("logo.plain: %d bytes, escapes: %d\n", strlen($plain), substr_count($plain, "\e"));
