<?php

declare(strict_types=1);

/**
 * gen-ansi.php — write logo.ansi from the DupBanner class (single source of
 * truth). Usage: php tools/gen-ansi.php [out.ansi]
 */

require __DIR__ . '/../DupBanner.php';

/** @var array<int, mixed> $argvIn */
$argvIn = (array) ($_SERVER['argv'] ?? []);
$out = (string) ($argvIn[1] ?? __DIR__ . '/../logo.ansi');
$bytes = Phpdup\Ui\DupBanner::stream(Phpdup\Ui\DupBanner::MODE_TRUECOLOR);
if (file_put_contents($out, $bytes) === false) {
    fwrite(STDERR, "cannot write $out\n");
    exit(1);
}
fwrite(STDERR, sprintf("logo.ansi: %d bytes, %d ticks\n", strlen($bytes), Phpdup\Ui\DupBanner::TICKS));
