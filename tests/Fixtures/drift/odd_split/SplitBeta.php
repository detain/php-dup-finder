<?php
declare(strict_types=1);

// tests/Fixtures/drift/odd_split/SplitBeta.php — item 4.1 NEGATIVE CONTROL: a
// 2-vs-2 split is disagreement, not an odd one out; no predicate may fire.

final class SplitBeta
{
    public function handle($id)
    {
        $m = 'disk';
        report($m);
    }
}
