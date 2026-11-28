<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard_pair/PairGuarded.php — item 4.1 two-member
// Start.php analog: the guarded copy of the pair.

final class PairGuarded
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
