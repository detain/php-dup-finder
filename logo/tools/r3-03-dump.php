<?php
declare(strict_types=1);
require __DIR__ . '/DupBanner.php';
use Phpdup\Branding\DupBanner;
foreach ([0, 43] as $t) {
    echo "=== tick $t ===\n";
    echo preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', DupBanner::frame($t)) . "\n";
}
echo "=== plain final ===\n" . DupBanner::frame(43, 'plain') . "\n";
