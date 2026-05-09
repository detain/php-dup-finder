<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

/**
 * A point in the generalized AST where members of a cluster disagree.
 *
 * Each Hole carries:
 *   - placeholder: e.g. "$__P0" — what stands in for the values in the
 *     generalized template
 *   - kind: 'literal' | 'identifier' | 'name' | 'call' | 'subtree'
 *   - observedValues: one entry per cluster member, in the same order
 *   - inferredType: best-guess PHP type for the synthesized parameter
 *   - suggestedName: heuristic parameter name
 */
final class Hole
{
    public string $inferredType = 'mixed';
    public string $suggestedName = '';

    /**
     * @param list<string> $observedValues
     */
    public function __construct(
        public readonly string $placeholder,
        public readonly string $kind,
        public array $observedValues = [],
    ) {
    }

    public function appendObserved(string $repr): void
    {
        $this->observedValues[] = $repr;
    }

    public function uniqueValueCount(): int
    {
        return count(array_unique($this->observedValues));
    }
}
