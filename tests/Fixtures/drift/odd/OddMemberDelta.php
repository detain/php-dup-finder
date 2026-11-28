<?php
declare(strict_types=1);

// tests/Fixtures/drift/odd/OddMemberDelta.php — the lone divergent value
// holder of the item 4.1 odd-one-out fixture.

final class OddMemberDelta
{
    public function handle($id)
    {
        $m = 'network';
        report($m);
    }
}
