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
 * before committing. Since once-S93 the regen leg is not silent: the
 * write is read back and asserted against the payload, a vacuous
 * empty rewrite is refused by the per-fixture cluster floor, and the
 * run still ends marked skipped — which the dedicated CI
 * `--fail-on-skipped` Golden leg turns into a hard red, so an
 * unreviewed regeneration can never merge as green.
 *
 * Path / timing fields are stripped so snapshots stay portable across
 * machines and CI runners (see {@see normalize()}).
 */
final class GoldenTest extends TestCase
{
    /**
     * Each row: [fixture dir, snapshot file, exactOnly, minClusters].
     * The minClusters floor (once-S93) is the structural guard that a
     * golden is REAL input: the file asserts detections happened, so an
     * empty-clusters manifest — by drift or by an UPDATE_SNAPSHOTS
     * rewrite over a regressed pipeline — fails loud instead of passing
     * vacuously. Floors sit at the committed-content cluster counts'
     * safe minimum of 1; the exact content is still pinned by the diff.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: int}>
     */
    public static function fixtures(): array
    {
        $base = __DIR__ . '/../Fixtures/';
        return [
            'notify-exact'         => ['notify',   'notify-exact.json',         true,  1],
            'sql-exact'            => ['sql',      'sql-exact.json',            true,  1],
            'optional-near-dups'   => ['optional', 'optional-near-dups.json',   false, 1],
        ];
    }

    /**
     * @dataProvider fixtures
     */
    public function testFixtureMatchesGoldenSnapshot(string $fixtureName, string $snapshot, bool $exactOnly, int $minClusters): void
    {
        $fixtureDir = realpath(__DIR__ . '/../Fixtures/' . $fixtureName);
        $this->assertNotFalse($fixtureDir, "fixture not found: $fixtureName");

        $payload = $this->normalize($this->buildPayload($fixtureDir, $exactOnly));
        $snapshotFile = __DIR__ . '/' . $snapshot;

        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            // once-S93 / once-116: a regen is a WRITE that has to prove it
            // landed, and an empty write is never a pass. Every arm below
            // fails the test loudly; only a fully verified rewrite is
            // allowed to reach the (now genuinely loud) skip marker.
            $written = file_put_contents(
                $snapshotFile,
                (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
            $this->assertNotFalse(
                $written,
                "UPDATE_SNAPSHOTS: the write to $snapshotFile failed — no snapshot was updated."
            );
            $roundTrip = json_decode((string) file_get_contents($snapshotFile), true);
            $this->assertIsArray(
                $roundTrip,
                "UPDATE_SNAPSHOTS: $snapshotFile did not read back as a JSON object."
            );
            $this->assertEquals(
                $payload,
                $roundTrip,
                "UPDATE_SNAPSHOTS: $snapshotFile does not round-trip the payload it claimed to write."
            );
            $this->assertGreaterThanOrEqual(
                $minClusters,
                count($roundTrip['clusters'] ?? []),
                "UPDATE_SNAPSHOTS: refusing a vacuous regen of $snapshot to fewer than $minClusters cluster(s) — the golden must carry real detections (once-116)."
            );
            $this->markTestSkipped(
                "snapshot REGENERATED at $snapshotFile — a WRITE, not a PASS: review the git diff, "
                . "then rerun without UPDATE_SNAPSHOTS=1. The CI Golden --fail-on-skipped leg keeps "
                . "this skip from merging silently."
            );
        }

        $this->assertFileExists($snapshotFile, "missing snapshot $snapshotFile — generate with UPDATE_SNAPSHOTS=1");
        $expected = json_decode((string)file_get_contents($snapshotFile), true);
        $this->assertIsArray($expected);

        // Structural floor on both sides of the diff (once-S93): the
        // committed manifest is real input, and the freshly built payload
        // really detects — so the equality below can never be an
        // empty-equals-empty ceremony.
        $this->assertGreaterThanOrEqual(
            $minClusters,
            count($expected['clusters'] ?? []),
            "golden manifest $snapshot is vacuous: fewer than $minClusters cluster(s) — regenerate from real input, never from a regressed pipeline (once-116)."
        );
        $this->assertGreaterThanOrEqual(
            $minClusters,
            count($payload['clusters'] ?? []),
            "fixture $fixtureName currently yields fewer than $minClusters cluster(s) while $snapshot claims detections — the pipeline lost what the golden pins."
        );

        $this->assertEquals($expected, $payload, "Fixture $fixtureName drifted from $snapshot");
    }

    /** @return array<string,mixed> */
    private function buildPayload(string $fixtureDir, bool $exactOnly): array
    {
        $config = new Config(
            paths: [$fixtureDir],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
            // E3 / item 6.2 re-baseline: impact is now REMOVABLE DUPLICATED
            // LINES (interval-union x (m-1)/m), so the shipped default
            // floor of 20 lines is far stricter than the old 20-node one
            // and would empty every manifest (measured: notify 12/6, sql
            // 14, optional 8 removable). The goldens pin DETECTION
            // contracts on small synthetic clones, so they run an explicit
            // floor of 5 — the same value the ci.yml smoke leg uses
            // (--min-impact 5) — keeping membership stable across the
            // flip; only impact values move.
            minClusterImpact: 5,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        // E2 hermeticity fix: the goldens pin computation, not replay.
        // With the 1.8 emit the live clustersReplayed counter rides the
        // payload — an unwired cluster cache made the snapshot
        // cold-run/warm-run dependent (regenerated false, replayed
        // true). useCache:false on every caching stage, like the
        // RefactorStage leg already did.
        (new ClusterStage(exactOnly: $exactOnly, useClusterCache: false))->run($state, $out);
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
            // E2 / schema 1.8: the same tracked-not-null posture for the
            // trailing carriers — blocks_oversized (the fixtures hold no
            // oversized declarations, so this pins a tracked ZERO; a
            // future fixture that plants one moves the golden LOUD),
            // the once-S37 scan-signal trio (empty/false for these
            // cache-less drives) and ml_status (null: no ML url wired,
            // no client constructed — the honest absence).
            oversizedBlocks: $state->oversizedBlocks,
            scanNotes: $state->scanNotes,
            parseFailures: $state->parseFailures,
            clustersReplayed: $state->clustersReplayed,
            mlStatus: $state->mlConnectionStatus,
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
