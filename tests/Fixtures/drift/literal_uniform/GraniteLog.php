<?php
declare(strict_types=1);

// tests/Fixtures/drift/literal_uniform/GraniteLog.php — item 4.1
// NEGATIVE CONTROL: each member's string names only its own module.

final class GraniteLog
{
    public function handle($id)
    {
        $m = 'granite module id invalid';
        return respond($m);
    }
}
