<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use Phpdup\Util\LineRange;

/**
 * Lightweight block descriptor: location + kind + identity, no AST.
 *
 * Used by streaming-extraction code paths that want to walk a corpus
 * and emit fingerprints without ever materialising the (possibly
 * large) AST. The corresponding heavy {@see Block} is rebuilt
 * on-demand by {@see BlockAstLoader} when later stages actually
 * need the AST.
 *
 * The header is deliberately a value object — no setters, no
 * mutation methods. It's safe to ship across worker boundaries via
 * serialize() because it's all primitives + LineRange.
 */
final class BlockHeader
{
    public function __construct(
        public readonly string $id,
        public readonly string $file,
        public readonly LineRange $range,
        public readonly string $kind,
        public readonly ?string $namespace,
        public readonly ?string $class,
        public readonly ?string $name,
        public readonly ?string $rangeHash = null,
    ) {
    }

    public static function fromBlock(Block $block): self
    {
        return new self(
            id:        $block->id,
            file:      $block->file,
            range:     $block->range,
            kind:      $block->kind,
            namespace: $block->namespace,
            class:     $block->class,
            name:      $block->name,
            rangeHash: $block->rangeHash,
        );
    }
}
