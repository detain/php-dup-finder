<?php
declare(strict_types=1);

// tests/Fixtures/drift/literal_uniform/MarbleLog.php — the sibling of the
// literal uniform control, naming its own module too.

final class MarbleLog
{
    public function handle($id)
    {
        $m = 'marble module id invalid';
        return respond($m);
    }
}
