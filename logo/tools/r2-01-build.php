<?php

declare(strict_types=1);

/**
 * build.php — generate the truecolor animation stream (logo.ansi) and a static
 * plain fallback (logo.plain) FROM the DupBanner class (single source of truth).
 *
 *   php build.php
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Branding\DupBanner;

$dir = __DIR__;

$tc   = new DupBanner('tc');
$banner256 = new DupBanner('256');
$plain = new DupBanner('plain');

file_put_contents($dir . '/logo.ansi', $tc->play());
file_put_contents($dir . '/logo.256.ansi', $banner256->play());
file_put_contents($dir . '/logo-static.ansi', $tc->render());
file_put_contents($dir . '/logo.plain', $plain->plain());

printf(
    "logo.ansi: %d bytes  (%d ticks, %dx%d canvas)\n",
    strlen($tc->play()),
    DupBanner::TICKS,
    DupBanner::ROWS,
    DupBanner::COLS,
);
printf("logo.plain: %d bytes, 0 escapes: %s\n", strlen($plain->plain()), str_contains($plain->plain(), "\e") ? 'NO!' : 'yes');
