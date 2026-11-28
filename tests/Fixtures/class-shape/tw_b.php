<?php
declare(strict_types=1);

// tests/Fixtures/class-shape/tw_b.php — twin half b of the item-2.3
// declaration pair (see tw_a.php). Only the class name differs.

final class LedgerWidgetState
{
    private const VERSION = 'v1';
    private string $cacheKey = 'portal.default';

    public function refresh(): void
    {
        $now = time();
        $this->cacheKey = $this->cacheKey . '-' . (string) $now;
    }

    public function version(): string
    {
        return self::VERSION;
    }
}
