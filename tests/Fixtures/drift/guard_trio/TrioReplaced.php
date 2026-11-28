<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard_trio/TrioReplaced.php — the guardless member
// of the trio fixture: it carries a smaller statement where the guard
// sits, so its trial yields the same optional-hole count as the guarded
// seeds and the guarded template keeps the seed (item 4.1).

final class TrioReplaced
{
    public function handle($id)
    {
        log_line('skipped check');
        work($id);
    }
}
