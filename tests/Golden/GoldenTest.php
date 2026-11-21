<?php
declare(strict_types=1);

namespace Phpdup\Tests\Golden;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Golden-file regression tests.
 *
 * Each entry under {@see fixtures()} runs the full Scanning →
 * Preprocess → Cluster → Refactor → JsonReporter pipeline against a
 * fixture corpus and compares the normalised JSON output to a known-
 * good snapshot at tests/Golden/<name>.json.
 *
 * Snapshot updates: set the env var `UPDATE_SNAPSHOTS=1` and rerun
 * the test. The snapshot file is rewritten in place; review the diff
 * before committing.
 *
 * Path / timing fields are stripped so snapshots stay portable across
 * machines and CI runners (see {@see normalize()}).
 */
final class GoldenTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function fixtures(): array
    {
        $base = __DIR__ . '/../Fixtures/';
        return [
            'notify-exact'         => ['notify',   'notify-exact.json',         true],
            'sql-exact'            => ['sql',      'sql-exact.json',            true],
            'optional-near-dups'   => ['optional', 'optional-near-dups.json',   false],
        ];
    }

    /**
     * @dataProvider fixtures
     */
    public function testFixtureMatchesGoldenSnapshot(string $fixtureName, string $snapshot, bool $exactOnly): void
    {
        $fixtureDir = realpath(__DIR__ . '/../Fixtures/' . $fixtureName);
        $this->assertNotFalse($fixtureDir, "fixture not found: $fixtureName");

        $payload = $this->normalize($this->buildPayload($fixtureDir, $exactOnly));
        $snapshotFile = __DIR__ . '/' . $snapshot;

        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            file_put_contents(
                $snapshotFile,
                (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
            $this->markTestSkipped("snapshot updated: $snapshotFile (rerun without UPDATE_SNAPSHOTS=1 to verify)");
        }

        $this->assertFileExists($snapshotFile, "missing snapshot $snapshotFile — generate with UPDATE_SNAPSHOTS=1");
        $expected = json_decode((string)file_get_contents($snapshotFile), true);
        $this->assertIsArray($expected);

        $this->assertEquals($expected, $payload, "Fixture $fixtureName drifted from $snapshot");
    }

    /** @return array<string,mixed> */
    private function buildPayload(string $fixtureDir, bool $exactOnly): array
    {
        $config = new Config(
            paths: [$fixtureDir],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: $exactOnly))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        $report = new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
            // 0.6 accounting flows into the snapshots too (useCache:false
            // → reused is a tracked 0, never null): the goldens pin the
            // balance law per fixture corpus.
            reusedFiles: $state->reusedFiles,
            processedFiles: $state->processedFiles,
            skippedFiles: $state->skippedFiles,
        );
        return (new JsonReporter())->build($report);
    }

    /**
     * Strip portable-but-irrelevant fields so snapshots stay stable
     * across machines and clones:
     *
     *   - top-level config.paths (absolute) is dropped — the fixture
     *     name in the snapshot filename is enough to identify it.
     *   - cluster member 'file' fields are made repo-relative.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalize(array $payload): array
    {
        if (isset($payload['config']['paths'])) {
            unset($payload['config']['paths']);
        }
        if (isset($payload['clusters']) && is_array($payload['clusters'])) {
            $repoRoot = realpath(__DIR__ . '/../..');
            $base = $repoRoot !== false ? $repoRoot . '/' : '';
            foreach ($payload['clusters'] as &$cluster) {
                if (!is_array($cluster) || !isset($cluster['members']) || !is_array($cluster['members'])) {
                    continue;
                }
                foreach ($cluster['members'] as &$member) {
                    if (is_array($member) && isset($member['file']) && is_string($member['file']) && $base !== '') {
                        if (str_starts_with($member['file'], $base)) {
                            $member['file'] = substr($member['file'], strlen($base));
                        }
                    }
                }
                unset($member);
                // Item 5.4: the evidence tail carries the same absolute
                // paths the production report shows (template identity,
                // diff peer + its ---/+++ headers). Relativize on the same
                // rule so snapshots stay portable across checkouts.
                if ($base !== '') {
                    foreach (['template', 'evidence_diff'] as $key) {
                        if (isset($cluster[$key]['file']) && is_string($cluster[$key]['file'])
                            && str_starts_with($cluster[$key]['file'], $base)) {
                            $cluster[$key]['file'] = substr($cluster[$key]['file'], strlen($base));
                        }
                        if (isset($cluster[$key]['diff']) && is_string($cluster[$key]['diff'])) {
                            $cluster[$key]['diff'] = str_replace($base, '', $cluster[$key]['diff']);
                        }
                    }
                }
            }
            unset($cluster);
        }
        return $payload;
    }
}
