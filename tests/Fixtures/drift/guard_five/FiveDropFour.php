<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard_five/FiveDropFour.php — item 4.1 cap-tuning fixture:
// five members, four distinct guards; every member drops a different one,
// so the third alignment exceeds the optional-segment cap (3) and the
// fourth position's absence is deliberately not signed.

final class FiveDropFour
{
    public function handle($id)
    {
        if (!is_numeric($id)) {
            fail(101);
            return;
        }        if (!is_string($id)) {
            fail(102);
            return;
        }        if (!is_scalar($id)) {
            fail(103);
            return;
        }        work($id);
    }
}
