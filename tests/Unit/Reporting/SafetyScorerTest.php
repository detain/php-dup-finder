<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\SafetyScorer;
use Phpdup\Util\LineRange;

final class SafetyScorerTest extends TestCase
{
    public function testHighSimilaritySingleNamespaceTypedHolesScoresHigh(): void
    {
        $cluster = $this->cluster(
            similarity: 0.95,
            patternTags: ['config-driven'],
            members: [
                $this->member('App\\Mod', 'Mod'),
                $this->member('App\\Mod', 'Mod'),
                $this->member('App\\Mod', 'Mod'),
            ],
            holes: [
                $this->hole('literal', 'int'),
                $this->hole('literal', 'string'),
            ],
        );
        $score = (new SafetyScorer())->score($cluster);
        $this->assertGreaterThan(0.9, $score);
    }

    public function testCrossNamespaceClusterIsPenalised(): void
    {
        $hi = $this->cluster(
            similarity: 0.95,
            members: [
                $this->member('A', 'C1'), $this->member('A', 'C2'),
                $this->member('A', 'C3'), $this->member('A', 'C4'),
            ],
            holes: [$this->hole('literal', 'int')],
        );
        $lo = $this->cluster(
            similarity: 0.95,
            members: [
                $this->member('A', 'C'), $this->member('B', 'C'),
                $this->member('C', 'C'), $this->member('D', 'C'),
            ],
            holes: [$this->hole('literal', 'int')],
        );
        $scorer = new SafetyScorer();
        $this->assertGreaterThan(
            $scorer->score($lo),
            $scorer->score($hi),
            'single-namespace cluster should score higher than 4-namespace one',
        );
    }

    public function testMixedHolesPullScoreDown(): void
    {
        $typed = $this->cluster(
            similarity: 0.9,
            members: [$this->member('A', 'C'), $this->member('A', 'C'), $this->member('A', 'C')],
            holes: [$this->hole('literal', 'int'), $this->hole('literal', 'int')],
        );
        $mixed = $this->cluster(
            similarity: 0.9,
            members: [$this->member('A', 'C'), $this->member('A', 'C'), $this->member('A', 'C')],
            holes: [$this->hole('subtree', 'mixed'), $this->hole('subtree', 'mixed')],
        );
        $scorer = new SafetyScorer();
        $this->assertGreaterThan($scorer->score($mixed), $scorer->score($typed));
    }

    public function testStateMachineTagSubtractsScore(): void
    {
        $base = $this->cluster(
            similarity: 0.9,
            patternTags: [],
            members: [$this->member('A', 'C'), $this->member('A', 'C'), $this->member('A', 'C')],
            holes: [$this->hole('literal', 'int')],
        );
        $sm = clone $base;
        $sm->patternTags = ['state-machine'];
        $scorer = new SafetyScorer();
        $this->assertGreaterThan($scorer->score($sm), $scorer->score($base));
    }

    public function testMemberPairIsSlightlyPenalised(): void
    {
        $pair = $this->cluster(
            similarity: 0.9,
            members: [$this->member('A', 'C'), $this->member('A', 'C')],
            holes: [$this->hole('literal', 'int')],
        );
        $three = $this->cluster(
            similarity: 0.9,
            members: [$this->member('A', 'C'), $this->member('A', 'C'), $this->member('A', 'C')],
            holes: [$this->hole('literal', 'int')],
        );
        $scorer = new SafetyScorer();
        $this->assertGreaterThan($scorer->score($pair), $scorer->score($three));
    }

    public function testScoreIsClampedToZeroOne(): void
    {
        $cluster = $this->cluster(
            similarity: 1.0,
            patternTags: ['config-driven', 'sql-builder', 'crud-handler'],
            members: array_fill(0, 5, $this->member('A', 'C')),
            holes: [$this->hole('literal', 'int')],
        );
        $score = (new SafetyScorer())->score($cluster);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    /** @param list<Block> $members @param list<Hole> $holes @param list<string> $patternTags */
    private function cluster(float $similarity, array $members, array $holes = [], array $patternTags = []): Cluster
    {
        $c = new Cluster('TEST', $members, $similarity, false);
        $c->holes = $holes;
        $c->patternTags = $patternTags;
        return $c;
    }

    private function member(string $namespace, string $class): Block
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $a;');
        return new Block(
            file: 'x.php',
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: $namespace,
            class: $class,
            name: 'm',
            ast: $stmts[0],
        );
    }

    private function hole(string $kind, string $inferredType): Hole
    {
        static $i = 0;
        $h = new Hole('__h' . (++$i) . '__', $kind);
        $h->inferredType = $inferredType;
        $h->suggestedName = '$x';
        return $h;
    }
}
