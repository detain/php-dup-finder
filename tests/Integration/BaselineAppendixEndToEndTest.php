<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 4.4 APPLY half end-to-end: accepted findings drop out of the
 * main report into a baseline appendix, the CI gate exempts them, and
 * a drifted acceptance RESURFACES fail-open — through the real
 * bin/phpdup surface over a throwaway two-file corpus.
 *
 * ScopeFlagTest house pattern: child process, closed stdin, config
 * written per-run under a deep temp dir, wall deadline.
 */
final class BaselineAppendixEndToEndTest extends TestCase
{
    private const TIMEOUT_S = 120.0;

    private string $tmp;
    private string $tree;

    protected function setUp(): void
    {
        // NB: the scratch path deliberately spells the feature "b44" — the
        // default-posture test asserts the word 'baseline  ' is absent
        // from the CLI trailer, and member paths render verbatim there.
        $this->tmp  = sys_get_temp_dir() . '/phpdup_b44e2e_' . uniqid('t', true);
        $this->tree = $this->tmp . '/corpus';
        mkdir($this->tree, 0o775, true);

        $dup = <<<'PHP'
            %A<?php
            function %N(int $n): int
            {
                $total = 0;
                if ($n > 10) { $total += 1; }
                if ($n > 20) { $total += 2; }
                if ($n > 30) { $total += 3; }
                return $total;
            }
            PHP;
        file_put_contents($this->tree . '/p1.php', strtr($dup, ['%A' => '', '%N' => 'p1']));
        file_put_contents($this->tree . '/p2.php', strtr($dup, ['%A' => '', '%N' => 'p2']));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testDirectiveAcceptanceMovesTheFindingFromReportToAppendix(): void
    {
        // Pass 1: learn the stable finding id.
        $first = $this->cli(['analyze', $this->tree, '--json', $this->tmp . '/r1.json']);
        $this->assertSame(0, $first['exit'], $first['combined']);
        $id = $this->findingIdOf((string)file_get_contents($this->tmp . '/r1.json'));

        // Plant the code-local acceptance INSIDE a member file.
        $src = (string)file_get_contents($this->tree . '/p1.php');
        file_put_contents(
            $this->tree . '/p1.php',
            "<?php\n// @phpdup-ignore $id protocol-mirror kept\n" . substr($src, 5),
        );

        // Pass 2: the id is unchanged (comments are outside the AST), so
        // the directive matches and the finding moves to the appendix.
        $second = $this->cli(['analyze', $this->tree]);
        $this->assertSame(0, $second['exit'], $second['combined']);
        $this->assertStringContainsString('no clusters above threshold — clean.', $second['stdout']);
        $this->assertStringContainsString('baseline  1 accepted · 0 resurfaced · 0 unresolved', $second['stdout']);
        $this->assertStringContainsString("$id  accepted (directive, $this->tree/p1.php:", $second['stdout']);

        // JSON payload must not carry the accepted finding in clusters.
        $json = $this->cli(['analyze', $this->tree, '--json', $this->tmp . '/r2.json']);
        $this->assertSame(0, $json['exit'], $json['combined']);
        $decoded = json_decode((string)file_get_contents($this->tmp . '/r2.json'), true);
        $this->assertIsArray($decoded);
        $this->assertSame(0, $decoded['summary']['clusters'], 'accepted findings leave the main payload');
    }

    public function testRegistryAcceptanceExemptsTheGateAndRendersAppendix(): void
    {
        $id = $this->findingIdOfCurrentRun();

        // A v4 registry file accepting the id (empty gate entries — the
        // suppression is the decision, not the hash coverage).
        $baseline = $this->tmp . '/gate.json';
        file_put_contents($baseline, json_encode([
            'version' => 4,
            'clusters' => [],
            'dispositions' => [
                $id => [
                    'status' => 'accepted',
                    'note' => 'team decision 2026-11-28',
                    'accepted_at' => '2026-11-28T00:00:00+00:00',
                    'provenance' => [
                        'files' => ['p1.php', 'p2.php'],
                        'mtimes' => ['p1.php' => 1764316800],
                        'member_hashes' => [],
                    ],
                ],
            ],
        ]));

        $run = $this->cli(['analyze', $this->tree, '--baseline', $baseline]);
        $this->assertSame(0, $run['exit'], $run['combined']);
        $this->assertStringContainsString('baseline gate passed — 1 accepted finding(s) excluded', $run['stdout']);
        $this->assertStringContainsString("accepted (registry)", $run['stdout']);
        $this->assertStringContainsString('team decision 2026-11-28', $run['stdout']);
    }

    public function testConfigAcceptedListSuppressesAndAnnotates(): void
    {
        $id = $this->findingIdOfCurrentRun();

        $run = $this->cli(['analyze', $this->tree], ['accepted' => [$id]]);
        $this->assertSame(0, $run['exit'], $run['combined']);
        $this->assertStringContainsString('baseline  1 accepted', $run['stdout']);
        $this->assertStringContainsString('accepted (config)', $run['stdout']);
        $this->assertStringContainsString('accepted list in phpdup.json', $run['stdout']);
        $this->assertStringContainsString('no clusters above threshold — clean.', $run['stdout']);
    }

    public function testDriftedAcceptanceResurfacesFailOpen(): void
    {
        // The registry accepted an OLD id whose provenance names the
        // member files; the live cluster carries a different (drifted)
        // id. The finding must stay visible and be named resurfaced —
        // and the gate must count it.
        $liveId = $this->findingIdOfCurrentRun();

        $baseline = $this->tmp . '/drift.json';
        file_put_contents($baseline, json_encode([
            'version' => 4,
            'clusters' => [],
            'dispositions' => [
                'F0000000000000000' => [
                    'status' => 'accepted',
                    'note' => 'pre-drift decision',
                    'provenance' => ['files' => ['p1.php', 'p2.php']],
                ],
            ],
        ]));

        $run = $this->cli(['analyze', $this->tree, '--baseline', $baseline]);
        $this->assertNotSame($liveId, 'F0000000000000000');
        $this->assertSame(4, $run['exit'], 'a resurfaced finding re-trips the gate — fail-open re-review');
        $this->assertStringContainsString('1 new duplicate cluster(s) found since baseline', $run['stdout']);
        $this->assertStringContainsString('summary  1 clusters', $run['stdout'], 'the drifted finding stays in the main report');
        $this->assertStringContainsString('RESURFACED (was accepted as F0000000000000000', $run['stdout']);
    }

    public function testBaselineOutPersistsDirectiveAcceptance(): void
    {
        // Pass 1: learn the id, plant the directive.
        $id = $this->findingIdOfCurrentRun();
        $src = (string)file_get_contents($this->tree . '/p1.php');
        file_put_contents(
            $this->tree . '/p1.php',
            "<?php\n// @phpdup-ignore $id kept mirror\n" . substr($src, 5),
        );

        // Pass 2: --baseline-out writes clusters + the harvested decision.
        $out = $this->tmp . '/written.json';
        $write = $this->cli(['analyze', $this->tree, '--baseline-out', $out]);
        $this->assertSame(0, $write['exit'], $write['combined']);

        $raw = json_decode((string)file_get_contents($out), true);
        $this->assertIsArray($raw);
        $this->assertSame(4, $raw['version'], 'a decision-carrying baseline is v4');
        $this->assertArrayHasKey($id, $raw['dispositions']);
        $this->assertSame('accepted', $raw['dispositions'][$id]['status']);
        $this->assertSame('kept mirror', $raw['dispositions'][$id]['note']);
        $this->assertSame(['p1.php', 'p2.php'], $raw['dispositions'][$id]['provenance']['files']);
        $this->assertNotEmpty($raw['dispositions'][$id]['provenance']['member_hashes']);

        // Pass 3: the persisted file now REGISTRY-accepts the finding —
        // the directive could be deleted and the decision survives.
        $src2 = (string)file_get_contents($this->tree . '/p1.php');
        file_put_contents($this->tree . '/p1.php', str_replace("// @phpdup-ignore $id kept mirror\n", '', $src2));

        $compare = $this->cli(['analyze', $this->tree, '--baseline', $out]);
        $this->assertSame(0, $compare['exit'], $compare['combined']);
        $this->assertStringContainsString('accepted (registry)', $compare['stdout']);
        $this->assertStringContainsString('no clusters above threshold — clean.', $compare['stdout']);
    }

    public function testCorruptBaselineCompareKeepsExit2AdviceContract(): void
    {
        // M-1 (E4 r1): a corrupt --baseline file in compare mode must land
        // on the SAME door the pre-4.4 gate used — exit 2 plus the named
        // repair advice. The dispositions door defers its code-2 failures
        // so the curated gate door below renders them after the report,
        // exactly the base 940efc8 shape.
        $baseline = $this->tmp . '/corrupt.json';
        file_put_contents($baseline, '{"version": 4, "clu');

        $run = $this->cli(['analyze', $this->tree, '--baseline', $baseline]);
        $this->assertSame(2, $run['exit'], $run['combined']);
        $this->assertStringContainsString('not valid JSON', $run['combined']);
        $this->assertStringContainsString('re-accept with --baseline-out', $run['combined']);
    }

    public function testCorruptPriorFileIsHealedByReAccept(): void
    {
        // M-1 (E4 r1): the documented escape hatch — re-accepting with
        // --baseline-out over a corrupt file must WORK: loud warning
        // naming the repair, the file healed to a valid baseline (no
        // decisions this run, so the classic v3 shape) — AND the run still
        // refuses at exit 2: the corruption is reported, never laundered
        // by the successful write (acceptance contract rc2+advice+file).
        $reap = $this->tmp . '/reaccept.json';
        file_put_contents($reap, '{"version"');

        $run = $this->cli(['analyze', $this->tree, '--baseline', $reap, '--baseline-out', $reap]);
        $this->assertSame(2, $run['exit'], $run['combined']);
        $this->assertStringContainsString('phpdup: warning:', $run['combined']);
        $this->assertStringContainsString('not valid JSON', $run['combined']);
        $this->assertStringContainsString('baseline written to', $run['combined']);

        $raw = json_decode((string)file_get_contents($reap), true);
        $this->assertIsArray($raw, 'the healed baseline must be valid JSON');
        $this->assertSame(3, $raw['version']);
        $this->assertArrayNotHasKey('dispositions', $raw, 'no decisions -> byte-classic v3');
    }

    public function testNoDecisionsKeepsTheReportByteShape(): void
    {
        // Guard for the default posture: a plain run with no flags and no
        // directives renders no appendix trailer (double-spaced marker)
        // and no baseline words at all.
        $run = $this->cli(['analyze', $this->tree]);
        $this->assertSame(0, $run['exit'], $run['combined']);
        $this->assertStringNotContainsString('baseline  ', $run['stdout']);
        $this->assertStringContainsString('summary  1 clusters', $run['stdout']);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{exit: int, stdout: string, stderr: string, combined: string} */
    private function cli(array $args, array $cfgExtra = []): array
    {
        $cfgPath = $this->tmp . '/cfg-' . uniqid('c', true) . '.json';
        file_put_contents($cfgPath, json_encode([
            // Cold cache per invocation. Reusing one cache dir across
            // passes replays clusters from the cluster cache and the
            // directive-harvest rows do not reach the report (named
            // hazard in E4.md; replay semantics are out of scope here).
            'cache_dir' => $this->tmp . '/cache-' . uniqid('k', true),
            'min_cluster_impact' => 0,
            // Function-level families only: the if-statement bodies of
            // the pair would otherwise extract their own clone families
            // and multiply the cluster count this suite pins.
            'kinds' => ['function'],
        ] + $cfgExtra));

        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/phpdup'], $args, ['-c', $cfgPath]);
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
        );
        $this->assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + self::TIMEOUT_S;
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $this->fail('phpdup child exceeded ' . self::TIMEOUT_S . 's: ' . implode(' ', $cmd));
            }
            usleep(50_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stdout' => $out, 'stderr' => $err, 'combined' => $out . $err];
    }

    /** Run the analyzer once and return the corpus' single cluster id. */
    private function findingIdOfCurrentRun(): string
    {
        $run = $this->cli(['analyze', $this->tree, '--json', $this->tmp . '/seed.json']);
        $this->assertSame(0, $run['exit'], $run['combined']);
        return $this->findingIdOf((string)file_get_contents($this->tmp . '/seed.json'));
    }

    private function findingIdOf(string $jsonPayload): string
    {
        $decoded = json_decode($jsonPayload, true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['clusters'], 'the fixture corpus must produce exactly one cluster family');
        $id = $decoded['clusters'][0]['id'] ?? null;
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^F[0-9a-f]{16}$/', $id);
        return $id;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
