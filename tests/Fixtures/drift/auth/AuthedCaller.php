<?php
declare(strict_types=1);

// tests/Fixtures/drift/auth/AuthedCaller.php — item 4.1 auth-drift
// fixture: the authorized copy calls require_auth() first.

final class AuthedCaller
{
    public function handle($id)
    {
        $this->require_auth();
        work($id);
    }
}
