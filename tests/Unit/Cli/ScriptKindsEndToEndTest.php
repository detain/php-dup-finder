<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Command;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Item 1.3 end-to-end acceptance on the REAL bench target:
 * tests/Fixtures/micro-fixture/scripts_{0,1,2}.php — three near-identical
 * top-level MyAdmin-style scripts (differ only in the table name), the
 * exact blind-spot improve_logic.md Appendix A documents: today NOTHING
 * clusters them because no function/method wraps their statements.
 *
 * Pins: (a) a DEFAULT run emits zero script-kind clusters; (b) the opt-in
 * run (--kinds script) clusters them end-to-end under the DEFAULT
 * lazy_ast (fix-review M-1: fold ASTs are never unloaded — the eager
 * --no-lazy-ast run is now the equivalence comparison, not a requirement);
 * (c) every apply/refactor-patch surface refuses the script report with
 * the typed line and exit 1 under both worker settings — docs-truth:
 * runtime refusal is exit 1, not 2, not a stack trace.
 *
 * Scope note: with 3 script blocks the pair count (3) never arms the
 * fork scoring zone (>= 64 pairs), so --workers 1 vs 4 exercises the
 * same serial scorer — the workers=4 arm guards that the refusal
 * surfaces (which are scoring-engine agnostic) hold under a fork-config
 * run too; the fork-zone gating itself is pinned in
 * TedGateConvergenceTest (item 1.2).
 */
final class ScriptKindsEndToEndTest extends TestCase
{
    private string $tmp;
    private string $corpus;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ske2e-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o775, true);
        $corpus = realpath(__DIR__ . '/../../Fixtures/micro-fixture');
        $this->assertNotFalse($corpus);
        $this->corpus = $corpus;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->tmp);
    }

    private function tester(): CommandTester
    {
        $app = new Application('phpdup', '0.1.0');
        $app->add(new Command());
        return new CommandTester($app->find('analyze'));
    }

    /** @return array<string, mixed> decoded --json payload */
    private function runAnalyze(array $args, ?int &$exit = null): array
    {
        $json = $this->tmp . '/out-' . bin2hex(random_bytes(4)) . '.json';
        $tester = $this->tester();
        $exit = $tester->execute($args + ['--json' => $json]);
        $this->assertFileExists($json, 'a report must be written on the exit-0 paths');
        $payload = json_decode((string) file_get_contents($json), true);
        $this->assertIsArray($payload);
        return $payload;
    }

    public function testDefaultRunCarriesZeroScriptClusters(): void
    {
        $payload = [];
        $exit = $this->tester()->execute([
            'paths' => [$this->corpus],
            '--no-cache' => true,
            '--json' => $json = $this->tmp . '/default.json',
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode((string) file_get_contents($json), true);
        foreach ($payload['clusters'] ?? [] as $cluster) {
            $this->assertNotSame('script', $cluster['kind'] ?? null);
            foreach ($cluster['members'] ?? [] as $member) {
                $this->assertNotSame('script', $member['kind'] ?? null, 'script must never ride the implicit kind set');
            }
        }
    }

    public function testOptInRunClustersTheThreeScripts(): void
    {
        $payload = $this->runAnalyze([
            'paths'        => [$this->corpus],
            '--kinds'      => 'script',
            '--no-lazy-ast' => true,
            '--no-cache'   => true,
        ], $exit);
        $this->assertSame(0, $exit);

        $scriptClusters = array_values(array_filter(
            $payload['clusters'] ?? [],
            static fn (array $c): bool => ($c['kind'] ?? null) === 'script',
        ));
        $this->assertGreaterThanOrEqual(1, count($scriptClusters),
            'the scripts_0/1/2 blind-spot family must cluster once the fold kind is opted in');
        foreach ($scriptClusters as $c) {
            $files = array_map(static fn (array $m): string => (string) $m['file'], $c['members'] ?? []);
            foreach ($files as $f) {
                $this->assertStringContainsString('scripts_', $f, 'a script-kind cluster may only be built from script folds');
            }
        }
    }

    public function testApplyRefusalExitOneBothWorkerSettings(): void
    {
        foreach (['1', '4'] as $workers) {
            $tester = $this->tester();
            $exit = $tester->execute([
                'paths'         => [$this->corpus],
                '--kinds'       => 'script',
                '--no-lazy-ast' => true,
                '--no-cache'    => true,
                '--apply'       => true,
                '--workers'     => $workers,
            ]);
            $display = $tester->getDisplay();
            $this->assertSame(1, $exit, "--apply on a script report must be a runtime refusal (workers={$workers})");
            $this->assertStringContainsString('phpdup:', $display, 'clean error line, not a Symfony stack trace');
            $this->assertStringContainsString('script', $display);
            $this->assertStringContainsString('refused in v1', $display);
            $this->assertDoesNotMatchRegularExpression('/(PHP Fatal|Stack trace|Symfony\\\\Component)/', $display);
        }
    }

    public function testRefactorPatchRefusalExitOne(): void
    {
        // Surface law (item 1.3): the refusal targets AST-addressing
        // machinery — RefactorPatchReporter (--refactor-patch plain .patch
        // files, F1a, F1b) and ApplyExtractor. The cumulative --patch file
        // is DiffReporter TEXT rendering: relocatable-AST-free by design,
        // so script folds are legitimately allowed there.
        $tester = $this->tester();
        $exit = $tester->execute([
            'paths'           => [$this->corpus],
            '--kinds'         => 'script',
            '--no-lazy-ast'   => true,
            '--no-cache'      => true,
            '--refactor-patch' => $this->tmp . '/rp',
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('phpdup:', $tester->getDisplay());
        $this->assertStringContainsString('refused in v1', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->tmp . '/rp', 'refusal is all-or-nothing: no partial patch set');
    }

    // ── Fix-review M-1: script detection works under the DEFAULT lazy_ast ─

    public function testPlainScriptRunUnderDefaultLazyDetects(): void
    {
        // The reviewer's DOA repro — plain --kinds script with everything
        // else default — must now RUN and detect: folds are exempt from the
        // AST unload sweeps, so RefactorStage never asks the loader to
        // relocate a synthetic block (the loader refusal stays as the
        // loud backstop only).
        $payload = $this->runAnalyze([
            'paths'       => [$this->corpus],
            '--kinds'     => 'script',
            '--no-cache'  => true,
        ], $exit);
        $this->assertSame(0, $exit, 'plain --kinds script must not abort under default lazy_ast');
        $scriptClusters = array_values(array_filter(
            $payload['clusters'] ?? [],
            static fn (array $c): bool => ($c['kind'] ?? null) === 'script',
        ));
        $this->assertGreaterThanOrEqual(1, count($scriptClusters));
    }

    public function testMixedRunKeepsExistingKindDetectionAndTextPatch(): void
    {
        // The M-1 complaint's second half: opting into script must never
        // annihilate the pre-existing kinds. The fixture's traversable
        // dup families are 'method' (its helpers are static methods), so
        // the honest survival law is asserted exactly: the non-script
        // payload of the mixed run is BYTE-IDENTICAL to the same run
        // without the script opt-in (ids are content-derived, so this
        // compares membership, order-normalised), and script clusters are
        // strictly ADDED. --patch text rendering rides the same default-
        // lazy run the docs promise is allowed.
        $solo = $this->runAnalyze([
            'paths'      => [$this->corpus],
            '--kinds'    => 'method',
            '--no-cache' => true,
        ], $exitA);
        $this->assertSame(0, $exitA);

        $patch = $this->tmp . '/mixed.patch';
        $mixed = $this->runAnalyze([
            'paths'      => [$this->corpus],
            '--kinds'    => 'script,method',
            '--no-cache' => true,
            '--patch'    => $patch,
        ], $exitB);
        $this->assertSame(0, $exitB);

        $byId = static fn (array $cs): array => (static function () use ($cs) {
            usort($cs, static fn (array $x, array $y): int => strcmp((string) $x['id'], (string) $y['id']));
            return $cs;
        })();
        $nonScript = array_values(array_filter(
            $mixed['clusters'] ?? [],
            static fn (array $c): bool => ($c['kind'] ?? null) !== 'script',
        ));
        $this->assertSame($byId($solo['clusters'] ?? []), $byId($nonScript),
            'the pre-existing kind lanes must survive the script opt-in byte-for-byte');
        $this->assertGreaterThan(count($nonScript), count($mixed['clusters'] ?? []),
            'and the script family must arrive on top, not instead');
        $this->assertFileExists($patch, '--patch text rendering is allowed on script reports');
        $this->assertGreaterThan(0, filesize($patch));
    }

    public function testLazyAndEagerRunsProduceIdenticalDetections(): void
    {
        // M-1's equivalence demand: the default-lazy script run must not
        // merely avoid aborting — it must detect the SAME clones with the
        // SAME scores as the --no-lazy-ast run. The fold ASTs are the same
        // synthetic trees on both paths; the eager path additionally keeps
        // traversable ASTs, which anti-unification re-reads identically
        // through the loader on the lazy path. The 'config' subtree differs
        // by construction (it echoes the flags) — clusters and the summary
        // counts are compared whole.
        $lazy  = $this->runAnalyze(['paths' => [$this->corpus], '--kinds' => 'script', '--no-cache' => true]);
        $eager = $this->runAnalyze(['paths' => [$this->corpus], '--kinds' => 'script', '--no-lazy-ast' => true, '--no-cache' => true]);

        $this->assertSame($eager['clusters'], $lazy['clusters'],
            'lazy and eager script detection must be byte-equivalent in the report payload');
        foreach (['files', 'blocks', 'clusters'] as $key) {
            $this->assertSame($eager['summary'][$key] ?? null, $lazy['summary'][$key] ?? null, "summary.{$key}");
        }
    }

    public function testWarmSecondRunStaysGreenUnderDefaultLazy(): void
    {
        // M-1's replay half: the fold ASTs ride the disk caches exactly
        // like embedded canonical trees already do (the persistence layer
        // allow-lists every PhpParser node class for precisely this). A
        // warm run-2 replay must therefore never ask the loader to
        // relocate a dead fold — no abort, and identical output.
        $cfg = $this->tmp . '/warm.json';
        file_put_contents($cfg, json_encode(['cache_dir' => $this->tmp . '/cachedir']) ?: '');
        $args = [
            'paths'  => [$this->corpus],
            '--kinds' => 'script',
            '--config' => $cfg,
        ];
        $first  = $this->runAnalyze($args, $exit1);
        $second = $this->runAnalyze($args, $exit2);
        $this->assertSame(0, $exit1);
        $this->assertSame(0, $exit2, 'warm replay of script clusters must not abort');
        $this->assertSame($first['clusters'], $second['clusters']);
    }
}
