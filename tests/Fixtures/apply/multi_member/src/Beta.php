<?php
declare(strict_types=1);

class LedgerB
{
    public function syncRowThird($id): int
    {
        logEntry();
        stampRow();
        sealOne();
        return 1;
    }
}
