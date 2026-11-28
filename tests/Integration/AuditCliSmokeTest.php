<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 8.2 — `phpdup audit` reached through the REAL launcher: the bin
 * pre-dispatch guard must route the registered 'audit' token (not the
 * analyze fallback), the OFF posture must ship a well-formed document
 * with zero transport, and dry-run must inventory without dialing.
 * Hermetic by construction: no llm_url is ever configured here, and no
 * fake transport exists at this level — the OFF/dry postures need none.
 */
final class AuditCliSmokeTest extends TestCase
{
    private string $tmp;
    private ?string $savedToken;

    protected function setUp(): void
    {
        $this->tmp = (string) sys_get_temp_dir() . '/phpdup-audit-cli-' . uniqid('', true);
        mkdir($this->tmp, 0o775, true);
        $this->savedToken = getenv('PHPDUP_LLM_TOKEN') === false ? null : (string) getenv('PHPDUP_LLM_TOKEN');
        putenv('PHPDUP_LLM_TOKEN'); // the CLI legs must not inherit a developer token
    }

    protected function tearDown(): void
    {
        if ($this->savedToken === null) {
            putenv('PHPDUP_LLM_TOKEN');
        } else {
            putenv('PHPDUP_LLM_TOKEN=' . $this->savedToken);
        }
        foreach (glob($this->tmp . '/{,*/}*', GLOB_BRACE) ?: [] as $f) {
            if (is_dir($f)) {
                @rmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($this->tmp);
    }

    /** @return array{rc:int, out:string} */
    private function phpdup(array $args): array
    {
        $repo = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/phpdup');
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $out = [];
        $rc = 0;
        exec($cmd . ' 2>&1', $out, $rc);

        return ['rc' => $rc, 'out' => implode("\n", $out)];
    }

    public function testAuditIsARegisteredCommandNotTheAnalyzeFallback(): void
    {
        $listing = $this->phpdup(['list']);
        $this->assertSame(0, $listing['rc']);
        $this->assertStringContainsString('audit', $listing['out'], 'registered in the launcher roster');

        // Help for audit must exist and name its two options (a token that
        // fell through to analyze's fallback would print analyze's help).
        $help = $this->phpdup(['audit', '--help']);
        $this->assertSame(0, $help['rc']);
        $this->assertStringContainsString('--audit', $help['out']);
        $this->assertStringContainsString('--dry-run-packs', $help['out']);

        // And the door refusals speak through argv too:
        $refused = $this->phpdup(['audit', 'tests/Fixtures/micro-fixture', '--audit', $this->tmp . '/x.json', '--apply']);
        $this->assertSame(2, $refused['rc']);
        $this->assertStringContainsString('never runs --apply', $refused['out']);
    }

    public function testOffPostureShipsDocumentThroughTheRealEntry(): void
    {
        $doc = $this->tmp . '/audit.json';
        $res = $this->phpdup([
            'audit', 'tests/Fixtures/micro-fixture',
            '--no-cache', '--workers', '1', '--min-impact', '0',
            '--audit', $doc,
        ]);

        $this->assertSame(0, $res['rc'], $res['out']);
        $this->assertStringContainsString('document written to', $res['out']);
        $this->assertFileExists($doc);

        $decoded = json_decode((string) file_get_contents($doc), true);
        $this->assertIsArray($decoded);
        $this->assertSame('phpdup-audit', $decoded['schema']);
        $this->assertFalse($decoded['llm']['configured']);
        $this->assertSame(0, $decoded['totals']['annotated']);
        $this->assertNotCount(0, $decoded['findings'], 'the fixture yields findings — the doc lists them un-annotated, honestly');
        $this->assertStringNotContainsString('http://', (string) file_get_contents($doc), 'nothing dialed, nothing recorded');
    }

    public function testDryRunThroughTheRealEntryIsCostFree(): void
    {
        // Armed endpoint + explicit private opt-in so admission sails,
        // dry-run guarantees the zero-dial even with a live-looking URL.
        file_put_contents($this->tmp . '/phpdup.json', json_encode([
            'llm_url' => 'http://10.0.0.5:9999',
            'llm_allow_private' => true,
            'cache_dir' => $this->tmp . '/cache',
        ]));
        $doc = $this->tmp . '/dry.json';
        $res = $this->phpdup([
            'audit', 'tests/Fixtures/micro-fixture',
            '--config', $this->tmp . '/phpdup.json',
            '--no-cache', '--workers', '1', '--min-impact', '0',
            '--dry-run-packs', '--audit', $doc,
        ]);

        $this->assertSame(0, $res['rc'], $res['out']);
        $this->assertStringContainsString('pack inventory (dry run', $res['out']);
        $decoded = json_decode((string) file_get_contents($doc), true);
        $this->assertTrue($decoded['llm']['configured']);
        $this->assertTrue($decoded['llm']['dry_run']);
        $this->assertFileDoesNotExist($this->tmp . '/cache/llm-annotations');
    }

    public function testArmedWithoutCredentialDegradesThroughTheRealEntry(): void
    {
        file_put_contents($this->tmp . '/phpdup.json', json_encode([
            'llm_url' => 'https://llm.invalid-for-tests.example/v1',
            'cache_dir' => $this->tmp . '/cache2',
        ]));
        $doc = $this->tmp . '/notok.json';
        $res = $this->phpdup([
            'audit', 'tests/Fixtures/micro-fixture',
            '--config', $this->tmp . '/phpdup.json',
            '--no-cache', '--workers', '1', '--min-impact', '0',
            '--audit', $doc,
        ]);

        // No token in env => the judgment layer skips BEFORE any dial:
        // exit unchanged, document ships with the loud note, zero cache.
        $this->assertSame(0, $res['rc'], $res['out']);
        $decoded = json_decode((string) file_get_contents($doc), true);
        $this->assertFalse($decoded['llm']['token_present']);
        $this->assertStringContainsString('PHPDUP_LLM_TOKEN', $decoded['notes'][0] ?? '');
        $this->assertFileDoesNotExist($this->tmp . '/cache2/llm-annotations', 'a run without credential never created the judgment cache — no socket was opened to llm.invalid-for-tests either');
    }
}
