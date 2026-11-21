<?php

declare(strict_types=1);

/**
 * gen-ansi.php — write the deliverable ANSI streams FROM DupBanner.php
 * (the single source of truth). No timing here: pure frame emission.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Branding\DupBanner;

$dir = __DIR__;

file_put_contents("$dir/logo.ansi", (new DupBanner('tc'))->stream());
file_put_contents("$dir/logo.256.ansi", (new DupBanner('256'))->stream());
file_put_contents("$dir/logo.plain", (new DupBanner('plain'))->plain());

$tc = new DupBanner('tc');
file_put_contents("$dir/logo-static.ansi", $tc->frame(DupBanner::TICKS - 1));

printf(
    "wrote logo.ansi (%d B), logo.256.ansi (%d B), logo-static.ansi (%d B), logo.plain\n",
    filesize("$dir/logo.ansi") ?: 0,
    filesize("$dir/logo.256.ansi") ?: 0,
    filesize("$dir/logo-static.ansi") ?: 0,
);

[$litCount, , , $streaks] = $tc->summary();
printf("plot: %d lit dots; streaks: ", $litCount);
foreach ($streaks as $s) {
    printf("%s(len %d) ", $s['type'], $s['length']);
}
echo "\n";
