<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use PhpParser\Node;
use Phpdup\Extraction\Block;

/**
 * Computes impact (duplicated LINES — item 6.2), confidence, safety and
 * the composite score for each cluster, drops low-impact clusters under
 * the configured threshold (gated on the lines quantity, semantics
 * moved from AST nodes to lines with the same numeric default), and
 * returns clusters sorted via {@see ClusterSort}.
 *
 * Default sort is `impact:desc` to preserve the original "biggest wins
 * first" behaviour. Pass any other {@see ClusterSort} (e.g. parsed
 * from `--sort=members` or `--sort=block-size:asc`) to change the
 * ordering — Ranker still scores impact + confidence first so the
 * stable tie-breakers in ClusterSort can break ties consistently.
 */
final class Ranker
{
    private readonly ClusterSort $sort;
    private readonly SafetyScorer $safetyScorer;

    public function __construct(
        private readonly int $minImpact = 20,
        ?ClusterSort $sort = null,
        private readonly float $minSafety = 0.0,
        ?SafetyScorer $safetyScorer = null,
        // Item 5.1: scoring stance for cross-repo clones. 'global'
        // (default) runs the legacy math byte-identically; 'per-repo'
        // counts namespace spread once per repo instead of globally
        // (the double-dip fix); 'typed' additionally flips the
        // cross-repo signal into a bonus (audit posture).
        private readonly string $scoreMode = Config::SCORE_MODE_GLOBAL,
    ) {
        if (!in_array($scoreMode, Config::SCORE_MODES, true)) {
            throw new \InvalidArgumentException("score_mode must be one of " . implode('|', Config::SCORE_MODES) . ", got: $scoreMode");
        }
        $this->sort         = $sort ?? new ClusterSort();
        $this->safetyScorer = $safetyScorer ?? new SafetyScorer($scoreMode);
        // No minSafety range check here: range admission is a CLI/config
        // boundary contract (output-056), not a per-call-site crash leg.
        //
        // once-S20 (php-dup-finder-once-023) keeps it that way on purpose.
        // The ranker-side half of that finding was a raw RuntimeException
        // (usage dump, exit 1 ≠ the documented 2) fired from HERE after a
        // full analysis for `--min-safety 5`. That value can no longer be
        // constructed: ConfigLoader::coerceRuntimeFloat('min_safety', …,
        // 0.0, 1.0) refuses it at option admission (shell-S24) and the
        // tokenizer beats no more (Command::run(), same step), so every
        // number reaching this constructor already carries the [0, 1]
        // domain — validated before use, never at use. Re-adding a throw
        // here would resurrect the leak the finding named. Pinned by
        // tests/Unit/Cli/NumericFlagCoercionTest.
    }

    /**
     * @param list<Cluster> $clusters
     * @return list<Cluster>
     */
    public function rank(array $clusters): array
    {
        foreach ($clusters as $c) {
            $c->impact     = $this->impactOf($c);
            $c->confidence = $this->confidenceOf($c);
            $c->safety     = $this->safetyScorer->score($c);
            // Item 6.2: composite computed after its factors exist.
            // The min_impact/min_safety floor below keeps gating on the
            // raw lines quantity (min_cluster_impact semantics per the
            // doc letter); the composite only drives ordering.
            $c->score      = $this->compositeScore($c);
        }
        $kept = array_values(array_filter(
            $clusters,
            fn(Cluster $c) => $c->impact >= $this->minImpact && $c->safety >= $this->minSafety,
        ));
        return $this->sort->apply($kept);
    }

    // ---------------------------------------------------------------
    // Item 6.2 composite ranking (improve_logic.md:321-323)
    //
    //   "Score = removable lines × spread (files, packages, repos) ×
    //    divergence risk × confidence. Express impact in lines.
    //    Demote declarative shapes (a body that is a single
    //    `return [...]` of property reads, route tables, accessor
    //    boilerplate, constructor promotion); a simple test is
    //    statement-kind entropy plus callee diversity."
    //
    // Reading (disclosed in the E3 commit body): $impact carries the
    // "removable lines" quantity (owner ruling 2026-11-28: dual
    // quantity NOT shipped). spread/divergence/confidence are the
    // multiplicative legs below; "divergence risk" is implemented as a
    // factor that GROWS with safety (risk discounts the score, never
    // inflates it). Declarative-shape demotion is a fifth, doc-listed
    // leg, kept separate from "divergence risk" because §3.5 attributes
    // the DTO false-positive to shape, not to ns-crossing.
    // ---------------------------------------------------------------

    /** Per additional distinct member file (capped) — the "packages" leg. */
    public const SPREAD_FILE_STEP = 0.10;
    public const SPREAD_FILE_CAP  = 10;
    /** Per additional distinct member directory (capped). */
    public const SPREAD_DIR_STEP = 0.05;
    public const SPREAD_DIR_CAP  = 5;
    /** Per additional distinct member repo label (capped). */
    public const SPREAD_REPO_STEP = 0.20;
    public const SPREAD_REPO_CAP  = 3;

    /** divergenceFactor = FLOOR + (1 − FLOOR) × safety, so risk ≤ halving. */
    public const DIVERGENCE_FLOOR = 0.5;

    /** Hard demotion: single `return [...]` of property reads, route tables,
     *  accessor/property-shuffle boilerplate (the doc's named shapes). */
    public const DEMOTION_HARD = 0.05;
    /** Soft demotion: doc's "simple test" — low statement-kind entropy AND
     *  near-zero callee diversity across a multi-statement body. */
    public const DEMOTION_SOFT = 0.30;
    /** Normalised top-level statement-kind entropy below which the soft
     *  leg may fire (Shannon / log2(statement count)). */
    public const SOFT_ENTROPY_CEILING = 0.5;
    /** Distinct callee names at or below which the soft leg may fire. */
    public const SOFT_CALLEE_CEILING = 1;
    /** A route table needs at least this many array items, ≥60% of them
     *  closures/arrow fns, to read as a declarative dispatch table. */
    public const ROUTE_TABLE_MIN_ITEMS = 3;

    /**
     * Composite = removable lines × spread × divergence × confidence ×
     * declarative-shape demotion. Deterministic pure arithmetic over the
     * cluster's member facts — identical in serial and parallel runs
     * (pinned by tests/Integration/E3RankingParityTest).
     */
    public function compositeScore(Cluster $c): float
    {
        if ($c->impact <= 0) {
            return 0.0;
        }
        return $c->impact
            * $this->spreadFactor($c)
            * (self::DIVERGENCE_FLOOR + (1 - self::DIVERGENCE_FLOOR) * $c->safety)
            * $c->confidence
            * $this->declarativeDemotion($c);
    }

    /**
     * 1 + 0.10 per extra file + 0.05 per extra dir + 0.20 per extra repo
     * (each capped), clamped into [1, SPREAD_MAX]. A clone mirrored
     * across more surfaces removes more real code per extracted helper,
     * exactly the doc's "(files, packages, repos)" parenthetical.
     *
     * The repo leg reads the Block::$repo scalars DIRECTLY (stamped every
     * run by RepoLabels from PreprocessStage/ReportStage — 5.1) rather
     * than through the ClusterScope spread memo: item 6.2's composite is
     * a Report-time arithmetic consumer, and item 5.1's contract
     * (ClusterScopeTest::testRealConsumersPayOneWalkPerClusterPerRun)
     * pins that global-score runs pay ZERO memo walks. Distinct
     * non-empty labels mirror ClusterScope::repos() exactly, so an
     * unlabeled or single-repo cluster gains no repo step either way.
     */
    public function spreadFactor(Cluster $c): float
    {
        $files = [];
        $dirs  = [];
        $repos = [];
        foreach ($c->members as $m) {
            $files[$m->file] = true;
            $dirs[\dirname($m->file)] = true;
            if ($m->repo !== '') {
                $repos[$m->repo] = true;
            }
        }
        $factor = 1.0
            + self::SPREAD_FILE_STEP * min(max(count($files) - 1, 0), self::SPREAD_FILE_CAP)
            + self::SPREAD_DIR_STEP  * min(max(count($dirs)  - 1, 0), self::SPREAD_DIR_CAP)
            + self::SPREAD_REPO_STEP * min(max(count($repos) - 1, 0), self::SPREAD_REPO_CAP);
        return min($factor, self::SPREAD_MAX);
    }

    /** Deliberate ceiling so spread can never dominate the lines leg. */
    public const SPREAD_MAX = 2.75;

    /**
     * Declarative-shape demotion, geometric mean over members so a
     * partially-declarative cluster fades smoothly instead of vanishing.
     *
     * Reads Block::$canonical — the SAME tree every scoring stage uses
     * (workers drop $ast after fingerprinting but ship canonical;
     * Clusterer:360 and PairScoreWorker already rely on it) — falling
     * back to $ast, and scoring 1.0 (no demotion) for a member with
     * neither tree loaded. That neutral fallback is the honest direction:
     * demotion may only fire on evidence, never on absence, and absent
     * trees cannot bias serial-vs-parallel (both modes have canonical at
     * rank time, verified by the parity belt).
     */
    public function declarativeDemotion(Cluster $c): float
    {
        $logSum = 0.0;
        $n      = 0;
        foreach ($c->members as $m) {
            $logSum += \log($this->memberDeclarativeFactor($m));
            $n++;
        }
        if ($n === 0) {
            return 1.0;
        }
        $factor = \exp($logSum / $n);
        // Homogeneous evidence snaps to the shipped constant exactly:
        // exp(log(x)) round-trips introduce a 1-ulp fuzz otherwise.
        if (abs($factor - self::DEMOTION_HARD) < 1e-12) {
            return self::DEMOTION_HARD;
        }
        if (abs($factor - self::DEMOTION_SOFT) < 1e-12) {
            return self::DEMOTION_SOFT;
        }
        if (abs($factor - 1.0) < 1e-12) {
            return 1.0;
        }
        return $factor;
    }

    /**
     * One member's [DEMOTION_HARD, 1] factor. Named tests follow the
     * doc's four enumerated shapes; the un-named low-entropy +
     * low-callee-diversity body is the doc's "simple test".
     */
    private function memberDeclarativeFactor(Block $m): float
    {
        $node = isset($m->canonical) ? $m->canonical : $m->ast;
        if ($node === null) {
            return 1.0; // tree never loaded here: no evidence, no demotion
        }

        $stmts = $this->topLevelStmts($node);

        // Shape 1+4 (accessor boilerplate / constructor-promotion copy):
        // every top-level statement is `return <prop|const|var>` or
        // `$this->p = <var|prop>` — property shuffling, zero computation.
        if ($stmts !== [] && $this->isPropertyShuffle($stmts)) {
            return self::DEMOTION_HARD;
        }

        // Shape 2 (DTO serializer): a single `return [...]` whose every
        // (recursively nested) item value is a property read.
        if (count($stmts) === 1 && $stmts[0] instanceof Node\Stmt\Return_
            && $stmts[0]->expr instanceof Node\Expr\Array_
            && $this->allValuesArePropertyReads($stmts[0]->expr->items)
        ) {
            return self::DEMOTION_HARD;
        }

        // Shape 3 (route table): a single-expression Array_ dispatch
        // map where ≥60% of the item values are closures/arrow fns.
        if ($stmts !== [] && $this->isRouteTable(\count($stmts) === 1 && $stmts[0] instanceof Node\Stmt\Return_
            ? $stmts[0]->expr
            : $node)) {
            return self::DEMOTION_HARD;
        }

        // The doc's simple test: statement-kind entropy plus callee
        // diversity. A body that repeats one statement kind and calls
        // essentially nothing (canonical name-stamping keeps counts
        // exact — identity is lost in aggressive mode, diversity is a
        // COUNT) is a declarative wall of noise, not logic worth
        // page-one attention.
        $count = \count($stmts);
        if ($count >= 3
            && $this->normalisedStmtEntropy($stmts) < self::SOFT_ENTROPY_CEILING
            && $this->distinctCallees($node) <= self::SOFT_CALLEE_CEILING
        ) {
            return self::DEMOTION_SOFT;
        }

        return 1.0;
    }

    /**
     * Top-level statements of a member's canonical tree. Kinds wrap one
     * node each (ClassMethod/Function_/Closure carry `stmts`; `window`
     * is a Stmt\Block; arrow/match bodies are single expressions).
     *
     * @return list<Node>
     */
    private function topLevelStmts(Node $node): array
    {
        if (property_exists($node, 'stmts') && \is_array($node->stmts)) {
            return $node->stmts;
        }
        return [$node];
    }

    /**
     * @param list<Node\ArrayItem|Node\Expr\ArrayItem|null> $items
     */
    private function allValuesArePropertyReads(array $items): bool
    {
        $seen = 0;
        foreach ($items as $item) {
            if ($item === null) {
                continue; // spread element — not a property read
            }
            $seen++;
            if (!$this->isPropertyReadTree($item->value)) {
                return false;
            }
        }
        return $seen > 0;
    }

    private function isPropertyReadTree(Node $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Expr\PropertyFetch,
            $expr instanceof Node\Expr\NullsafePropertyFetch,
            $expr instanceof Node\Expr\StaticPropertyFetch,
            $expr instanceof Node\Expr\ClassConstFetch,
            $expr instanceof Node\Expr\ConstFetch,
            $expr instanceof Node\Expr\Variable  => true,
            $expr instanceof Node\Expr\Array_    => $this->allValuesArePropertyReads($expr->items),
            default                             => false,
        };
    }

    /**
     * @param list<Node> $stmts
     */
    private function isPropertyShuffle(array $stmts): bool
    {
        $sawPropertyReturn = false;
        foreach ($stmts as $s) {
            if ($s instanceof Node\Stmt\Return_) {
                if (!($s->expr instanceof Node\Expr\PropertyFetch
                    || $s->expr instanceof Node\Expr\NullsafePropertyFetch
                    || $s->expr instanceof Node\Expr\StaticPropertyFetch
                    || $s->expr instanceof Node\Expr\ClassConstFetch)) {
                    return false; // returning a call result, new instance,
                                  // or local var is computation, not shuffling
                }
                $sawPropertyReturn = true;
                continue;
            }
            if ($s instanceof Node\Stmt\Expression
                && $s->expr instanceof Node\Expr\Assign
                && $s->expr->var instanceof Node\Expr\PropertyFetch
                && ($s->expr->expr instanceof Node\Expr\Variable
                    || $s->expr->expr instanceof Node\Expr\PropertyFetch
                    || $s->expr->expr instanceof Node\Expr\ClassConstFetch
                    || $s->expr->expr instanceof Node\Expr\ConstFetch)
            ) {
                continue; // promotion-style copy (direct read-only transport)
            }
            return false;
        }
        // A body of ONLY property-assigns needs ≥2 of them to read as a
        // constructor-promotion copy; a lone property-getter qualifies by
        // the property-return leg.
        return $sawPropertyReturn || \count($stmts) >= 2;
    }

    /** Route-table test: an Array_ (root or anywhere shallow under a
     *  declarative single-expression wrapper) of mostly callables. */
    private function isRouteTable(?Node $expr): bool
    {
        if (!$expr instanceof Node\Expr\Array_) {
            return false;
        }
        $items   = 0;
        $closure = 0;
        foreach ($expr->items as $item) {
            if ($item === null) {
                continue;
            }
            $items++;
            if ($item->value instanceof Node\Expr\Closure
                || $item->value instanceof Node\Expr\ArrowFunction) {
                $closure++;
            }
        }
        return $items >= self::ROUTE_TABLE_MIN_ITEMS
            && $closure / $items >= 0.6;
    }

    /**
     * Shannon entropy of top-level statement classes, normalised by
     * log2(n) so 1.0 means "every kind equally present" and ~0 means
     * one monotone repetition.
     *
     * @param list<Node> $stmts
     */
    private function normalisedStmtEntropy(array $stmts): float
    {
        $n = \count($stmts);
        if ($n < 2) {
            return 0.0;
        }
        $counts = [];
        foreach ($stmts as $s) {
            $cls = \get_class($s);
            $counts[$cls] = ($counts[$cls] ?? 0) + 1;
        }
        $entropy = 0.0;
        foreach ($counts as $c) {
            $p = $c / $n;
            $entropy -= $p * \log($p, 2);
        }
        return $entropy / \log($n, 2);
    }

    /**
     * Distinct invoked names in the member tree (methods, statics,
     * functions, constructors). Under aggressive canonicalisation names
     * are __CALL_<n> stamps — distinctness (and thus the COUNT) is
     * exact even though identity is gone; structural functions keep
     * real names, so pure isset/count walls still read as one callee
     * kind. Dynamic calls group under one sentinel each.
     */
    private function distinctCallees(Node $root): int
    {
        $names = [];
        $walk  = function (Node $node) use (&$walk, &$names): void {
            if ($node instanceof Node\Expr\MethodCall
                || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall
                || $node instanceof Node\Expr\FuncCall) {
                $key = $this->calleeKey($node->name);
                $names[$key] = true;
            } elseif ($node instanceof Node\Expr\New_) {
                $names['new:' . $this->calleeKey($node->class)] = true;
            }
            foreach ($node->getSubNodeNames() as $sub) {
                $child = $node->{$sub};
                if ($child instanceof Node) {
                    $walk($child);
                } elseif (\is_array($child)) {
                    foreach ($child as $grand) {
                        if ($grand instanceof Node) {
                            $walk($grand);
                        }
                    }
                }
            }
        };
        $walk($root);
        return \count($names);
    }

    private function calleeKey(mixed $name): string
    {
        if ($name instanceof Node\Identifier) {
            return $name->toString();
        }
        if ($name instanceof Node\Name) {
            return $name->toString();
        }
        return '<dynamic>';
    }

    /**
     * impact: removable source LINES if the abstraction is applied
     * (item 6.2 — improve_logic.md §6.2 "Express impact in lines").
     *
     * Formula: union-line coverage of the members (per-file interval
     * dedupe — nested/overlapping blocks count shared lines once, never
     * twice) × (members − 1) / members: all but one copy can disappear,
     * expressed as the mean per-copy coverage so an over-split cluster
     * with duplicated line-spans cannot outscore an equal-size exact
     * clone set. Rounded to whole lines.
     *
     * The old node-scaled (members − 1) × avgBlockSize − holesPenalty
     * leg is retired: a per-hole node credit has no meaning on a lines
     * quantity, and its intent (discount shaky generalisations at the
     * reporting floor) is already carried by the confidence −0.1
     * subtree-hole penalty, which the composite multiplies with.
     */
    private function impactOf(Cluster $c): int
    {
        $members = $c->size();
        if ($members < 2) {
            return 0;
        }
        return (int) round(Cluster::unionLineSpan($c->members) * ($members - 1) / $members);
    }

    /**
     * confidence: how safe the suggested abstraction looks.
     *
     *   start at the cluster's similarity score
     *   - subtract 0.1 for each subtree hole (large variable parts)
     *   - subtract 0.05 if cluster spans more than 3 namespaces
     *     (item 5.1: under score-mode per-repo that spread is counted
     *     within the densest member repo; under typed, cross-repo
     *     clusters get the inverse +0.05 instead)
     *   - bump 0.05 if all members share the same class
     *   - clamp to [0,1]
     */
    private function confidenceOf(Cluster $c): float
    {
        $conf = $c->similarity;
        foreach ($c->holes as $h) {
            if ($h->kind === 'subtree') $conf -= 0.1;
        }
        $namespaces = [];
        $classes = [];
        foreach ($c->members as $m) {
            $namespaces[$m->namespace ?? ''] = true;
            $classes[$m->class ?? ''] = true;
        }
        if ($this->scoreMode === Config::SCORE_MODE_GLOBAL) {
            // legacy math — byte-identical to every score ever stored.
            if (count($namespaces) > 3) $conf -= 0.05;
        } elseif (ClusterScope::isCrossRepo($c) && $this->scoreMode === Config::SCORE_MODE_TYPED) {
            // Item 5.1 audit posture: a cross-repo clone is stronger
            // dedup evidence (one shared helper erases N repo-local
            // copies), so the ns-spread penalty is replaced by a bonus.
            $conf += 0.05;
        } elseif (ClusterScope::maxRepoNamespaceSpread($c) > 3) {
            // per-repo (and typed same-repo): the >3 penalty fires on
            // the namespace spread within the densest single repo, so
            // the same subsystem mirrored into N repos no longer reads
            // as N unrelated namespaces (the double-dip fix).
            $conf -= 0.05;
        }
        if (count($classes) === 1)  $conf += 0.05;
        return max(0.0, min(1.0, $conf));
    }
}
