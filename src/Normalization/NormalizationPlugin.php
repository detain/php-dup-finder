<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

use PhpParser\Node;

/**
 * Hook for user-defined canonicalisation passes.
 *
 * Implementations are loaded from {@see ConfigLoader} via the
 * `normalization.plugins` array in `phpdup.json`. Each plugin gets
 * called once per node in the AST during normalisation, after the
 * built-in passes (variable/literal/name/etc.) have run. Mutations
 * to $node are picked up by the surrounding NodeTraverser.
 *
 * Mode is one of strict/default/aggressive — plugins should
 * self-gate to the modes where their rewrites make sense.
 */
interface NormalizationPlugin
{
    public function visit(Node $node, string $mode): void;
}
