<?php

declare(strict_types=1);

/**
 * autoload-shim.php — pulls the repo vendor autoloader (read-only use of its
 * binaries + deps) and our class, for standalone phpstan runs on this lab.
 */
require '/home/sites/php-dup-finder/vendor/autoload.php';
require __DIR__ . '/DupBanner.php';
