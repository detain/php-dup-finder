<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard/GuardMissing.php — the deficient copy of the
// item 4.1 guard-drift fixture: same tail, guard never written.

final class GuardMissing
{
    public function handle($id)
    {
        work($id);
    }
}
