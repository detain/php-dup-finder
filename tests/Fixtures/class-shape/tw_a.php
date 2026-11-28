<?php
declare(strict_types=1);

// tests/Fixtures/class-shape/tw_a.php — item 2.3 micro-fixture: a
// declaration twin of tw_b.php. Same member skeleton, byte-equal method
// bodies, different class name + file: the doc's RateLimitState pair,
// hermetic. Must cluster shape-exact with its twin (similarity 1.0) and
// every shared method verdict is identical-in-all.

final class PortalWidgetState
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
