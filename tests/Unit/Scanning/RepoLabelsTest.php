<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Scanning\RepoLabels;
use Phpdup\Util\LineRange;

/**
 * Item 5.1 — repo/scope stamping from scan roots.
 */
final class RepoLabelsTest extends TestCase
{
    public function testSingleRootYieldsSingleScope(): void
    {
        $block = self::block('/home/dev/proj/src/A.php');
        RepoLabels::stamp($block, ['/home/dev/proj/src']);

        $this->assertSame('src', $block->repo);
        $this->assertSame(RepoLabels::SCOPE_SINGLE, $block->scope,
            'a one-root run labels scope single, not the repo basename');
    }

    public function testMultiRootScopeEqualsRepoLabel(): void
    {
        $block = self::block('/srv/repoA/src/A.php');
        RepoLabels::stamp($block, ['/srv/repoA', '/srv/repoB']);

        $this->assertSame('repoA', $block->repo);
        $this->assertSame('repoA', $block->scope);
    }

    public function testRootNamingASubtreeLabelsByThatSubtree(): void
    {
        // The label is the basename of the root AS GIVEN — scanning
        // repoA/src (not repoA) is an honest choice and labels 'src';
        // docs tell users to pass one path per repository for stable
        // repo identity.
        $block = self::block('/srv/repoA/src/A.php');
        RepoLabels::stamp($block, ['/srv/repoA/src', '/srv/repoB']);

        $this->assertSame('src', $block->repo);
    }

    public function testLongestMatchingRootWins(): void
    {
        $block = self::block('/srv/packages/app/src/Deep/File.php');
        RepoLabels::stamp($block, ['/srv/packages', '/srv/packages/app']);

        $this->assertSame('app', $block->repo,
            'nested roots must attribute to the deepest one containing the file');
    }

    public function testTrailingSlashRootsNormalize(): void
    {
        $block = self::block('/srv/checkouts/shop/lib/X.php');
        RepoLabels::stamp($block, ['/srv/checkouts/shop/']);

        $this->assertSame('shop', $block->repo);
        $this->assertSame(RepoLabels::SCOPE_SINGLE, $block->scope);
    }

    public function testHostIndependenceSameCheckoutAtDifferentPrefixes(): void
    {
        $a = self::block('/home/alice/work/acme-api/src/T.php');
        $b = self::block('/var/ci/builds/42/acme-api/src/T.php');
        RepoLabels::stamp($a, ['/home/alice/work/acme-api']);
        RepoLabels::stamp($b, ['/var/ci/builds/42/acme-api']);

        $this->assertSame($a->repo, $b->repo,
            'the same checkout moved anywhere emits the same label (item 5.1 host-independence law)');
        $this->assertSame('acme-api', $a->repo);
    }

    public function testNoMatchKeepsHonestEmptyFallback(): void
    {
        $block = self::block('/elsewhere/X.php');
        RepoLabels::stamp($block, ['/srv/repoA']);

        $this->assertSame('', $block->repo, 'unattributed file keeps the honest empty fallback');
        $this->assertSame(RepoLabels::SCOPE_SINGLE, $block->scope,
            'one scan root means one repo, attributed or not — the claim is about root count');

        $fresh = self::block('/srv/repoA/Y.php');
        $fresh->repo  = 'stale-from-cache';
        $fresh->scope = 'stale-from-cache';
        RepoLabels::stamp($fresh, ['/srv/repoA']);
        $this->assertSame('repoA', $fresh->repo,
            'stamping always overwrites whatever a deserialized blob carried');
    }

    public function testFilePassedAsItsOwnRootLabelsByBasename(): void
    {
        $block = self::block('/tmp/deep/one-off/Script.php');
        RepoLabels::stamp($block, ['/tmp/deep/one-off/Script.php']);

        $this->assertSame('Script.php', $block->repo);
        $this->assertSame(RepoLabels::SCOPE_SINGLE, $block->scope);
    }

    public function testEmptyRootsIgnored(): void
    {
        $block = self::block('/srv/repo/Z.php');
        RepoLabels::stamp($block, ['', '/srv/repo']);

        $this->assertSame('repo', $block->repo);
    }

    public function testStampAllIterates(): void
    {
        $blocks = [
            self::block('/srv/x1/a/F.php'),
            self::block('/srv/x2/b/G.php'),
        ];
        RepoLabels::stampAll($blocks, ['/srv/x1/a', '/srv/x2/b']);

        $this->assertSame(['a', 'b'], [$blocks[0]->repo, $blocks[1]->repo]);
        $this->assertSame(['a', 'b'], [$blocks[0]->scope, $blocks[1]->scope]);
    }

    public function testStampedLabelsRoundTripThroughSerialization(): void
    {
        // In-process serialize keeps the labels (the property pair is a
        // normal Block fact); the NEVER-persisted guarantee is enforced
        // by stamp-order, pinned in IndexStoreStaleLabelsTest.
        $block = self::block('/srv/repoA/A.php');
        RepoLabels::stamp($block, ['/srv/repoA', '/srv/repoB']);

        $this->assertSame('repoA', $block->repo);

        $serialized = serialize($block);
        $this->assertStringContainsString('s:4:"repo";s:5:"repoA";', $serialized);
        $this->assertStringContainsString('s:5:"scope";s:5:"repoA";', $serialized);

        $copy = unserialize($serialized);
        $this->assertInstanceOf(Block::class, $copy);
        $this->assertSame('repoA', $copy->repo);
        $this->assertSame('repoA', $copy->scope);
    }

    private static function block(string $file): Block
    {
        return new Block(
            file:      $file,
            range:     new LineRange(1, 10),
            kind:      'method',
            namespace: 'Acme',
            class:     'C',
            name:      'm',
            ast:       new Node\Stmt\Nop(),
        );
    }
}
