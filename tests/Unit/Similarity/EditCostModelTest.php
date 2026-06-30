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

    private function stmts(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        return $stmts[0];
    }
}
