<?php
declare(strict_types=1);

// tests/Fixtures/drift/odd/UniformMemberBeta.php — item 4.1 odd-one-out
// fixture: three members agree on the value; OddMemberDelta alone holds
// another. The string is assigned outside any call argument so only the
// odd-one-out predicate (never the args-path option arm) can see it.

final class UniformMemberBeta
{
    public function handle($id)
    {
        $m = 'disk';
        report($m);
    }
}
