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

    /**
     * Item 2.1: fragment identity fields, set by the shared miner ONLY on
     * 'fragment'-kind blocks (list-consistent statement-run metadata; the
     * regular per-block fields above carry the window's own fold). All
     * public-mutable like every other field here, and ALL are persisted
     * with the cached Block list — a warm replay must recompute suppression
     * from fields alone (never from a re-walk), which is what keeps
     * cold/warm cluster output equal by construction.
     *
     * $fragmentKey: '~'-joined {@see \Phpdup\Util\StmtHash} keys of the
     * window's statements, taken from the whole-list normalization (so two
     * identical runs at different offsets agree). $fragmentPos is the
     * 0-based statement offset of the run's first statement inside its
     * list; $fragmentSize the number of statements. $fragmentPrev /
     * $fragmentNext are the list's neighbor statement keys ('' at the
     * list edge) — the maximality suppression's one-step witnesses.
     * $fragmentWhole flags a run equal to its entire list (suppressed: a
     * whole declaration body is already minted as that declaration).
     */
    public string $fragmentKey = '';
    public string $fragmentPrev = '';
    public string $fragmentNext = '';
    public int $fragmentPos = 0;
    public int $fragmentSize = 0;
    public bool $fragmentWhole = false;

    /**
     * Item 7.2b (opt-in 'html_script' kind): the fragment LINEAGE of a
     * synthetic fold — one class row per member of the block's wrapper
     * statement list, in member order:
     *   'markup' — a whole raw-HTML run (an uncut T_INLINE_HTML span),
     *   'slice'  — a position-marked text-window piece cut out of a raw
     *              run (the run's bytes the window begins or ends inside),
     *   'stmt'   — a real PHP statement that lives inside the window.
     * Empty for every other species (same doctrine as the fragment
     * fields above). Plain scalar list, so the IndexStore blob
     * round-trips it exactly — a warm replay re-derives the wrapper from
     * the file and position-MATCHES it against these rows
     * ({@see \Phpdup\Extraction\BlockAstLoader}'s html_script arm) —
     * never from a re-walk the replay cannot perform.
     *
     * @var list<string>
     */
    public array $fragmentLineage = [];

    /**
     * Item 2.3 (opt-in 'class' kind): the doc's shape tokens — one
     * readable row per member plus the leading declaration row (see
     * {@see \Phpdup\Normalization\ShapeFingerprint::memberSignatures()}).
     * Filled ONLY by the PreprocessWorker fold's class arm; empty for
     * every other species. Plain scalar arrays, so the IndexStore blob
     * (whole-object serialize) round-trips them exactly like the
     * fragment fields above — cold/warm equal by construction.
     *
     * @var list<string>
     */
    public array $shapeMembers = [];

    /**
     * Item 2.3: per RAW method name, the two structural hashes driving
     * the skeleton-family verdict table ('s' strict lane, 'd' run fold —
     * see {@see \Phpdup\Normalization\ShapeFingerprint::methodHashes()}).
     * Same persistence law as $shapeMembers.
     *
     * @var array<string, array{s:string, d:string}>
     */
    public array $shapeMethods = [];

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
