<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 8.1 e2e: the `--evidence-packs DIR` sink on a real pipeline run —
 * packs land ranked and decodable, the OFF posture is byte-silent, and the
 * surface never grows the report JSON (schema rides unchanged).
 */
final class EvidencePackSinkTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string)sys_get_temp_dir() . '/phpdup-pack-e2e-' . uniqid('', true);
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(glob($this->tmp . '/{,*/}*', GLOB_BRACE) ?: []) as $f) {
            if (is_dir($f)) {
                @rmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($this->tmp);
    }

    public function testSinkLandsRankedDecodablePacks(): void
    {
        $repo = dirname(__DIR__, 2);
        $out  = $this->tmp . '/packs';
        mkdir($out, 0o775, true);
        // Reviewer 8.1.r1 M-1 (sink leg): a stale owned name from a prior
        // generation, and a foreign name the sweep must never touch.
        file_put_contents($out . '/999-F0000000000000000.pack.json', '{"stale":true}');
        file_put_contents($out . '/keep.txt', 'foreign');
        $cmd  = sprintf(
            '%s %s analyze %s --evidence-packs %s --min-impact 0 --workers 1 --no-cache 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($repo . '/bin/phpdup'),
            escapeshellarg($repo . '/tests/Fixtures/micro-fixture'),
            escapeshellarg($out),
        );
        exec($cmd, $lines, $rc);
        $this->assertSame(0, $rc, 'pack run failed:\n' . implode("\n", array_slice($lines, -8)));

        $stdout = implode("\n", $lines);
        $this->assertStringContainsString('evidence packs → ' . $out . '/', $stdout);

        $files = glob($out . '/*.pack.json') ?: [];
        $this->assertNotEmpty($files, 'micro-fixture must emit at least one pack (vacuity crux)');
        $this->assertFileDoesNotExist($out . '/999-F0000000000000000.pack.json', 'stale generation swept at the sink');
        $this->assertFileExists($out . '/keep.txt', 'the sweep is owned-names-only');

        exec($cmd, $lines2, $rc2); // second run into the same dir
        $this->assertSame(0, $rc2);
        $this->assertCount(count($files), glob($out . '/*.pack.json') ?: [], 'one generation per dir across runs');

        $rank0 = null;
        foreach ($files as $path) {
            $doc = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('evidence_pack', $doc['schema']);
            $this->assertSame('1', $doc['pack_version']);
            $this->assertMatchesRegularExpression('/^\d{3}-F[0-9a-f]{16}\.pack\.json$/', basename($path));
            $this->assertGreaterThan(0, count($doc['members']));
            $this->assertSame((int)substr(basename($path), 0, 3), $doc['rank'], 'file rank prefix mirrors the document rank');
            $rank0 ??= (int)$doc['rank'];
        }
        $this->assertSame(1, $rank0, 'the best finding earns rank 1');

        // At least one pack carries the full generalized-template view —
        // the pipeline actually ran AntiUnifier end to end into this sink.
        $templateSeen = false;
        $diffSeen     = false;
        foreach ($files as $path) {
            $doc = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (($doc['template']['available'] ?? false) === true) {
                $templateSeen = true;
                $this->assertIsString($doc['template']['text']);
                foreach ($doc['member_diffs'] as $d) {
                    if (is_string($d['diff'] ?? null)) {
                        $diffSeen = true;
                    }
                }
            }
        }
        $this->assertTrue($templateSeen, 'micro-fixture pairs unify into templates — a pack must show one');
        $this->assertTrue($diffSeen, 'at least one member must differ from its template text');
    }

    public function testOffPostureStaysSilentAndSchemaUnchanged(): void
    {
        $repo = dirname(__DIR__, 2);
        $cmd  = sprintf(
            '%s %s analyze %s --min-impact 0 --workers 1 --no-cache 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($repo . '/bin/phpdup'),
            escapeshellarg($repo . '/tests/Fixtures/micro-fixture'),
        );
        exec($cmd, $lines, $rc);
        $this->assertSame(0, $rc);
        $stdout = implode("\n", $lines);
        $this->assertStringNotContainsString('evidence packs', $stdout, 'the sink is opt-in — OFF is silent');

        // And the report JSON carries no new key: the pack surface is a
        // standalone file artifact (§84 "prefer file output over JSON growth").
        $json = $this->tmp . '/report.json';
        exec($cmd . ' --json ' . escapeshellarg($json) . ' > /dev/null 2>&1');
        $payload = json_decode((string)file_get_contents($json), true, flags: JSON_THROW_ON_ERROR);
        foreach ($payload['clusters'] as $cluster) {
            $this->assertArrayNotHasKey('evidence_pack', $cluster);
        }
    }
}
