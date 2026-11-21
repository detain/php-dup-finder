<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Util\LineRange;
use ReflectionMethod;

/**
 * CI-85 T2 r2 — id-minting determinism pins.
 *
 * The r1 canonical sort key (file, start, end, kind, name, structuralHash)
 * was NOT total: the r1 review constructed two live shapes where it ties —
 *   (A) class-asymmetric: same-name one-line methods in sibling classes on
 *       one physical line. Members[] rows embed `class`, so the tie was
 *       REPORT-OBSERVABLE under an adversarial arrival shuffle (reviewer
 *       seam flipped report md5s 2/4).
 *   (B) emit-symmetric: byte-identical same-line closures. The tie survives
 *       even the r2 eight-key tuple — but every EMITTED members[] field of
 *       the two blocks is byte-equal and the family ids are positional
 *       labels, so the permutation cannot move the members[]-layer bytes.
 *
 * Fix (A) is namespace+class in the key; (B) is pinned AT THIS LAYER ONLY.
 * The r2 wording here ("no report byte can observe the permutation", arm
 * name "...InvisibleToEveryReportByte") overclaimed and is corrected in
 * this round: raw ASTs of orbit members can differ post-normalization
 * (default/aggressive modes fold literals) and holes[].observed is
 * member-position ordered — that exposure lives OUTSIDE these arms, is
 * guarded on the production lazy-AST path only accidentally (the
 * pre-existing BlockAstLoader fail-fast), and is filed as a WAVE-5
 * OWNER-DECISION backlog row. The invariant these arms pin: total order
 * up to emit-symmetry AT THE MINT/MEMBERS[] LAYER (boundary stated in
 * PreprocessStage::assignCanonicalIds docblock). The
 * r1 code comment claiming such ties were unconstructible was false and
 * is corrected by the same commit that adds these pins.
 *
 * Methodology: reviewer's adversarial seam — scramble the whole block
 * array with fixed mt_srand seeds (each chosen seed verifiably flips the
 * two leading blocks' input order) and re-run the private static mint
 * step via reflection; production paths must not depend on this seam,
 * but if they cannot be perturbed by the worst perturbation available
 * short of source edits, arrival order cannot hurt either.
 */
final class PreprocessStageIdDeterminismTest extends TestCase
{
    /**
     * Seeds (probed on the build box) whose shuffle() permutation is a
     * verified flip of a 2-element leading pair — so every arm below
     * actually runs a scrambled feed, never a no-op pass.
     */
    private const FLIP_SEEDS = [0, 2, 3, 4, 42, 123];

    /** @return list<Block> */
    private function extract(string $file, string $src): array
    {
        $stmts = (new AstParser())->parseCode($src);
        $this->assertNotNull($stmts);
        $extractor  = new BlockExtractor(minSize: 1, maxSize: 100000);
        $normalizer = new Normalizer('aggressive');
        $hasher     = new SubtreeHasher();
        $out = [];
        foreach ($extractor->extract($file, $stmts) as $b) {
            $normalizer->normalize($b);
            $b->structuralHash = $hasher->hash($b->canonical);
            $out[] = $b;
        }
        return $out;
    }

    /** @param list<Block> $blocks @return list<Block> */
    private function assign(array $blocks): array
    {
        $m = new ReflectionMethod(PreprocessStage::class, 'assignCanonicalIds');
        /** @var list<Block> $out */
        $out = $m->invoke(null, $blocks);
        return $out;
    }

    /**
     * The r2 eight-key projection of a block — exactly what the
     * comparator consumes, and (minus ids assigned positionally) what
     * a members[] row embeds.
     *
     * @param list<Block> $blocks
     */
    private function emitProjection(array $blocks): string
    {
        $rows = [];
        foreach ($blocks as $b) {
            $rows[] = json_encode([
                $b->id, $b->file, $b->range->start, $b->range->end, $b->kind,
                $b->namespace, $b->class, $b->name, $b->structuralHash,
            ]);
        }
        return implode("\n", $rows);
    }

    public function testClassAsymmetricOneLineTieIsKilledByTheEightKeyTuple(): void
    {
        // Reviewer's exact corpus: two one-line sibling classes, identical
        // method, same physical line.
        $blocks = $this->extract('Asym.php', "<?php\nclass Alpha { public function run(): int { return 42; } } class Beta { public function run(): int { return 42; } }\n");

        $methods = array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === 'method'));
        $this->assertCount(2, $methods, 'corpus must yield exactly the two one-line methods');
        [$alpha, $beta] = $methods;

        // The r1 six-key tuple genuinely ties (this is the defect shape)…
        $r1Key = static fn (Block $b): string => json_encode(
            [$b->file, $b->range->start, $b->range->end, $b->kind, $b->name, $b->structuralHash],
        );
        $this->assertSame($r1Key($alpha), $r1Key($beta), 'craft premise: r1 key must tie or the pin is vacuous');
        $this->assertSame('Alpha', $alpha->class);
        $this->assertSame('Beta', $beta->class);

        // …while the r2 eight-key tuple discriminates (class differs).
        $this->assertNotSame($alpha->class, $beta->class);

        $canonical = $this->assign($methods);
        $idByObject = [];
        foreach ($canonical as $b) {
            $idByObject[spl_object_id($b)] = $b->id;
        }
        $baselineProjection = $this->emitProjection($canonical);
        $leadId = $canonical[0]->id;

        // Adversarial seam: 6 seeded shuffles, each a verified input-order
        // flip; the physical-to-id binding and the sorted emission
        // projection must survive every one.
        foreach (self::FLIP_SEEDS as $seed) {
            $shuffled = $methods;
            mt_srand($seed);
            shuffle($shuffled);
            $this->assertSame('Beta', $shuffled[0]->class, "seed $seed must scramble (non-vacuous seam)");

            $after = $this->assign($shuffled);
            $this->assertSame('Alpha', $after[0]->class, "seed $seed: canonical order must put Alpha first");
            $this->assertSame($idByObject[spl_object_id($alpha)], $after[0]->id, "seed $seed must not re-bind Alpha's id");
            $this->assertSame($baselineProjection, $this->emitProjection($after), "seed $seed must not move any emitted byte");
            $this->assertSame($leadId, $after[0]->id);
        }

        // And the family ranks are exactly prefix_0/prefix_1.
        $this->assertNotSame($canonical[0]->id, $canonical[1]->id);
        $this->assertSame(substr($alpha->structuralHash, 0, 8) . '_0', $idByObject[spl_object_id($alpha)] ?? '');
    }

    public function testEmitSymmetricOrbitKeepsMintLayerLabelsStable(): void
    {
        // Reviewer tie1 craft: byte-identical closures, same physical line,
        // same class. Honest admission: the eight-key tuple STILL ties.
        $blocks = $this->extract('Tied.php', "<?php\nclass Tied { public function m(\$a, \$b) { usort(\$a, function (\$v) { return \$v + 1; }); usort(\$b, function (\$v) { return \$v + 1; }); } }\n");
        $closures = array_values(array_filter($blocks, static fn (Block $b): bool => $b->kind === 'closure'));
        $this->assertCount(2, $closures);
        [$c1, $c2] = $closures;

        $key = static fn (Block $b): string => json_encode([
            $b->file, $b->range->start, $b->range->end, $b->kind,
            $b->namespace, $b->class, $b->name, $b->structuralHash,
        ]);
        $this->assertSame($key($c1), $key($c2), 'residual orbit premise: r2 key genuinely ties here (total order up to emit-symmetry only)');

        $canonical = $this->assign($closures);
        $baselineProjection = $this->emitProjection($canonical);
        $idSet = [$canonical[0]->id, $canonical[1]->id];

        foreach (self::FLIP_SEEDS as $seed) {
            $shuffled = $closures;
            mt_srand($seed);
            shuffle($shuffled);
            $after = $this->assign($shuffled);
            // Scope of this arm: the per-position members[]/label
            // projection is byte-equal because every tied EMITTED field is
            // byte-equal and ids are positional family labels. It does NOT
            // (and cannot) witness raw-AST projections — holes[].observed
            // order escapes here; see class docblock + wave-5 backlog.
            $this->assertSame($baselineProjection, $this->emitProjection($after), "seed $seed orbit scramble must not move family labels at the mint layer");
            $this->assertSame($idSet, [$after[0]->id, $after[1]->id]);
        }
    }

    public function testNullLabelFieldsSurviveCanonicalAssignment(): void
    {
        // Script-fold-shaped blocks (null namespace/class/name) must pass
        // the comparator's ''-arms without fataling and mint ids — direct
        // synthesis because the extractor only folds scripts under a
        // kinds opt-in; the mint step only ever sees the Block fields.
        $mk = static function (string $name, string $hash): Block {
            $b = new Block(
                file: 'fold.php',
                range: new LineRange(1, 1),
                kind: 'script',
                namespace: null,
                class: null,
                name: null,
                ast: new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_($name)),
            );
            $b->structuralHash = $hash;
            return $b;
        };
        $blocks = [$mk('b', str_repeat('b', 64)), $mk('a', str_repeat('a', 64))];
        $after = $this->assign($blocks);
        $this->assertSame(
            [substr(str_repeat('a', 64), 0, 8) . '_0', substr(str_repeat('b', 64), 0, 8) . '_0'],
            [$after[0]->id, $after[1]->id],
            'null-label blocks mint normally, sorted by structuralHash',
        );
    }
}
