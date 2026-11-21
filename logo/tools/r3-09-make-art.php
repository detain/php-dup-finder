<?php

declare(strict_types=1);

/**
 * make-art.php — regenerate every static art asset FROM DupBanner.php.
 *
 *   php make-art.php
 *
 * Writes logo.ansi (truecolor play stream), logo.256.ansi (256-colour
 * fallback stream), logo.plain (zero-escape fallback) into this directory.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Branding\DupBanner;

$dir = __DIR__;

$tc = new DupBanner('tc');
file_put_contents($dir . '/logo.ansi', $tc->play());

$c256 = new DupBanner('256');
file_put_contents($dir . '/logo.256.ansi', $c256->play());

$plain = new DupBanner('plain');
file_put_contents($dir . '/logo.plain', $plain->play());

printf(
    "logo.ansi %d B · logo.256.ansi %d B · logo.plain %d B · ticks %d\n",
    strlen($tc->play()),
    strlen($c256->play()),
    strlen($plain->play()),
    DupBanner::TICKS,
);
