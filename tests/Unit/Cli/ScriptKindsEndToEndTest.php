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
 * run (--kinds script, lazy_ast off) clusters them end-to-end; (c) every
 * apply/patch surface refuses the script report with the typed line and
 * exit 1 under both worker settings — docs-truth: runtime refusal is
 * exit 1, not 2, not a stack trace.
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
}
