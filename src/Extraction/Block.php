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
 */
final class Block
{
    public string $id = '';
    public Node $canonical;
    public string $structuralHash = '';
    public int $size = 0;

    /** @var array<string,int>|null token -> count */
    public ?array $ngramBag = null;

    /** @var array<string,mixed> bookkeeping for normalization holes */
    public array $holeMap = [];

    public function __construct(
        public string $file,
        public LineRange $range,
        public string $kind,
        public ?string $namespace,
        public ?string $class,
        public ?string $name,
        public Node $ast,
    ) {
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
