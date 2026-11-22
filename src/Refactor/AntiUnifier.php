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
use Phpdup\Util\Delimiters;

/**
 * Computes the most-specific generalization of a cluster's members.
 *
 * Uses path-based hole tracking rather than embedded sentinel nodes:
 * the template is read-only during the walk (walk() only ever reads
 * it — never a clone), and divergences are recorded as Hole objects
 * with the path through the AST and the observed values per member.
 * The one deep clone is the consumer-facing generalizedAst.
 *
 * Hole row contract (php-dup-finder-output-018): every hole carries
 * exactly one observedValues row per cluster member, in cluster order,
 * once unify() returns. A row whose member never cleanly visited the
 * path is an explicit {@see Hole::MISSING} placeholder with the hole
 * degraded — the pad never back-records seed content, and the
 * seed-order → cluster-order remap swaps unconditionally instead of
 * silently skipping short lists. Reporters and ApplyExtractor index
 * rows positionally, so this invariant is load-bearing.
 *
 * Algorithm — per cluster:
 *
 *   1. Pick the seed: for clusters of 3+ members, the top
 *      {@see self::SEED_CANDIDATES} members by node count are each tried
 *      as the template and the one whose walk yields the fewest holes
 *      wins; below that, the member with the most AST nodes. The winning
 *      walk IS the unification — see {@see self::pickBestSeed()}.
 *   2. For each other member i, walk seed and member i in parallel:
 *       - if a hole already exists at this path: record member i's
 *         value, stop recursing
 *       - if scalars match: continue
 *       - otherwise: NEW hole. Backfill prior members — but only with
 *         the seed's repr where a prior member walked this exact path
 *         cleanly (output-018: its node is token-equal to the seed's,
 *         so the seed repr is that member's value); members that
 *         diverged at or inside this path get an explicit gap row,
 *         then record member i's divergence.
 *   3. When subnodes are arrays of statements with differing length AND
 *      type-3 detection is enabled, run LCS over each statement's
 *      structural hash. Matched positions recurse normally; unmatched
 *      template positions become 'optional_block' holes — that's how
 *      "block 1 has 5 stmts, block 2 has 3 of those 5" is rendered as
 *      "function ... bool $includeFooBar = false". Member-side
 *      statements with no template counterpart cannot become holes
 *      (holes are template-relative); when such extras exist the
 *      optional segments born from that alignment are degraded — the
 *      boolean params understate what the member drops (output-018).
 *   4. Every hole is padded to the full member count with explicit gap
 *      rows, then observed values are remapped from the seed-first
 *      internal order back to the original cluster order so reporters
 *      see the per-member values in the order they expect.
 */
final class AntiUnifier
{
    private Standard $printer;
    /** @var array<string, list<int|string>> holeId => path — populated after each unify() call */
    private array $lastHolePaths = [];
    /** @var int full anti-unification walks performed by the most recent unify() call */
    private int $lastWalkCount = 0;

    public function __construct(
        private readonly ?BlockAstLoader $astLoader = null,
        private readonly bool $optionalBlocksEnabled = true,
        private readonly int $optionalBlocksMaxPerCluster = 3,
        private readonly int $optionalBlocksMinSegmentLength = 1,
    ) {
        $this->printer = new Standard();
    }

    /**
     * Returns the hole→path map from the most recent unify() call.
     *
     * @return array<string, list<int|string>>
     */
    public function getLastHolePaths(): array
    {
        return $this->lastHolePaths;
    }

    /**
     * Number of complete anti-unification walks performed by the most
     * recent unify() call.
     *
     * Regression seam for php-dup-finder-output-014: the seed search used
     * to run SEED_CANDIDATES trial walks, throw them all away, and walk a
     * second time with the winner (≈4× per ≥3-member cluster). The
     * winning trial's context is now carried forward, so this count is
     * exactly the number of candidate walks — never more than
     * SEED_CANDIDATES, and 1 for every cluster below 3 members.
     */
    public function getLastWalkCount(): int
    {
        return $this->lastWalkCount;
    }

    public function unify(Cluster $cluster): void
    {
        $members = $cluster->members;
        $count   = count($members);
        $this->lastWalkCount = 0;
        if ($count === 0) {
            return;
        }
        if ($count === 1) {
            $cluster->generalizedAst = Normalizer::deepClone($this->astOf($members[0]));
            $cluster->holes = [];
            return;
        }

        // 1. Seed search. Multi-seed: try the top candidate seeds and pick
        //    the one whose walk yields the smallest hole set (the
        //    abstraction with the most agreement across members). When all
        //    members are the same size the original largest-member
        //    tiebreaker still wins, so this only changes behaviour for
        //    clusters where one seed truly produces a tighter abstraction.
        //    The winning trial is CARRIED FORWARD as the final unification
        //    (output-014): walk() only ever reads the template, so the
        //    trials need no per-candidate deep clone and the winner must
        //    not be walked a second time. The one legitimate clone is the
        //    generalizedAst below — the mutation-ready template handed to
        //    the refactor consumers.
        [$seedIdx, $seedAst, $ctx] = $this->pickBestSeed($members);

        $cluster->generalizedAst = Normalizer::deepClone($seedAst);
        $cluster->holes          = array_values($ctx->holes);

        // 2. Pad every hole to the full member count. A hole born while
        //    earlier subnodes were already recursed is never reached by
        //    members that stop at an ancestor divergence, and the old
        //    optional-only pad back-filled those gaps with the SEED's repr
        //    — a silent claim that the skipped member held seed content
        //    (output-018). Placeholder rows are now explicit structural
        //    gaps on holes of EVERY kind, and the hole degrades honestly.
        foreach ($cluster->holes as $hole) {
            $this->fillGaps($hole, $count);
        }

        // 3. Remap observed values from internal (seed-first) to external
        //    (cluster.members) order. The rows were appended in walk-order:
        //    index 0 = seed = original cluster index $seedIdx; index
        //    $seedIdx in the list = original cluster index 0; everyone else
        //    lines up. The swap is UNCONDITIONAL and placeholder-aware
        //    (output-018): the old isset() guard silently skipped the remap
        //    whenever a short list left the seed's counterpart missing,
        //    pairing every row beside the wrong cluster member. The pad
        //    above guarantees density; the in-loop guard keeps a
        //    mis-ordered future call from ever skipping the remap again.
        if ($seedIdx !== 0) {
            foreach ($cluster->holes as $hole) {
                while (count($hole->observedValues) <= $seedIdx) {
                    $hole->appendMissing();
                }
                [$hole->observedValues[0], $hole->observedValues[$seedIdx]]
                    = [$hole->observedValues[$seedIdx], $hole->observedValues[0]];
            }
        }

        // 4. Persist hole paths for ApplyExtractor (paths are template-
        //    relative and unaffected by the row remap above).
        $cluster->holePaths = $ctx->holePaths;
        $this->lastHolePaths = $ctx->holePaths;
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
     * Multi-seed search returning the winning walk itself.
     *
     * Picks the top {@see self::SEED_CANDIDATES} candidates by size,
     * runs a *trial* unification with each as seed, and hands back the
     * index whose run produces the smallest set of holes — together
     * with that run's template AST and context, so the caller adopts
     * the winning trial as the final unification instead of re-walking
     * (php-dup-finder-output-014: walk() only reads the template, so
     * trials need no deep clone and the winner needs no second pass).
     *
     * Falls back to the single-seed pick for clusters with fewer
     * than 3 members — exactly one walk, no trial comparison. There is
     * no margin-based short-circuit: at ≥3 members the trial search
     * always runs, over whichever members rank top SEED_CANDIDATES by
     * size, and ties go to the earlier candidate (strict-less-than
     * comparison over the size-ranked order).
     *
     * @param list<Block> $members
     * @return array{0: int, 1: Node|null, 2: UnifyContext} seed index, seed AST, winning walk context
     */
    private function pickBestSeed(array $members): array
    {
        $count = count($members);
        if ($count < 3) {
            $idx = $this->pickSeedIndex($members);
            [$ctx, $ast] = $this->trialUnify($members, $idx);
            return [$idx, $ast, $ctx];
        }

        $candidates = $this->rankSeedCandidates($members);

        $bestIdx = $candidates[0];
        [$bestCtx, $bestAst] = $this->trialUnify($members, $bestIdx);
        $bestHoles = count($bestCtx->holes);

        foreach (array_slice($candidates, 1) as $idx) {
            [$ctx, $ast] = $this->trialUnify($members, $idx);
            $holes = count($ctx->holes);
            if ($holes >= $bestHoles) {
                continue;
            }
            $bestIdx   = $idx;
            $bestCtx   = $ctx;
            $bestAst   = $ast;
            $bestHoles = $holes;
        }
        return [$bestIdx, $bestAst, $bestCtx];
    }

    /**
     * Seed candidate indices, largest member first. PHP's sort is stable,
     * so equal sizes keep their cluster order.
     *
     * @param list<Block> $members
     * @return list<int>
     */
    private function rankSeedCandidates(array $members): array
    {
        $ranked = [];
        foreach ($members as $i => $m) {
            $ranked[] = [$i, $m->size];
        }
        usort($ranked, static fn(array $a, array $b) => $b[1] <=> $a[1]);
        $top = array_slice($ranked, 0, self::SEED_CANDIDATES);
        return array_map(static fn(array $pair): int => $pair[0], $top);
    }

    /** Number of seed candidates to evaluate in the multi-seed search. */
    private const SEED_CANDIDATES = 3;

    /**
     * Run one anti-unification with $seedIdx as template and return its
     * context plus the template it walked. Doesn't mutate $members, the
     * cluster, or any AST node — walk() reads the template, so no deep
     * clone is needed here; the single clone that IS required (the
     * mutation-ready generalizedAst) lives in unify(). Costs exactly one
     * full walk per call.
     *
     * @param list<Block> $members
     * @return array{0: UnifyContext, 1: Node|null}
     */
    private function trialUnify(array $members, int $seedIdx): array
    {
        $this->lastWalkCount++;
        $local = $members;
        if ($seedIdx !== 0) {
            // Swap into a local array — cluster.members order stays as the
            // ranker arranged it, but unification proceeds with seed at index 0.
            [$local[0], $local[$seedIdx]] = [$local[$seedIdx], $local[0]];
        }
        $ctx      = new UnifyContext($this->optionalBlocksEnabled, $this->optionalBlocksMaxPerCluster);
        $template = $this->astOf($local[0]);
        $count    = count($local);
        for ($i = 1; $i < $count; $i++) {
            $this->walk($template, $this->astOf($local[$i]), $i, $ctx, []);
        }
        return [$ctx, $template];
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
     * Walk one member's node against the template at $path.
     *
     * @param list<int|string> $path
     * @return bool true when this member's node at $path is token-clean
     *              against the template — every descendant agreed and no
     *              value was recorded here. Only such paths may be
     *              back-recorded with the seed's repr at a later hole
     *              birth (output-018: the implicit-agreement backfill is
     *              a lie for any member that diverged at or inside the
     *              path); the mark is recorded per member in
     *              {@see UnifyContext::$passedByMember}.
     */
    private function walk(?Node $template, ?Node $member, int $memberIdx, UnifyContext $ctx, array $path): bool
    {
        $key = self::pathKey($path);
        if (isset($ctx->holesByPath[$key])) {
            $this->observe($ctx->holesByPath[$key], $member, $memberIdx);
            return false;
        }

        if ($template === null && $member === null) {
            $this->markPassed($ctx, $memberIdx, $key);
            return true;
        }
        if ($template === null || $member === null || $template::class !== $member::class) {
            $this->createHole($path, $template, $member, $memberIdx, $ctx, 'subtree');
            return false;
        }

        $tScalar = AstSerializer::scalarPart($template);
        $mScalar = AstSerializer::scalarPart($member);
        if ($tScalar !== null && $mScalar !== null) {
            if ($tScalar === $mScalar) {
                $this->markPassed($ctx, $memberIdx, $key);
                return true;
            }
            $this->createHole($path, $template, $member, $memberIdx, $ctx, $this->kindForLeaf($template));
            return false;
        }

        $clean = true;
        foreach ($template->getSubNodeNames() as $sub) {
            if (self::shouldSkipSubnode($template, $sub)) {
                continue;
            }
            $tVal = $template->$sub;
            $mVal = $member->$sub;

            if ($tVal instanceof Node && $mVal instanceof Node) {
                if (!$this->walk($tVal, $mVal, $memberIdx, $ctx, [...$path, $sub])) {
                    $clean = false;
                }
                continue;
            }
            if (is_array($tVal) && is_array($mVal)) {
                if (count($tVal) === count($mVal)) {
                    foreach ($tVal as $idx => $tChild) {
                        $mChild = $mVal[$idx];
                        if ($tChild instanceof Node && $mChild instanceof Node) {
                            if (!$this->walk($tChild, $mChild, $memberIdx, $ctx, [...$path, $sub, $idx])) {
                                $clean = false;
                            }
                        } elseif ($tChild !== $mChild && !($tChild === null && $mChild === null)) {
                            $this->createHole([...$path, $sub, $idx], null, null, $memberIdx, $ctx, 'subtree');
                            $clean = false;
                        }
                    }
                    continue;
                }
                // Lengths differ — try LCS-based optional-block detection. Falls
                // back to the legacy whole-array hole when disabled, when the
                // contents aren't statements, or when too many segments would
                // diverge.
                if (!$this->unifyDivergentArray($tVal, $mVal, $memberIdx, $ctx, $path, $sub)) {
                    $clean = false;
                }
                continue;
            }
            if ($tVal === $mVal) {
                continue;
            }
            if ($tVal === null xor $mVal === null) {
                $this->createHole([...$path, $sub], null, null, $memberIdx, $ctx, 'subtree');
                $clean = false;
                continue;
            }
            $this->createHole($path, $template, $member, $memberIdx, $ctx, 'subtree');
            return false;
        }
        if ($clean) {
            $this->markPassed($ctx, $memberIdx, $key);
        }
        return $clean;
    }

    /**
     * Handle two arrays of children that differ in length. When enabled and the
     * elements look like statements, run LCS over their structural hashes and
     * emit one optional_block hole per unmatched template position. Otherwise
     * fall back to a whole-array subtree hole — the legacy v0.3 behaviour.
     *
     * Returns true only when this member's list agrees with the template
     * list outright — every template position matched and its walk stayed
     * clean (output-018). Member-side statements with no template
     * counterpart cannot become holes (holes are template-relative), so the
     * abstraction silently drops that behavior; every optional segment BORN
     * from this alignment is then degraded, telling consumers the new
     * boolean params understate this member — the full re-pin is routed to
     * the S27/S45 chain.
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
    ): bool {
        if (!$ctx->optionalBlocksEnabled || !$this->arrayIsStmtList($tList) || !$this->arrayIsStmtList($mList)) {
            $this->createHole([...$path, $sub], null, null, $memberIdx, $ctx, 'subtree');
            return false;
        }

        $tList = array_values($tList);
        $mList = array_values($mList);
        $tHashes = array_map(fn(Node $s) => $this->stmtHash($s), $tList);
        $mHashes = array_map(fn(Node $s) => $this->stmtHash($s), $mList);
        $matchT  = $this->lcsAlignTemplate($tHashes, $mHashes);

        // Member-side extras: statement indices of THIS member that no
        // template position aligned to. They are behavior the generalized
        // template cannot represent (output-018).
        $alignedMember = [];
        foreach ($matchT as $j) {
            if ($j !== -1) {
                $alignedMember[$j] = true;
            }
        }
        $hasMemberExtras = count($alignedMember) < count($mList);

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
            return false;
        }

        /** @var list<Hole> $bornHere optional holes newly born from this alignment */
        $bornHere = [];
        $clean    = !$hasMemberExtras;
        foreach ($tList as $i => $tStmt) {
            $childPath = [...$path, $sub, $i];
            $childKey  = self::pathKey($childPath);
            $j         = $matchT[$i];

            if ($j >= 0) {
                if (isset($ctx->holesByPath[$childKey])) {
                    // A prior member created an optional hole here; this member
                    // has the segment, so record the present marker. Evidence
                    // below this path is not gathered on this visit.
                    $this->observe($ctx->holesByPath[$childKey], $tStmt instanceof Node ? $tStmt : null, $memberIdx);
                    $clean = false;
                } else {
                    if (!$this->walk($tStmt, $mList[$j], $memberIdx, $ctx, $childPath)) {
                        $clean = false;
                    }
                }
            } else {
                $hole = $this->createOrAppendOptionalHole($childPath, $tStmt, $memberIdx, $ctx);
                if ($hole !== null) {
                    $bornHere[] = $hole;
                }
                $clean = false;
            }
        }
        if ($hasMemberExtras && $bornHere !== []) {
            foreach ($bornHere as $hole) {
                $hole->markDegraded();
            }
        }
        return $clean;
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
        return sha1(implode(Delimiters::HASH_JOIN, AstSerializer::tokens($stmt)));
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
     * Record that the member lacks (or already disagrees at) one template
     * statement position inside a diverging stmts array.
     *
     * @param list<int|string> $path
     * @return Hole|null the hole NEWLY BORN from this call — existing
     *                   holes are appended to, never re-born, so the
     *                   member-extras degrade only touches claims this
     *                   alignment itself minted (output-018).
     */
    private function createOrAppendOptionalHole(array $path, ?Node $tStmt, int $memberIdx, UnifyContext $ctx): ?Hole
    {
        $key = self::pathKey($path);
        if (isset($ctx->holesByPath[$key])) {
            $existing = $ctx->holesByPath[$key];
            $this->fillGaps($existing, $memberIdx);
            if ($existing->kind === 'optional_block') {
                // '<absent>' is the native row of an optional segment —
                // this member verifiably lacks the template's statement here.
                $existing->appendObserved('<absent>');
            } else {
                // output-018: stamping '<absent>' into a VALUE hole claims
                // the member merely lacked what the seed has. This path is
                // a recorded divergence, not an optional segment — the
                // member's real slot content is unrecoverable here, so the
                // row is an explicit structural gap and the hole degrades.
                $existing->appendMissing();
            }
            return null;
        }
        $holeId = '__O' . ($ctx->holeCounter++);
        $hole   = new Hole($holeId, 'optional_block');
        for ($i = 0; $i < $memberIdx; $i++) {
            if ($i === 0 || isset($ctx->passedByMember[$i][$key])) {
                // Seed (always) or a member that walked this exact path
                // cleanly — its statement was token-equal to the template's,
                // so the segment was present.
                $this->observe($hole, $tStmt, $i);
            } else {
                // Never visited, or diverged beneath the segment: presence
                // of the seed's statement is unverifiable — gap, not guess.
                $this->fillGaps($hole, $i);
                $hole->appendMissing();
            }
        }
        $this->fillGaps($hole, $memberIdx);
        $hole->appendObserved('<absent>');
        $ctx->holes[$holeId]      = $hole;
        $ctx->holesByPath[$key]   = $hole;
        $ctx->holePaths[$holeId]  = $path;
        return $hole;
    }

    /**
     * @param list<int|string> $path
     */
    private function createHole(array $path, ?Node $tNode, ?Node $mNode, int $memberIdx, UnifyContext $ctx, string $kind): void
    {
        $key = self::pathKey($path);
        if (isset($ctx->holesByPath[$key])) {
            $this->observe($ctx->holesByPath[$key], $mNode, $memberIdx);
            return;
        }
        $holeId = '__P' . ($ctx->holeCounter++);
        $hole   = new Hole($holeId, $kind);
        for ($i = 0; $i < $memberIdx; $i++) {
            if ($tNode !== null && ($i === 0 || isset($ctx->passedByMember[$i][$key]))) {
                // The seed itself, or a member whose walk proved its node at
                // this path is token-equal to the template's. The seed's repr
                // IS that member's value here.
                $this->observe($hole, $tNode, $i);
            } else {
                // output-018: this member diverged at or inside this path
                // (or never reached it) — back-recording the seed's repr
                // would misreport differing content as agreement. Whole-
                // array/null-slot holes keep their all-gap backfill.
                $this->fillGaps($hole, $i);
                $hole->appendMissing();
            }
        }
        $this->observe($hole, $mNode, $memberIdx);
        $ctx->holes[$holeId]      = $hole;
        $ctx->holesByPath[$key]   = $hole;
        $ctx->holePaths[$holeId] = $path;
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

    /**
     * Materialize every row the hole has not yet received for local walk
     * order up to (exclusive) $memberIdx as an explicit structural gap.
     * Rows are positional — consumers index them by member — so a skipped
     * member must never shift later members' rows into its slot.
     */
    private function fillGaps(Hole $hole, int $memberIdx): void
    {
        while (count($hole->observedValues) < $memberIdx) {
            $hole->appendMissing();
        }
    }

    private function markPassed(UnifyContext $ctx, int $memberIdx, string $key): void
    {
        $ctx->passedByMember[$memberIdx][$key] = true;
    }

    /**
     * Record one member's observation on a hole. Item 5.4: a null node
     * (the member had nothing at this path) becomes a structural GAP —
     * {@see Hole::MISSING} plus `degraded` — instead of the old `<missing>`
     * string, which masqueraded as a PHP fragment in signatures, reports
     * and parameter-type inference.
     */
    private function observe(Hole $hole, ?Node $node, int $memberIdx): void
    {
        $this->fillGaps($hole, $memberIdx);
        if ($node === null) {
            $hole->appendMissing();
            return;
        }
        $hole->appendObserved($this->repr($node));
    }

    private function repr(Node $node): string
    {
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
    /** @var array<string, list<int|string>> holeId => path */
    public array $holePaths = [];
    /**
     * local member index => set of path keys the member walked cleanly
     * (its whole subtree under the path was token-equal to the template's).
     * Seed-repr back-records are only honest for marked paths (output-018).
     *
     * @var array<int, array<string, true>>
     */
    public array $passedByMember = [];

    public function __construct(
        public readonly bool $optionalBlocksEnabled = true,
        public readonly int $optionalBlocksMaxPerCluster = 3,
    ) {}
}
