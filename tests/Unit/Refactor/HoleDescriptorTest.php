<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\ObservationDescriptor;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\LineRange;

/**
 * Item 4.1: the descriptor sidecar's positional contract on Hole and
 * its recording through the real AntiUnifier walk.
 */
final class HoleDescriptorTest extends TestCase
{
    public function testAppendKeepsRowsAndDescriptorsAligned(): void
    {
        $hole = new Hole('__P0', 'literal');
        $hole->appendObserved("'a'", ['type' => 'Scalar_String_', 'callNames' => [], 'containsExit' => false, 'scalar' => 's:a']);
        $hole->appendObserved(Hole::MISSING, ObservationDescriptor::absent());
        $hole->appendMissing();
        $hole->appendObserved("'c'", null);

        self::assertSame(["'a'", Hole::MISSING, Hole::MISSING, "'c'"], $hole->observedValues);
        self::assertCount(4, $hole->descriptors);
        self::assertSame('Scalar_String_', $hole->descriptorAt(0)['type'] ?? null);
        self::assertSame(ObservationDescriptor::ABSENT_TYPE, $hole->descriptorAt(1)['type'] ?? null);
        self::assertNull($hole->descriptorAt(2), 'a gap pad is never a witness');
        self::assertNull($hole->descriptorAt(3));
        self::assertNull($hole->descriptorAt(99), 'out of range reads null, never errors');
    }

    public function testLegacyHoleWithoutSidecarStaysAmbiguous(): void
    {
        $hole = new Hole('__P0', 'literal', [Hole::MISSING, "'x'"]);
        self::assertSame([], $hole->descriptors);
        self::assertNull($hole->descriptorAt(0));
        self::assertFalse($hole->isVerifiedAbsent(0), 'no marker means no claim');
        self::assertFalse($hole->isVerifiedAbsent(1), 'a present row is never an absence');
    }

    public function testIsVerifiedAbsentReadsTheWitnessRow(): void
    {
        $hole = new Hole('__O0', 'optional_block');
        $hole->appendObserved('if ($x) { return; }', ObservationDescriptor::build(new Node\Stmt\Return_()));
        $hole->appendObserved(Hole::MISSING, ObservationDescriptor::absent());

        self::assertTrue($hole->isVerifiedAbsent(1));
        self::assertFalse($hole->isDegraded(), 'the witnessed absence alone must not degrade');
    }

    public function testAntiUnifierRecordsDescriptorsAtTheWalkSites(): void
    {
        [$a, $b] = self::methodPair(
            'public function a($id) { if (!is_numeric($id)) { json_error("bad"); return; } save($id); }',
            'public function a($id) { save($id); }',
        );
        $cluster = new Cluster('C1', [$a, $b], 0.9, false);
        (new AntiUnifier())->unify($cluster);

        $optional = null;
        foreach ($cluster->holes as $hole) {
            if ($hole->kind === 'optional_block') {
                $optional = $hole;
            }
        }
        self::assertNotNull($optional, 'the guard must surface as an optional_block hole');

        $template = $optional->templateDescriptor;
        self::assertNotNull($template, 'hole birth must capture the template-side descriptor');
        self::assertSame('Stmt_If_', $template['type']);
        self::assertTrue($template['containsExit']);
        self::assertContains('json_error', $template['callNames']);

        self::assertCount(count($optional->observedValues), $optional->descriptors);
        // Seed row: observed guard with its descriptor. Member row:
        // witnessed absence carrying the ABSENT marker (or the walk
        // order swapped the pair — both slots must classify cleanly).
        $absentIndexes = [];
        foreach ($optional->observedValues as $i => $row) {
            if ($row === Hole::MISSING) {
                $absentIndexes[] = $i;
                self::assertTrue(
                    $optional->isVerifiedAbsent($i),
                    'the guard-less member row is a WITNESSED absence, not a gap'
                );
                continue;
            }
            $descriptor = $optional->descriptorAt($i);
            self::assertNotNull($descriptor, 'present rows carry their observation descriptor');
            self::assertSame('Stmt_If_', $descriptor['type']);
        }
        self::assertSame([1], $absentIndexes, 'exactly the guard-less member is absent (seed is the larger member)');
    }

    public function testSerializeRoundTripPreservesTheSidecar(): void
    {
        [$a, $b] = self::methodPair(
            'public function a($id) { if (!is_numeric($id)) { json_error("bad"); return; } save($id); }',
            'public function a($id) { save($id); }',
        );
        $cluster = new Cluster('C1', [$a, $b], 0.9, false);
        (new AntiUnifier())->unify($cluster);

        // The socketpair path ships whole Hole objects; the sidecar is
        // scalars-only so it must survive serialize() untouched.
        $round = unserialize(serialize($cluster->holes));
        foreach ($round as $hole) {
            $original = null;
            foreach ($cluster->holes as $o) {
                if ($o->placeholder === $hole->placeholder) {
                    $original = $o;
                }
            }
            self::assertNotNull($original);
            self::assertSame($original->descriptors, $hole->descriptors);
            self::assertSame($original->templateDescriptor, $hole->templateDescriptor);
        }
        self::assertNotSame([], $round, 'belt: the cluster actually produced holes');
    }

    /** @return array{0: Block, 1: Block} */
    private static function methodPair(string $codeA, string $codeB): array
    {
        return [
            self::methodBlock($codeA, 'a.php', 'A'),
            self::methodBlock($codeB, 'b.php', 'B'),
        ];
    }

    private static function methodBlock(string $methodCode, string $file, string $name): Block
    {
        $stmts = (new AstParser())->parseCode("<?php\nclass Svc_{$name} {\n    {$methodCode}\n}");
        self::assertNotNull($stmts);
        $method = (new NodeFinder())->findFirst($stmts, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);
        $class = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);

        $block           = new Block(
            $file,
            new LineRange($method->getStartLine(), $method->getEndLine()),
            'method',
            null,
            'Svc_' . $name,
            $name,
            $method,
        );
        $block->size     = AstSerializer::nodeCount($method);
        return $block;
    }
}
