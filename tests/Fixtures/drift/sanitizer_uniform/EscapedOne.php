<?php
declare(strict_types=1);

// tests/Fixtures/drift/sanitizer_uniform/EscapedOne.php — item 4.1
// NEGATIVE CONTROL: both members wrap the argument identically; no
// sanitizer predicate may fire.

final class EscapedOne
{
    public function handle($ip)
    {
        return send($this->real_escape($ip));
    }
}
