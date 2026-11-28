<?php
declare(strict_types=1);

// tests/Fixtures/drift/guard_pair/PairRaw.php — the guardless twin of the
// item 4.1 two-member fixture (a pair cannot collapse under the seed law).

final class PairRaw
{
    public function handle($id)
    {
        work($id);
    }
}
