<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\AuditCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Item 8.2 — the audit door + judgment layer, driven IN PROCESS through
 * the hermetic transport seam: zero sockets, zero credentials, every
 * posture of the decided §8.2 doctrine pinned.
 */
final class AuditCommandTest extends TestCase
{
    private string $tmp;
    private ?string $savedToken;

    protected function setUp(): void
    {
        $this->tmp = (string) sys_get_temp_dir() . '/phpdup-audit-' . uniqid('', true);
        mkdir($this->tmp, 0o775, true);
        $this->savedToken = getenv('PHPDUP_LLM_TOKEN') === false ? null : (string) getenv('PHPDUP_LLM_TOKEN');
    }

    protected function tearDown(): void
    {
        if ($this->savedToken === null) {
            putenv('PHPDUP_LLM_TOKEN');
        } else {
            putenv('PHPDUP_LLM_TOKEN=' . $this->savedToken);
        }
        $this->rrmdir($this->tmp);
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function config(array $values): string
    {
        $path = $this->tmp . '/phpdup.json';
        file_put_contents($path, json_encode($values, JSON_PRETTY_PRINT) . "\n");

        return $path;
    }

    /**
     * @param array<string, mixed> $extra
     * @param (callable(string,string,list<string>): ?string)|null $transport
     * @return array{rc:int, out:string, doc:array<string,mixed>|null, calls:int}
     */
    private function drive(AuditCommand $command, array $extra = [], ?callable $transport = null): array
    {
        $calls = 0;
        $wrapped = $transport === null
            ? null
            : static function (string $url, string $body, array $headers) use ($transport, &$calls): ?string {
                $calls++;

                return $transport($url, $body, $headers);
            };
        $command = new AuditCommand(null, $wrapped);

        $params = array_merge([
            'paths' => [dirname(__DIR__, 2) . '/Fixtures/micro-fixture'],
            '--no-cache' => true,
            '--workers' => 1,
            '--min-impact' => 0,
            '--audit' => $this->tmp . '/audit.json',
        ], $extra);

        $out = new BufferedOutput();
        $rc = $command->run(new ArrayInput($params, $command->getDefinition()), $out);
        $docPath = $params['--audit'] ?? null;
        $doc = is_string($docPath) && is_file($docPath)
            ? json_decode((string) file_get_contents($docPath), true)
            : null;

        return ['rc' => $rc, 'out' => $out->fetch(), 'doc' => $doc, 'calls' => $calls];
    }

    /** A believable endpoint: echoes annotations for every sent pack id. */
    private function goodTransport(): callable
    {
        return static function (string $url, string $body): ?string {
            $payload = json_decode($body, true);
            if (str_ends_with($url, '/annotate')) {
                $annotations = [];
                foreach ($payload['packs'] as $packSlot) {
                    $annotations[] = [
                        'finding_id' => $packSlot['finding_id'],
                        'title' => 'shared retry bookkeeping',
                        'proposal' => 'extract one retry helper parameterized by transport',
                        'risk' => 'medium',
                        'effort' => 'M',
                        'intentional' => false,
                    ];
                }

                return json_encode(['annotations' => $annotations]);
            }
            $ids = array_column($payload['findings'], 'finding_id');

            return json_encode([
                'themes' => [['name' => 'retry-cluster', 'label' => 'outbound HTTP retry', 'members' => $ids]],
                'summary' => 'One retry-shaped duplicate family dominates.',
                'landing_order' => ['retry-cluster'],
            ]);
        };
    }

    // ---- doors ----------------------------------------------------------

    public function testHeadlessDoorsRefuseInteractiveAndRewritePostures(): void
    {
        foreach (['--tui' => '--tui', '--watch' => '--watch', '--apply' => '--apply'] as $flag => $needle) {
            $res = $this->drive(new AuditCommand(), [$flag => true]);
            $this->assertSame(2, $res['rc'], $flag . ' must refuse the audit invocation');
            $this->assertStringContainsString('never runs ' . $needle, $res['out']);
        }
    }

    public function testAuditDocumentPathIsRequiredAndControlSafe(): void
    {
        $command = new AuditCommand();
        $out = new BufferedOutput();
        $rc = $command->run(new ArrayInput([
            'paths' => [dirname(__DIR__, 2) . '/Fixtures/micro-fixture'],
            '--no-cache' => true,
            '--workers' => 1,
        ], $command->getDefinition()), $out);
        $this->assertSame(2, $rc, 'no --audit FILE => no deliverable => invalid invocation');
        $this->assertStringContainsString('requires --audit FILE', $out->fetch());

        $res = $this->drive($command, ['--audit' => "evil\npath.json"]);
        $this->assertSame(2, $res['rc']);
        $this->assertStringContainsString('refused to embed', $res['out']);
    }

    // ---- OFF posture ------------------------------------------------------

    public function testOffPostureIsHermeticUnannotatedAndStable(): void
    {
        $cfg = $this->config(['cache_dir' => $this->tmp . '/cache']);
        $res = $this->drive(new AuditCommand(), ['--config' => $cfg]); // llm_url absent => OFF

        $this->assertSame(0, $res['rc']);
        $this->assertStringContainsString('micro-fixture', $res['out'], 'the drive renders the ordinary analyze report');
        $this->assertNotNull($res['doc']);
        $this->assertSame('phpdup-audit', $res['doc']['schema']);
        $this->assertFalse($res['doc']['llm']['configured']);
        $this->assertSame(0, $res['doc']['totals']['annotated']);
        $this->assertNull($res['doc']['themes']);

        // zero cache space was ever created — OFF ships no bytes anywhere
        $this->assertFileDoesNotExist($this->tmp . '/.phpdup-cache');
        $this->assertFileDoesNotExist($this->tmp . '/cache/llm-annotations');

        // un-annotated judgment on a drive with findings must explain itself
        $this->assertStringContainsString('llm_url is not configured', $res['doc']['notes'][0] ?? '', 'notes stay honest: no endpoint armed says why annotations are null');

        $again = $this->drive(new AuditCommand(), ['--config' => $cfg]);
        $this->assertSame($res['doc'], $again['doc'], 'OFF reruns are byte-stable judgments of nothing (the doc has no volatile fields)');
        $this->assertStringNotContainsString('Bearer', (string) json_encode($res['doc']), 'no bearer material in any posture');
    }

    // ---- dry-run ----------------------------------------------------------

    public function testDryRunInventoriesAndNeverDialsEvenWhenArmed(): void
    {
        $cfg = $this->config([
            'llm_url' => 'https://llm.example/v1',
            'cache_dir' => $this->tmp . '/cache',
        ]);
        putenv('PHPDUP_LLM_TOKEN=dry-run-should-not-matter');
        $res = $this->drive(new AuditCommand(), ['--config' => $cfg, '--dry-run-packs' => true], $this->goodTransport());

        $this->assertSame(0, $res['rc']);
        $this->assertSame(0, $res['calls'], 'dry run makes ZERO transport calls even fully armed (cost posture gate)');
        $this->assertStringContainsString('pack inventory (dry run; no LLM calls were made)', $res['out']);
        $this->assertMatchesRegularExpression('/\s{2}\d\s+F[0-9a-f]{16}\s+\d+ B/', $res['out'], 'each row: rank, finding id, pack bytes');
        $this->assertTrue($res['doc']['llm']['dry_run']);
        $this->assertNull($res['doc']['findings'][0]['annotation']);
        $this->assertFileDoesNotExist($this->tmp . '/cache/llm-annotations', 'dry run does not warm the judgment cache either (the dir the DRIVE allocates for its own caches is unrelated)');
    }

    // ---- armed: the four failure modes + success --------------------------

    public function testArmedWithoutTokenDegradesLoudlyExitUnchanged(): void
    {
        putenv('PHPDUP_LLM_TOKEN');
        $cfg = $this->config(['llm_url' => 'https://llm.example/v1', 'cache_dir' => $this->tmp . '/cache']);
        $res = $this->drive(new AuditCommand(), ['--config' => $cfg], $this->goodTransport());

        $this->assertSame(0, $res['rc'], 'the §8.2 ruling: missing credential is a degrade, never a different exit');
        $this->assertSame(0, $res['calls']);
        $this->assertNotNull($res['doc']);
        $this->assertFalse($res['doc']['llm']['token_present']);
        $this->assertNotEmpty($res['doc']['notes']);
        $this->assertStringContainsString('PHPDUP_LLM_TOKEN', implode(' ', $res['doc']['notes']));
        $this->assertStringContainsString('skipped', $res['out']);
    }

    public function testArmedHappyPathAnnotatesThemesAndWritesCache(): void
    {
        putenv('PHPDUP_LLM_TOKEN=sekrit-bearer-1234');
        $cfg = $this->config(['llm_url' => 'https://llm.example/v1', 'cache_dir' => $this->tmp . '/cache']);
        $res = $this->drive(new AuditCommand(), ['--config' => $cfg], $this->goodTransport());

        $this->assertSame(0, $res['rc']);
        $this->assertGreaterThan(0, $res['calls']);
        $doc = $res['doc'];
        $this->assertNotNull($doc);
        $this->assertStringStartsWith('F-', $doc['findings'][0]['pack_key'], 'the cache key doubles as the pack hash identity in the doc');
        $this->assertSame('llm', $doc['findings'][0]['origin']);
        $this->assertSame('shared retry bookkeeping', $doc['findings'][0]['annotation']['title']);
        $this->assertSame('outbound HTTP retry', $doc['themes'][0]['label'], 'THE semantic-label venue the docs deferred to 8.2 (item 6.4 prose)');
        $this->assertSame('One retry-shaped duplicate family dominates.', $doc['summary']);
        $this->assertSame(['retry-cluster'], $doc['landing_order']);
        $this->assertStringContainsString('landing order', $res['out']);

        // the bearer never rides the artifact, only the boolean presence
        $raw = (string) file_get_contents($this->tmp . '/audit.json');
        $this->assertStringNotContainsString('sekrit-bearer-1234', $raw, 'ENV-token discipline: no secret in any report byte');
        $this->assertTrue($doc['llm']['token_present']);

        $cachedFiles = glob($this->tmp . '/cache/llm-annotations/*.json') ?: [];
        $this->assertNotEmpty($cachedFiles, 'verdicts persist under the judgment cache (host+model+version-scoped keys)');
    }

    public function testRerunsPayOnlyForChangedFindingsViaCache(): void
    {
        putenv('PHPDUP_LLM_TOKEN=sekrit');
        $cfg = $this->config(['llm_url' => 'https://llm.example/v1', 'cache_dir' => $this->tmp . '/cache']);

        $first = $this->drive(new AuditCommand(), ['--config' => $cfg], $this->goodTransport());
        $this->assertGreaterThan(0, $first['calls']);

        $second = $this->drive(new AuditCommand(), ['--config' => $cfg], static fn (): ?string => null);
        $this->assertSame(0, $second['calls'], 'second run over identical packs is served ENTIRELY from the judgment cache — the letter\'s economy clause, proven');
        $this->assertSame(0, $second['rc']);
        $this->assertSame($first['doc']['findings'][0]['annotation'], $second['doc']['findings'][0]['annotation']);
        $this->assertSame('cached', $second['doc']['findings'][0]['origin']);
        $this->assertGreaterThan(0, $second['doc']['totals']['cached_hits']);
        $this->assertTrue($second['doc']['themes'] !== null, 'the theme verdict is cached too');
    }

    public function testTransportFailureLeavesUnannotatedDocAndDriveExit(): void
    {
        putenv('PHPDUP_LLM_TOKEN=sekrit');
        $cfg = $this->config(['llm_url' => 'https://llm.example/v1', 'cache_dir' => $this->tmp . '/cache']);
        $res = $this->drive(new AuditCommand(), ['--config' => $cfg], static fn (): ?string => null);

        $this->assertSame(0, $res['rc']);
        $this->assertNull($res['doc']['findings'][0]['annotation']);
        $this->assertStringContainsString('failed', implode(' ', $res['doc']['notes']));
        $this->assertStringContainsString('theme pass skipped', implode(' ', $res['doc']['notes']));
    }

    // ---- drive-exit passthrough + scope cap --------------------------------

    public function testDriveGateTripPassesThroughAndAuditStillShips(): void
    {
        putenv('PHPDUP_LLM_TOKEN=sekrit');
        $cfg = $this->config(['llm_url' => 'https://llm.example/v1', 'cache_dir' => $this->tmp . '/cache', 'fail_on_impact' => 1]);
        $res = $this->drive(new AuditCommand(), ['--config' => $cfg], $this->goodTransport());

        $this->assertSame(3, $res['rc'], 'the drive gate keeps its code; the judgment layer neither launders nor invents exits');
        $this->assertNotNull($res['doc'], 'the un-/half-annotated document still ships on a tripped run');
    }

    public function testLlmMaxFindingsCapsScopeAndDiscloses(): void
    {
        putenv('PHPDUP_LLM_TOKEN=sekrit');
        $cfg = $this->config(['llm_url' => 'https://llm.example/v1', 'cache_dir' => $this->tmp . '/cache', 'llm_max_findings' => 1]);
        // micro-fixture yields >=1 pack; cap=1 with >=1 pack: first row annotated,
        // note only when MORE packs exist than the cap allows.
        $res = $this->drive(new AuditCommand(), ['--config' => $cfg], $this->goodTransport());

        $this->assertSame(0, $res['rc']);
        $this->assertSame('llm', $res['doc']['findings'][0]['origin'], 'the top-ranked finding is always in scope');
        if (count($res['doc']['findings']) > 1) {
            $this->assertNull($res['doc']['findings'][1]['annotation'], 'past the cap: pending, listed, never silently dropped');
            $this->assertStringContainsString('llm_max_findings', implode(' ', $res['doc']['notes']));
        }
    }

    // ---- r1 fix-round pins (8.2 r1 m-1 / m-2) ------------------------------

    /**
     * m-1 (8.2 r1): a STORED theme verdict is operator-writable disk and
     * must clear the SAME normalizeThemes contract a fresh wire response
     * clears. Pre-cure, the isset-trio accepted {"themes":[{"name":"evil",
     * "members":"not-a-list"}],"summary":17,...} into the document and the
     * render crashed with a TypeError the analyze-family exit contract has
     * no code for. Post-cure: the tampered entry degrades to a miss with
     * one loud note, exactly one re-dial (the theme call — annotations
     * stay cached), and the healed verdict ships.
     */
    public function testTamperedThemeCacheEntryFailsRevalidationAndHeals(): void
    {
        putenv('PHPDUP_LLM_TOKEN=sekrit');
        $cfg = $this->config(['llm_url' => 'https://llm.example/v1', 'cache_dir' => $this->tmp . '/cache']);
        $first = $this->drive(new AuditCommand(), ['--config' => $cfg], $this->goodTransport());
        $this->assertTrue($first['doc']['totals']['theme_pass']);

        $themeFiles = glob($this->tmp . '/cache/llm-annotations/T-*.json') ?: [];
        $this->assertCount(1, $themeFiles, 'one theme verdict per annotation set');
        file_put_contents($themeFiles[0], (string) json_encode([
            'themes'        => [['name' => 'evil', 'members' => 'not-a-list']],
            'summary'       => 17,
            'landing_order' => [],
        ]) . "\n");

        $announced = 0;
        $res = $this->drive(new AuditCommand(), ['--config' => $cfg], static function (string $url, string $body) use (&$announced): ?string {
            if (str_ends_with($url, '/annotate')) {
                $announced++;

                return null; // an annotate re-dial would defeat the cache contract
            }
            $payload = json_decode($body, true);
            $ids = array_column($payload['findings'], 'finding_id');

            return json_encode([
                'themes'        => [['name' => 'retry-cluster', 'label' => 'healed theme', 'members' => $ids]],
                'summary'       => 'healed after tamper',
                'landing_order' => ['retry-cluster'],
            ]);
        });

        $this->assertSame(0, $announced, 'the annotation pass stays fully cache-served despite the tamper');
        $this->assertSame(1, $res['calls'], 'only the tampered theme pass re-dials — the tamper heals at the theme leg alone');
        $this->assertSame(0, $res['rc'], 'never a crash, never a new exit code — degrade-and-heal');
        $this->assertStringContainsString('failed shape re-validation', implode(' ', $res['doc']['notes']));
        $this->assertSame('healed theme', $res['doc']['themes'][0]['label']);
        $this->assertGreaterThan(0, $res['doc']['totals']['cached_hits'], 'the annotation cache reads were untouched');
    }

    /**
     * m-2 (8.2 r1): LlmPackCache::packKey throws on an unencodable pack and
     * its belt names "AuditCommand's degrade arm" as the catcher — the
     * claim must be TRUE at the call site, not fiction. Source belt (the
     * ConfigSchemaParity deriveRouting precedent: greps pin doc-vs-code
     * agreement where no operator input can reach the throw); the throw
     * itself is pinned directly in LlmPackCacheTest.
     */
    public function testPackKeyCallSiteIsGuardedByTheDegradeArm(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(AuditCommand::class))->getFileName());
        $this->assertNotSame('', $src);
        $this->assertSame(
            1,
            preg_match('~try \{\s*\$key = \$cache->packKey\(\$pack\);\s*\} catch \(\\\\RuntimeException~', $src),
            'the packKey derive arm must sit inside a RuntimeException-catching degrade arm — the cache docblock promises this exact door',
        );
        $this->assertStringContainsString(
            'served un-cached this run',
            $src,
            'the degrade arm names its posture in the note it emits',
        );
    }
}
