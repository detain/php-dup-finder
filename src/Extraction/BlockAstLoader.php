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
use Phpdup\Refactor\ScriptKindApplyRefusal;
use Phpdup\Util\CanonicalNodePool;
use Phpdup\Util\LineRange;

/**
 * Resolves a Block's original AST on demand.
 *
 * Memory optimisation for very large corpora: after fingerprinting we
 * can drop {@see Block::$ast} and the AntiUnifier reloads the original
 * subtree only for the small subset of blocks that end up in clusters.
 *
 * Resolution first consults the per-file batched candidate index — ONE
 * whole-file walk per process collects every servable node, and the
 * strict search answers from that memo for a node whose kind,
 * line-range, and (when applicable) declared name match the block —
 * the strict triple, exact whenever the range was produced by this
 * process's own scan (engine-031/output-015 leg A: the walk count is
 * bounded by the number of distinct files, never by the block count).
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
 * preserved, nearest to the hint, below-or-at the hint wins ties). A
 * survivor-less pool or an exact tie returns null so the caller keeps
 * its loud failure. A silent mis-anchor poisons every downstream
 * consumer (reports, --apply), while a loud relocation error merely
 * costs one honest cache miss the operator can force. Guessing a
 * signature from the wrong sibling is never acceptable.
 *
 * The escalation sweep inside rebaseClusters() carries the other half of
 * the engine-040 posture: when stale members outnumber the live content
 * twins of a hash class the excess bodies are GONE, and no assignment
 * law can resurrect them — but killing the whole run over one drifted
 * cache entry is not what 'loud' was ever meant to cost on a mainstream
 * corpus. Such members are dropped from their (shared, by-reference)
 * clusters, the clusters ride the existing inert <2-member guards
 * downstream (ApplyExtractor::apply, Ranker), and every drop is recorded
 * as a relocated-degrade note (relocationDegrades(), rebaseStats()
 * 'degrades') for the run surface. Fail-loud survives as fail-INFORMED:
 * the poison is named, the surviving members stay anchored on truth, and
 * resolve()'s per-block throw remains the strict-path backstop for flows
 * the sweep cannot speak for.
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
     * assigned live range, the physically claimed NODE, and the owning
     * Block's object id.
     *
     * engine-040 (half A): physical distinctness is enforced against the
     * node reference, never its span. A compact one-line
     * `if (...) { ... } else if (...) { ... } else { ... };` (the shape
     * that killed the PERF vendor run on Parser/Php7.php:1635) nests TWO
     * If_ nodes sharing one start==end line, so a span-keyed ledger lets
     * the outer's claim shadow the physically distinct inner node —
     * exactly the door the law was meant to close, reopened as a
     * run-fatal starve. The claim pins the node object itself; the
     * batched index and the per-process stmt cache keep every served
     * node alive for the loader's lifetime, so the reference can never
     * dangle into an id reuse, and `===` is the belt.
     *
     * @var array<string, list<array{hint: array{int,int}, assigned: array{int,int}, node: Node, owner: int}>>
     */
    private array $claims = [];

    /**
     * Lazily built normalization mirrors, keyed by the exact
     * config-fingerprint that PreprocessWorker keys its tool cache by.
     *
     * @var array<string, Normalizer>
     */
    private array $normalizers = [];

    /**
     * Per-file candidate index (engine-031 / output-015 leg A): ONE
     * batched whole-file walk per process collects every node the
     * relocation machinery can ever serve — kind, declared name, live
     * scope and span recorded in NodeTraverser (pre-)order — and
     * findStrict, findReanchored and liveCandidateNodes all answer from
     * this memo. Before this law a file with B blocks paid up to 2B
     * whole-file traversals (a strict walk plus a pool walk per
     * resolve()); after it, a file is walked exactly once and every
     * match decision is an ordered lookup that RETURNS on its first
     * hit — the post-match visit waste STOP_TRAVERSAL existed to cure
     * cannot occur because no match site traverses anymore, and the one
     * walk that remains must run to completion (cutting it short would
     * starve later pool consumers of the same file).
     *
     * Entries are plain immutable-at-use descriptors over the SHARED
     * cached stmt tree — the same node objects the old per-call
     * visitors yielded, never a copy: pooled leaves (engine-S04) keep
     * their exact aliasing, the index only READS kind/scope/span
     * metadata off them.
     *
     * @var array<string, list<array{node: Node, kind: string, name: string|null, namespace: string|null, class: string|null, start: int, end: int}>>
     */
    private array $index = [];

    /**
     * spl_object_id candidate hash memo (engine-031 / output-015 /
     * output-040 leg B): every content decision used to re-pay the FINAL
     * clone machinery — Normalizer's deepClone + canonicalising passes
     * (and the low-memory pool splice) plus SubtreeHasher — for the SAME
     * node, once per (hash class × live candidate) in the escalation
     * sweep and again per member at the strict gate and pool filter.
     * That chain is a pure function of the node within one loader
     * process: Normalizer reads nothing but the block's ast, every
     * candidate is served under its own file's one effective config
     * (all pools here come from indexFor($block->file)), and pool
     * interning only swaps structurally equal leaves, which the
     * token-stream hash cannot observe. The memo collapses every repeat
     * to ONE computation per distinct node: the value carries the node
     * reference itself, so a stored spl_object_id can never be recycled
     * under a dead object — a hit is provably this node's hash. The
     * match DECISIONS (hash against the member's structuralHash) stay
     * bit-identical to the per-call chain.
     *
     * Only a hash string crosses into the memo — no clone, no pooled
     * leaf, no AST object — so engine-S04's pooled-leaf discipline and
     * the S18a/S18b interning posture are untouched, and nothing here
     * reaches any persisted blob format.
     *
     * @var array<int, array{node: Node, hash: string}>
     */
    private array $candidateHashes = [];

    /** Evidence: whole-file index walks performed (see rebaseStats()). */
    private int $walkCount = 0;

    /** Evidence: content decisions served from the candidate hash memo. */
    private int $memoHits = 0;

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
     * Relocated-degrade notes recorded by the escalation sweep
     * (engine-040 half A: always-skip + note, never run death). Plain
     * strings, in-process only — they never ride a worker wire
     * (Socketpair law neutral). Collect-in-class, surface-elsewhere,
     * exactly the FileScanner::skipNotes() posture; see
     * relocationDegrades().
     *
     * @var list<string>
     */
    private array $degradeNotes = [];

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
        // Item 1.3 law: a 'script' block is a synthetic fold, not a
        // parse-tree node — relocation is impossible BY CONSTRUCTION and
        // must fail typed-loud here, never silent-mis-anchor (P4 law).
        // Fix-review M-1: the pipeline now NEVER unloads folds, so a
        // well-behaved run never reaches this throw for script; it stays
        // load-bearing as the backstop that turns any future
        // unload-sweep regression into a loud typed refusal instead of a
        // silent mis-anchor.
        if ($block->kind === 'script') {
            throw ScriptKindApplyRefusal::forBlock($block, 'BlockAstLoader::resolve()');
        }

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
            $again = $this->findStrict($block, exclude: $foreign, forceRange: $claim['assigned']);
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
        $found   = $this->findStrict($block, exclude: $foreign);
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
            $found = $this->findReanchored($block, $hint, $foreign);
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
            'node'     => $found,
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
     * Nodes and ranges already owned by OTHER blocks of the same group,
     * plus the hint each claim arrived with (order bookkeeping).
     *
     * @return list<array{hint: array{int,int}, assigned: array{int,int}, node: Node}>
     */
    private function foreignClaims(string $group, Block $block): array
    {
        $out = [];
        foreach ($this->claims[$group] ?? [] as $claim) {
            if ($claim['owner'] === spl_object_id($block)) {
                continue;
            }
            $out[] = ['hint' => $claim['hint'], 'assigned' => $claim['assigned'], 'node' => $claim['node']];
        }
        return $out;
    }

    /**
     * Exact kind + line-range + name match, minus any NODE a foreign
     * member of the same group already claimed (a claimed node is
     * somebody else's anchor — serving it twice is the silent
     * double-anchor Vector-2 door). engine-040 (half A): exclusion runs
     * on node identity, not span equality — a physically distinct node
     * sharing a claimed span is the nested same-line shape (compact
     * `if … } else if …`), and shadowing it turns honest relocation
     * into a run-fatal starve. Same-span lookups that were unique
     * before stay unique: only nesting produces several servable nodes
     * on one exact range, and pre-order answers with the outer first,
     * exactly like the old whole-file visitor did.
     *
     * Answered from the per-file batched index in NodeTraverser order,
     * so the FIRST hit of the ordered scan is exactly the node the old
     * whole-file visitor would have landed on, and the lookup returns
     * on it — decisive at the match site, zero post-match visiting.
     * The strict triple carries no scope law (identity is the span
     * itself), so scope is not filtered here — unchanged semantics.
     *
     * @param list<array{hint: array{int,int}, assigned: array{int,int}, node: Node}> $exclude
     * @param array{int,int}|null                                                     $forceRange
     */
    private function findStrict(Block $block, array $exclude, ?array $forceRange = null): ?Node
    {
        $wantStart = $forceRange[0] ?? $block->range->start;
        $wantEnd   = $forceRange[1] ?? $block->range->end;
        $claimed   = [];
        foreach ($exclude as $taken) {
            $claimed[spl_object_id($taken['node'])] = true;
        }
        foreach ($this->indexFor($block->file) as $entry) {
            if ($entry['start'] !== $wantStart || $entry['end'] !== $wantEnd) {
                continue;
            }
            if ($entry['kind'] !== $block->kind) {
                continue;
            }
            if ($block->name !== null && $entry['name'] !== $block->name) {
                continue;
            }
            if (isset($claimed[spl_object_id($entry['node'])])) {
                continue; // a foreign-claimed node is never served twice
            }
            return $entry['node'];
        }
        return null;
    }

    /**
     * The twelve candidate kinds the relocation machinery can serve, in
     * the matchesKind table's canonical order. Item 1.3: deliberately NO
     * 'script' entry — a synthetic fold is not a parse-tree counterpart
     * and positional script matching is the silent-mis-anchor class.
     */
    private const CANDIDATE_KINDS = [
        'function', 'method', 'closure', 'arrow', 'if', 'for',
        'foreach', 'while', 'do', 'try', 'switch', 'match',
    ];

    /**
     * Classify a node into the single block kind it can serve — the
     * matchesKind table probed per kind (the twelve candidate classes
     * are disjoint, so at most one kind matches). Answers inside the
     * once-per-file batched walk, which is why probing the table here
     * is vanishingly cheap compared to the per-block walks it replaces.
     */
    private static function kindOf(Node $node): ?string
    {
        foreach (self::CANDIDATE_KINDS as $kind) {
            if (self::matchesKind($node, $kind)) {
                return $kind;
            }
        }
        return null;
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
     * The candidate pool is read off the per-file batched index (leg A
     * of engine-031/output-015): same nodes, same traversal order the
     * old dedicated whole-file visitor collected — zero extra walks.
     *
     * @param array{int,int}   $hint  [start,end] as originally served
     * @param list<array{hint: array{int,int}, assigned: array{int,int}, node: Node}> $foreign
     */
    private function findReanchored(Block $block, array $hint, array $foreign): ?Node
    {
        $candidates = [];
        $hintSpan   = $block->range->end - $block->range->start;
        foreach ($this->scopeCandidates($block) as $entry) {
            $nodeSpan = $entry['end'] - $entry['start'];
            $dStart   = $entry['start'] - $block->range->start;
            $dEnd     = $entry['end'] - $block->range->end;
            $candidates[] = [
                'node'  => $entry['node'],
                'score' => [
                    $nodeSpan === $hintSpan ? 0 : 1,
                    abs($dStart) + abs($dEnd),
                    $dStart >= 0 ? 0 : 1,
                ],
            ];
        }

        // [M-1]: positional geometry is only ever a TIE-BREAKER among
        // content-equal candidates. Sub-extraction-threshold same-kind
        // blocks (the norm in real files) must never win a slot their
        // bytes cannot fill.
        if ($this->contentBound($block)) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $c): bool => $this->candidateMatches($block, $c['node'])
            ));
        }

        $pool = [];
        foreach ($candidates as $candidate) {
            $start = $candidate['node']->getStartLine();
            $ok    = true;
            foreach ($foreign as $claim) {
                if ($claim['node'] === $candidate['node']) {
                    // physically taken — engine-040 (half A): node
                    // identity, not span equality, because a compact
                    // single-line if/elseif nests TWO servable nodes on
                    // one exact range and the outer's claim must never
                    // shadow the inner node.
                    $ok = false;
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
     *
     * The heavy half (clone + canonicalise + hash) runs at most once per
     * distinct candidate node, served after that from the spl_object_id
     * memo ($candidateHashes, engine-031 leg B); only the string
     * comparison against the member's hash repeats. The probe's identity
     * fields never reach Normalizer (it reads the ast only), so the memo
     * is decision-identical to the pre-memo per-call chain by
     * construction.
     */
    private function candidateMatches(Block $block, Node $node): bool
    {
        $candidateId = spl_object_id($node);
        $memo        = $this->candidateHashes[$candidateId] ?? null;
        if ($memo !== null && $memo['node'] === $node) {
            $this->memoHits++;
            return $memo['hash'] === $block->structuralHash;
        }
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
        $hash = $this->hasher->hash($probe->canonical);
        $this->candidateHashes[$candidateId] = ['node' => $node, 'hash' => $hash];
        return $hash === $block->structuralHash;
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
     * Drop one starved member from its owning cluster by object identity
     * (engine-040 degrade). rebaseClusters receives the cluster LIST by
     * value, so the removal goes through the shared Cluster object's
     * public members list — visible to every holder, the caller's
     * $state->clusters included. (The list is deliberately not spliced
     * by reference: callers pass array expressions, and a below-two
     * residual cluster is inert downstream by existing guards, while an
     * empty one is proven safe in AntiUnifier::unify's $count===0 arm.)
     */
    private function dropMember(Cluster $cluster, Block $member): void
    {
        $kept = [];
        foreach ($cluster->members as $candidate) {
            if ($candidate !== $member) {
                $kept[] = $candidate;
            }
        }
        $cluster->members = $kept;
    }

    /**
     * Public evidence counters for the [m-2] fast path (per-process,
     * observation only — NEVER a correctness input): keys = positional
     * groups swept, freshKeys = groups proven class-exact without a single
     * content recomputation, hashes = candidate re-normalisations
     * PAID (engine-031 leg B memo: memo hits are decisions served from a
     * stored node hash and never count here — an escalation over C hash
     * classes and L live candidates pays at most L, not C·L; before the
     * memo this counter grew with the calls), memo = content decisions
     * served from the spl_object_id candidate hash memo,
     * walks = whole-file index walks (engine-031/output-015 leg A law:
     * at most one per distinct file touched by resolution, whatever the
     * block count — the pre-leg-A shape cost up to two walks per block),
     * degrades = members the escalation sweep skipped with a
     * relocated-degrade note instead of killing the run (engine-040
     * half A — always-skip + note, see relocationDegrades()).
     *
     * @return array{keys:int, freshKeys:int, hashes:int, memo:int, walks:int, degrades:int}
     */
    public function rebaseStats(): array
    {
        return [
            'keys'      => $this->sweptKeys,
            'freshKeys' => $this->freshKeyCount,
            'hashes'    => $this->hashCalls,
            'memo'      => $this->memoHits,
            'walks'     => $this->walkCount,
            'degrades'  => count($this->degradeNotes),
        ];
    }

    /**
     * The relocated-degrade notes recorded so far (engine-040 half A):
     * one line per starved member the escalation sweep skipped — kind,
     * hinted range and the twin arithmetic that starved it included, in
     * the skipNotes posture: collected here, surfaced by the run layer
     * (the RefactorStage → DebugLogger/stderr wiring is a separate lane's
     * row). Plain strings; they never ride a worker wire.
     *
     * @return list<string>
     */
    public function relocationDegrades(): array
    {
        return $this->degradeNotes;
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
     * twin count lost bodies, and those surplus members are DEGRADED —
     * always-skip + relocated-degrade note (engine-040 half A), never the
     * run-killing throw the first shape of this loop was. Parsing and
     * hashing now happen ONLY inside escalation arms.
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
        /** @var array<int, Cluster> $memberOwners object id → owning cluster (degrade drops) */
        $memberOwners = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $member) {
                if (!$member->isAstUnloaded()) {
                    continue; // live bytes — nothing to relocate
                }
                // Item 1.3: an unloaded script fold on a warm replay can
                // never be re-anchored (no parse-tree counterpart) — the
                // typed refusal beats positional guessing outright.
                if ($member->kind === 'script') {
                    throw ScriptKindApplyRefusal::forBlock($member, 'BlockAstLoader::rebaseClusters()');
                }
                if ($this->config === null || $member->structuralHash === '') {
                    continue; // nothing content-bound to rebase against
                }
                $poolKey = self::groupKey($member);
                $byKey[$poolKey][$poolKey . "\x1e" . $member->structuralHash][] = $member;
                $memberOwners[spl_object_id($member)] = $cluster;
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

            if (!isset($pools[$poolKey])) {
                $pools[$poolKey] = $this->liveCandidateNodes($sample);
            }
            $live = $pools[$poolKey];

            foreach ($classes as $members) {
                usort($members, static fn (Block $a, Block $b): int => $a->range->start <=> $b->range->start);

                // Content gate: keep only true twins of this class (the
                // pool spans one hash value, so one comparison chain per
                // candidate — and after leg B the chain's heavy half is
                // memoized per node, making the sweep O(L) clones +
                // normalisations + hashes across ALL classes of a key,
                // never O(C·L)).
                $twins = [];
                foreach ($live as $node) {
                    if ($this->candidateMatches($members[0], $node)) {
                        $twins[] = $node;
                    }
                }
                usort($twins, static fn (Node $a, Node $b): int => $a->getStartLine() <=> $b->getStartLine());

                if (count($members) > count($twins)) {
                    // engine-040 (half A): always-skip + note. Stale
                    // members beyond the last live content twin lost
                    // their bodies — no assignment law can resurrect
                    // them, and a full-corpus run must not die of one
                    // drifted cache entry. Drop the surplus tail (hint
                    // order) from its owning cluster by object identity,
                    // record a relocated-degrade note per drop, and let
                    // the honest prefix pair normally. A cluster driven
                    // below two members rides the codebase's existing
                    // inert guards (ApplyExtractor::apply count<2,
                    // Ranker impact 0); the sweep's mutations never
                    // persist to the cluster cache (saved pre-refactor),
                    // so every replay re-degrades deterministically and
                    // re-notes.
                    $keep = array_slice($members, 0, count($twins));
                    foreach (array_slice($members, count($twins)) as $starved) {
                        $this->degradeNotes[] = sprintf(
                            'BlockAstLoader: relocated-degrade: skipped block %s in %s (kind %s, hinted range %s) — %d stale member(s), %d live content twin(s)',
                            $starved->qualifiedName(),
                            $starved->file,
                            $starved->kind,
                            (string) $starved->range,
                            count($members),
                            count($twins)
                        );
                        $this->dropMember($memberOwners[spl_object_id($starved)], $starved);
                    }
                    $members = $keep;
                    if ($members === []) {
                        continue; // twins === 0 — nothing left to pair
                    }
                }

                $pairs = self::monotoneAssign($members, $twins);
                foreach ($pairs as $i => $node) {
                    $member = $members[$i];
                    $hintObj = $member->range;
                    $member->range = new LineRange($node->getStartLine(), $node->getEndLine());
                    $this->claims[$poolKey][] = [
                        'hint'     => [$hintObj->start, $hintObj->end],
                        'assigned' => [$node->getStartLine(), $node->getEndLine()],
                        'node'     => $node,
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
     * All nodes of the block's kind/name/scope family in the file — the
     * raw candidate pool (claim ledger and content gates applied by the
     * caller), read off the per-file batched index: one whole-file walk
     * per process backs every pool in the escalation sweep AND every
     * per-block resolve, no matter how many groups a file carries.
     *
     * @return list<Node>
     */
    private function liveCandidateNodes(Block $block): array
    {
        $nodes = [];
        foreach ($this->scopeCandidates($block) as $entry) {
            $nodes[] = $entry['node'];
        }
        return $nodes;
    }

    /**
     * Index entries of the block's (kind, scope, name) family in file
     * traversal order — the shared collection law the old dedicated
     * findReanchored/liveCandidateNodes visitors enforced, now answered
     * from the memo instead of a per-call walk.
     *
     * @return list<array{node: Node, kind: string, name: string|null, namespace: string|null, class: string|null, start: int, end: int}>
     */
    private function scopeCandidates(Block $block): array
    {
        $out = [];
        foreach ($this->indexFor($block->file) as $entry) {
            if ($entry['kind'] !== $block->kind) {
                continue;
            }
            if ($entry['class'] !== $block->class) {
                continue;
            }
            if ($entry['namespace'] !== $block->namespace) {
                continue;
            }
            if ($block->name !== null && $entry['name'] !== $block->name) {
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * The per-file candidate index (engine-031 / output-015 leg A): one
     * batched NodeTraverser walk over the parse-cached stmts collects
     * every servable node with its kind, declared name, live scope and
     * span, in pre-order. Scope tracking mirrors the old pool visitors
     * exactly: Namespace_ binds, ClassLike pushes the BlockVisitor
     * anonymous sentinel ('<anonymous>', BlockExtractor.php:101 — a
     * divergent sentinel silently drops every anonymous-class member,
     * the self-scan warm replay caught this), leaveNode unwinds.
     *
     * @return list<array{node: Node, kind: string, name: string|null, namespace: string|null, class: string|null, start: int, end: int}>
     */
    private function indexFor(string $file): array
    {
        if (isset($this->index[$file])) {
            return $this->index[$file];
        }
        $this->walkCount++;
        $stmts = $this->stmtsFor($file);
        $traverser = new NodeTraverser();
        // Scope-bound callable: created inside BlockAstLoader, so the
        // private classifier is reachable from the anon visitor.
        $classify = static fn (Node $node): ?string => self::kindOf($node);
        $visitor = new class($classify) extends NodeVisitorAbstract {
            /** @var list<array{node: Node, kind: string, name: string|null, namespace: string|null, class: string|null, start: int, end: int}> */
            public array $entries = [];

            private ?string $namespace = null;

            /** @var list<string> */
            private array $classStack = [];

            /** @param \Closure(Node):?string $classify */
            public function __construct(
                private readonly \Closure $classify,
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
                    $this->classStack[] = $node->name?->toString() ?? '<anonymous>';
                    return null;
                }
                $kind = ($this->classify)($node);
                if ($kind === null) {
                    return null;
                }
                $name = match (true) {
                    $node instanceof Node\Stmt\Function_   => $node->name->toString(),
                    $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                    default                              => null,
                };
                $this->entries[] = [
                    'node'      => $node,
                    'kind'      => $kind,
                    'name'      => $name,
                    'namespace' => $this->namespace,
                    'class'     => $this->classStack === [] ? null : end($this->classStack),
                    'start'     => $node->getStartLine(),
                    'end'       => $node->getEndLine(),
                ];
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
        $this->index[$file] = $visitor->entries;
        return $this->index[$file];
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

    /**
     * The relocation machinery's candidate classifier (belt pinned by
     * ScriptKindTest via reflection: a 'script' block must never
     * positionally match a parse-tree node — the typed refusal in
     * resolve()/rebaseClusters() fires long before this table matters).
     */
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
            // Item 1.3: deliberately NO 'script' arm. A synthetic fold
            // must never match a positional candidate — falling through
            // to `default => false` is the posture, and the typed
            // refusal in resolve()/rebaseClusters() fires long before
            // this table is consulted for a script block. Positional
            // matching of script kinds is the silent-mis-anchor class.
            default     => false,
        };
    }
}
