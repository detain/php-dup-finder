<?php
declare(strict_types=1);

// tests/Fixtures/drift/literal/QuartzLog.php — item 4.1 copy-paste
// fixture: this member's user-facing string cites the sibling module's
// identity while naming none of its own.

final class QuartzLog
{
    public function handle($id)
    {
        $m = 'invalid id for the zircon module';
        return respond($m);
    }
}
