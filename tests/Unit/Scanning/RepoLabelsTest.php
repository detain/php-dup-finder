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

    // ── Item 5.1 (this lane): marker resolution inside the boundary ──────
    // The legacy pins above keep the frozen root-only contract honest on
    // virtual paths; the tests below exercise the composer.json/.git
    // resolution on REAL temp trees (the doc letter's two markers).

    private string $tmpRoot = '';

    /** @var list<string> */
    private array $tmpTrees = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpTrees as $dir) {
            self::removeTree($dir);
        }
        $this->tmpTrees = [];
    }

    public function testNearestGitDirSplitsLabelsWithinOneRoot(): void
    {
        $R = $this->tree();
        @mkdir("$R/.git", 0777, true);
        @mkdir("$R/pkg-a/.git", 0777, true);
        @mkdir("$R/other", 0777, true);

        $labels = RepoLabels::forRoots([$R]);
        $this->assertSame('pkg-a', $labels->repoOf("$R/pkg-a/A.php"),
            'the nearest in-boundary .git carrier wins over the root word');
        $this->assertSame(basename($R), $labels->repoOf("$R/other/B.php"),
            'files without an in-boundary .git keep the root basename (legacy word)');
    }

    public function testGitFileFormCountsAsCarrier(): void
    {
        // Submodule / linked-worktree checkouts carry a .git FILE
        // ('gitdir: ...'), not a directory — both forms label.
        $R = $this->tree();
        @mkdir("$R/work", 0777, true);
        file_put_contents("$R/work/.git", "gitdir: /nowhere/modules/work\n");

        $labels = RepoLabels::forRoots([$R]);
        $this->assertSame('work', $labels->repoOf("$R/work/C.php"));
    }

    public function testComposerNameNearestWins(): void
    {
        $R = $this->tree();
        @mkdir("$R/p", 0777, true);
        file_put_contents("$R/composer.json", json_encode(['name' => 'belt/outer']));
        file_put_contents("$R/p/composer.json", json_encode(['name' => 'belt/inner']));

        $labels = RepoLabels::forRoots([$R]);
        $this->assertSame('belt/inner', $labels->packageOf("$R/p/F.php"));
        $this->assertSame('belt/outer', $labels->packageOf("$R/Q.php"));
    }

    public function testNamelessManifestDegradesToRepoNotOuterManifest(): void
    {
        // Nearest-composer law: the FIRST manifest the walk meets that is
        // unusable ENDS the seek — an outer name must not launder the
        // inner tree's missing identity (degrade to the repo word).
        $R = $this->tree();
        @mkdir("$R/p", 0777, true);
        file_put_contents("$R/composer.json", json_encode(['name' => 'belt/outer']));
        file_put_contents("$R/p/composer.json", '{"description":"no name here"}');

        $labels = RepoLabels::forRoots([$R]);
        $repo   = basename($R);
        $this->assertSame($repo, $labels->packageOf("$R/p/F.php"),
            'a found-but-nameless manifest degrades to the repo label, never the outer name');
        $this->assertCount(1, $labels->notes(), 'exactly one fail-open note for the unusable manifest');
        $this->assertStringContainsString('no usable package name', $labels->notes()[0]);
    }

    public function testCorruptComposerFailOpenSingleNote(): void
    {
        $R = $this->tree();
        file_put_contents("$R/composer.json", "{ not json \n");

        $labels = RepoLabels::forRoots([$R]);
        $this->assertSame(basename($R), $labels->packageOf("$R/A.php"));
        $this->assertSame(basename($R), $labels->packageOf("$R/B.php"));
        $this->assertCount(1, $labels->notes(), 'two files, one directory, one note');
        $this->assertStringContainsString('corrupt composer.json', $labels->notes()[0]);
    }

    public function testHostileCharsetPackageNameDegrades(): void
    {
        $R = $this->tree();
        file_put_contents("$R/composer.json", json_encode(['name' => "belt/na\rme"]));

        $labels = RepoLabels::forRoots([$R]);
        $this->assertSame(basename($R), $labels->packageOf("$R/A.php"),
            'control bytes are unnameable, never rewritten into the label');
        $this->assertCount(1, $labels->notes());
    }

    public function testMarkersAboveTheRootBoundaryIgnored(): void
    {
        // The decoy shape from the reproduction corpus: operator layout
        // above every scan root must not collapse distinct labels.
        $P = $this->tree();
        @mkdir("$P/.git", 0777, true);
        file_put_contents("$P/composer.json", json_encode(['name' => 'belt/ABOVE']));
        @mkdir("$P/R/src", 0777, true);

        $labels = RepoLabels::forRoots(["$P/R"]);
        $this->assertSame('R', $labels->repoOf("$P/R/src/F.php"));
        $this->assertSame('R', $labels->packageOf("$P/R/src/F.php"),
            'no manifest inside the boundary: package degrades to the repo word');
        $this->assertSame([], $labels->notes(), 'the above-boundary decoy is never even read');
    }

    public function testFileAsOwnRootLabelsBothLegs(): void
    {
        $R    = $this->tree();
        $solo = "$R/Solo.php";
        file_put_contents($solo, "<?php\n");

        $labels = RepoLabels::forRoots([$solo]);
        $this->assertSame('Solo.php', $labels->repoOf($solo));
        $this->assertSame('Solo.php', $labels->packageOf($solo));
    }

    public function testStampBlockOverwritesStalePackageAndMemosDeterministically(): void
    {
        $R = $this->tree();
        @mkdir("$R/p", 0777, true);
        file_put_contents("$R/p/composer.json", json_encode(['name' => 'belt/p']));

        $labels = RepoLabels::forRoots([$R]);
        $a      = self::block("$R/p/A.php");
        $a->package = 'stale-from-cache';
        $labels->stampBlock($a);
        $this->assertSame('belt/p', $a->package, 'restamp law: stale package words never survive');
        $this->assertSame(basename($R), $a->repo);

        $b = self::block("$R/p/B.php");
        $labels->stampBlock($b);
        $this->assertSame([$a->repo, $a->package], [$b->repo, $b->package],
            'same directory memo answers both files identically');

        $again = self::block("$R/p/C.php");
        $labels->stampBlock($again);
        $this->assertSame([$b->repo, $b->package], [$again->repo, $again->package]);
        $this->assertCount(0, $labels->notes(), 'memo hits re-note nothing');
    }

    public function testScopeWordStillRidesRootCountWithMarkersPresent(): void
    {
        $R = $this->tree();
        @mkdir("$R/.git", 0777, true);

        $single = self::block("$R/A.php");
        RepoLabels::stamp($single, [$R]);
        $this->assertSame(RepoLabels::SCOPE_SINGLE, $single->scope,
            'one root stays single even when the repo word differs from it');
        $this->assertSame(basename($R), $single->repo);

        $multi = self::block("$R/A.php");
        RepoLabels::stamp($multi, [$R, '/srv/elsewhere']);
        $this->assertSame(basename($R), $multi->scope, 'multi-root scope equals the repo label (legacy law)');
    }

    /** Create a unique temp tree root, registered for teardown removal. */
    private function tree(): string
    {
        if ($this->tmpRoot === '') {
            $this->tmpRoot = sys_get_temp_dir() . '/phpdup-repolabels-' . getmypid() . '-' . bin2hex(random_bytes(4));
        }
        $dir = $this->tmpRoot . '/t' . count($this->tmpTrees);
        @mkdir($dir, 0777, true);
        $this->tmpTrees[] = $dir;
        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
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
