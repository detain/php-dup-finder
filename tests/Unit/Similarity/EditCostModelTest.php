<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Similarity;

use PHPUnit\Framework\TestCase;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\AptedDistance;
use Phpdup\Similarity\EditCostModel;

final class EditCostModelTest extends TestCase
{
    public function testDefaultModelHasUnitCost(): void
    {
        $m = new EditCostModel(EditCostModel::MODEL_DEFAULT);
        $this->assertSame(1.0, $m->cost('MethodCall'));
        $this->assertSame(1.0, $m->cost('String_'));
        $this->assertSame(1.0, $m->cost('If_'));
    }

    public function testSemanticModelWeightsCallsHeavier(): void
    {
        $m = new EditCostModel(EditCostModel::MODEL_SEMANTIC);
        // Bare names (kept for backward compatibility)
        $this->assertSame(2.0, $m->cost('MethodCall'));
        $this->assertSame(1.5, $m->cost('If_'));
        $this->assertSame(0.5, $m->cost('String_'));
        $this->assertSame(1.0, $m->cost('SomeOtherNode'));
    }

    public function testSemanticModelWithPrefixedLabels(): void
    {
        // Labels from AstSerializer::shortType() arrive prefixed.
        $m = new EditCostModel(EditCostModel::MODEL_SEMANTIC);
        $this->assertSame(2.0, $m->cost('Expr_MethodCall'));
        $this->assertSame(2.0, $m->cost('Expr_NullsafeMethodCall'));
        $this->assertSame(2.0, $m->cost('Expr_StaticCall'));
        $this->assertSame(2.0, $m->cost('Expr_FuncCall'));
        $this->assertSame(2.0, $m->cost('Expr_New_'));
        $this->assertSame(2.0, $m->cost('Expr_Name'));
        $this->assertSame(1.5, $m->cost('Stmt_If_'));
        $this->assertSame(0.5, $m->cost('Scalar_Int_'));
        $this->assertSame(0.5, $m->cost('Scalar_String_'));
    }

    public function testRejectsUnknownModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EditCostModel('made-up');
    }

    public function testWeightedAptedDistanceProducesDifferentScoresThanDefault(): void
    {
        // Two snippets that differ in a method-call name vs in a literal
        // value. Under semantic weights the call-rename should hurt the
        // similarity more than the literal change.
        $callA   = $this->stmts('<?php class C { function m() { return $this->alpha($x); } }');
        $callB   = $this->stmts('<?php class C { function m() { return $this->beta($x); } }');
        $litA    = $this->stmts('<?php class C { function m() { return $this->alpha("a"); } }');
        $litB    = $this->stmts('<?php class C { function m() { return $this->alpha("b"); } }');

        $semantic = new AptedDistance(new EditCostModel(EditCostModel::MODEL_SEMANTIC));
        $callSim = $semantic->similarity($callA, $callB);
        $litSim  = $semantic->similarity($litA, $litB);

        // Semantic weights MUST produce a strictly different score from default.
        // If the prefix-stripping bug is present, both models return identical costs.
        $default = new AptedDistance(new EditCostModel(EditCostModel::MODEL_DEFAULT));
        $defaultCallSim = $default->similarity($callA, $callB);
        $defaultLitSim  = $default->similarity($litA, $litB);

        $this->assertNotEquals($defaultCallSim, $callSim,
            'semantic model must differ from default for call-rename');
        $this->assertNotEquals($defaultLitSim, $litSim,
            'semantic model must differ from default for literal-change');
    }

    /**
     * engine-007 (S08a, PDDETECT companion note) — the worst-cost
     * denominator bound.
     *
     * AptedDistance normalises by `worstCost()` = the sum of per-node
     * costs of the LARGER tree (AptedDistance::worstCost / :100-107).
     * For the unit model that is exactly max(|a|,|b|), the true
     * ordered-tree-diameter, so `sim = 1 - cost/worst` is a genuine
     * fraction in [0, 1].
     *
     * Under `semantic` weights the sum-of-larger-tree is NOT an upper
     * bound on the achievable distance: a substitution charges
     * max(costA, costB), so aligning one expensive node in the SMALLER
     * tree against a cheap node in the larger one can cost more than
     * the larger tree's own weight-sum. PDDETECT flagged this as dubious
     * ("worst = maxNodeCount assumption dubious for semantic weights").
     * It is deliberately NOT "fixed" here — it is unverified as a real
     * false-negative risk, and `similarity()` guards the overshoot with
     * `cost > budget → 0.0`, so the OUTCOME never leaves [0, 1]; it just
     * saturates at 0.0 rather than going negative.
     *
     * These two pins lock that saturation contract in: a skewed-cost
     * pair whose true distance exceeds the denominator must clamp to a
     * finite value inside [0, 1], never emit a negative similarity.
     */
    public function testSemanticWorstCostBoundStaysInUnitInterval(): void
    {
        // Heavy scalar-literal tree (cheap nodes under semantic weights)
        // against a small call-bearing tree (expensive nodes): the
        // denominators deliberately disagree with node counts.
        $cheap = $this->stmts('<?php function m() { return 1 + 2 + 3 + 4 + 5 + 6 + 7 + 8; }');
        $calls = $this->stmts('<?php function m() { return $o->doIt(); }');

        $semantic = new AptedDistance(new EditCostModel(EditCostModel::MODEL_SEMANTIC));

        $sim = $semantic->similarity($cheap, $calls);
        $this->assertGreaterThanOrEqual(0.0, $sim, 'similarity must never go negative under weighted cost');
        $this->assertLessThanOrEqual(1.0, $sim, 'similarity must never exceed 1.0 under weighted cost');

        // The budget guard makes a far-apart skewed-cost pair saturate to
        // the 0.0 floor instead of a negative fraction — the bound may be
        // loose but the result stays a valid [0, 1] similarity.
        $far = $semantic->similarity($cheap, $calls, 0.99);
        $this->assertGreaterThanOrEqual(0.0, $far);
        $this->assertLessThanOrEqual(1.0, $far);
    }

    private function stmts(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        return $stmts[0];
    }
}
