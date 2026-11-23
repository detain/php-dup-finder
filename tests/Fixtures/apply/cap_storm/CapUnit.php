<?php
declare(strict_types=1);

class CapUnit
{
    public function pumpRow(): int
    {
        logEntry();
        stampRow();
        return 1;
    }
}
