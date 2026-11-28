<?php

declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * JLEG — belt for bench/text-tier-acceptance.php (7.1 interim-comparator
 * acceptance leg).
 *
 *  1. the planter is deterministic: generateTextTwins output matches the
 *     committed [text-twins] pins in bench/corpus-pins.txt exactly;
 *  2. the native gate passes EXACT (4/4 keyed families, zero false
 *     positives) via bench/text-tier-acceptance.php --no-jscpd;
 *  3. the jscpd leg degrades to an honest green skip when the binary is
 *     absent — asserted with a sanitized environment (PATH holding only
 *     the PHP dir) so a host-installed jscpd can never flip the case.
 *
 * No network, no jscpd install, no golden/`src/` coupling. Runtime is a
 * couple of seconds; every artifact lives under a TMP dir.
 */
final class TextTierBenchAcceptanceTest extends TestCase
{
    private string $tmp;
    private string $corpus;
    private string $root;

    protected function setUp(): void
    {
        $this->root   = dirname(__DIR__, 2);
        $this->tmp    = sys_get_temp_dir() . '/jleg-text-tier-' . bin2hex(random_bytes(6));
        $this->corpus = $this->tmp . '/text-twins';
        mkdir($this->corpus, 0o777, true);

        if (!defined('PHPDUP_CORPORA_LIB')) {
            define('PHPDUP_CORPORA_LIB', true);
        }
        require_once $this->root . '/bench/corpora.php';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testPlanterMatchesCommittedTextTwinsPins(): void
    {
        \generateTextTwins($this->corpus);
        $pins     = \readPins($this->root . '/bench/corpus-pins.txt');
        $failures = [];
        \verifyTextTwins($pins, $this->corpus, $failures);

        $this->assertSame([], $failures, 'planted text-twins drifted from the committed pins');
    }

    public function testNativeGateIsExactAndComparatorDegradesToGreenSkip(): void
    {
        \generateTextTwins($this->corpus);

        $evidence = $this->tmp . '/evidence.json';
        $cmd = sprintf(
            '%s %s --corpus=%s --no-jscpd --out=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/bench/text-tier-acceptance.php'),
            escapeshellarg($this->corpus),
            escapeshellarg($evidence),
        );
        [$rc, $out] = $this->execWithEnv($cmd, ['PATH' => dirname(PHP_BINARY)]);

        $this->assertSame(0, $rc, "acceptance leg exited {$rc}:\n" . $out);
        $this->assertStringContainsString('[GATE] native text-tier recall EXACT', $out);
        $this->assertStringContainsString('native gate : 4/4 keyed families', $out);
        $this->assertStringContainsString('[skip] jscpd comparator leg: --no-jscpd given', $out);

        $ev = json_decode((string)file_get_contents($evidence), true);
        $this->assertIsArray($ev);
        $this->assertSame(4, $ev['native']['caught']);
        $this->assertSame(4, $ev['native']['families']);
        $this->assertSame([], $ev['native']['missed']);
        $this->assertSame([], $ev['native']['false_positive_clusters']);
        $this->assertNull($ev['jscpd']['binary']);
        $this->assertNotNull($ev['jscpd']['skip_notice']);
    }

    public function testAutoDiscoverySkipsGreenWhenNoBinaryAnywhere(): void
    {
        \generateTextTwins($this->corpus);

        // PATH holds only the PHP binary dir → command -v jscpd cannot hit;
        // JSCPD_BIN unset; bench/tools/node_modules is gitignored and absent
        // from a checkout → resolver prints the absent-notice skip.
        $cmd = sprintf(
            '%s %s --corpus=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/bench/text-tier-acceptance.php'),
            escapeshellarg($this->corpus),
        );
        [$rc, $out] = $this->execWithEnv($cmd, ['PATH' => dirname(PHP_BINARY)]);

        $this->assertSame(0, $rc, "absent-binary leg must stay green, exited {$rc}:\n" . $out);
        $this->assertStringContainsString('[skip] jscpd comparator leg: no jscpd binary', $out);
        $this->assertStringContainsString('[GATE] native text-tier recall EXACT', $out);
    }

    /**
     * @param array<string,string> $env
     * @return array{0:int,1:string}
     */
    private function execWithEnv(string $cmd, array $env): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->root, $env);
        $this->assertIsResource($proc);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        return [$rc, $stdout . $stderr];
    }
}
