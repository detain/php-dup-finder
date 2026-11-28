<?php
declare(strict_types=1);

// tests/Fixtures/drift/sanitizer/EscapedCaller.php — item 4.1
// sanitizer-divergence fixture: the wrapped copy routes the argument
// through the sanitizer vocabulary's real_escape().

final class EscapedCaller
{
    public function handle($ip)
    {
        return send($this->real_escape($ip));
    }
}
