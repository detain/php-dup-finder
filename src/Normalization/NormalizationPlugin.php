<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

use PhpParser\Node;

/**
 * Hook for user-defined canonicalisation passes.
 *
 * Implementations are loaded from {@see ConfigLoader} via the
 * `normalization.plugins` array in `phpdup.json`. Each plugin is
 * called once per node during normalisation — in EVERY mode — after
 * that mode's built-in passes have run on the node: `strict` has
 * only the identifier pass behind it, `default` adds literal and
 * named-argument canonicalisation, and `aggressive` also adds the
 * name and attribute passes. (The match/switch fold replaces nodes
 * in a later leave pass, so plugins see the original Match_/Switch_
 * shape, never the synthetic `__MATCH__` call.) Mutations to $node
 * are picked up by the surrounding NodeTraverser.
 *
 * php-dup-finder-once-029: this invocation used to sit behind the
 * strict/default early-returns, so plugins written to this contract
 * silently never ran outside aggressive mode. The loop relocated and
 * this docblock now states what actually happens.
 *
 * $mode names the canonicalisation set that produced the node as
 * handed to you — one of strict/default/aggressive. Plugins should
 * self-gate to the modes where their rewrites make sense.
 */
interface NormalizationPlugin
{
    public function visit(Node $node, string $mode): void;
}
