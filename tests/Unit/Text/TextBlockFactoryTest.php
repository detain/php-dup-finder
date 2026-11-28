<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Text;

use PhpParser\Node\Stmt\InlineHTML;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Text\LangProfiles;
use Phpdup\Text\TextBlockFactory;
use Phpdup\Text\TextFileTokens;
use Phpdup\Text\TextRunHarvester;
use Phpdup\Text\TextTokenizer;

/**
 * Item 7.1: the factory turns a harvested run into a plain Block(kind:
 * 'text') whose identity follows the RAW-TEXT FOLD contract T1a proved:
 * identical blanked streams fuse to one structuralHash (exact tier), the
 * bag rides fingerprintTokens, and raw names/literals never reach the
 * fold payload.
 */
final class TextBlockFactoryTest extends TestCase
{
    private function twinRuns(): array
    {
        $profile = LangProfiles::profileFor('a.js');
        $this->assertNotNull($profile);
        $a = new TextFileTokens('/tmp/alpha_one.js', $profile, TextTokenizer::tokenize(
            "checkout run( item1, 'sku-1' );\nsummary post( item2, 'sku-2' );\ninvoice clear( item3, 'sku-3' );\nledger check( item4, 'sku-4' );\n",
            $profile,
        ));
        $b = new TextFileTokens('/tmp/beta_two.js', $profile, TextTokenizer::tokenize(
            "orderBelt run( thing1, 'sku-9' );\nvaultCard post( thing2, 'sku-8' );\nclaimTag clear( thing3, 'sku-7' );\nproofLock check( thing4, 'sku-6' );\n",
            $profile,
        ));
        $runs = (new TextRunHarvester())->harvest([$a, $b]);
        $this->assertCount(2, $runs);
        return $runs;
    }

    public function testBlocksCarryTheTextKindContract(): void
    {
        $factory = new TextBlockFactory(new NgramFingerprint(), new SubtreeHasher());
        [$ra, $rb] = $this->twinRuns();

        $ba = $factory->fromRun($ra);
        $this->assertSame(BlockExtractor::TEXT_KIND, $ba->kind);
        $this->assertSame('alpha_one.js', $ba->name);
        $this->assertNull($ba->namespace);
        $this->assertNull($ba->class);
        $this->assertGreaterThan(24, $ba->size, 'size is the token count');
        $this->assertInstanceOf(InlineHTML::class, $ba->canonical);
        $this->assertSame((string) $ra->startLine, (string) $ba->range->start);
        $this->assertSame((string) $ra->endLine, (string) $ba->range->end);
    }

    public function testIdenticalBlankedFoldsShareOneStructuralHash(): void
    {
        $factory = new TextBlockFactory(new NgramFingerprint(), new SubtreeHasher());
        [$ra, $rb] = $this->twinRuns();
        $ba = $factory->fromRun($ra);
        $bb = $factory->fromRun($rb);

        $this->assertSame($ba->structuralHash, $bb->structuralHash, 'twins fuse in the exact tier');
        $this->assertSame($ba->ngramBag, $bb->ngramBag, 'same window algebra, same bag');

        // Raw bytes never reach the fold: the two real names must not appear.
        $fold = ((array) $ba->canonical)['value'] ?? '';
        $this->assertIsString($fold);
        $this->assertStringNotContainsString('alpha_one', (string) $fold);
        $this->assertStringNotContainsString('sku-1', (string) $fold);
    }

    public function testFoldIsSubtreeHasherRawTextIdentity(): void
    {
        // Re-building an InlineHTML from the same fold string must hash the
        // same (the T1a raw-text identity, riding the text tier unchanged).
        $factory = new TextBlockFactory(new NgramFingerprint(), new SubtreeHasher());
        [$ra] = $this->twinRuns();
        $ba = $factory->fromRun($ra);

        $rebuilt = new InlineHTML(implode(' ', $ra->blankedTokens()));
        $this->assertSame($ba->structuralHash, (new SubtreeHasher())->hash($rebuilt));
    }

    public function testDifferentFoldsNeverCollide(): void
    {
        $factory = new TextBlockFactory(new NgramFingerprint(), new SubtreeHasher());
        [$ra] = $this->twinRuns();
        $ba = $factory->fromRun($ra);

        $profile = LangProfiles::profileFor('x.js');
        $this->assertNotNull($profile);
        $other = new TextFileTokens('/tmp/other.js', $profile, TextTokenizer::tokenize(
            "one( 1 ); two( 2 );\nthree( 3 ); four( 4 );\nfive( 5 ); six( 6 );\nseven( 7 ); eight( 8 );\n",
            $profile,
        ));
        $run = new \Phpdup\Text\TextRun($other, 0, count($other->blanked()), 1, 4);
        $bb = $factory->fromRun($run);

        $this->assertNotSame($ba->structuralHash, $bb->structuralHash, 'distinct blanked streams keep distinct hashes');
    }
}
