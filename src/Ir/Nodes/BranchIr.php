<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing a two-way conditional (`if` / `else`,
 * ternary, match arm).
 *
 * Carries up to three children:
 *
 *   - `$condition` — IR of the predicate. Always present.
 *   - `$then`      — IR of the consequent block.
 *   - `$else`      — IR of the alternative block, or `null` when
 *                     the source had a bare `if` with no else.
 *
 * Match expressions and switch statements lift to a chain of
 * BranchIr nodes — see {@see \Phpdup\Ir\IrLifter::liftSwitch()}.
 */
final class BranchIr extends IrNode
{
    public function __construct(
        public readonly IrNode $condition,
        public readonly IrNode $then,
        public readonly ?IrNode $else = null,
    ) {
    }

    public function kind(): string
    {
        return 'branch';
    }

    public function children(): array
    {
        $out = [$this->condition, $this->then];
        if ($this->else !== null) {
            $out[] = $this->else;
        }
        return $out;
    }
}
