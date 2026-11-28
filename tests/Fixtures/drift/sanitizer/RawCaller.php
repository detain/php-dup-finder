<?php
declare(strict_types=1);

// tests/Fixtures/drift/sanitizer/RawCaller.php — the raw twin of the
// item 4.1 sanitizer fixture: same call, unsanitized argument.

final class RawCaller
{
    public function handle($ip)
    {
        return send($ip);
    }
}
