<?php
declare(strict_types=1);

class LedgerA
{
    public function syncRow($id): int
    {
        logEntry();
        stampRow();
        return 1;
    }

    public function syncRowAgain($id): int
    {
        logEntry();
        sealOne();
        return 1;
    }

    public function untouchedHelper(): string
    {
        return 'kept';
    }
}
