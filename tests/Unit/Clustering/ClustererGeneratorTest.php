<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests for {@see Clusterer::generateCandidatePairs()}.
 *
 * Verifies:
 * - The method returns a Generator instance (lazy iteration)
 * - Bidirectional candidate pairs are deduplicated (A→B and B→A → one yield)
 * - Yield count is correct for a known small corpus
 */
final class ClustererGeneratorTest extends TestCase
{
    /**
     * Build a synthetic block with a given id, unique structural hash,
     * and an ngram bag that will produce the given candidate relationships.
     *
     * The ngram bag format is array<string, int> (token -> count).
     * Sharing a rare gram between two blocks makes each appear in the other's
     * candidate list from the inverted index.
     */
    private function makeBlockWithNgrams(
        string $id,
        string $uniqueHash,
        array $ngrams,
    ): Block {
        $node = new \PhpParser\Node\Stmt\Return_(
            new \PhpParser\Node\Scalar\String_($id)
        );

        $block = new Block(
            file: 'synthetic.php',
            range: new \Phpdup\Util\LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: $node,
        );
        $block->id = $id;
        $block->structuralHash = $uniqueHash;
        $block->ngramBag = $ngrams;

        return $block;
    }

    /**
     * Assert that generateCandidatePairs returns a Generator instance.
     */
    public function testGenerateCandidatePairsReturnsGenerator(): void
    {
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['gram_a' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['gram_a' => 1]);

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );

        $result = $clusterer->generateCandidatePairs($index);

        $this->assertInstanceOf(\Generator::class, $result);
    }

    /**
     * Test deduplication of bidirectional candidates.
     *
     * Scenario: blocks A and B share a rare gram "shared_gram".
     * - candidatesFor(A) returns [B]
     * - candidatesFor(B) returns [A]
     * Both directions produce the same canonical pair [A, B].
     * Only ONE yield should appear.
     */
    public function testGenerateCandidatePairsDeduplicatesBidirectionalCandidates(): void
    {
        // Both blocks share the same rare gram → each appears in the other's candidate list
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['shared_gram' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['shared_gram' => 1]);

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );

        $pairs = iterator_to_array($clusterer->generateCandidatePairs($index));

        // Should have exactly ONE pair [A, B], not two.
        $this->assertCount(1, $pairs, 'Bidirectional candidates must be deduplicated to a single pair');

        // Verify the canonical ordering: smaller id first.
        $this->assertEquals(['A', 'B'], $pairs[0]);
    }

    /**
     * Verify yield count for a small corpus with known ngram relationships.
     *
     * Setup: three blocks A, B, C where:
     *   - A and B share "ab_gram" (rare for both)
     *   - B and C share "bc_gram" (rare for both)
     *   - A and C share no grams
     *
     * Expected pairs:
     *   - A <-> B (shares ab_gram) → [A, B]
     *   - B <-> C (shares bc_gram) → [B, C]
     *   Total: 2 pairs
     */
    public function testGenerateCandidatePairsYieldCount(): void
    {
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['ab_gram' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['ab_gram' => 1, 'bc_gram' => 1]);
        $c = $this->makeBlockWithNgrams('C', 'hash_c', ['bc_gram' => 1]);

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);
        $index->add($c);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );

        $pairs = iterator_to_array($clusterer->generateCandidatePairs($index));

        $this->assertCount(2, $pairs, 'Expected exactly 2 candidate pairs: A-B and B-C');

        // Verify canonical ordering in each pair.
        foreach ($pairs as $pair) {
            $this->assertCount(2, $pair);
            [$x, $y] = $pair;
            $this->assertTrue($x < $y, "First id must be alphabetically before second: [$x, $y]");
        }

        // Collect pair keys for easier assertion.
        $pairKeys = array_map(fn($p) => "{$p[0]}|{$p[1]}", $pairs);
        sort($pairKeys);

        $this->assertEquals(['A|B', 'B|C'], $pairKeys, 'Expected pairs A|B and B|C');
    }

    /**
     * Verify that a block with no ngram bag yields no candidates.
     */
    public function testGenerateCandidatePairsSkipsBlocksWithNullNgramBag(): void
    {
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['gram_a' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', []); // empty ngram bag

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );

        $pairs = iterator_to_array($clusterer->generateCandidatePairs($index));

        $this->assertCount(0, $pairs, 'Block with empty ngram bag should produce no candidates');
    }

    /**
     * Verify that blocks sharing a gram that is too common (above maxDocumentFrequency)
     * are NOT returned as candidates.
     */
    public function testGenerateCandidatePairsRespectsMaxDocumentFrequency(): void
    {
        // Create 3 blocks all sharing the same gram "common_gram"
        // If maxDocumentFrequency is 0.33 (1/3), the gram is at the threshold.
        // Set it to 0.3 to exclude blocks where the gram appears in >=30% of blocks.
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['common_gram' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['common_gram' => 1]);
        $c = $this->makeBlockWithNgrams('C', 'hash_c', ['common_gram' => 1]);

        $index = new BlockIndex();
        $index->add($a);
        $index->add($b);
        $index->add($c);

        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 0.3, // 30% — gram appears in all 3 blocks = 100% > 30%
        );

        $pairs = iterator_to_array($clusterer->generateCandidatePairs($index));

        $this->assertCount(0, $pairs, 'Gram appearing in 100% of blocks (3/3) exceeds 30% threshold and must be excluded');
    }
}
