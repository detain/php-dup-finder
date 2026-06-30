<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use Phpdup\Util\LineRange;

/**
 * A comparable subtree extracted from a source file.
 *
 * Originally produced bare by BlockExtractor; later stages enrich the
 * canonical AST, hole map, structural hash, and fingerprint. Mutability
 * is intentional — the pipeline mutates blocks in-place to avoid
 * copying potentially large ASTs.
 *
 * Memory note: $ast is nullable. After fingerprinting, callers may
 * `unloadAst()` to drop the original tree from memory; AntiUnifier
 * reconstructs it on demand via {@see BlockAstLoader}.
 */
final class Block
{
    public string $id = '';
    public Node $canonical;
    public string $structuralHash = '';
    public int $size = 0;

    /** @var array<string,int>|null token -> count */
    public ?array $ngramBag = null;

    /**
     * IR token-bag fingerprint (multiset of {@see \Phpdup\Ir\IrPrinter}
     * tokens), populated by {@see \Phpdup\Parallel\PreprocessWorker}
     * only when `--scorer=ir` is on. Null otherwise — and null is what
     * {@see \Phpdup\Clustering\Clusterer} treats as "fall back to
     * AST-level scoring", per option 5 of
     * `docs/plans/orm-db-semantic-dedup.md`'s risk-mitigation note.
     *
     * @var array<string,int>|null
     */
    public ?array $irBag = null;

    public ?Node $ast;

    public function __construct(
        public string $file,
        public LineRange $range,
        public string $kind,
        public ?string $namespace,
        public ?string $class,
        public ?string $name,
        Node $ast,
    ) {
        $this->ast = $ast;
    }

    public function unloadAst(): void
    {
        $this->ast = null;
    }

    public function isAstUnloaded(): bool
    {
        return $this->ast === null;
    }

    public function location(): string
    {
        return $this->file . ':' . $this->range;
    }

    public function qualifiedName(): string
    {
        $parts = array_filter([$this->namespace, $this->class, $this->name]);
        return implode('::', $parts) ?: '<' . $this->kind . '>';
    }
}
