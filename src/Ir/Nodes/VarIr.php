<?php
declare(strict_types=1);

namespace Phpdup\Ir\Nodes;

use Phpdup\Ir\IrNode;

/**
 * IR leaf representing a variable reference. The actual variable
 * name is collapsed to the synthetic placeholder `__V` to stay
 * structurally identical to the {@see \Phpdup\Normalization\Normalizer}'s
 * variable canonicalisation; specific names re-emerge only through
 * the dataflow signal in containing IR nodes.
 */
final class VarIr extends IrNode
{
    public function kind(): string
    {
        return 'var';
    }

    public function scalar(): string
    {
        return '__V';
    }
}
