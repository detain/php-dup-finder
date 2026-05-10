<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR node representing an unrecognised method/function/static call.
 *
 * Carries the called symbol verbatim (lower-cased) and the IR of
 * each argument as children. Calls whose symbol is recognised by
 * {@see \Phpdup\Normalization\DbOpRegistry} lift to a more
 * specific IR node ({@see DbReadIr}, {@see DbWriteIr}, …); only
 * the rest land here.
 */
final class CallIr extends IrNode
{
    /** @param list<IrNode> $args */
    public function __construct(
        public readonly string $symbol,
        public readonly array $args = [],
    ) {
    }

    public function kind(): string
    {
        return 'call';
    }

    public function scalar(): string
    {
        return $this->symbol;
    }

    public function children(): array
    {
        return $this->args;
    }
}
