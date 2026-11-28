<?php
declare(strict_types=1);

// tests/Fixtures/drift/auth/NakedCaller.php — the member of the item 4.1
// auth fixture that dropped the require_auth() call (non-guard statement
// shape; the predicate recognizes it through the auth vocabulary).

final class NakedCaller
{
    public function handle($id)
    {
        work($id);
    }
}
