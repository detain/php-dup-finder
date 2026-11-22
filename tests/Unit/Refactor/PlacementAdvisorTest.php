<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node\Stmt\Nop;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\PlacementAdvisor;
use Phpdup\Util\LineRange;
use PHPUnit\Framework\TestCase;

/**
 * Item 5.3 — the advisor reads Hole kinds + observed values + member
 * labels only (no AST reload, no call-graph probe). Each shape rule and
 * each conservative-mode honesty branch gets a named test.
 */
final class PlacementAdvisorTest extends TestCase
{
    /** @var list<string> directories to sweep in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tmpDirs = [];
    }

    public function testZeroHoleClusterIsAVerbatimMoveAndStaysTerse(): void
    {
        $a = $this->member('/r1/src/A.php', 'Acme\\Svc', 'Alpha');
        $b = $this->member('/r1/src/B.php', 'Acme\\Svc', 'Alpha');
        $advice = (new PlacementAdvisor([]))->advise($this->cluster($a, $b));

        $this->assertSame(['shape', 'rationale', 'same_host', 'lca_namespace', 'host_packages'], array_keys($advice));
        $this->assertSame('move', $advice['shape']);
        $this->assertStringContainsString('verbatim relocation', $advice['rationale']);
        // move is a relocation, not an extraction: the lift/LCA prose is
        // suppressed even though both members share one class.
        $this->assertTrue($advice['same_host']);
        $this->assertStringNotContainsString('base-class', $advice['rationale']);
        $this->assertStringNotContainsString('Shared namespace root', $advice['rationale']);
        $this->assertSame('Acme\\Svc', $advice['lca_namespace']);
    }

    public function testAllLiteralHolesBecomeADataTableWithCellCount(): void
    {
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member('/r/src/B.php', 'Acme\\Y', 'Beta'),
        );
        $cluster->holes[] = new Hole('__P0', 'literal', ['10', '20']);
        $cluster->holes[] = new Hole('__P1', 'literal', ["'a'", "'b'", "'a'"]);

        $advice = (new PlacementAdvisor([]))->advise($cluster);
        $this->assertSame('data_table', $advice['shape']);
        $this->assertStringContainsString('lookup table', $advice['rationale']);
        $this->assertStringContainsString('4 distinct observed values', $advice['rationale']);
        // Different classes: no base-class sentence; shared 'Acme' root is said.
        $this->assertStringNotContainsString('base-class', $advice['rationale']);
        $this->assertStringContainsString('Shared namespace root: Acme.', $advice['rationale']);
        // Library posture: no configured roots means nothing to say about
        // hosts — never a bogus "does not resolve" complaint.
        $this->assertNull($advice['host_packages']);
        $this->assertStringNotContainsString('Host-package advice', $advice['rationale']);
    }

    public function testSubtreeHoleOutranksLiteralAndOptionalKinds(): void
    {
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Alpha'),
        );
        $cluster->holes[] = new Hole('__P0', 'literal', ['10', '20']);
        $cluster->holes[] = new Hole('__P1', 'subtree', ['if ($s > 1)', 'for (;;)']);
        $cluster->holes[] = new Hole('__P2', 'optional_block', ['<absent>', 'x();']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);
        $this->assertSame('expression_function', $advice['shape']);
        $this->assertStringContainsString('1 of 3 holes replace whole subtrees', $advice['rationale']);
        // Same class AND shape != move: the lift sentence appears.
        $this->assertTrue($advice['same_host']);
        $this->assertStringContainsString('base-class or trait lift', $advice['rationale']);
    }

    public function testOptionalBlockHolesBecomeDefaultedFunction(): void
    {
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta'),
        );
        $cluster->holes[] = new Hole('__P0', 'optional_block', ['<absent>', '$m->flush();']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);
        $this->assertSame('defaulted_function', $advice['shape']);
        $this->assertStringContainsString('defaulted parameters', $advice['rationale']);
    }

    public function testIdentifierHolesBecomePlainParameterizedFunction(): void
    {
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);
        $this->assertSame('parameterized_function', $advice['shape']);
        $this->assertStringContainsString('ordinary parameterized extraction', $advice['rationale']);
    }

    public function testSameHostFalseWhenClassesDifferOrNamespaceIsEmpty(): void
    {
        $diffClasses = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta'),
        );
        $this->assertFalse((new PlacementAdvisor([]))->advise($diffClasses)['same_host']);

        $noNs = $this->cluster(
            $this->member('/r/src/A.php', '', 'Alpha'),
            $this->member('/r/src/B.php', '', 'Alpha'),
        );
        $advice = (new PlacementAdvisor([]))->advise($noNs);
        $this->assertFalse($advice['same_host'], 'global-namespace members are not one shared host');
        $this->assertSame('', $advice['lca_namespace']);
    }

    public function testLcaNamespaceIsLongestCommonPrefixOrEmpty(): void
    {
        $shared = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\A\\B', 'Alpha'),
            $this->member('/r/src/B.php', 'Acme\\A\\C', 'Beta'),
        );
        $this->assertSame('Acme\\A', (new PlacementAdvisor([]))->advise($shared)['lca_namespace']);

        $disjoint = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\A', 'Alpha'),
            $this->member('/r/src/B.php', 'Other\\B', 'Beta'),
        );
        $this->assertSame('', (new PlacementAdvisor([]))->advise($disjoint)['lca_namespace']);
    }

    public function testHostPackagesIntersectRequireAndRequireDevAcrossRoots(): void
    {
        [$root1, $root2] = $this->twoRoots(
            json_encode([
                'require' => ['php' => '>=8.1', 'acme/shared' => '^1', 'acme/only-one' => '^1'],
                'require-dev' => ['phpunit/phpunit' => '^10'],
            ]),
            json_encode([
                'require' => ['php' => '*', 'ext-json' => '*', 'acme/shared' => '^1'],
                'require-dev' => ['phpunit/phpunit' => '^10'],
            ]),
        );

        $cluster = $this->cluster(
            $this->member($root1 . '/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member($root2 . '/src/B.php', 'Acme\\X', 'Beta'),
        );
        $advice = (new PlacementAdvisor([$root1, $root2]))->advise($cluster);

        // Bare `php` and `ext-*` are platform rows, not packages; acme/only-one
        // fails the intersection; the rest is sorted.
        $this->assertSame(['acme/shared', 'phpunit/phpunit'], $advice['host_packages']);
    }

    /**
     * output-046 (php-dup-finder-output-046): require/require-dev KEYS ride
     * from the untrusted tree's composer.json straight into the CLI placement
     * line. The advisor gates them to composer's package-name charset, so a
     * planted newline/ESC/CR/NUL/space/tab key is dropped as unnameable at the
     * source — never laundered into advice, never forging a report line or
     * driving a terminal downstream.
     */
    public function testComposerManifestKeysAreGatedToComposerNameCharset(): void
    {
        $clean = [
            'a.b_c-d~e/ok'  => '^1', // every charset member: dot . underscore _ hyphen - tilde ~ slash /
            'acme/shared'   => '^1',
        ];
        $hostile = [
            "vendor/na\nme"     => '^1', // LF forges a standalone stream line
            "vendor/esc\x1bkey" => '^1', // ESC starts an OSC/CSI frame on any cat of the output
            "vendor/over\rload" => '^1', // CR drives cursor-overwrite
            "vendor/nul\x00byte" => '^1', // NUL truncates system calls on consumers
            'vendor/spaced key' => '^1', // space: never part of a package name
            "vendor/tab\tkey"   => '^1', // tab is a legal FILENAME byte but not a name byte
        ];
        $body = json_encode([
            'require'     => $clean + ['php' => '*', 'ext-json' => '*'] + $hostile,
            'require-dev' => ['dev/tool.one' => '^1'] + $hostile,
        ]);

        [$root1, $root2] = $this->twoRoots($body, $body);
        $cluster = $this->cluster(
            $this->member($root1 . '/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member($root2 . '/src/B.php', 'Acme\\X', 'Beta'),
        );
        $advice = (new PlacementAdvisor([$root1, $root2]))->advise($cluster);

        $this->assertSame(
            ['a.b_c-d~e/ok', 'acme/shared', 'dev/tool.one'],
            $advice['host_packages'],
            'only composer-charset keys survive the gate; hostile ones are dropped, not scrubbed, at the advisor',
        );
        $flat = $advice['shape'] . $advice['rationale'] . implode('|', $advice['host_packages'] ?? []);
        $this->assertStringNotContainsString("\x1b", $flat);
        $this->assertStringNotContainsString("vendor/na\nme", $flat);
    }

    public function testSingleRootClusterSaysNothingAboutPackages(): void
    {
        [$root1, $root2] = $this->twoRoots('{}', '{}');
        $cluster = $this->cluster(
            $this->member($root1 . '/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member($root1 . '/src/B.php', 'Acme\\X', 'Beta'),
        );
        $advice = (new PlacementAdvisor([$root1, $root2]))->advise($cluster);

        $this->assertNull($advice['host_packages']);
        $this->assertStringNotContainsString('Host-package advice', $advice['rationale']);
    }

    public function testMemberOutsideEveryRootDegradesHostPackagesToNull(): void
    {
        [$root1, $root2] = $this->twoRoots('{}', '{}');
        $stray = $this->member($this->tmpDir() . '/elsewhere/W.php', 'Acme\\X', 'Gamma');
        $cluster = $this->cluster(
            $this->member($root1 . '/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member($root2 . '/src/B.php', 'Acme\\X', 'Beta'),
            $stray,
        );
        $advice = (new PlacementAdvisor([$root1, $root2]))->advise($cluster);

        $this->assertNull($advice['host_packages']);
        $this->assertStringContainsString('does not resolve under any configured scan root', $advice['rationale']);
    }

    public function testLoneFileRootResolvesItsOwnMembersInSilence(): void
    {
        // item 051 regression: `analyze foo.php` is legal input (FileScanner
        // yields the root verbatim for a file root). The member IS its root,
        // so the advisor must say nothing — not blame it for "not resolving".
        $dir = $this->tmpDir();
        $fileRoot = $dir . '/loose.php';
        file_put_contents($fileRoot, "<?php\n");

        $cluster = $this->cluster(
            $this->member($fileRoot, 'Acme\\X', 'Alpha'),
            $this->member($fileRoot, 'Acme\\X', 'Beta'),
        );
        $advice = (new PlacementAdvisor([$fileRoot]))->advise($cluster);

        $this->assertNull($advice['host_packages']);
        $this->assertStringNotContainsString('does not resolve under any configured scan root', $advice['rationale']);
        $this->assertStringNotContainsString('Host-package advice', $advice['rationale']);
    }

    public function testFileRootAlongsideDirRootNeverClaimsUnresolvableMembers(): void
    {
        // Multi-root run `analyze pkg/ loose.php`: the file-root member must
        // resolve to its own root. With two roots there is an intersection to
        // attempt, and the honest outcome is the manifest-degradation note —
        // never the "does not resolve" complaint (the file has no composer.json;
        // being scanned as itself is not a resolution failure).
        $dir = $this->tmpDir();
        $dirRoot = $dir . '/pkg';
        mkdir($dirRoot . '/src', 0777, true);
        file_put_contents($dirRoot . '/composer.json', '{"require":{"acme/shared":"^1"}}');
        $fileRoot = $dir . '/loose.php';
        file_put_contents($fileRoot, "<?php\n");

        $cluster = $this->cluster(
            $this->member($dirRoot . '/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member($fileRoot, 'Acme\\X', 'Beta'),
        );
        $advice = (new PlacementAdvisor([$dirRoot, $fileRoot]))->advise($cluster);

        $this->assertNull($advice['host_packages']);
        $this->assertStringNotContainsString('does not resolve under any configured scan root', $advice['rationale']);
        $this->assertStringContainsString('no parseable composer.json (conservative mode, item 5.3)', $advice['rationale']);
    }

    public function testUnparseableManifestDegradesHonestlyWithConservativeModeNote(): void
    {
        [$root1, $root2] = $this->twoRoots('{"require":{}}', '{not json');
        $cluster = $this->cluster(
            $this->member($root1 . '/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member($root2 . '/src/B.php', 'Acme\\X', 'Beta'),
        );
        $advice = (new PlacementAdvisor([$root1, $root2]))->advise($cluster);

        $this->assertNull($advice['host_packages']);
        $this->assertStringContainsString('no parseable composer.json (conservative mode, item 5.3)', $advice['rationale']);
    }

    public function testDegradedHoleEvidenceAddsVerificationCaution(): void
    {
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta'),
        );
        $cluster->holes[] = new Hole('__P0', 'literal', ['10', ''], true);

        $advice = (new PlacementAdvisor([]))->advise($cluster);
        $this->assertStringContainsString('structural gap (see holes[].degraded)', $advice['rationale']);
    }

    // ---- helpers -------------------------------------------------------

    /** @param list<Block> $members */
    private function cluster(Block ...$members): Cluster
    {
        return new Cluster('T', $members, 0.9, false);
    }

    private function member(string $file, string $namespace, string $class): Block
    {
        return new Block($file, new LineRange(1, 3), 'method', $namespace, $class, 'run', new Nop());
    }

    /** @return array{0:string,1:string} two fresh scan roots with composer.json bodies */
    private function twoRoots(string $manifest1, string $manifest2): array
    {
        $parent = $this->tmpDir();
        $roots = [];
        foreach ([$manifest1, $manifest2] as $i => $body) {
            $root = $parent . '/r' . $i;
            mkdir($root . '/src', 0777, true);
            file_put_contents($root . '/composer.json', $body);
            $roots[] = $root;
        }
        return $roots;
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-w4bpa-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
