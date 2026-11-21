<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\DbOpRegistry;
use Phpdup\Normalization\Normalizer;
use Phpdup\Normalization\PluginRegistry;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\CanonicalNodePool;
use Phpdup\Util\LineRange;

/**
 * Resolves a Block's original AST on demand.
 *
 * Memory optimisation for very large corpora: after fingerprinting we
 * can drop {@see Block::$ast} and the AntiUnifier reloads the original
 * subtree only for the small subset of blocks that end up in clusters.
 *
 * Resolution first walks the file's parse-cached statement list for a
 * node whose kind, line-range, and (when applicable) declared name
 * match the block — the strict triple, exact whenever the range was
 * produced by this process's own scan.
 *
 * A range replayed from the cluster cache is only an ADVISORY hint:
 * content-preserving edits (comment or blank-line shifts) leave the
 * structuralHash — and therefore the corpus cache token — untouched, so
 * the cache legitimately hits while every cached line number below the
 * edit has drifted. Anchors are therefore CONTENT-BOUND first (when a
 * Config is threaded and the member carries its structuralHash — every
 * production block does): a candidate node qualifies only if re-
 * normalising it under the effective config reproduces the member's own
 * structuralHash. Positional geometry is a tie-breaker among content-
 * equal candidates, never a substitute for identity. On top of that the
 * loader keeps a per-GROUP claim ledger
 * — group = file + kind + declared name + namespace + enclosing class —
 * and every resolution (strict or re-anchored) must:
 *   • claim a physically distinct node (a span another stale member of
 *     the same group already owns is never served twice — this closes
 *     the "sibling drifted exactly onto my stale hint" door), and
 *   • preserve order: stale hints are file order (a content-preserving
 *     edit never swaps two blocks), so a member may only anchor to a
 *     candidate that sits between the nodes claimed by its hint-
 *     neighbours — the k-th stale member maps onto the k-th live
 *     candidate.
 * Within those constraints insertion drift re-anchors cleanly (span
 * preserved, nearest to the hint, below-or-at the hint wins ties). When
 * the re-anchor pool is exactly the group's live content twins, deletion
 * drift either pairs honestly or exhausts a member — an exhausted pool
 * or a truly ambiguous survivor set then fails LOUD by design. A silent
 * mis-anchor poisons every downstream consumer (reports, --apply), while
 * a loud relocation error merely costs one honest cache miss the operator
 * can force. Guessing a signature from the wrong sibling is never
 * acceptable.
 *
 * The claim ledger is PER LOADER INSTANCE (per process); cross-process
 * dedup is not its job — the fork parent rebases merged members through
 * rebaseClusters(), which re-runs this same content law over the real
 * objects before anything is reported or applied (excepting whole
 * positional keys it PROVES class-exact — the [m-2] freshness law, see
 * that method's docblock).
 */
final class BlockAstLoader
{
    /** @var array<string, list<Node\Stmt>> file → cached stmts (per-process) */
    private array $cache = [];

    /**
     * Claims per group key: hint range (as served, pre-refresh), the
     * assigned live range, and the owning Block's object id.
     *
     * @var array<string, list<array{hint: array{int,int}, assigned: array{int,int}, owner: int}>>
     */
    private array $claims = [];

    /**
     * Lazily built normalization mirrors, keyed by the exact
     * config-fingerprint that PreprocessWorker keys its tool cache by.
     *
     * @var array<string, Normalizer>
     */
    private array $normalizers = [];

    private ?PluginRegistry $pluginRegistry = null;

    private ?CanonicalNodePool $nodePool = null;

    private ?SubtreeHasher $hasher = null;

    /** Evidence counters (per process) — see rebaseStats(). */
    private int $sweptKeys = 0;

    private int $freshKeyCount = 0;

    private int $hashCalls = 0;

    /** See trustFreshScan(). */
    private bool $freshScan = false;

    /**
     * Positional group keys the rebasing sweep PROVED class-exact against
     * live bytes (freshness law, see rebaseClusters()). Loaders are
     * per-process and a warm replay is a fresh process over fresh bytes,
     * so booking stays valid for exactly the run whose bytes it proved;
     * members anchored on these keys need no content re-proof.
     *
     * @var array<string, true>
     */
    private array $freshKeys = [];

    /**
     * [m-2] run-provenance fast path: RefactorStage announces this on
     * every run whose clusters were NOT replayed from the cluster cache —
     * i.e. when every member block's range AND structuralHash were minted
     * from the current bytes inside this very process. In that world a
     * strict hit IS identity by construction and the rebasing sweep has no
     * stale object to fix, so all content re-proofs (which exist to catch
     * replayed stale ranges) are switched off for the whole run. Warm
     * replay never sets this and keeps the full law; mid-run file edits
     * remain the program-wide unsupported-flow assumption they already
     * were for every other stage.
     */
    public function trustFreshScan(): void
    {
        $this->freshScan = true;
    }

    /**
     * @param Config|null $config when threaded, replayed anchors are
     *                            CONTENT-BOUND: a candidate node only
     *                            qualifies if re-normalising it under the
     *                            effective config reproduces the member's
     *                            stored structuralHash (the same pipeline
     *                            that minted it, PreprocessWorker.php:88-193
     *                            — mirrored, not reimplemented). Null
     *                            disables the gate for synthetic callers
     *                            and unit fixtures that carry no hashes.
     */
    public function __construct(
        private readonly AstCache $astCache,
        private readonly AstParser $parser,
        private readonly ?Config $config = null,
    ) {
    }

    public function resolve(Block $block): Node|null
    {
        if (!$block->isAstUnloaded()) {
            return $block->ast;
        }
        $stmts = $this->stmtsFor($block->file);

        $group   = self::groupKey($block);
        $foreign = $this->foreignClaims($group, $block);

        // Re-entry: this exact block was already located once in this
        // process (its range now carries the assigned truth). Serve the
        // same node without touching the ledger.
        foreach ($this->claims[$group] ?? [] as $claim) {
            if ($claim['owner'] !== spl_object_id($block)) {
                continue;
            }
            if ([$block->range->start, $block->range->end] !== $claim['assigned']) {
                continue;
            }
            $again = $this->findStrict($stmts, $block, exclude: $foreign, forceRange: $claim['assigned']);
            if ($again !== null) {
                $block->ast = $again;
                return $again;
            }
            // Self-claim no longer points at real bytes (mid-process file
            // change — not a supported flow, but never let it double-claim):
            // drop OUR entry only, foreign claims stay booked, fall through.
            $self    = spl_object_id($block);
            $this->claims[$group] = array_values(array_filter(
                $this->claims[$group] ?? [],
                static fn (array $c): bool => $c['owner'] !== $self
            ));
            break;
        }

        $hintObj = $block->range;
        $hint    = [$hintObj->start, $hintObj->end];
        $found   = $this->findStrict($stmts, $block, exclude: $foreign);
        // [M-1] gate: a node sitting exactly on the stale hint is only the
        // block's own if its CONTENT still matches — a different-content
        // sibling that drifted onto the hint (or any same-span stranger)
        // is an imposter, and serving it silently is the Vector-2 door
        // seen from the strict side. Keys the sweep proved class-exact
        // against live bytes ([m-2] freshness law) are exempt: their
        // strict hit is identity, not a guess.
        if ($found !== null
            && !$this->freshScan
            && !isset($this->freshKeys[$group])
            && $this->contentBound($block)
            && !$this->candidateMatches($block, $found)
        ) {
            $found = null;
        }
        if ($found === null) {
            $found = $this->findReanchored($stmts, $block, $hint, $foreign);
        }
        if ($found === null) {
            throw new \RuntimeException(sprintf(
                'BlockAstLoader: could not relocate block %s in %s (kind %s, hinted range %s)',
                $block->qualifiedName(), $block->file, $block->kind, (string) $hintObj
            ));
        }
        // The located node is now the truth for this block: a warm-cache
        // replay may have handed us a stale hint, and downstream
        // consumers (refactor signatures, JSON member lines) must not
        // keep reporting the drifted position.
        $block->range = new LineRange($found->getStartLine(), $found->getEndLine());
        $block->ast   = $found;
        $this->claims[$group][] = [
            'hint'     => $hint,
            'assigned' => [$found->getStartLine(), $found->getEndLine()],
            'owner'    => spl_object_id($block),
        ];
        return $found;
    }

    private static function groupKey(Block $block): string
    {
        return implode("\x1f", [
            $block->file,
            $block->kind,
            $block->name ?? '*',
            $block->namespace ?? '*',
            $block->class ?? '*',
        ]);
    }

    /**
     * Ranges already owned by OTHER blocks of the same group, plus the
     * hint each claim arrived with (order bookkeeping).
     *
     * @return list<array{hint: array{int,int}, assigned: array{int,int}}>
     */
    private function foreignClaims(string $group, Block $block): array
    {
        $out = [];
        foreach ($this->claims[$group] ?? [] as $claim) {
            if ($claim['owner'] === spl_object_id($block)) {
                continue;
            }
            $out[] = ['hint' => $claim['hint'], 'assigned' => $claim['assigned']];
        }
        return $out;
    }

    /**
     * Exact kind + line-range + name match, minus any span a foreign
     * member of the same group already claimed (a claimed node is
     * somebody else's anchor — serving it twice is the silent
     * double-anchor Vector-2 door).
     *
     * @param list<Node\Stmt>                  $stmts
     * @param list<array{hint: array{int,int}, assigned: array{int,int}}> $exclude
     * @param array{int,int}|null              $forceRange
     */
    private function findStrict(array $stmts, Block $block, array $exclude, ?array $forceRange = null): ?Node
    {
        $found = null;
        $kindMatch = static fn (Node $node, string $kind): bool => self::matchesKind($node, $kind);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($block, $found, $kindMatch, $exclude, $forceRange) extends NodeVisitorAbstract {
            /**
             * @param list<array{hint: array{int,int}, assigned: array{int,int}}> $exclude
             * @param array{int,int}|null                                         $forceRange
             */
            public function __construct(
                private readonly Block $block,
                private ?Node &$found,
                private readonly \Closure $kindMatch,
                private readonly array $exclude,
                private readonly ?array $forceRange,
            ) {
            }
            #[\Override]
            public function enterNode(Node $node)
            {
                if ($this->found !== null) {
                    return null;
                }
                $wantStart = $this->forceRange[0] ?? $this->block->range->start;
                $wantEnd   = $this->forceRange[1] ?? $this->block->range->end;
                if ($node->getStartLine() !== $wantStart) return null;
                if ($node->getEndLine()   !== $wantEnd)   return null;
                foreach ($this->exclude as $taken) {
                    if ($taken['assigned'] === [$wantStart, $wantEnd]) {
                        return null; // foreign-claimed span is never free
                    }
                }
                if (!($this->kindMatch)($node, $this->block->kind))  return null;
                if ($this->block->name !== null) {
                    $name = match (true) {
                        $node instanceof Node\Stmt\Function_   => $node->name->toString(),
                        $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                        default                              => null,
                    };
                    if ($name !== $this->block->name) return null;
                }
                $this->found = $node;
                return null;
            }
        });
        $traverser->traverse($stmts);
        return $found;
    }

    /**
     * Range-insensitive re-anchor — the warm-cache cure.
     *
     * Candidates must agree on kind, declared name (when present),
     * enclosing namespace and class scope. Beyond that, only nodes not
     * already claimed by a foreign group member and ORDER-CONSISTENT
     * with every existing claim survive: stale hints are file order, so
     * a member anchors strictly below the node claimed by its
     * next-lower-hint neighbour and strictly above its next-higher one.
     * Among survivors the one whose span the edit preserved and whose
     * distance to the stale hint is smallest wins (insertions are the
     * promised drift direction, so a candidate below-or-at the hint
     * beats an equidistant mirror above it); a survivor-less pool or an
     * exact tie returns null so the caller keeps its loud failure.
     *
     * @param list<Node\Stmt>  $stmts
     * @param array{int,int}   $hint  [start,end] as originally served
     * @param list<array{hint: array{int,int}, assigned: array{int,int}}> $foreign
     */
    private function findReanchored(array $stmts, Block $block, array $hint, array $foreign): ?Node
    {
        $kindMatch = static fn (Node $node, string $kind): bool => self::matchesKind($node, $kind);
        $traverser = new NodeTraverser();
        $visitor = new class($block, $kindMatch) extends NodeVisitorAbstract {
            /** @var list<array{node: Node, score: array{int,int,int}}> */
            public array $candidates = [];

            private ?string $namespace = null;

            /** @var list<string> */
            private array $classStack = [];

            public function __construct(
                private readonly Block $block,
                private readonly \Closure $kindMatch,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                    return null;
                }
                if ($node instanceof Node\Stmt\ClassLike) {
                    // MUST mirror BlockVisitor's anonymous marker verbatim
                    // ('<anonymous>', BlockExtractor.php:101): the scope
                    // filter compares against Block::$class, and a
                    // divergent sentinel silently excludes every
                    // anonymous-class member from re-anchoring (the
                    // self-scan warm replay caught this).
                    $this->classStack[] = $node->name?->toString() ?? '<anonymous>';
                    return null;
                }
                $this->consider($node);
                return null;
            }

            #[\Override]
            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = null;
                } elseif ($node instanceof Node\Stmt\ClassLike) {
                    array_pop($this->classStack);
                }
                return null;
            }

            private function consider(Node $node): void
            {
                if (!($this->kindMatch)($node, $this->block->kind)) {
                    return;
                }
                $currentClass = $this->classStack === [] ? null : end($this->classStack);
                if ($currentClass !== $this->block->class) {
                    return;
                }
                if ($this->namespace !== $this->block->namespace) {
                    return;
                }
                if ($this->block->name !== null) {
                    $name = match (true) {
                        $node instanceof Node\Stmt\Function_   => $node->name->toString(),
                        $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                        default                              => null,
                    };
                    if ($name !== $this->block->name) {
                        return;
                    }
                }
                $hintSpan = $this->block->range->end - $this->block->range->start;
                $nodeSpan = $node->getEndLine() - $node->getStartLine();
                $dStart   = $node->getStartLine() - $this->block->range->start;
                $dEnd     = $node->getEndLine() - $this->block->range->end;
                $this->candidates[] = [
                    'node'  => $node,
                    'score' => [
                        $nodeSpan === $hintSpan ? 0 : 1,
                        abs($dStart) + abs($dEnd),
                        $dStart >= 0 ? 0 : 1,
                    ],
                ];
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        // [M-1]: positional geometry is only ever a TIE-BREAKER among
        // content-equal candidates. Sub-extraction-threshold same-kind
        // blocks (the norm in real files) must never win a slot their
        // bytes cannot fill.
        $candidates = $visitor->candidates;
        if ($this->contentBound($block)) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $c): bool => $this->candidateMatches($block, $c['node'])
            ));
        }

        $pool = [];
        foreach ($candidates as $candidate) {
            $start = $candidate['node']->getStartLine();
            $span  = [$start, $candidate['node']->getEndLine()];
            $ok    = true;
            foreach ($foreign as $claim) {
                if ($claim['assigned'] === $span) {
                    $ok = false; // physically taken
                    break;
                }
                if ($hint[0] > $claim['hint'][0] && $start <= $claim['assigned'][0]) {
                    $ok = false; // must sit strictly below the lower neighbour
                    break;
                }
                if ($hint[0] < $claim['hint'][0] && $start >= $claim['assigned'][0]) {
                    $ok = false; // must sit strictly above the upper neighbour
                    break;
                }
            }
            if ($ok) {
                $pool[] = $candidate;
            }
        }

        if ($pool === []) {
            return null;
        }
        if (count($pool) === 1) {
            return $pool[0]['node'];
        }
        usort($pool, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        if ($pool[0]['score'] === $pool[1]['score']) {
            return null; // genuinely ambiguous — fail loud upstream
        }
        return $pool[0]['node'];
    }

    /**
     * Content binding is live only with a threaded Config AND a replayed
     * member that actually carries its structuralHash. Every production
     * block gets its hash at fingerprint time (PreprocessWorker.php:193);
     * a hash-less block is a synthetic/unit fixture and falls back to the
     * positional discipline.
     */
    private function contentBound(Block $block): bool
    {
        return $this->config !== null && $block->structuralHash !== '';
    }

    /**
     * Re-normalise a candidate node through the SAME pipeline that minted
     * the member's structuralHash and compare. The probe block reuses the
     * member's kind/name/namespace/class — the scope filter already forced
     * those to agree — and the live node's own span.
     */
    private function candidateMatches(Block $block, Node $node): bool
    {
        $this->hashCalls++;
        $probe = new Block(
            $block->file,
            new LineRange($node->getStartLine(), $node->getEndLine()),
            $block->kind,
            $block->namespace,
            $block->class,
            $block->name,
            $node,
        );
        $this->normalizerFor($block->file)->normalize($probe);
        // Faithful mirror of the mint at PreprocessWorker.php:193: the
        // normalization pipeline always attaches ->canonical before the
        // hash step runs — the scan path itself assumes exactly that.
        $this->hasher ??= new SubtreeHasher();
        return $this->hasher->hash($probe->canonical) === $block->structuralHash;
    }

    /**
     * Normalizer mirror of the worker's per-effective-config tooling
     * (PreprocessWorker.php toolFor(): same fingerprint fields, same
     * global plugin registry, same low-memory node pool). Kept private
     * and read-only: this loader NEVER mints new hashes, it only re-checks
     * existing ones, so no cache/baseline format moves.
     */
    private function normalizerFor(string $file): Normalizer
    {
        $config = $this->config;
        if ($config === null) {
            throw new \LogicException('BlockAstLoader: content check requested without a threaded Config');
        }
        $cfg = $config->effectiveFor($file);
        $key = sprintf(
            '%d|%d|%s|%d|%s|%s|%d|%d|%s|%s|%s',
            $cfg->minBlockSize, $cfg->maxBlockSize,
            $cfg->normalizationMode, $cfg->ngramSize,
            implode(',', $cfg->allowedKinds),
            implode(',', $cfg->normalizationPlugins),
            $cfg->dbAware ? 1 : 0,
            $cfg->trinityCollapse ? 1 : 0,
            self::serializeStringMap($cfg->dbSymbolsMethods),
            self::serializeStringMap($cfg->dbSymbolsFunctions),
            $cfg->scorer,
        );
        if (!isset($this->normalizers[$key])) {
            $this->pluginRegistry ??= $config->normalizationPlugins !== []
                ? PluginRegistry::fromClassNames($config->normalizationPlugins)
                : null;
            if ($config->lowMemory && $this->nodePool === null) {
                $this->nodePool = new CanonicalNodePool();
            }
            $dbRegistry = ($cfg->dbSymbolsMethods === [] && $cfg->dbSymbolsFunctions === [])
                ? null
                : new DbOpRegistry(
                    customMethodOps: $cfg->dbSymbolsMethods,
                    customFunctionOps: $cfg->dbSymbolsFunctions,
                );
            $this->normalizers[$key] = new Normalizer(
                mode: $cfg->normalizationMode,
                plugins: $this->pluginRegistry,
                dbAware: $cfg->dbAware,
                dbOpRegistry: $dbRegistry,
                trinityCollapse: $cfg->trinityCollapse,
                lowMemory: $config->lowMemory,
                nodePool: $this->nodePool,
            );
        }
        return $this->normalizers[$key];
    }

    /** @param array<string,string> $map */
    private static function serializeStringMap(array $map): string
    {
        ksort($map);
        $parts = [];
        foreach ($map as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return implode(',', $parts);
    }

    /**
     * Public evidence counters for the [m-2] fast path (per-process,
     * observation only — NEVER a correctness input): keys = positional
     * groups swept, freshKeys = groups proven class-exact without a single
     * content recomputation, hashes = candidate re-normalisations
     * performed (escalated sweeps plus per-block resolve() gates).
     *
     * @return array{keys:int, freshKeys:int, hashes:int}
     */
    public function rebaseStats(): array
    {
        return [
            'keys'      => $this->sweptKeys,
            'freshKeys' => $this->freshKeyCount,
            'hashes'    => $this->hashCalls,
        ];
    }

    /**
     * Parent-side rebase over merged fork results — the [M-2] cure.
     *
     * Worker children re-anchor their private COPIES; the wire carries
     * hole/signature payloads only, so after the merge the parent's member
     * blocks would otherwise report (and --apply would strict-match)
     * stale pre-drift line numbers, silently. This sweep runs in the
     * parent, once, over the real objects, and it is the SAME content
     * truth applied batch-wise.
     *
     * Two passes per positional file+group key ([m-2] no-drift fast path):
     * FIRST the CLASS-SCOPED freshness law ([C-1] revision) — a cluster-cache
     * HIT certifies the corpus token multiset (file|structuralHash, count-
     * sensitive) unchanged, which means the run's live extracted blocks are
     * a PERMUTATION of the cached ones among their own hash classes — but
     * NOT that they never crossed slots: whole-statement transpositions of
     * same-scope blocks of DIFFERENT classes preserve every token while
     * moving bodies between hinted spans. Geometry alone (count + position
     * identity) is therefore blind to exactly the crossing that matters.
     * Booking now asks the live corpus itself: for EVERY (group, hash-class)
     * the member classes and the live block classes must agree as SETS, and
     * per class the sorted member hint spans must equal the sorted live
     * spans of that class. A pass pins each member onto a live block of its
     * OWN class by multiset agreement — still zero re-normalisation — and
     * books the key in freshKeys so resolve() may skip the strict-side
     * content gate too. Within a class, WHICH twin sits on WHICH span is
     * provably immaterial: --apply re-parses each file and rewrites by SPAN
     * (ApplyExtractor::rewriteMemberCallSite + strict findNodeInStmts), and
     * unification reads the resolve-ATTACHED ASTs, so any bijection inside
     * a hash class yields identical signatures, hole tables and patches.
     * SECOND, any key failing the class proof (drifted spans, transposed
     * bodies, orphan or missing classes, an empty live corpus) escalates to
     * the full content law, unchanged from [M-1]: for every
     * file+group+structuralHash class it parses the file, sorts the stale
     * members by hint, gathers the content-equal live candidate nodes by
     * position, and takes the unique cost-minimal ORDER-PRESERVING
     * assignment (sequence DP); classes whose member count exceeds the live
     * twin count lost a body and hit the loud spine instead of a silent
     * guess. Parsing and hashing now happen ONLY inside escalation arms.
     * Members the serial path already resolved carry live ASTs and are
     * skipped; ranges-only mutation keeps the lazy-memory profile intact.
     *
     * @param list<Cluster> $clusters
     * @param list<Block>   $liveBlocks this run's extracted blocks (with
     *                                  fresh ranges + structuralHashes);
     *                                  empty = no booking evidence, every
     *                                  key escalates to the full law
     */
    public function rebaseClusters(array $clusters, array $liveBlocks = []): void
    {
        if ($this->freshScan) {
            return; // nothing replayed, nothing stale, nothing to rebase
        }
        /** @var array<string, array<string, list<Block>>> $byKey positional key → hash class → members */
        $byKey = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $member) {
                if (!$member->isAstUnloaded()) {
                    continue; // live bytes — nothing to relocate
                }
                if ($this->config === null || $member->structuralHash === '') {
                    continue; // nothing content-bound to rebase against
                }
                $poolKey = self::groupKey($member);
                $byKey[$poolKey][$poolKey . "\x1e" . $member->structuralHash][] = $member;
            }
        }
        if ($byKey === []) {
            return;
        }

        // [C-1] the live corpus, bucketed by (positional group, hash class)
        // into span strings — the run's own extraction already carries the
        // correct ranges and hashes, so booking costs one array pass.
        /** @var array<string, array<string, list<string>>> $liveByGroup */
        $liveByGroup = [];
        foreach ($liveBlocks as $block) {
            if ($block->structuralHash === '') {
                continue;
            }
            $liveByGroup[self::groupKey($block)][$block->structuralHash][]
                = $block->range->start . '-' . $block->range->end;
        }

        /** @var array<string, list<Node>> $pools candidate pools, escalation-only */
        $pools = [];
        foreach ($byKey as $poolKey => $classes) {
            $this->sweptKeys++;

            // [m-2] fast path, [C-1] class-scoped: book only when member
            // classes and live classes agree as SETS and per class the hint
            // and live span multisets are identical. Nothing is parsed or
            // re-normalised to get here.
            if (self::groupIsFresh($classes, $liveByGroup[$poolKey] ?? [])) {
                $this->freshKeys[$poolKey] = true;
                $this->freshKeyCount++;
                continue;
            }

            /** @var list<Block> $keyMembers every stale member sharing this key */
            $keyMembers = array_merge(...array_values($classes));
            $sample = $keyMembers[0];
            $stmts  = $this->stmtsFor($sample->file);

            if (!isset($pools[$poolKey])) {
                $pools[$poolKey] = $this->liveCandidateNodes($stmts, $sample);
            }
            $live = $pools[$poolKey];

            foreach ($classes as $members) {
                usort($members, static fn (Block $a, Block $b): int => $a->range->start <=> $b->range->start);

                // Content gate: keep only true twins of this class (the
                // pool spans one hash value, so one comparison chain per
                // candidate).
                $twins = [];
                foreach ($live as $node) {
                    if ($this->candidateMatches($members[0], $node)) {
                        $twins[] = $node;
                    }
                }
                usort($twins, static fn (Node $a, Node $b): int => $a->getStartLine() <=> $b->getStartLine());

                if (count($members) > count($twins)) {
                    $starved = $members[count($twins)];
                    throw new \RuntimeException(sprintf(
                        'BlockAstLoader: could not relocate block %s in %s (kind %s, hinted range %s)',
                        $starved->qualifiedName(), $starved->file, $starved->kind, (string) $starved->range
                    ));
                }

                $pairs = self::monotoneAssign($members, $twins);
                foreach ($pairs as $i => $node) {
                    $member = $members[$i];
                    $hintObj = $member->range;
                    $member->range = new LineRange($node->getStartLine(), $node->getEndLine());
                    $this->claims[$poolKey][] = [
                        'hint'     => [$hintObj->start, $hintObj->end],
                        'assigned' => [$node->getStartLine(), $node->getEndLine()],
                        'owner'    => spl_object_id($member),
                    ];
                }
            }
        }
    }

    /**
     * Class-scoped freshness proof for one positional key ([C-1]).
     * A cluster-cache hit preserves the (file|structuralHash) COUNT
     * MULTISET, so this run's live extracted blocks are the cached blocks
     * permuted among their own hash classes; comment/whitespace drift then
     * shifts each class strictly order-preservingly. Booking requires more
     * than geometry: the member classes and the live classes of the group
     * must agree as SETS (an orphan live class or a vanished member class
     * means bodies moved across classes — escalate), and per class the
     * sorted hint spans must equal the sorted live spans (any crossing,
     * near-miss or drift fails here). A booked key may still permute
     * WITHIN a class — provably harmless, see rebaseClusters()'s docblock
     * (span-local apply + attachment-local unification). This proof only
     * ever CERTIFES zero harmful staleness; it never guesses an anchor.
     * An empty live map books nothing (escalate everything).
     *
     * @param array<string, list<Block>> $classes   class key → members
     * @param array<string, list<string>> $liveByHash live spans per class
     */
    private static function groupIsFresh(array $classes, array $liveByHash): bool
    {
        if ($liveByHash === []) {
            return false; // no live-corpus evidence => no proof
        }
        if (count($liveByHash) !== count($classes)) {
            return false; // classes crossed (orphan or missing)
        }
        foreach ($classes as $classKey => $members) {
            $hash = substr($classKey, strrpos($classKey, "\x1e") + 1);
            if (!isset($liveByHash[$hash])) {
                return false;
            }
            $hints = [];
            foreach ($members as $member) {
                $hints[] = $member->range->start . '-' . $member->range->end;
            }
            $live = $liveByHash[$hash];
            sort($hints);
            sort($live);
            if ($hints !== $live) {
                return false;
            }
        }
        return true;
    }

    /**
     * Minimum-total-shift order-preserving matching of k sorted members
     * onto distinct sorted candidates (classic sequence DP). Ties break
     * toward the FIRST member's nearest candidate; with strictly ordered
     * starts the optimum is unique in every non-degenerate case.
     *
     * @param list<Block> $members    sorted by hint start
     * @param list<Node>  $candidates sorted by live start
     * @return list<Node>             chosen node per member index
     */
    private static function monotoneAssign(array $members, array $candidates): array
    {
        $m = count($members);
        $c = count($candidates);
        $INF = PHP_INT_MAX >> 8;

        /** @var list<list<int>> $dp */
        $dp = array_fill(0, $m + 1, array_fill(0, $c + 1, $INF));
        /** @var list<list<true|null>> $took — dp[i][j] optimal uses member i-1 onto candidate j-1? */
        $took = array_fill(0, $m + 1, array_fill(0, $c + 1, null));
        for ($j = 0; $j <= $c; $j++) {
            $dp[0][$j] = 0;
        }
        for ($i = 1; $i <= $m; $i++) {
            $hintStart = $members[$i - 1]->range->start;
            for ($j = 1; $j <= $c; $j++) {
                // skip this candidate for member i
                $best    = $dp[$i][$j - 1];
                $tookIj  = $best < $INF ? false : null;
                $use     = $dp[$i - 1][$j - 1] + abs($candidates[$j - 1]->getStartLine() - $hintStart);
                if ($dp[$i - 1][$j - 1] < $INF && $use < $best) {
                    $best   = $use;
                    $tookIj = true;
                }
                $dp[$i][$j]  = $best;
                $took[$i][$j] = $tookIj;
            }
        }

        $chosen = array_fill(0, $m, -1);
        $i = $m;
        $j = $c;
        while ($i > 0 && $j > 0) {
            if ($took[$i][$j] === true) {
                $chosen[$i - 1] = $j - 1;
                $i--;
                $j--;
            } elseif ($took[$i][$j] === false) {
                $j--;
            } else {
                // $INF frontier — cannot happen for feasible tables; walk j defensively.
                $j--;
            }
        }

        $out = [];
        foreach ($chosen as $idx) {
            if ($idx < 0) {
                // Feasibility was checked by the caller (m <= c); a hole
                // here would be an algorithm bug, not a drift outcome.
                throw new \LogicException('BlockAstLoader: monotone assignment lost a member');
            }
            $out[] = $candidates[$idx];
        }
        return $out;
    }

    /**
     * All nodes of the block's kind/name/scope family in $stmts — the
     * raw candidate pool (claim ledger and content gates applied by the
     * caller). Extracted from findReanchored's visitor.
     *
     * @param list<Node\Stmt> $stmts
     * @return list<Node>
     */
    private function liveCandidateNodes(array $stmts, Block $block): array
    {
        $kindMatch = static fn (Node $node, string $kind): bool => self::matchesKind($node, $kind);
        $traverser = new NodeTraverser();
        $visitor = new class($block, $kindMatch) extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $nodes = [];

            private ?string $namespace = null;

            /** @var list<string> */
            private array $classStack = [];

            public function __construct(
                private readonly Block $block,
                private readonly \Closure $kindMatch,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                    return null;
                }
                if ($node instanceof Node\Stmt\ClassLike) {
                    // MUST mirror BlockVisitor's anonymous marker verbatim
                    // ('<anonymous>', BlockExtractor.php:101) — see the
                    // matching note in findReanchored's visitor.
                    $this->classStack[] = $node->name?->toString() ?? '<anonymous>';
                    return null;
                }
                if (!($this->kindMatch)($node, $this->block->kind)) {
                    return null;
                }
                $currentClass = $this->classStack === [] ? null : end($this->classStack);
                if ($currentClass !== $this->block->class) {
                    return null;
                }
                if ($this->namespace !== $this->block->namespace) {
                    return null;
                }
                if ($this->block->name !== null) {
                    $name = match (true) {
                        $node instanceof Node\Stmt\Function_   => $node->name->toString(),
                        $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                        default                              => null,
                    };
                    if ($name !== $this->block->name) {
                        return null;
                    }
                }
                $this->nodes[] = $node;
                return null;
            }

            #[\Override]
            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = null;
                } elseif ($node instanceof Node\Stmt\ClassLike) {
                    array_pop($this->classStack);
                }
                return null;
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);
        return $visitor->nodes;
    }

    /**
     * Parse-cached statements for a file (per-process memo).
     *
     * @return list<Node\Stmt>
     */
    private function stmtsFor(string $file): array
    {
        $stmts = $this->cache[$file] ?? null;
        if ($stmts !== null) {
            return $stmts;
        }
        $stmts = $this->astCache->get($file);
        if ($stmts === null) {
            $stmts = $this->parser->parseFile($file) ?? [];
            $this->astCache->put($file, $stmts);
        }
        $this->cache[$file] = $stmts;
        return $stmts;
    }

    private static function matchesKind(Node $node, string $kind): bool
    {
        return match ($kind) {
            'function'  => $node instanceof Node\Stmt\Function_,
            'method'    => $node instanceof Node\Stmt\ClassMethod,
            'closure'   => $node instanceof Node\Expr\Closure,
            'arrow'     => $node instanceof Node\Expr\ArrowFunction,
            'if'        => $node instanceof Node\Stmt\If_,
            'for'       => $node instanceof Node\Stmt\For_,
            'foreach'   => $node instanceof Node\Stmt\Foreach_,
            'while'     => $node instanceof Node\Stmt\While_,
            'do'        => $node instanceof Node\Stmt\Do_,
            'try'       => $node instanceof Node\Stmt\TryCatch,
            'switch'    => $node instanceof Node\Stmt\Switch_,
            'match'     => $node instanceof Node\Expr\Match_,
            default     => false,
        };
    }
}
