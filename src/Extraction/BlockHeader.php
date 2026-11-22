<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use Phpdup\Util\LineRange;

/**
 * Lightweight block descriptor: location + kind + identity, no AST.
 *
 * Built for streaming-extraction code paths that would walk a corpus
 * and emit fingerprints without ever materialising the (possibly
 * large) AST. NOT WIRED today (engine-028 catalog row 11, disposition
 * KEEP, executed by engine-S31b): BlockExtractor never emits headers
 * and BlockAstLoader reloads ASTs on live {@see Block} instances,
 * not on headers — the "streaming fingerprint pass" that would pair
 * them exists only as this latent value object.
 *
 * The header is deliberately a value object — no setters, no
 * mutation methods. It would be safe to ship across worker
 * boundaries via serialize() because it is all primitives +
 * LineRange (a future wiring must still honor the socketpair law:
 * scalars / JSON strings only on the worker wire).
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
        );
    }
}
