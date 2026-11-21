<?php

declare(strict_types=1);

/**
 * build.php — emit the logo artefacts FROM the class (single source of truth):
 * logo.ansi (truecolor), logo256.ansi (256-colour), logo.txt (plain static).
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Cli\DupBanner;

file_put_contents(__DIR__ . '/logo.ansi', DupBanner::play('tc'));
file_put_contents(__DIR__ . '/logo256.ansi', DupBanner::play('256'));
file_put_contents(__DIR__ . '/logo.txt', DupBanner::text());

fwrite(STDERR, sprintf(
    "logo.ansi %d B (tc, %d ticks) | logo256.ansi %d B | logo.txt %d B (final %dx%d)\n",
    strlen(DupBanner::play('tc')),
    DupBanner::FRAMES,
    strlen(DupBanner::play('256')),
    strlen(DupBanner::text()),
    DupBanner::ROWS,
    56,
));
