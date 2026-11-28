<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard_uniform/UniformOne.php — item 4.1 NEGATIVE
// CONTROL: all three members carry the identical guard; no presence hole
// exists and no predicate may fire.

final class UniformOne
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
