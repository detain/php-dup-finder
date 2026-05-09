<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Normalization\Normalizer;
use Phpdup\Util\AstSerializer;

/**
 * Computes the most-specific generalization of a cluster's members.
 *
 * Uses path-based hole tracking rather than embedded sentinel nodes:
 * the template is left untouched (it's a deep clone of the seed
 * member's AST), and divergences are recorded as Hole objects with
 * the path through the AST and the observed values per member.
 *
 * Algorithm — per cluster:
 *
 *   1. Pick the seed: the member with the most AST nodes. Used as the
 *      template so it acts as the "maximal" version of the abstraction.
 *   2. For each other member i, walk seed and member i in parallel:
 *       - if a hole already exists at this path: record member i's
 *         value, stop recursing
 *       - if scalars match: continue
 *       - otherwise: NEW hole. Backfill prior members' implicit
 *         agreement with the seed, then record member i's divergence.
 *   3. When subnodes are arrays of statements with differing length AND
 *      type-3 detection is enabled, run LCS over each statement's
 *      structural hash. Matched positions recurse normally; unmatched
 *      template positions become 'optional_block' holes — that's how
 *      "block 1 has 5 stmts, block 2 has 3 of those 5" is rendered as
 *      "function ... bool $includeFooBar = false".
 *   4. After unification, observed values are remapped from the
 *      seed-first internal order back to the original cluster order so
 *      reporters see the per-member values in the order they expect.
 */
final class AntiUnifier
{
    private Standard $printer;

    public function __construct(
        private readonly ?BlockAstLoader $astLoader = null,
        private readonly bool $optionalBlocksEnabled = true,
        private readonly int $optionalBlocksMaxPerCluster = 3,
        private readonly int $optionalBlocksMinSegmentLength = 1,
    ) {
        $this->printer = new Standard();
    }

    public function unify(Cluster $cluster): void
    {
        $members = $cluster->members;
        $count   = count($members);
        if ($count === 0) {
            return;
        }
        if ($count === 1) {
            $cluster->generalizedAst = Normalizer::deepClone($this->astOf($members[0]));
            $cluster->holes = [];
            return;
        }

        // 1. Multi-seed search: try the top candidate seeds and pick
        //    the one that yields the smallest hole set (the abstraction
        //    with the most agreement across members). When all members
        //    are the same size the original largest-member tiebreaker
        //    still wins, so this only changes behaviour for clusters
        //    where one seed truly produces a tighter abstraction.
        $seedIdx = $this->pickBestSeed($members);
        if ($seedIdx !== 0) {
            // Swap into a local array — cluster.members order stays as the
            // ranker arranged it, but unification proceeds with seed at index 0.
            [$members[0], $members[$seedIdx]] = [$members[$seedIdx], $members[0]];
        }

        $ctx = new UnifyContext($this->optionalBlocksEnabled, $this->optionalBlocksMaxPerCluster);
        $first = $this->astOf($members[0]);
        for ($i = 1; $i < $count; $i++) {
            $this->walk($first, $this->astOf($members[$i]), $i, $ctx, []);
        }

        // 2. Pad any holes whose later-arriving members agreed structurally —
        //    walk's early-return appends a value, but optional_block holes get
        //    explicit append calls only when LCS visits the index, so a member
        //    whose array length matched the template never visits an
        //    already-existing optional hole. Backfill those gaps with the
        //    seed's repr (the member effectively had the seed's stmt).
        $expectedLen = $count;
        foreach ($ctx->holes as $hole) {
            if ($hole->kind !== 'optional_block') continue;
            while (count($hole->observedValues) < $expectedLen) {
                $hole->observedValues[] = $hole->observedValues[0] ?? '<absent>';
            }
        }

        $cluster->generalizedAst = Normalizer::deepClone($first);
        $cluster->holes          = array_values($ctx->holes);

        // 3. Remap observed values from internal (seed-first) to external
        //    (cluster.members) order. The values were appended in walk-order:
        //    index 0 = seed = original cluster index $seedIdx; index $seedIdx
        //    in the array = original cluster index 0; everyone else lines up.
        if ($seedIdx !== 0) {
            foreach ($cluster->holes as $hole) {
                if (isset($hole->observedValues[0]) && isset($hole->observedValues[$seedIdx])) {
                    [$hole->observedValues[0], $hole->observedValues[$seedIdx]]
                        = [$hole->observedValues[$seedIdx], $hole->observedValues[0]];
                }
            }
        }
    }

    /** @param list<Block> $members */
    private function pickSeedIndex(array $members): int
    {
        $bestIdx  = 0;
        $bestSize = -1;
        foreach ($members as $i => $m) {
            if ($m->size > $bestSize) {
                $bestSize = $m->size;
                $bestIdx  = $i;
            }
        }
        return $bestIdx;
    }

    /**
     * Multi-seed search.
     *
     * Picks the top {@see self::SEED_CANDIDATES} candidates by size,
     * runs a *trial* unification with each as seed, and returns the
     * index whose run produces the smallest set of holes. Smaller
     * hole sets mean the seed maximally captured what the cluster
     * has in common.
     *
     * For clusters of <=2 members or when there's a unique largest
     * member by a wide margin, this falls back to the single-seed
     * pick. Only kicks in when ≥3 members of comparable size give
     * the search room to choose.
     *
     * @param list<Block> $members
     */
    private function pickBestSeed(array $members): int
    {
        $count = count($members);
        if ($count < 3) {
            return $this->pickSeedIndex($members);
        }

        // Rank candidates by size, descending; consider top SEED_CANDIDATES.
        $ranked = [];
        foreach ($members as $i => $m) {
            $ranked[] = [$i, $m->size];
        }
        usort($ranked, static fn(array $a, array $b) => $b[1] <=> $a[1]);
        $candidates = array_slice($ranked, 0, self::SEED_CANDIDATES);

        $bestIdx  = $candidates[0][0];
        $bestHoles = PHP_INT_MAX;
        foreach ($candidates as [$idx, $size]) {
            $holes = $this->trialUnifyHoleCount($members, $idx);
            if ($holes < $bestHoles) {
                $bestHoles = $holes;
                $bestIdx   = $idx;
            }
        }
        return $bestIdx;
    }

    /** Number of seed candidates to evaluate in the multi-seed search. */
    private const SEED_CANDIDATES = 3;

    /**
     * Run a *no-op* unification with $candidateIdx as seed and report
     * the resulting hole count. Doesn't mutate $cluster — used purely
     * for picking between candidate seeds. Costs roughly the same as
     * one regular unification call per candidate; only invoked when
     * ≥3 members are available so the worst case is 3x.
     *
     * @param list<Block> $members
     */
    private function trialUnifyHoleCount(array $members, int $candidateIdx): int
    {
        $local = $members;
        if ($candidateIdx !== 0) {
            [$local[0], $local[$candidateIdx]] = [$local[$candidateIdx], $local[0]];
        }
        $ctx   = new UnifyContext($this->optionalBlocksEnabled, $this->optionalBlocksMaxPerCluster);
        $first = Normalizer::deepClone($this->astOf($local[0]));
        $count = count($local);
        for ($i = 1; $i < $count; $i++) {
            $this->walk($first, $this->astOf($local[$i]), $i, $ctx, []);
        }
        return count($ctx->holes);
    }

    private function astOf(Block $block): Node|null
    {
        if (!$block->isAstUnloaded()) {
            return $block->ast;
        }
        if ($this->astLoader === null) {
            throw new \RuntimeException(sprintf(
                'AntiUnifier: block %s has unloaded AST and no loader was supplied', $block->qualifiedName()
            ));
        }
        return $this->astLoader->resolve($block);
    }

    /**
     * @param list<int|string> $path
     */
    private function walk(?Node $template, ?Node $member, int $memberIdx, UnifyContext $ctx, array $path): void
    {
        $key = self::pathKey($path);
        if (isset($ctx->holesByPath[$key])) {
            $ctx->holesByPath[$key]->appendObserved($this->repr($member));
            return;
        }

        if ($template === null && $member === null) {
            return;
        }
        if ($template === null || $member === null || $template::class !== $member::class) {
            $this->createHole($path, $template, $member, $memberIdx, $ctx, 'subtree');
            return;
        }

        $tScalar = AstSerializer::scalarPart($template);
        $mScalar = AstSerializer::scalarPart($member);
        if ($tScalar !== null && $mScalar !== null) {
            if ($tScalar === $mScalar) {
                return;
            }
            $this->createHole($path, $template, $member, $memberIdx, $ctx, $this->kindForLeaf($template));
            return;
        }

        foreach ($template->getSubNodeNames() as $sub) {
            if (self::shouldSkipSubnode($template, $sub)) {
                continue;
            }
            $tVal = $template->$sub;
            $mVal = $member->$sub;

            if ($tVal instanceof Node && $mVal instanceof Node) {
                $this->walk($tVal, $mVal, $memberIdx, $ctx, [...$path, $sub]);
                continue;
            }
            if (is_array($tVal) && is_array($mVal)) {
                if (count($tVal) === count($mVal)) {
                    foreach ($tVal as $idx => $tChild) {
                        $mChild = $mVal[$idx];
                        if ($tChild instanceof Node && $mChild instanceof Node) {
                            $this->walk($tChild, $mChild, $memberIdx, $ctx, [...$path, $sub, $idx]);
                        } elseif ($tChild !== $mChild && !($tChild === null && $mChild === null)) {
                            $this->createHole([...$path, $sub, $idx], null, null, $memberIdx, $ctx, 'subtree');
                        }
                    }
                    continue;
                }
                // Lengths differ — try LCS-based optional-block detection. Falls
                // back to the legacy whole-array hole when disabled, when the
                // contents aren't statements, or when too many segments would
                // diverge.
                $this->unifyDivergentArray($tVal, $mVal, $memberIdx, $ctx, $path, $sub);
                continue;
            }
            if ($tVal === $mVal) {
                continue;
            }
            if ($tVal === null xor $mVal === null) {
                $this->createHole([...$path, $sub], null, null, $memberIdx, $ctx, 'subtree');
                continue;
            }
            $this->createHole($path, $template, $member, $memberIdx, $ctx, 'subtree');
            return;
        }
    }

    /**
     * Handle two arrays of children that differ in length. When enabled and the
     * elements look like statements, run LCS over their structural hashes and
     * emit one optional_block hole per unmatched template position. Otherwise
     * fall back to a whole-array subtree hole — the legacy v0.3 behaviour.
     *
     * @param array<int, mixed> $tList
     * @param array<int, mixed> $mList
     * @param list<int|string> $path
     */
    private function unifyDivergentArray(
        array $tList,
        array $mList,
        int $memberIdx,
        UnifyContext $ctx,
        array $path,
        string $sub,
    ): void {
        if (!$ctx->optionalBlocksEnabled || !$this->arrayIsStmtList($tList) || !$this->arrayIsStmtList($mList)) {
            $this->createHole([...$path, $sub], null, null, $memberIdx, $ctx, 'subtree');
            return;
        }

        $tList = array_values($tList);
        $mList = array_values($mList);
        $tHashes = array_map(fn(Node $s) => $this->stmtHash($s), $tList);
        $mHashes = array_map(fn(Node $s) => $this->stmtHash($s), $mList);
        $matchT  = $this->lcsAlignTemplate($tHashes, $mHashes);

        // Check we wouldn't blow past the optional-segment cap. Counting only the
        // newly-unmatched positions on this iteration; existing optional holes at
        // these paths reuse their slot.
        $newOptionalCount = 0;
        foreach ($matchT as $i => $j) {
            if ($j !== -1) continue;
            $childKey = self::pathKey([...$path, $sub, $i]);
            if (!isset($ctx->holesByPath[$childKey])) $newOptionalCount++;
        }
        $existingOptional = 0;
        foreach ($ctx->holes as $h) {
            if ($h->kind === 'optional_block') $existingOptional++;
        }
        if ($existingOptional + $newOptionalCount > $ctx->optionalBlocksMaxPerCluster) {
            // Too many optional segments would fall out of this alignment —
            // safer to wrap the whole array as a subtree hole and let the user
            // resolve manually than to ship a 7-boolean signature.
            $this->createHole([...$path, $sub], null, null, $memberIdx, $ctx, 'subtree');
            return;
        }

        foreach ($tList as $i => $tStmt) {
            $childPath = [...$path, $sub, $i];
            $childKey  = self::pathKey($childPath);
            $j         = $matchT[$i];

            if ($j >= 0) {
                if (isset($ctx->holesByPath[$childKey])) {
                    // A prior member created an optional hole here; this member
                    // has the segment, so record the present marker.
                    $ctx->holesByPath[$childKey]->appendObserved($this->repr($tStmt));
                } else {
                    $this->walk($tStmt, $mList[$j], $memberIdx, $ctx, $childPath);
                }
            } else {
                $this->createOrAppendOptionalHole($childPath, $tStmt, $memberIdx, $ctx);
            }
        }
    }

    /** @param array<int,mixed> $list */
    private function arrayIsStmtList(array $list): bool
    {
        if ($list === []) return false;
        foreach ($list as $entry) {
            if (!($entry instanceof Node\Stmt)) return false;
        }
        return true;
    }

    private function stmtHash(Node $stmt): string
    {
        return sha1(implode("\0", AstSerializer::tokens($stmt)));
    }

    /**
     * Longest common subsequence on two hash sequences. Returns, for each
     * template index i, the matched member index or -1.
     *
     * @param list<string> $tHashes
     * @param list<string> $mHashes
     * @return array<int, int>
     */
    private function lcsAlignTemplate(array $tHashes, array $mHashes): array
    {
        $n = count($tHashes);
        $m = count($mHashes);
        if ($n === 0 || $m === 0) {
            return array_fill(0, $n, -1);
        }
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                if ($tHashes[$i - 1] === $mHashes[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }
        // Backtrace to find the alignment for each template index.
        $matchT = array_fill(0, $n, -1);
        $i = $n; $j = $m;
        while ($i > 0 && $j > 0) {
            if ($tHashes[$i - 1] === $mHashes[$j - 1]) {
                $matchT[$i - 1] = $j - 1;
                $i--; $j--;
            } elseif ($dp[$i - 1][$j] >= $dp[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }
        return $matchT;
    }

    /**
     * @param list<int|string> $path
     */
    private function createOrAppendOptionalHole(array $path, ?Node $tStmt, int $memberIdx, UnifyContext $ctx): void
    {
        $key = self::pathKey($path);
        if (isset($ctx->holesByPath[$key])) {
            $ctx->holesByPath[$key]->appendObserved('<absent>');
            return;
        }
        $holeId = '__O' . ($ctx->holeCounter++);
        $hole   = new Hole($holeId, 'optional_block');
        $tRepr  = $this->repr($tStmt);
        for ($i = 0; $i < $memberIdx; $i++) {
            $hole->appendObserved($tRepr);
        }
        $hole->appendObserved('<absent>');
        $ctx->holes[$holeId]      = $hole;
        $ctx->holesByPath[$key]   = $hole;
    }

    /**
     * @param list<int|string> $path
     */
    private function createHole(array $path, ?Node $tNode, ?Node $mNode, int $memberIdx, UnifyContext $ctx, string $kind): void
    {
        $key = self::pathKey($path);
        if (isset($ctx->holesByPath[$key])) {
            $ctx->holesByPath[$key]->appendObserved($this->repr($mNode));
            return;
        }
        $holeId = '__P' . ($ctx->holeCounter++);
        $hole   = new Hole($holeId, $kind);
        $tRepr  = $this->repr($tNode);
        for ($i = 0; $i < $memberIdx; $i++) {
            $hole->appendObserved($tRepr);
        }
        $hole->appendObserved($this->repr($mNode));
        $ctx->holes[$holeId]      = $hole;
        $ctx->holesByPath[$key]   = $hole;
    }

    private function kindForLeaf(Node $template): string
    {
        if ($template instanceof Node\Scalar\String_)            return 'literal';
        if ($template instanceof Node\Scalar\Int_)               return 'literal';
        if ($template instanceof Node\Scalar\Float_)             return 'literal';
        if ($template instanceof Node\Scalar\InterpolatedString) return 'literal';
        if ($template instanceof Node\Expr\Variable)             return 'identifier';
        // VarLikeIdentifier extends Identifier, so the Identifier check below already covers it.
        if ($template instanceof Node\Identifier)                return 'name';
        if ($template instanceof Node\Name)                      return 'name';
        return 'subtree';
    }

    /**
     * Container-label subnodes that mustn't become holes — those are
     * what the suggested abstraction's *name* will be, not parameters.
     */
    private static function shouldSkipSubnode(Node $node, string $subnode): bool
    {
        if ($subnode !== 'name') return false;
        return $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_;
    }

    /** @param list<int|string> $path */
    private static function pathKey(array $path): string
    {
        return implode('/', array_map('strval', $path));
    }

    private function repr(?Node $node): string
    {
        if ($node === null) return '<missing>';
        if ($node instanceof Node\Scalar\String_)     return var_export($node->value, true);
        if ($node instanceof Node\Scalar\Int_)        return (string)$node->value;
        if ($node instanceof Node\Scalar\Float_)      return (string)$node->value;
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) return '$' . $node->name;
        // Identifier covers VarLikeIdentifier (its subclass); they're rendered without the $ prefix in this branch.
        if ($node instanceof Node\Identifier)         return $node->name;
        if ($node instanceof Node\Name)               return $node->toString();

        try {
            if ($node instanceof Node\Expr) {
                return $this->printer->prettyPrintExpr($node);
            }
            if ($node instanceof Node\Stmt) {
                return trim($this->printer->prettyPrint([$node]));
            }
        } catch (\Throwable) {
            // fall through
        }
        return '<' . AstSerializer::shortType($node) . '>';
    }
}

/** @internal */
final class UnifyContext
{
    public int $holeCounter = 0;
    /** @var array<string,Hole> by holeId */
    public array $holes = [];
    /** @var array<string,Hole> by pathKey */
    public array $holesByPath = [];

    public function __construct(
        public readonly bool $optionalBlocksEnabled = true,
        public readonly int $optionalBlocksMaxPerCluster = 3,
    ) {}
}
