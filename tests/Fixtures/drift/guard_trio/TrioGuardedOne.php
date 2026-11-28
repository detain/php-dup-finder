<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard_trio/TrioGuardedOne.php — guarded member of
// the item 4.1 three-member tie-candidate case (deficient member holds a
// REPLACEMENT statement, so the hole count ties and the guarded seed wins).

final class TrioGuardedOne
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
