<?php

declare(strict_types=1);

/**
 * build.php — regenerates the deliverable streams from RadarBanner.
 * The class is the single source of truth; this only serialises its output.
 */

require __DIR__ . '/RadarBanner.php';

use Phpdup\Branding\RadarBanner;

$dir = __DIR__;
$banner = new RadarBanner();

file_put_contents("$dir/logo.ansi", $banner->play('tc'));
file_put_contents("$dir/logo-256.ansi", $banner->play('256'));
file_put_contents("$dir/logo-still.txt", $banner->finalFrame());
file_put_contents("$dir/logo-frame0.txt", $banner->frame(0));
file_put_contents("$dir/logo-plain.txt", RadarBanner::plainFrame() . "\n");

printf(
    "wrote logo.ansi (%d B) logo-256.ansi (%d B) ticks=%d\n",
    filesize("$dir/logo.ansi"),
    filesize("$dir/logo-256.ansi"),
    RadarBanner::TICKS,
);
