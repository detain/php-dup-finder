<?php
declare(strict_types=1);

function nestOuterY(): int
{
    logEntry();
    function nestInnerY(): int
    {
        logEntry();
        stampRow();
        return 1;
    }
    return 1;
}
