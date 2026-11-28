<?php
declare(strict_types=1);

// tests/Fixtures/drift/odd_split/SplitGamma.php — the other half of the 2-vs-2
// split control.

final class SplitGamma
{
    public function handle($id)
    {
        $m = 'network';
        report($m);
    }
}
