<?php
declare(strict_types=1);

function nestOuterX(): int
{
    logEntry();
    function nestInnerX(): int
    {
        logEntry();
        stampRow();
        return 1;
    }
    return 1;
}
