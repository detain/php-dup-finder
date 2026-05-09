<?php
declare(strict_types=1);

namespace Phpdup\Util;

final class LineRange
{
    public function __construct(public readonly int $start, public readonly int $end)
    {
        if ($end < $start) {
            throw new \InvalidArgumentException("end ($end) < start ($start)");
        }
    }

    public function lines(): int
    {
        return $this->end - $this->start + 1;
    }

    public function __toString(): string
    {
        return "{$this->start}-{$this->end}";
    }
}
