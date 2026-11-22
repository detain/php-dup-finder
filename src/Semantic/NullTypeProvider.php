<?php
declare(strict_types=1);

namespace Phpdup\Semantic;

/**
 * No-op TypeProvider. Returns null for every query so callers fall
 * back to their own heuristics. Cost: zero — the call is monomorphic
 * and JIT-friendly.
 *
 * Status: NOT WIRED yet (engine-S31a catalog ledger, row 6): nothing
 * constructs any TypeProvider today. This class is the planned
 * zero-cost default once the type-upgrade path lands.
 */
final class NullTypeProvider implements TypeProvider
{
    #[\Override]
    public function typeAt(string $file, int $line): ?string
    {
        return null;
    }

    #[\Override]
    public function name(): string
    {
        return 'null';
    }
}
