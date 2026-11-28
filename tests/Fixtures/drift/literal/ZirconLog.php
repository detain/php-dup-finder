<?php
declare(strict_types=1);

// tests/Fixtures/drift/literal/ZirconLog.php — the cited sibling of the
// item 4.1 copy-paste fixture: its string names its OWN module, which is
// exactly the case the predicate must refuse.

final class ZirconLog
{
    public function handle($id)
    {
        $m = 'zircon module id invalid';
        return respond($m);
    }
}
