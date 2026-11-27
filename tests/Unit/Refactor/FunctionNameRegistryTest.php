<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node\Stmt\Nop;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\FunctionNameRegistry;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Util\LineRange;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * W4-B fix M3/M4: one shared `namespace Refactored;`, many clusters — the
 * registry must make every emitted function name unique per report payload,
 * deterministically, from content ids (never list order), while leaving the
 * first claimer untouched. The ReportStage wiring test proves the pass is
 * LIVE (deleting the resolve() call turns it red).
 *
 * once-S88 (php-dup-finder-once-111) extends the file with the cross-run
 * story the per-run law leaves open: the apply-root occupancy probe, the
 * fresh-registry re-mint collision (observed with a real `php -l`), and a
 * bin-level leg proving the second real apply onto one root refuses loud
 * with zero writes while the preview posture stays exempt.
 */
final class FunctionNameRegistryTest extends TestCase
{
    /** @var list<string> scratch trees created by the once-S88 pins, removed on teardown */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            self::rrmdir($dir);
        }
        $this->scratch = [];
        parent::tearDown();
    }

    public function testFirstClaimWinsAndTheLoserGainsItsContentIdSuffix(): void
    {
        $a = $this->cluster('FAAAAA11112222', 'sharedThing');
        $b = $this->cluster('FBBBBB33334444', 'sharedThing');

        $renamed = (new FunctionNameRegistry())->resolve([$a, $b]);

        $this->assertSame(1, $renamed);
        $this->assertStringStartsWith('function sharedThing(', (string) $a->signature);
        $this->assertStringStartsWith('function sharedThing_FBBBBB33(', (string) $b->signature);
    }

    public function testNamesFollowContentIdsNotListPosition(): void
    {
        $a = $this->cluster('FAAAAA11112222', 'sharedThing');
        $b = $this->cluster('FBBBBB33334444', 'sharedThing');
        (new FunctionNameRegistry())->resolve([$b, $a]); // reversed arrival

        // First in LIST order claims verbatim; the suffix still names the
        // loser's own id — reproducible across runs, index-free.
        $this->assertStringStartsWith('function sharedThing(', (string) $b->signature);
        $this->assertStringStartsWith('function sharedThing_FAAAAA11(', (string) $a->signature);
    }

    public function testSuffixGrowsWhenTheShortTierIsAlreadyClaimed(): void
    {
        // c0 verbatim-mines `x_IDEM1111`; c1 claims `x`; c2 (whose id starts
        // IDEM1111) collides on `x` — its 8-char tier would spell exactly
        // c0's claimed name, so the tier must grow to 16 to stay unique.
        $first  = $this->cluster('F11111111AAAAAAAA', 'x_IDEM1111');
        $second = $this->cluster('F22222222BBBBBBBB', 'x');
        $third  = $this->cluster('IDEM111122223333', 'x');

        $renamed = (new FunctionNameRegistry())->resolve([$first, $second, $third]);

        $this->assertSame(1, $renamed);
        $this->assertStringStartsWith('function x_IDEM1111(', (string) $first->signature);
        $this->assertStringStartsWith('function x(', (string) $second->signature);
        $this->assertStringStartsWith('function x_IDEM111122223333(', (string) $third->signature);
    }

    public function testNullAndUnparseableSignaturesPassThroughUntouched(): void
    {
        $nullSig   = $this->cluster('FNULL00000000000');
        $nullSig->signature = null;
        $weird                = $this->cluster('FWEIRD0000000000');
        $weird->signature     = 'not a signature at all';

        $renamed = (new FunctionNameRegistry())->resolve([$nullSig, $weird, $weird]);

        $this->assertSame(0, $renamed, 'unparseable duplicates are left to the consumers own fallbacks');
        $this->assertNull($nullSig->signature);
        $this->assertSame('not a signature at all', $weird->signature);
    }

    public function testResolveIsIdempotentWithinOneInstance(): void
    {
        $a     = $this->cluster('FAAAAA11112222', 'sharedThing');
        $b     = $this->cluster('FBBBBB33334444', 'sharedThing');
        $reg   = new FunctionNameRegistry();
        $reg->resolve([$a, $b]);
        $first = [(string) $a->signature, (string) $b->signature];

        $this->assertSame(0, $reg->resolve([$a, $b]));
        $this->assertSame($first, [(string) $a->signature, (string) $b->signature]);
    }

    public function testFreshInstanceCarriesNoClaimsFromEarlierPayloads(): void
    {
        // The lifecycle law made concrete: a second run() (Watch/Server
        // cycles) must resolve from a blank slate, not inherit claims.
        $a1 = $this->cluster('FCCCCC55556666', 'dupName');
        $b1 = $this->cluster('FDDDDD77778888', 'dupName');
        (new FunctionNameRegistry())->resolve([$a1, $b1]);

        $a2 = $this->cluster('FCCCCC55556666', 'dupName');
        $b2 = $this->cluster('FDDDDD77778888', 'dupName');
        (new FunctionNameRegistry())->resolve([$a2, $b2]);

        $this->assertSame($a1->signature, $a2->signature);
        $this->assertSame($b1->signature, $b2->signature);
    }

    public function testNameOfAndWithNameRoundTrip(): void
    {
        $sig  = "function notifyMailer(\n    mixed \$x,\n): mixed";
        $name = FunctionNameRegistry::nameOf($sig);
        $this->assertSame('notifyMailer', $name);

        $swapped = FunctionNameRegistry::withName($sig, 'notifyMailer_ZZZ9');
        $this->assertSame('notifyMailer_ZZZ9', FunctionNameRegistry::nameOf($swapped));
        $this->assertStringContainsString('mixed $x', $swapped, 'params survive verbatim');
    }

    public function testReportStageActuallyRunsTheResolver(): void
    {
        // Wiring proof: with two clusters that arrive at ReportStage sharing
        // one mined name, the emitted report payload must show unique names.
        // Delete the resolve() call in ReportStage and this goes red.
        $tmp     = (string) sys_get_temp_dir() . '/phpdup-fnr-' . uniqid('', true);
        $cache   = $tmp . '/cache';
        $json    = $tmp . '/report.json';
        mkdir($cache, 0777, true);
        try {
            $config = new Config(
                paths: [$tmp], exclude: [], cacheDir: $cache, jsonReportFile: $json,
                // hand-built clusters have tiny ranges; keep them past the Ranker floor
                minClusterImpact: 1,
            );
            $state = new PipelineState($config);
            $state->files    = ['seeded.php'];
            $state->clusters = [
                $this->cluster('FRRRRR11112222', 'batchResolve', impact: 9),
                $this->cluster('FSSSSS33334444', 'batchResolve', impact: 9),
            ];

            (new ReportStage(limit: 50, showStats: false))->run($state, new BufferedOutput());

            $this->assertNotNull($state->report);
            $decoded = json_decode((string) file_get_contents($json), true);
            $names   = array_map(
                static fn(array $c): ?string => FunctionNameRegistry::nameOf((string) $c['signature']),
                $decoded['clusters'],
            );
            $this->assertCount(2, $names);
            $this->assertSame(['batchResolve', 'batchResolve_FSSSSS33'], $names);
        } finally {
            @unlink($json);
            @array_map('unlink', glob($cache . '/*') ?: []);
            @rmdir($cache);
            @rmdir($tmp);
        }
    }

    // ── once-S88: cross-run apply collisions + occupancy probe (once-111) ──

    public function testOccupancyProbeNamesNothingOnAFreeOrForeignRoot(): void
    {
        $root = $this->scratchDir();
        $this->assertSame([], FunctionNameRegistry::applyRootOccupancy($root), 'an empty root is free');
        $this->assertSame([], FunctionNameRegistry::applyRootOccupancy($root . '/absent'), 'an absent root is free');

        // The F1a preview artifact and the non-proofs a blind user tree
        // carries: NONE may trip the armed gate — the committed pins re-run
        // previews over one root repeatedly, and foreign bytes are nobody's
        // business. A dot-only '.orig' is not the extractor's name image
        // (`<member-basename>.orig`) and proves nothing either.
        file_put_contents($root . '/' . RefactorPatchReporter::APPLY_DIFF_FILE, "preview bytes\n");
        file_put_contents($root . '/notes.txt', 'user file');
        $this->assertTrue(mkdir($root . '/' . ApplyExtractor::BACKUP_SUBDIR, 0o775, true));
        file_put_contents($root . '/' . ApplyExtractor::BACKUP_SUBDIR . '/.orig', 'hidden decoy');

        $this->assertSame([], FunctionNameRegistry::applyRootOccupancy($root),
            'apply.diff, foreign files, an empty backup dir and a dot-only .orig are no markers');
    }

    public function testOccupancyProbeNamesEveryEarlierApplyMarker(): void
    {
        $root = $this->scratchDir();
        // Paths are BUILT from the public constants, and the probe returns
        // keys the CLI prints: if the registry's restated literal ever
        // drifts from RefactorPatchReporter's artifact name, this goes red.
        $summary   = $root . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE;
        $artifacts = $root . '/' . ApplyExtractor::ARTIFACT_NAMESPACE;
        $backup    = $root . '/' . ApplyExtractor::BACKUP_SUBDIR;
        $this->assertTrue(mkdir($artifacts, 0o775, true));
        $this->assertTrue(mkdir($backup, 0o775, true));
        file_put_contents($summary, "# phpdup apply summary\n");
        file_put_contents($artifacts . '/cluster_a.php', "<?php\n");
        file_put_contents($backup . '/Member.php' . ApplyExtractor::BACKUP_SUFFIX, "<?php\n");

        $occupied = FunctionNameRegistry::applyRootOccupancy($root);

        $this->assertSame([$summary, $artifacts, $backup], array_keys($occupied));
        $this->assertStringContainsString('earlier real apply', $occupied[$summary]);
        $this->assertStringContainsString('invisible to this run', $occupied[$artifacts]);
        $this->assertStringContainsString('.orig backups', $occupied[$backup]);
    }

    public function testOccupancyProbeReportsSymbolicLinksWearingMarkerNames(): void
    {
        $root      = $this->scratchDir();
        $elsewhere = $this->scratchDir();
        file_put_contents($elsewhere . '/payload.txt', 'outside bytes');
        symlink($elsewhere, $root . '/' . ApplyExtractor::ARTIFACT_NAMESPACE);
        symlink($elsewhere . '/payload.txt', $root . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
        symlink($elsewhere, $root . '/' . ApplyExtractor::BACKUP_SUBDIR);

        $occupied = FunctionNameRegistry::applyRootOccupancy($root);

        $this->assertCount(3, $occupied);
        foreach ($occupied as $reason) {
            $this->assertStringContainsString('symbolic link', $reason,
                'a link at a marker name is the escape hazard named, never scanned through');
        }
    }

    public function testOccupancyProbeFailsClosedWhenTheBackupDirCannotBeListed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('filesystem read refusal cannot be provoked for uid 0 (house root-guard, S44b precedent)');
        }
        $root   = $this->scratchDir();
        $backup = $root . '/' . ApplyExtractor::BACKUP_SUBDIR;
        $this->assertTrue(mkdir($backup, 0o775, true));
        $this->assertTrue(chmod($backup, 0o000));
        try {
            $occupied = FunctionNameRegistry::applyRootOccupancy($root);

            $this->assertSame([$backup], array_keys($occupied), 'unprovable backup contents fail CLOSED');
            $this->assertStringContainsString('cannot be listed', $occupied[$backup]);
        } finally {
            $this->assertTrue(chmod($backup, 0o775));
        }
    }

    public function testFreshRegistriesRemintTheSameNameBecauseClaimsArePerRunMemory(): void
    {
        // The Lifecycle law made concrete across RUNS (finding once-111):
        // two payloads, two fresh registries, two different clusters that
        // mine the same verb+noun — both keep the name verbatim.
        $runOne = $this->cluster('FEEEEEEE11112222', 'renderLine');
        $runTwo = $this->cluster('FFFFFFFF33334444', 'renderLine');

        $this->assertSame(0, (new FunctionNameRegistry())->resolve([$runOne]));
        $this->assertSame(0, (new FunctionNameRegistry())->resolve([$runTwo]));
        $this->assertSame((string) $runOne->signature, (string) $runTwo->signature,
            'a second run cannot see the first run\'s claims — the collision class the cross-run gate refuses');

        // In one payload the same pair is impossible: the loser is suffixed.
        $this->assertSame(1, (new FunctionNameRegistry())->resolve([
            $this->cluster('FEEEEEEE11112222', 'renderLine'),
            $this->cluster('FFFFFFFF33334444', 'renderLine'),
        ]));
    }

    public function testCrossRunReMintFatalsPhpLintWhileOneRunDeduplicates(): void
    {
        $root = $this->scratchDir();

        // (a) The refused state: two runs, each fresh registry, same mined
        //     name — the declarations they minted merge into ONE fatal.
        $one = $this->cluster('F11111111AAAAAAAA', 'renderLine');
        $two = $this->cluster('F22222222BBBBBBBB', 'renderLine');
        (new FunctionNameRegistry())->resolve([$one]);
        (new FunctionNameRegistry())->resolve([$two]);
        $nameOne = (string) FunctionNameRegistry::nameOf((string) $one->signature);
        $nameTwo = (string) FunctionNameRegistry::nameOf((string) $two->signature);
        $this->assertSame($nameOne, $nameTwo);

        $fatalFile = $root . '/cross-run-pair.php';
        file_put_contents($fatalFile, $this->generatedPair($nameOne, $nameTwo));
        $fatal = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($fatalFile) . ' 2>&1');
        $this->assertStringContainsString('Cannot redeclare', $fatal,
            'php -l must reject the merged declaration set two runs left behind — ' . $fatal);

        // (b) One run, one registry: the pass names the loser, and the
        //     merged set lints clean (the persisted-registry branch of the
        //     §88 Acceptance wording stands on this leg).
        $three = $this->cluster('F3333333CCCCCCCC', 'renderLine');
        $four  = $this->cluster('F4444444DDDDDDDD', 'renderLine');
        $this->assertSame(1, (new FunctionNameRegistry())->resolve([$three, $four]));
        $nameThree = (string) FunctionNameRegistry::nameOf((string) $three->signature);
        $nameFour  = (string) FunctionNameRegistry::nameOf((string) $four->signature);
        $this->assertNotSame($nameThree, $nameFour);

        $cleanFile = $root . '/one-run-pair.php';
        file_put_contents($cleanFile, $this->generatedPair($nameThree, $nameFour));
        $clean = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($cleanFile) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', $clean,
            'the single-run pair must lint green — ' . $clean);
    }

    public function testSecondRealApplyOntoTheSameRootRefusesLoudWithZeroWrites(): void
    {
        // The Acceptance leg end-to-end at the bin boundary (runPhpDup
        // precedent, shell-S19): a real apply, then a second real apply
        // onto the same ./refactored — refused exit 3 in the safety-gate
        // voice, byte-frozen tree and root, and the PREVIEW posture stays
        // exempt over the occupied root.
        $root = $this->scratchDir();
        $tree = $this->writeDupTree($root);
        $args = ['analyze', $tree, '--apply', '--dry-run=false', '--max-df=0.5', '--min-block-size=3', '--workers=1'];

        $first = $this->cliRun($args, $root);
        $this->assertSame(0, $first['exit'], 'run 1 (fresh root) must apply — output: ' . $first['combined']);
        $applyRoot = $root . '/refactored';
        $summaryPath = $applyRoot . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE;
        $this->assertFileExists($summaryPath);
        // Non-vacuity: run 1 must REALLY apply, else the refusal below
        // could ride a summary-only ghost and the pin would prove nothing.
        $this->assertStringContainsString('# Applied: 1 clusters', (string) file_get_contents($summaryPath),
            'the dup pair must actually apply in run 1 — ' . (string) file_get_contents($summaryPath));
        $this->assertDirectoryExists($applyRoot . '/' . ApplyExtractor::ARTIFACT_NAMESPACE);
        $this->assertNotEmpty(glob($applyRoot . '/' . ApplyExtractor::BACKUP_SUBDIR . '/*' . ApplyExtractor::BACKUP_SUFFIX));

        $rootBytesBefore = $this->hashTree($applyRoot);
        $treeBytesBefore = $this->hashTree($tree);

        $second = $this->cliRun($args, $root);
        $this->assertSame(3, $second['exit'], 'the second real apply is the once-S04 safety-gate family — output: ' . $second['combined']);
        $combined = $second['combined'];
        $this->assertStringContainsString('real apply refused', $combined);
        $this->assertStringContainsString('already holds the output of an earlier real apply', $combined);
        $this->assertStringContainsString($applyRoot . '/' . RefactorPatchReporter::APPLY_SUMMARY_FILE, $combined);
        $this->assertStringContainsString($applyRoot . '/' . ApplyExtractor::ARTIFACT_NAMESPACE, $combined);
        $this->assertStringContainsString($applyRoot . '/' . ApplyExtractor::BACKUP_SUBDIR, $combined,
            'the marker rows name every colliding path — summary, generated tree and backups');
        $this->assertStringContainsString('FunctionNameRegistry', $combined, '§88: the explanation names the registry provenance');
        $this->assertStringContainsString('No analysis was run and no file was written', $combined);
        $this->assertStringNotContainsString('real apply armed', $combined, 'the refusal precedes the posture announcement');

        $this->assertSame($rootBytesBefore, $this->hashTree($applyRoot), 'the refusal moved zero bytes in the apply root');
        $this->assertSame($treeBytesBefore, $this->hashTree($tree), 'the refusal moved zero bytes in the source tree');

        $dry = $this->cliRun(
            ['analyze', $tree, '--apply', '--max-df=0.5', '--min-block-size=3', '--workers=1'],
            $root
        );
        $this->assertSame(0, $dry['exit'], 'the PREVIEW posture is exempt on an occupied root — output: ' . $dry['combined']);
        $this->assertFileExists($applyRoot . '/' . RefactorPatchReporter::APPLY_DIFF_FILE);
        $this->assertSame([], glob($applyRoot . '/.apply.diff.*'), 'the atomic tmp+rename leaves zero temp litter');
        $this->assertSame($rootBytesBefore, array_filter(
            $this->hashTree($applyRoot),
            static fn (string $v, string $k): bool => !str_ends_with($k, 'apply.diff'),
            ARRAY_FILTER_USE_BOTH
        ), 'the dry leg touches nothing but its own apply.diff');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function cluster(string $id, string $name = 'thing', int $impact = 4): Cluster
    {
        $cluster        = new Cluster($id, [$this->blk('a.php'), $this->blk('b.php')], 0.9, false);
        $cluster->signature = "function {$name}(\n    mixed \$v,\n): mixed";
        $cluster->impact      = $impact;
        return $cluster;
    }

    private function blk(string $file): Block
    {
        $block      = new Block($file, new LineRange(1, 3), 'method', 'Acme\\X', 'Host', 'run', new Nop());
        $block->size = 30; // Ranker recomputes impact from member sizes
        return $block;
    }

    private function scratchDir(): string
    {
        $dir = (string) sys_get_temp_dir() . '/phpdup-s88-' . uniqid('', true);
        if (!mkdir($dir, 0o775, true)) {
            $this->fail('cannot create scratch tree ' . $dir);
        }
        $this->scratch[] = $dir;
        return $dir;
    }

    /**
     * The namespaced_strict member shape (fixture lineage, output-S53): the
     * SAME method body modulo one string literal, so the notify pair forms
     * one applying cluster under the pins' `--min-block-size=3 --max-df=0.5`
     * flags while nothing else in the tree clusters.
     */
    private function writeDupTree(string $root): string
    {
        $src = $root . '/tree/src';
        $this->assertTrue(mkdir($src, 0o775, true));
        foreach (['AlphaDup' => 'high', 'BetaDup' => 'low'] as $class => $level) {
            file_put_contents($src . '/' . $class . '.php', str_replace(
                ['%CLASS%', '%LEVEL%'],
                [$class, $level],
                <<<'PHP'
                    <?php
                    declare(strict_types=1);

                    namespace S88\Ledger;

                    final class %CLASS%
                    {
                        public function notify(string $user, int $score): string
                        {
                            $tag = strtoupper($user);
                            $line = render($tag, '%LEVEL%');
                            return $line;
                        }
                    }
                    PHP,
            ));
        }
        return $root . '/tree';
    }

    /** Two generated declarations in the one shared `namespace Refactored;`. */
    private function generatedPair(string $first, string $second): string
    {
        return "<?php\nnamespace Refactored;\n"
            . "function {$first}(): int { return 1; }\n"
            . "function {$second}(): int { return 2; }\n";
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, combined: string}
     */
    private function cliRun(array $args, string $cwd): array
    {
        $bin = dirname(__DIR__, 3) . '/bin/phpdup';
        $this->assertFileExists($bin, 'bin-level leg needs the real entrypoint');
        // ApplyEndToEndTest::cli() cfg lineage: keep the AST cache OUT of
        // both the scanned tree and the apply root.
        $cfg = $cwd . '/cfg.json';
        file_put_contents($cfg, (string) json_encode(['cache_dir' => $cwd . '/cache']));
        @mkdir($cwd . '/cache', 0o775, true);

        $proc = proc_open(
            array_merge([PHP_BINARY, $bin], $args, ['-c', $cfg]),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );
        $this->assertIsResource($proc, 'proc_open refused bin/phpdup');
        $combined = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($proc), 'combined' => $combined];
    }

    /** @return array<string,string> relative path => content fingerprint (files), marker (dirs/links) */
    private function hashTree(string $dir): array
    {
        $hashes = [];
        $items  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $file) {
            /** @var \SplFileInfo $file */
            $rel = substr($file->getPathname(), strlen($dir) + 1);
            if ($file->isLink()) {
                $hashes[$rel] = 'link:' . (string) readlink($file->getPathname());
            } elseif ($file->isDir()) {
                $hashes[$rel] = 'dir';
            } elseif ($file->isFile()) {
                $hashes[$rel] = 'sha256:' . (string) hash_file('sha256', $file->getPathname());
            } else {
                $hashes[$rel] = 'other';
            }
        }
        ksort($hashes);
        return $hashes;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0o775);
        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                self::rrmdir($path); // the recursion removes the child itself
            } else {
                unlink($path);
            }
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}
