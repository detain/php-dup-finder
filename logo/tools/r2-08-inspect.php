<?php

declare(strict_types=1);

/** inspect.php — print a tick's pixel grid as 2-char colour codes for eyeballing.
 *  Usage: php inspect.php <tick> */

require __DIR__ . '/DupBanner.php';

use Phpdup\Cli\DupBanner;

$ref  = new ReflectionClass(DupBanner::class);
$grid = $ref->getMethod('grid');
$grid->setAccessible(true);
$px   = $grid->invoke(null, (int) ($argv[1] ?? 44));

$pal = ['R' => [239, 68, 68], 'Y' => [250, 204, 21], 'B' => [59, 130, 246], 'W' => [248, 250, 252]];

$label = static function (array $c) use ($pal): string {
    $sum = $c[0] + $c[1] + $c[2];
    if ($sum > 700) {
        return 'w ';
    }
    $best  = 'R';
    $bestD = -1.0;
    foreach ($pal as $k => $p) {
        if ($k === 'W') {
            continue;
        }
        $d = abs($c[0] - $p[0]) + abs($c[1] - $p[1]) + abs($c[2] - $p[2]);
        if ($bestD < 0 || $d < $bestD) {
            $bestD = $d;
            $best  = $k;
        }
    }
    $ratio = $sum / array_sum($pal[$best]);
    return $ratio < 0.8 ? strtolower($best) . '-' : ($ratio > 1.12 ? $best . '+' : $best . ' ');
};

foreach ($px as $row) {
    $line = '';
    foreach ($row as $p) {
        $line .= $p === null ? '. ' : $label($p);
    }
    echo rtrim($line, '. ') . "\n";
}
