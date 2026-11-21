<?php

declare(strict_types=1);

require __DIR__ . '/DupBanner.php';

$args = \array_slice($_SERVER['argv'] ?? [], 1);
$noAnsi = \in_array('--no-ansi', $args, true);
$noColor = \getenv('NO_COLOR') !== false && \getenv('NO_COLOR') !== '';

$b = new Phpdup\Reporting\DupBanner();
$b->play(STDOUT, !$noAnsi && !$noColor, $noAnsi);
