<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Util\LineRange;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Normalization\ShapeFingerprint;
use Phpdup\Parsing\AstParser;

/**
 * Item 2.3 — unit pins for the class-shape fingerprint: the skeleton's
 * name-blank identity law, the member-token table's exact rows, and the
 * skeleton-family verdict fuel (the two per-method hash lanes).
 */
final class ShapeFingerprintTest extends TestCase
{
    public function testSkeletonBlanksOnlyTheDeclarationName(): void
    {
        $class = $this->classNode('class Alpha { public function go(): void {} }');
        $skeleton = ShapeFingerprint::skeleton($class);
        $this->assertInstanceOf(Node\Stmt\Class_::class, $skeleton);
        $this->assertSame(ShapeFingerprint::CLASS_NAME_SENTINEL, $skeleton->name->toString());

        $iface = $this->classNode('interface AIf { public function go(): void; }');
        $this->assertSame(
            ShapeFingerprint::CLASS_NAME_SENTINEL,
            ShapeFingerprint::skeleton($iface)->name->toString(),
        );
    }

    public function testSameShapeDifferentNameSkeletonsHashIdentically(): void
    {
        // The doc's declaration-twin law, mode-agnostic across the two
        // non-strict lanes (default blanks literals; aggressive stamps
        // calls) — the twins here carry NO literal/call vocabulary, so
        // the pair must be exact in every fold.
        foreach (['default', 'aggressive'] as $mode) {
            $a = $this->skeletonHash("class RateLimitStateA { private const MAX = 5; public string \$bucket; public function hit(): int { return 1; } }", $mode);
            $b = $this->skeletonHash("class RateLimitStateB { private const MAX = 5; public string \$bucket; public function hit(): int { return 1; } }", $mode);
            $this->assertSame($a, $b, "name-blanked twins must share one skeleton hash (mode $mode)");
        }
    }

    public function testDifferentImplementedInterfaceChangesTheSkeletonHash(): void
    {
        // Implements names are doc shape tokens — they survive the blank.
        $a = $this->skeletonHash('class X implements First {}', 'default');
        $b = $this->skeletonHash('class X implements Second {}', 'default');
        $this->assertNotSame($a, $b);
    }

    public function testMemberSignaturesSpellEveryRowFromTheRawAst(): void
    {
        $tokens = ShapeFingerprint::memberSignatures($this->classNode(
            'final class Page implements Stoppable
             {
                 private const STATUS_MAP = [\'a\' => 1];
                 protected static ?string $assetBase = null;
                 public function handle(mixed $order, int $n = 0): void {}
             }'
        ));
        $this->assertSame([
            'kind:class:final:impl:Stoppable',
            'c:private:STATUS_MAP',
            'p:protected:t:assetBase:?string',
            'm:public:handle/2:void',
        ], $tokens);
    }

    public function testMemberSignaturesCoverInterfaceEnumAndTraitSpecies(): void
    {
        $iface = ShapeFingerprint::memberSignatures($this->classNode(
            'interface I extends Base { public const OK = true; public function a(): void; public function b(int ...$xs): self; }'
        ));
        $this->assertSame([
            'kind:interface:impl:Base',
            'c:public:OK',
            'm:public:a/0:void',
            'm:public:b/1:self',
        ], $iface);

        $enum = ShapeFingerprint::memberSignatures($this->classNode(
            'enum Suit: string { case Hearts = \'H\'; use SharedBehavior; }'
        ));
        $this->assertSame(['kind:enum:backing=string', 'case:Hearts', 'use:SharedBehavior'], $enum);

        $abstract = ShapeFingerprint::memberSignatures($this->classNode(
            'abstract class Base extends Holder implements Countable { abstract protected function kid(): mixed; }'
        ));
        $this->assertSame([
            'kind:class:abstract:ext:Holder:impl:Countable',
            'm:protected:a:kid/0:mixed',
        ], $abstract);
    }

    public function testMethodHashesSeparateIdenticalParameterizableAndDivergent(): void
    {
        // Two classes, same shape: handle() differs only in a string
        // literal (parameterizable: s differs, d equal); boot() is
        // byte-identical (identical-in-all: s equal); tail() diverges in
        // BODY SHAPE (d differs).
        $a = $this->classNode('class A1 { public function handle(): string { return "checkout"; } public function boot(): void { $x = 1; } public function tail(): int { $n = 0; return $n + 1; } }');
        $b = $this->classNode('class A2 { public function handle(): string { return "orders"; } public function boot(): void { $x = 1; } public function tail(): int { $n = 2; return $n * 3; } }');

        $normalizer = new Normalizer('default');
        $hasher     = new SubtreeHasher();
        $ha = ShapeFingerprint::methodHashes($a, $normalizer, $hasher);
        $hb = ShapeFingerprint::methodHashes($b, $normalizer, $hasher);

        $this->assertSame(['handle', 'boot', 'tail'], array_keys($ha));
        $this->assertNotSame($ha['handle']['s'], $hb['handle']['s'], 'strict lane keeps literals');
        $this->assertSame($ha['handle']['d'], $hb['handle']['d'], 'run fold blanks literals');
        $this->assertSame($ha['boot']['s'], $hb['boot']['s']);
        $this->assertSame($ha['boot']['d'], $hb['boot']['d']);
        $this->assertNotSame($ha['tail']['d'], $hb['tail']['d'], 'shape divergence survives the fold');
    }

    public function testAbstractAndInterfaceBodiesShareOneEmptyToken(): void
    {
        $a = ShapeFingerprint::methodHashes(
            $this->classNode('interface IA { public function go(): void; }'),
            new Normalizer('default'),
            new SubtreeHasher(),
        );
        $b = ShapeFingerprint::methodHashes(
            $this->classNode('class IB { public function go(): void {} }'),
            new Normalizer('default'),
            new SubtreeHasher(),
        );
        // An interface signature and an empty-bodied declaration carry
        // the SAME fold (both canonicalise to the empty-statement
        // skeleton) — ServiceProviderInterface twins cluster trivially.
        $this->assertSame($a['go']['d'], $b['go']['d']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function classNode(string $code): Node
    {
        $stmts = (new AstParser())->parseCode('<?php ' . $code);
        $this->assertIsArray($stmts, 'fixture must parse');
        $found = (new NodeFinder())->findFirst(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\ClassLike
        );
        $this->assertInstanceOf(Node\Stmt\ClassLike::class, $found);
        return $found;
    }

    private function skeletonHash(string $code, string $mode): string
    {
        $node = $this->classNode($code);
        $block = new Block(
            file: 'shape.php',
            range: new LineRange(1, 5),
            kind: 'class',
            namespace: null,
            class: null,
            name: $node->name->toString(),
            ast: $node,
        );
        (new Normalizer($mode))->normalize($block);
        $hasher = new SubtreeHasher();
        return $hasher->hash(ShapeFingerprint::skeleton($block->canonical));
    }
}
