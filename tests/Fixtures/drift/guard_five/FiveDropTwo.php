<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard_five/FiveDropTwo.php — item 4.1 cap-tuning fixture:
// five members, four distinct guards; every member drops a different one,
// so the third alignment exceeds the optional-segment cap (3) and the
// fourth position's absence is deliberately not signed.

final class FiveDropTwo
{
    public function handle($id)
    {
        if (!is_numeric($id)) {
            fail(101);
            return;
        }        if (!is_scalar($id)) {
            fail(103);
            return;
        }        if (!is_bool($id)) {
            fail(104);
            return;
        }        work($id);
    }
}
