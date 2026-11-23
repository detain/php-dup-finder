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

    /** @var array<string|int,int>|null token -> count. Keys are xxh64 hex
     *                               strings in normal mode, shared canonically
     *                               via the producing NgramFingerprint's master
     *                               gram dictionary (engine-011 — the copy is
     *                               by reference only; serialization carries
     *                               the key content unchanged). Under
     *                               --low-memory the keys are the digest as a
     *                               masked-positive 63-bit int, NOT the old
     *                               hi^lo 32-bit fold (CompactNgramBag,
     *                               engine-042). Serialized Blocks carry these
     *                               keys; bag species separation rides the
     *                               IndexStore/ClusterCache config keys. */
    public ?array $ngramBag = null;

    /**
     * IR token-bag fingerprint (multiset of {@see \Phpdup\Ir\IrPrinter}
     * tokens), populated by {@see \Phpdup\Parallel\PreprocessWorker}
     * only when `--scorer=ir` is on. Null otherwise — and null is what
     * {@see \Phpdup\Clustering\Clusterer} treats as "fall back to
     * AST-level scoring", per the risk-mitigation note of option 5 in
     * the retired ORM/DB-semantic-dedup plan (fd74e30).
     *
     * @var array<string,int>|null
     */
    public ?array $irBag = null;

    public ?Node $ast;

    /**
     * Item 5.1: repo label of the scan root that produced this block —
     * basename of the longest matching configured root ('' when no root
     * matches: an honest identity fallback, exactly like
     * {@see \Phpdup\Util\PathCanonical}'s no-match case). Stamped per
     * run by {@see \Phpdup\Scanning\RepoLabels}; never trusted from a
     * deserialized cache (labels are run context, not content facts).
     * Public-mutable by design like every other field on this class.
     */
    public string $repo = '';

    /**
     * Item 5.1: scope label of this block — {@see \Phpdup\Scanning\RepoLabels::SCOPE_SINGLE}
     * ('single') when the run had one scan root, otherwise the
     * containing root's {@see self::$repo} label. Stamped in the same
     * pass; public-mutable by design.
     */
    public string $scope = '';

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
