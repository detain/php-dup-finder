<?php
declare(strict_types=1);

namespace Phpdup\Semantic;

/**
 * No-op TypeProvider used as the default. Returns null for every
 * query so callers fall back to their own heuristics. Cost: zero —
 * the call is monomorphic and JIT-friendly.
 */
final class NullTypeProvider implements TypeProvider
{
    public function typeAt(string $file, int $line): ?string
    {
        return null;
    }

    public function name(): string
    {
        return 'null';
    }
}
