<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\BaselineStore;
use Phpdup\Util\LineRange;
use Phpdup\Util\PathCanonical;

final class BaselineStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdup-baseline-test-' . uniqid();
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    public function testWriteAndReadBaselineRoundtrip(): void
    {
        $clusters = [
            $this->cluster('c1', [
                ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
                ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
            ], 100),
            $this->cluster('c2', [
                ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15],
            ], 50),
        ];

        $path = $this->tmpDir . '/baseline.json';
        $store = new BaselineStore();
        $store->writeBaseline($clusters, $path);

        $this->assertFileExists($path);

        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertSame(3, $raw['version'], 'baseline v3 rides the scan-root-relative member paths (phpver-fix); member_hashes stay content-hashed');

        $entries = $store->readBaseline($path);

        $this->assertCount(2, $entries);

        // Sort by id for consistent comparison
        usort($entries, fn($a, $b) => $a['id'] <=> $b['id']);

        $this->assertSame('c1', $entries[0]['id']);
        $this->assertSame(100, $entries[0]['impact']);
        $this->assertCount(2, $entries[0]['member_hashes']);

        $this->assertSame('c2', $entries[1]['id']);
        $this->assertSame(50, $entries[1]['impact']);
        $this->assertCount(1, $entries[1]['member_hashes']);
    }

    public function testCompareBaselinesFindsNoNewClustersWhenIdentical(): void
    {
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $clusterB = $this->cluster('c2', [
            ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15],
        ], 50);

        $currentEntries = $this->clustersToEntries([$clusterA, $clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA, $clusterB]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertSame([], $newClusters);
    }

    public function testCompareBaselinesFindsNewClustersWhenNotInBaseline(): void
    {
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 100);

        $clusterB = $this->cluster('c2', [
            ['file' => 'src/Bar.php', 'start' => 5, 'end' => 15],
        ], 50);

        $currentEntries = $this->clustersToEntries([$clusterA, $clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]); // c2 is missing

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertCount(1, $newClusters);
        $this->assertSame('c2', $newClusters[0]['id']);
    }

    public function testCompareBaselinesDetectsPartialOverlap(): void
    {
        // Cluster with same ID but different member hashes should be detected as new
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $clusterB = $this->cluster('c1', [ // same id but NEW member
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15], // different member
        ], 100);

        $currentEntries = $this->clustersToEntries([$clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertCount(1, $newClusters);
        $this->assertSame('c1', $newClusters[0]['id']);
    }

    public function testCompareBaselinesDetectsClusterWithAdditionalMembersAsNew(): void
    {
        // Cluster with more members than baseline IS new (has member Bar not in baseline)
        // The brief defines "new" as: a cluster whose member hashes aren't all
        // present in some baseline cluster. Current = [Foo, Bar], Baseline = [Foo].
        // Bar is NOT in baseline, so current IS new.
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 100);

        $clusterB = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 150);

        $currentEntries = $this->clustersToEntries([$clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        // Bar is not in baseline, so current cluster is NEW
        $this->assertCount(1, $newClusters);
        $this->assertSame('c1', $newClusters[0]['id']);
    }

    public function testCompareBaselinesSameMembersIsNotNew(): void
    {
        // Cluster with EXACTLY the same members as baseline is NOT new
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $clusterB = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $currentEntries = $this->clustersToEntries([$clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        // Same members, so NOT new
        $this->assertSame([], $newClusters);
    }

    public function testCompareBaselinesSubsetMembersIsNotNew(): void
    {
        // Cluster with fewer/same members than baseline (subset) is NOT new
        // This is the "allow pruning" case: if current has Foo, baseline has [Foo, Bar],
        // then current's members ARE all present in baseline, so NOT new.
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $clusterB = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 50);

        $currentEntries = $this->clustersToEntries([$clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        // All current members (Foo) ARE present in baseline (Foo, Bar), so NOT new
        $this->assertSame([], $newClusters);
    }

    /**
     * output-029 regression: with the array_flip hoisted out of the
     * comparison loop, coverage must still come from a SINGLE baseline
     * cluster — members spread across two baseline clusters do NOT
     * cover a current one. 2 baseline entries x 4 current entries.
     */
    public function testCompareBaselinesManyByManyMatchesPerClusterOnly(): void
    {
        $baselineOne = $this->cluster('b1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);
        $baselineTwo = $this->cluster('b2', [
            ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15],
            ['file' => 'src/Qux.php', 'start' => 1, 'end' => 9],
        ], 80);

        $coveredByFirst = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 50); // subset of b1 -> not new
        $coveredBySecond = $this->cluster('c2', [
            ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15],
            ['file' => 'src/Qux.php', 'start' => 1, 'end' => 9],
        ], 80); // exactly b2 -> not new
        $spansBoth = $this->cluster('c3', [
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
            ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15],
        ], 60); // split across b1+b2 -> NEW (no single cluster covers it)
        $unseen = $this->cluster('c4', [
            ['file' => 'src/Zzz.php', 'start' => 2, 'end' => 8],
        ], 40); // never baselined -> NEW

        $baselineEntries = $this->clustersToEntries([$baselineOne, $baselineTwo]);
        $currentEntries = $this->clustersToEntries([$coveredByFirst, $coveredBySecond, $spansBoth, $unseen]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertCount(2, $newClusters);
        $this->assertSame('c3', $newClusters[0]['id']);
        $this->assertSame('c4', $newClusters[1]['id']);
    }

    /**
     * output-029 pin: the size guard compares against the RAW baseline
     * member count, not the flipped lookup's size — duplicated member
     * hashes keep their covered verdict (flip semantics preserved).
     */
    public function testCompareBaselinesDuplicateMemberHashesKeepVerdicts(): void
    {
        $store = new BaselineStore();

        $baselineEntries = [[
            'id' => 'b1',
            'impact' => 10,
            'member_hashes' => ['sha256:a', 'sha256:b', 'sha256:a'],
        ]];
        $duplicatedCovered = [[
            'id' => 'c1',
            'impact' => 6,
            'member_hashes' => ['sha256:a', 'sha256:a', 'sha256:b'],
        ]];
        $duplicatedBeyond = [[
            'id' => 'c2',
            'impact' => 6,
            'member_hashes' => ['sha256:a', 'sha256:a', 'sha256:b', 'sha256:c'],
        ]];

        $this->assertSame([], $store->compareBaselines($duplicatedCovered, $baselineEntries));
        $this->assertCount(1, $store->compareBaselines($duplicatedBeyond, $baselineEntries));
    }

    public function testCompareBaselinesWithEmptyBaselineReturnsAllAsNew(): void
    {
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 100);

        $currentEntries = $this->clustersToEntries([$clusterA]);
        $baselineEntries = [];

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertCount(1, $newClusters);
    }

    public function testReadBaselineThrowsOnMissingFile(): void
    {
        $store = new BaselineStore();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Baseline file not found');
        $store->readBaseline($this->tmpDir . '/nonexistent.json');
    }

    public function testReadBaselineThrowsOnInvalidJson(): void
    {
        $path = $this->tmpDir . '/invalid.json';
        file_put_contents($path, 'not valid json');

        $store = new BaselineStore();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $store->readBaseline($path);
    }

    public function testReadBaselineThrowsOnMissingVersion(): void
    {
        $path = $this->tmpDir . '/no-version.json';
        file_put_contents($path, json_encode(['clusters' => []]));

        $store = new BaselineStore();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid structure');
        $store->readBaseline($path);
    }

    public function testReadBaselineThrowsOnVersionMismatch(): void
    {
        $path = $this->tmpDir . '/wrong-version.json';
        file_put_contents($path, json_encode(['version' => 99, 'clusters' => []]));

        $store = new BaselineStore();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('version mismatch');
        $store->readBaseline($path);
    }

    public function testComputeBlockHashIsStable(): void
    {
        $block = new Block(
            file: 'src/Foo.php',
            range: new LineRange(10, 20),
            kind: 'method',
            namespace: null,
            class: null,
            name: 'bar',
            ast: new Node\Stmt\Nop(),
        );

        $store = new BaselineStore();
        $hash1 = $store->computeBlockHash($block);
        $hash2 = $store->computeBlockHash($block);

        $this->assertSame($hash1, $hash2);
        $this->assertStringStartsWith('sha256:', $hash1);
    }

    public function testComputeBlockHashDiffersForDifferentBlocks(): void
    {
        $block1 = new Block(
            file: 'src/Foo.php',
            range: new LineRange(10, 20),
            kind: 'method',
            namespace: null,
            class: null,
            name: 'bar',
            ast: new Node\Stmt\Nop(),
        );
        $block1->structuralHash = 'alpha-content';

        $block2 = new Block(
            file: 'src/Foo.php',
            range: new LineRange(10, 20), // same position, different content
            kind: 'method',
            namespace: null,
            class: null,
            name: 'bar',
            ast: new Node\Stmt\Nop(),
        );
        $block2->structuralHash = 'beta-content';

        $store = new BaselineStore();
        $hash1 = $store->computeBlockHash($block1);
        $hash2 = $store->computeBlockHash($block2);

        $this->assertNotSame($hash1, $hash2);
    }

    /**
     * §5e / item 4.4: the hash is CONTENT, never position — an unrelated
     * edit above a block shifts its lines but must not churn its entry
     * (v1's file.start.end formula failed exactly this).
     */
    public function testComputeBlockHashSurvivesLineShifts(): void
    {
        $mk = function (int $start, int $end): Block {
            $b = new Block(
                file: 'src/Foo.php',
                range: new LineRange($start, $end),
                kind: 'method',
                namespace: null,
                class: null,
                name: 'bar',
                ast: new Node\Stmt\Nop(),
            );
            $b->structuralHash = 'same-content';
            return $b;
        };

        $store = new BaselineStore();

        $this->assertSame(
            $store->computeBlockHash($mk(10, 20)),
            $store->computeBlockHash($mk(105, 115)),
            'line shift must not move a baseline member hash',
        );
    }

    /**
     * Build a Cluster from an array of member specs.
     *
     * @param list<array{file: string, start: int, end: int}> $memberSpecs
     */
    public function testMemberHashesAreMachinePortableWithScanRoots(): void
    {
        // phpver-fix: CI baselines written on a laptop must match on the
        // runner — block hashes canonicalize member paths under the roots.
        $block = new \Phpdup\Extraction\Block(
            file: '/home/runner/work/proj/proj/src/Util/a.php',
            range: new \Phpdup\Util\LineRange(1, 5),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'a',
            ast: new \PhpParser\Node\Stmt\Nop(),
        );
        $block->structuralHash = 'deadbeef';
        $laptopCopy = new \Phpdup\Extraction\Block(
            file: '/home/sites/proj/src/Util/a.php',
            range: new \Phpdup\Util\LineRange(1, 5),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'a',
            ast: new \PhpParser\Node\Stmt\Nop(),
        );
        $laptopCopy->structuralHash = 'deadbeef';

        $runnerStore = new BaselineStore(['/home/runner/work/proj/proj']);
        $laptopStore = new BaselineStore(['/home/sites/proj']);

        $this->assertSame(
            $runnerStore->computeBlockHash($block),
            $laptopStore->computeBlockHash($laptopCopy),
        );
        // The pre-fix absolute-path coupling is exactly what got removed.
        $this->assertNotSame(
            (new BaselineStore())->computeBlockHash($block),
            (new BaselineStore())->computeBlockHash($laptopCopy),
        );
    }

    /**
     * once-055 (once-S50): an empty file is invalid INPUT with a named
     * repair, not the old bare "not valid JSON" shrug. Code 2 is the
     * leg the console maps onto the CLI's exit-2 invalid-invocation door.
     */
    public function testEmptyBaselineFileGetsActionableAdviceWithExitCode2(): void
    {
        foreach (['', '   ', "\n\t "] as $i => $raw) {
            $path = $this->tmpDir . '/empty-' . $i . '.json';
            file_put_contents($path, $raw);

            $store = new BaselineStore();
            try {
                $store->readBaseline($path);
                $this->fail('empty baseline (case ' . $i . ') must be refused');
            } catch (\RuntimeException $e) {
                $this->assertSame(2, $e->getCode(), 'the empty-file refusal rides the exit-2 door');
                $this->assertStringContainsString('Baseline file is empty', $e->getMessage());
                $this->assertStringContainsString('--baseline-out', $e->getMessage());
                $this->assertStringContainsString('first-run', $e->getMessage());
                $this->assertStringContainsString($path, $e->getMessage());
            }
        }
    }

    /**
     * once-055: a well-formed `[]` (or `{}`, which decodes to the same
     * empty array) carries no baseline — same actionable advice, same
     * exit-2 posture, never "invalid structure" guessed at exit 1.
     */
    public function testEmptyJsonDocumentBaselineGetsActionableAdviceWithExitCode2(): void
    {
        foreach (['[]', '{}', " [\t] "] as $i => $raw) {
            $path = $this->tmpDir . '/bare-empty-doc-' . $i . '.json';
            file_put_contents($path, $raw);

            $store = new BaselineStore();
            try {
                $store->readBaseline($path);
                $this->fail('empty JSON baseline document (case ' . $i . ') must be refused');
            } catch (\RuntimeException $e) {
                $this->assertSame(2, $e->getCode(), 'the [] refusal rides the exit-2 door');
                $this->assertStringContainsString('empty JSON document', $e->getMessage());
                $this->assertStringContainsString('--baseline-out', $e->getMessage());
            }
        }
    }

    /**
     * once-055: corrupt/truncated JSON keeps its 'not valid JSON' class
     * word (pinned by the historical test) but gains the repair advice
     * and the exit-2 code the finding demanded.
     */
    public function testCorruptJsonBaselineAdviceCarriesExitCode2(): void
    {
        $path = $this->tmpDir . '/truncated.json';
        file_put_contents($path, '{"version": 3, "clusters": [{"id": "c1", "imp');

        $store = new BaselineStore();
        try {
            $store->readBaseline($path);
            $this->fail('truncated baseline JSON must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(2, $e->getCode());
            $this->assertStringContainsString('not valid JSON', $e->getMessage());
            $this->assertStringContainsString('corrupt or truncated', $e->getMessage());
            $this->assertStringContainsString('--baseline-out', $e->getMessage());
        }
    }

    /**
     * once-055's boundary, pinned so the new advice door cannot swallow
     * the loud structural refusals the v1/v2 migration contract relies
     * on: a well-formed document that simply is not a v3 baseline keeps
     * the ORIGINAL messages at the ORIGINAL (codeless) posture.
     */
    public function testLoudStructuralRefusalsKeepTheirContract(): void
    {
        $store = new BaselineStore();

        $path = $this->tmpDir . '/no-version.json';
        file_put_contents($path, json_encode(['clusters' => [['id' => 'x', 'impact' => 1, 'member_hashes' => ['h']]]]));
        try {
            $store->readBaseline($path);
            $this->fail('missing version must still refuse loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('invalid structure', $e->getMessage());
            $this->assertSame(0, $e->getCode(), 'structural refusal stays outside the advice door');
        }

        $path = $this->tmpDir . '/v1.json';
        file_put_contents($path, json_encode(['version' => 1, 'clusters' => []]));
        try {
            $store->readBaseline($path);
            $this->fail('version mismatch must still refuse loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('version mismatch', $e->getMessage());
            $this->assertSame(0, $e->getCode());
        }
    }

    /**
     * once-055: the documented recipe — sha256(underRoots(file) + '|' +
     * structuralHash) — IS the identity, recomputed literally here (the
     * acceptance demands the doc formula == code, not prose == prose).
     */
    public function testDocumentedIdentityFormulaMatchesTheRealRecipe(): void
    {
        $block = new Block(
            file: '/srv/www/project/src/Util/Widget.php',
            range: new LineRange(31, 58),
            kind: 'method',
            namespace: null,
            class: null,
            name: 'render',
            ast: new Node\Stmt\Nop(),
        );
        $block->structuralHash = 'abc123def';

        $store = new BaselineStore(['/srv/www/project']);

        // Path under the configured root, separator-folded, root stripped.
        $this->assertSame('src/Util/Widget.php', PathCanonical::underRoots($block->file, ['/srv/www/project']));

        // The docs' formula, computed BY HAND from the literal parts...
        $byHand = 'sha256:' . hash('sha256', 'src/Util/Widget.php|abc123def');
        // ...and exactly what the store emits.
        $this->assertSame($byHand, $store->computeBlockHash($block));

        // Line numbers are NOT in the recipe: a shifted range with the
        // same path+structural hash is the same identity (the v1 lie).
        $shifted = new Block(
            file: '/srv/www/project/src/Util/Widget.php',
            range: new LineRange(131, 158),
            kind: 'method',
            namespace: null,
            class: null,
            name: 'render',
            ast: new Node\Stmt\Nop(),
        );
        $shifted->structuralHash = 'abc123def';
        $this->assertSame($store->computeBlockHash($block), $store->computeBlockHash($shifted));
    }

    /**
     * once-056: writeBaseline lands through a temp + fsync + rename, so
     * the committed file is ALWAYS a whole payload and no temp residue
     * survives a successful write. Pin: after each write the target
     * parses in full, and the directory holds exactly the files we made.
     */
    public function testWriteBaselineLandsWholeAndLeavesNoTempResidue(): void
    {
        $store = new BaselineStore();
        $path = $this->tmpDir . '/gate.json';

        $clusters = [
            $this->clusterWithHashes('c-a', 10),
            $this->clusterWithHashes('c-b', 25),
        ];
        $store->writeBaseline($clusters, $path);

        $entries = $store->readBaseline($path);
        $this->assertCount(2, $entries);
        $this->assertSame('c-a', $entries[0]['id']);
        $this->assertCount(10, $entries[0]['member_hashes']);
        $this->assertSame('c-b', $entries[1]['id']);
        $this->assertCount(25, $entries[1]['member_hashes']);

        $this->assertSame([], $this->tempResidue(), 'a completed write leaves no .tmp behind');
    }

    /**
     * once-056: re-writing an existing baseline replaces it whole (the
     * roundtrip test wrote a fresh path; this proves the OVERWRITE arm
     * goes through the same atomic door — old content can linger under a
     * truncated in-place rewrite, never under rename).
     */
    public function testRewriteReplacesExistingBaselineAtomically(): void
    {
        $store = new BaselineStore();
        $path = $this->tmpDir . '/gate.json';

        $store->writeBaseline([$this->clusterWithHashes('old', 40)], $path);
        $firstBytes = (string)file_get_contents($path);

        // Deliberately SMALLER payload: a truncate-in-place writer could
        // leave trailing bytes of the old document; rename cannot.
        $store->writeBaseline([$this->clusterWithHashes('new', 3)], $path);
        $secondBytes = (string)file_get_contents($path);

        $this->assertStringNotContainsString('old', $secondBytes);
        $entries = $store->readBaseline($path);
        $this->assertCount(1, $entries);
        $this->assertSame('new', $entries[0]['id']);
        $this->assertCount(3, $entries[0]['member_hashes']);
        $this->assertGreaterThan(strlen($secondBytes), strlen($firstBytes));
        $this->assertSame([], $this->tempResidue());
    }

    /**
     * once-056: when the final handover cannot happen (here: the target
     * name is a directory, so rename is impossible), the run FAILS
     * loudly, the temp is discarded, and nothing half-landed.
     */
    public function testImpossibleHandoverFailsLoudlyAndCleansItsTemp(): void
    {
        $store = new BaselineStore();
        $path = $this->tmpDir . '/blocked';
        mkdir($path);

        try {
            $store->writeBaseline([$this->clusterWithHashes('c', 5)], $path);
            $this->fail('a directory target cannot accept a baseline');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to write baseline file', $e->getMessage());
        }

        $this->assertSame([], $this->tempResidue(), 'the failed write discards its temp');
        $this->assertSame([], glob($path . '/*'), 'nothing landed inside the blocked target');
    }

    /**
     * once-056 acceptance: two writers, one target — every observation
     * (and the final file) is a WHOLE payload, never a truncation or a
     * mix. Forked processes, the posture HttpTest uses (bounded poll
     * reaping, SIGKILL only at deadline); skipped where pcntl is absent.
     */
    public function testTwoConcurrentWritersNeverExposeTruncatedBaseline(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('concurrent baseline writers require pcntl_fork/pcntl_waitpid');
        }

        $path = $this->tmpDir . '/contended.json';

        $store = new BaselineStore();
        $store->writeBaseline([$this->clusterWithHashes('small', 12)], $path); // seed: readers always hold a file

        $jobs = [
            ['tag' => 'small', 'members' => 12],
            ['tag' => 'big', 'members' => 60],
        ];

        $writers = [];
        foreach ($jobs as $job) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::markTestSkipped('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child: hammer the same target with whole payloads.
                $kid = new BaselineStore();
                $payload = [$this->clusterWithHashes($job['tag'], $job['members'])];
                for ($round = 0; $round < 120; $round++) {
                    try {
                        $kid->writeBaseline($payload, $path);
                    } catch (\Throwable $t) {
                        exit(1);
                    }
                }
                exit(0);
            }
            $writers[] = ['tag' => $job['tag'], 'members' => $job['members'], 'pid' => $pid, 'reaped' => false, 'ok' => false];
        }

        $violations = 0;
        $observed = ['small' => 0, 'big' => 0];
        $deadline = microtime(true) + 30.0;
        while (true) {
            $alive = false;
            foreach ($writers as $i => $w) {
                if ($w['reaped']) {
                    continue;
                }
                $status = 0;
                $reaped = pcntl_waitpid($w['pid'], $status, WNOHANG);
                if ($reaped === $w['pid']) {
                    $writers[$i]['reaped'] = true;
                    $writers[$i]['ok'] = pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
                } elseif ($reaped === -1) {
                    self::fail('pcntl_waitpid failed for baseline writer ' . $w['pid']);
                } else {
                    $alive = true;
                }
            }
            if (!$alive) {
                break;
            }
            if (microtime(true) > $deadline) {
                foreach ($writers as $w) {
                    if (!$w['reaped']) {
                        posix_kill($w['pid'], SIGKILL);
                    }
                }
                self::fail('baseline writers stalled past the 30 s deadline');
            }

            try {
                $entries = $store->readBaseline($path);
            } catch (\Throwable $t) {
                $violations++; // truncated or mixed document seen mid-run
                usleep(2000);
                continue;
            }
            $shape = count($entries) === 1 ? $entries[0]['id'] : '?';
            if ($shape === 'small' && count($entries[0]['member_hashes']) === 12) {
                $observed['small']++;
            } elseif ($shape === 'big' && count($entries[0]['member_hashes']) === 60) {
                $observed['big']++;
            } else {
                $violations++; // some third shape: half, merged or mangled
            }
            usleep(2000);
        }

        foreach ($writers as $w) {
            $this->assertTrue($w['ok'], "writer {$w['tag']} (pid {$w['pid']}) died mid-loop");
        }

        $this->assertSame(0, $violations, 'every concurrent observation was a whole payload');
        $this->assertGreaterThan(0, $observed['small'] + $observed['big']);
        $this->assertGreaterThan(0, $observed['big'], 'the contended run actually saw the bigger payload land');

        $final = $store->readBaseline($path);
        $this->assertCount(1, $final);
        $this->assertContains($final[0]['id'], ['small', 'big']);
        $this->assertSame([], $this->tempResidue(), 'temps of both writers are gone');
    }

    /** @return list<string> */
    private function tempResidue(): array
    {
        $found = [];
        foreach (scandir($this->tmpDir) ?: [] as $name) {
            if (str_ends_with($name, '.tmp')) {
                $found[] = $name;
            }
        }
        return $found;
    }

    /**
     * A cluster whose member blocks carry distinct structural hashes at
     * distinct paths, so payload sizes differ measurably between ids.
     */
    // ------------------------------------------------------------------
    // v4 dispositions (item 4.4 APPLY half)
    // ------------------------------------------------------------------

    public function testWriteBaselineWithDispositionsEmitsV4AndRoundtrips(): void
    {
        $path = $this->tmpDir . '/baseline.json';
        $store = new BaselineStore();
        $clusters = [$this->clusterWithHashes('Faaa', 2)];

        $dispositions = [
            'Fbbb' => new \Phpdup\Reporting\BaselineDisposition(
                findingId: 'Fbbb',
                status: 'accepted',
                note: 'intentional mirror',
                acceptedAt: '2026-11-28T00:00:00+00:00',
                files: ['src/Old.php'],
                memberHashes: ['sha256:old'],
            ),
            'Fccc' => new \Phpdup\Reporting\BaselineDisposition(
                findingId: 'Fccc',
                status: 'unresolved',
            ),
        ];
        $store->writeBaseline($clusters, $path, $dispositions);

        $raw = json_decode((string)file_get_contents($path), true);
        $this->assertSame(4, $raw['version']);
        $this->assertArrayHasKey('dispositions', $raw);
        $this->assertSame('accepted', $raw['dispositions']['Fbbb']['status']);
        $this->assertSame('intentional mirror', $raw['dispositions']['Fbbb']['note']);
        $this->assertSame(['src/Old.php'], $raw['dispositions']['Fbbb']['provenance']['files']);
        $this->assertSame(['sha256:old'], $raw['dispositions']['Fbbb']['provenance']['member_hashes']);
        $this->assertArrayNotHasKey('provenance', $raw['dispositions']['Fccc']);

        // The gate read still works on a v4 file (version [3,4] accepted).
        $entries = $store->readBaseline($path);
        $this->assertCount(1, $entries);
        $this->assertSame('Faaa', $entries[0]['id']);
    }

    public function testReadDispositionsLenientPosture(): void
    {
        $store = new BaselineStore();

        // Missing file: first-run posture, empty map (no throw).
        $this->assertSame([], $store->readDispositions($this->tmpDir . '/nope.json'));

        // v3 file (no dispositions key): complete gate baseline, no decisions.
        $v3 = $this->tmpDir . '/v3.json';
        $store->writeBaseline([$this->clusterWithHashes('Faaa', 1)], $v3);
        $this->assertSame([], $store->readDispositions($v3));
    }

    public function testReadDispositionsParsesRecords(): void
    {
        $path = $this->tmpDir . '/baseline.json';
        $store = new BaselineStore();
        $record = [
            'status' => 'accepted',
            'note' => 'protocol twins',
            'accepted_at' => '2026-11-20T10:00:00+00:00',
            'provenance' => [
                'files' => ['src/A.php', 'src/B.php'],
                'mtimes' => ['src/A.php' => 1764316800],
                'git_sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
                'member_hashes' => ['sha256:x'],
            ],
        ];
        file_put_contents($path, json_encode(['version' => 4, 'clusters' => [], 'dispositions' => ['Fabc' => $record]]));

        $parsed = $store->readDispositions($path);
        $this->assertCount(1, $parsed);
        $d = $parsed['Fabc'];
        $this->assertTrue($d->isAccepted());
        $this->assertSame('protocol twins', $d->note);
        $this->assertSame('2026-11-20T10:00:00+00:00', $d->acceptedAt);
        $this->assertSame(['src/A.php', 'src/B.php'], $d->files);
        $this->assertSame(['src/A.php' => 1764316800], $d->mtimes);
        $this->assertSame('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef', $d->gitSha);
        $this->assertSame(['sha256:x'], $d->memberHashes);
    }

    public function testReadDispositionsRefusesMalformedRecordsLoudly(): void
    {
        $store = new BaselineStore();

        $badStatus = $this->tmpDir . '/bad-status.json';
        file_put_contents($badStatus, json_encode(['version' => 4, 'clusters' => [], 'dispositions' => ['Fx' => ['status' => 'wontfix']]]));
        try {
            $store->readDispositions($badStatus);
            $this->fail('unknown disposition status must refuse loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("unknown status 'wontfix'", $e->getMessage());
        }

        $noStatus = $this->tmpDir . '/no-status.json';
        file_put_contents($noStatus, json_encode(['version' => 4, 'clusters' => [], 'dispositions' => ['Fx' => []]]));
        try {
            $store->readDispositions($noStatus);
            $this->fail('a record without status must refuse loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("must carry a string 'status'", $e->getMessage());
        }

        $badMap = $this->tmpDir . '/bad-map.json';
        file_put_contents($badMap, json_encode(['version' => 4, 'clusters' => [], 'dispositions' => 'nope']));
        try {
            $store->readDispositions($badMap);
            $this->fail('a non-object dispositions map must refuse loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("must be an object", $e->getMessage());
        }
    }

    /**
     * M-1 (E4 r1): door-level corruption on the dispositions read must carry
     * the SAME repair advice and exception code 2 as the curated
     * readBaseline() once-055 arms — the console surface maps code 2 to the
     * CLI's exit-2 door, and the message names the --baseline-out recovery.
     * Record-level garbage (the test above) stays code 0 by contrast.
     */
    public function testReadDispositionsDoorCorruptionCarriesAdviceAndExitCode2(): void
    {
        $store = new BaselineStore();

        $empty = $this->tmpDir . '/empty.json';
        file_put_contents($empty, '');
        try {
            $store->readDispositions($empty);
            $this->fail('an empty baseline must refuse with the repair advice');
        } catch (\RuntimeException $e) {
            $this->assertSame(2, $e->getCode(), 'door corruption rides the exit-2 code contract');
            $this->assertStringContainsString('Baseline file is empty', $e->getMessage());
            $this->assertStringContainsString('--baseline-out', $e->getMessage());
        }

        $truncated = $this->tmpDir . '/truncated.json';
        file_put_contents($truncated, '{"version": 4, "clu');
        try {
            $store->readDispositions($truncated);
            $this->fail('truncated JSON must refuse with the repair advice');
        } catch (\RuntimeException $e) {
            $this->assertSame(2, $e->getCode());
            $this->assertStringContainsString('not valid JSON', $e->getMessage());
            $this->assertStringContainsString('corrupt or truncated', $e->getMessage());
        }

        $emptyDoc = $this->tmpDir . '/empty-doc.json';
        file_put_contents($emptyDoc, '[]');
        try {
            $store->readDispositions($emptyDoc);
            $this->fail('an empty JSON document must refuse with the repair advice');
        } catch (\RuntimeException $e) {
            $this->assertSame(2, $e->getCode());
            $this->assertStringContainsString('empty JSON document', $e->getMessage());
        }

        // Posture split: version-mismatch (a well-formed file on an old
        // contract) keeps the curated bare code-0 voice, NOT the 2-door —
        // the same arm readBaseline() takes (pinned in the version tests).
        $v99 = $this->tmpDir . '/v99.json';
        file_put_contents($v99, json_encode(['version' => 99, 'clusters' => []]));
        try {
            $store->readDispositions($v99);
            $this->fail('an unsupported version must refuse loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expected 3 or 4', $e->getMessage());
        }
    }

    public function testDispositionsFromClustersCapturesProvenance(): void
    {
        // Members point at REAL temp files so mtimes are observed data.
        $fileA = $this->tmpDir . '/A.php';
        $fileB = $this->tmpDir . '/B.php';
        file_put_contents($fileA, "<?php\n");
        file_put_contents($fileB, "<?php\n");

        $blockA = new Block(file: $fileA, range: new LineRange(1, 5), kind: 'method', namespace: null, class: null, name: 'a', ast: new Node\Stmt\Nop());
        $blockA->structuralHash = 'ha';
        $blockB = new Block(file: $fileB, range: new LineRange(1, 5), kind: 'method', namespace: null, class: null, name: 'b', ast: new Node\Stmt\Nop());
        $blockB->structuralHash = 'hb';

        $cluster = new Cluster('Fprov', [$blockA, $blockB], 1.0, false);
        $cluster->impact = 10;

        $store = new BaselineStore([$this->tmpDir]);
        $records = $store->dispositionsFromClusters([$cluster], 'accepted', 'file pair kept');

        $this->assertCount(1, $records);
        $d = $records['Fprov'];
        $this->assertTrue($d->isAccepted());
        $this->assertSame('file pair kept', $d->note);
        $this->assertNotNull($d->acceptedAt);
        // tmpDir is not a git repo -> gitSha stays null (provenance is
        // best-effort, never guessed).
        $this->assertNull($d->gitSha);
        // Files canonicalize under the scan root: tmpDir-relative.
        $this->assertSame(['A.php', 'B.php'], $d->files);
        $this->assertCount(2, $d->mtimes);
        foreach ($d->mtimes as $mtime) {
            $this->assertIsInt($mtime);
        }
        $this->assertCount(2, $d->memberHashes);
        $this->assertStringStartsWith('sha256:', $d->memberHashes[0]);
    }

    public function testMergeDispositionsIncomingWinsAndSorts(): void
    {
        $existing = [
            'Fzz' => new \Phpdup\Reporting\BaselineDisposition(findingId: 'Fzz', status: 'unresolved'),
            'Faa' => new \Phpdup\Reporting\BaselineDisposition(findingId: 'Faa', status: 'accepted'),
        ];
        $incoming = [
            'Faa' => new \Phpdup\Reporting\BaselineDisposition(findingId: 'Faa', status: 'unresolved'),
        ];

        $merged = BaselineStore::mergeDispositions($existing, $incoming);
        $this->assertSame(['Faa', 'Fzz'], array_keys($merged));
        $this->assertFalse($merged['Faa']->isAccepted(), 'the re-recorded decision supersedes');
        $this->assertFalse($merged['Fzz']->isAccepted(), 'untouched entries keep their recorded status');
    }

    public function testReadBaselineStillRefusesUnknownVersions(): void
    {
        $path = $this->tmpDir . '/future.json';
        file_put_contents($path, json_encode(['version' => 99, 'clusters' => []]));
        try {
            (new BaselineStore())->readBaseline($path);
            $this->fail('version 99 must still refuse loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('version mismatch', $e->getMessage());
            $this->assertStringContainsString('expected 3 or 4', $e->getMessage());
        }
    }

    private function clusterWithHashes(string $id, int $members): Cluster
    {
        $blocks = [];
        for ($i = 0; $i < $members; $i++) {
            $b = new Block(
                file: 'src/M' . $i . '.php',
                range: new LineRange(1, 10),
                kind: 'method',
                namespace: null,
                class: null,
                name: 'm' . $i,
                ast: new Node\Stmt\Nop(),
            );
            $b->structuralHash = $id . '-hash-' . $i;
            $blocks[] = $b;
        }
        $cluster = new Cluster($id, $blocks, 1.0, false);
        $cluster->impact = $members * 10;
        return $cluster;
    }

    private function cluster(string $id, array $memberSpecs, int $impact): Cluster
    {
        $blocks = [];
        foreach ($memberSpecs as $spec) {
            $blocks[] = new Block(
                file: $spec['file'],
                range: new LineRange($spec['start'], $spec['end']),
                kind: 'method',
                namespace: null,
                class: null,
                name: 'method',
                ast: new Node\Stmt\Nop(),
            );
        }
        $cluster = new Cluster($id, $blocks, 1.0, false);
        $cluster->impact = $impact;
        return $cluster;
    }

    /**
     * Convert a list of Cluster objects to baseline entry format.
     *
     * @param list<Cluster> $clusters
     * @return list<array{id: string, impact: int, member_hashes: list<string>}>
     */
    private function clustersToEntries(array $clusters): array
    {
        $store = new BaselineStore();
        $entries = [];
        foreach ($clusters as $cluster) {
            $memberHashes = [];
            foreach ($cluster->members as $block) {
                $memberHashes[] = $store->computeBlockHash($block);
            }
            sort($memberHashes);
            $entries[] = [
                'id' => $cluster->id,
                'impact' => $cluster->impact,
                'member_hashes' => $memberHashes,
            ];
        }
        return $entries;
    }
}
