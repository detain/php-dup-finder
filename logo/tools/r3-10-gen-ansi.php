<?php

declare(strict_types=1);

/**
 * gen-ansi.php — produce logo.ansi (truecolor) + the two fallback streams
 * straight from DupBanner (the class is the single source of truth).
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Lab\DupBanner;

$banner = new DupBanner();
file_put_contents(__DIR__ . '/logo.ansi', $banner->stream(DupBanner::DEPTH_TRUECOLOR));
file_put_contents(__DIR__ . '/logo-256.ansi', $banner->stream(DupBanner::DEPTH_256));
file_put_contents(__DIR__ . '/logo-plain.txt', $banner->stream(DupBanner::DEPTH_PLAIN));

printf("logo.ansi      %d bytes\n", strlen($banner->stream(DupBanner::DEPTH_TRUECOLOR)));
printf("logo-256.ansi  %d bytes\n", strlen($banner->stream(DupBanner::DEPTH_256)));
printf("logo-plain.txt %d bytes\n", strlen($banner->stream(DupBanner::DEPTH_PLAIN)));
