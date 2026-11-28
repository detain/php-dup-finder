<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard/GuardedOne.php — item 4.1 fixture: one of
// three guarded copies; the shared siblings in this directory carry the
// identical input guard, GuardMissing.php dropped it (drift/guard_cluster
// shape, doc §4.1 guard-drift predicate).

final class GuardedOne
{
    public function handle($id)
    {
        if (!is_numeric($id)) {
            fail('invalid id');
            return;
        }
        work($id);
    }
}
