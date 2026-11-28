<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard/GuardedThree.php — guarded sibling, byte-equal body
// to the other members of the set (item 4.1 guard-drift fixture).

final class GuardedThree
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
