<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\TimeseriesReporter;
use Symfony\Component\Console\Output\NullOutput;

final class TimeseriesReporterTest extends TestCase
{
    /** CI-sha env keys the reporter consults — neutralized for every test. */
    private const ENV_KEYS = ['GIT_COMMIT', 'GITHUB_SHA', 'CI_COMMIT_SHA', 'BUILD_VCS_NUMBER'];

    /** @var array<string,string|false> env values to restore in tearDown */
    private array $envBackup = [];

    /** @var list<string> scratch dirs (tmp git repos) to remove in tearDown */
    private array $tmpDirs = [];

    private int $dirSeq = 0;

    protected function setUp(): void
    {
        // File-based sha detection must not be shadowed by a real CI env
        // (GitHub Actions sets GITHUB_SHA) leaking into these pins.
        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->envBackup = [];
        foreach ($this->tmpDirs as $dir) {
            self::rrmdir($dir);
        }
        $this->tmpDirs = [];
    }

    public function testRecordContainsExpectedFields(): void
    {
        $reporter = new TimeseriesReporter(commitSha: 'abc123', timestamp: 1700000000);
        $record = $reporter->buildRecord(
            new Report(0, 0, 0, [], Config::defaults([])),
        );

        $this->assertSame('abc123', $record['commit_sha']);
        $this->assertSame(0, $record['files']);
        $this->assertSame(0, $record['clusters']);
        $this->assertSame([], $record['pattern_tags']);
        $this->assertArrayHasKey('timestamp', $record);
        $this->assertArrayHasKey('phpdup_version', $record);
    }

    // ── once-S101 (php-dup-finder-once-126): row-shape marker ───────────────

    public function testSchemaVersionMarkerLeadsTheRowAndKeySetIsDocumentedV2(): void
    {
        $reporter = new TimeseriesReporter(commitSha: 'abc123', timestamp: 1700000000);
        $record   = $reporter->buildRecord(new Report(0, 0, 0, [], Config::defaults([])));

        // Row contract v2: the marker leads, the v1 key set follows in its
        // historical order. The full ordered list is asserted on purpose —
        // any future row edit must reckon with this pin, which is the whole
        // point of versioning the row.
        $this->assertSame(
            ['schema_version', 'timestamp', 'commit_sha', 'phpdup_version', 'files',
             'blocks', 'parse_errors', 'clusters', 'duplicated_lines', 'total_impact', 'pattern_tags'],
            array_keys($record),
        );
        $this->assertSame('2', $record['schema_version']);

        // On the wire the marker is the object's first member — the cheap
        // probe a streaming consumer uses before decoding the rest.
        $this->assertStringStartsWith('{"schema_version":"2"', $reporter->buildLine($this->buildReport()));
    }

    public function testFixtureRecordCarriesTheMarkerToo(): void
    {
        // The pipeline-built record (fixture corpus, real clusters) carries
        // the identical key contract — the marker does not depend on the
        // synthetic empty-report shortcut above.
        $record = (new TimeseriesReporter(commitSha: 'test-sha', timestamp: 1700000000))
            ->buildRecord($this->buildReport());
        $this->assertSame('2', $record['schema_version']);
        $this->assertSame('test-sha', $record['commit_sha']);
        $this->assertGreaterThan(0, $record['clusters']);
    }

    public function testFixtureRecordHasNonZeroClusterCount(): void
    {
        $report = $this->buildReport();
        $reporter = new TimeseriesReporter(commitSha: 'test-sha', timestamp: 1700000000);
        $record = $reporter->buildRecord($report);

        $this->assertSame(count($report->clusters), $record['clusters']);
        $this->assertSame($report->files, $record['files']);
        $this->assertSame($report->blocks, $record['blocks']);
        $this->assertSame('test-sha', $record['commit_sha']);
    }

    public function testWriteToAppendsLine(): void
    {
        $reporter = new TimeseriesReporter(commitSha: 'sha1', timestamp: 1);
        $tmp = tempnam(sys_get_temp_dir(), 'phpdup-ts-');
        try {
            $report = new Report(0, 0, 0, [], Config::defaults([]));
            $reporter->writeTo($report, $tmp);
            $reporter2 = new TimeseriesReporter(commitSha: 'sha2', timestamp: 2);
            $reporter2->writeTo($report, $tmp);

            $contents = file_get_contents($tmp);
            $this->assertNotFalse($contents);
            $lines = array_values(array_filter(explode("\n", $contents)));
            $this->assertCount(2, $lines);

            $row1 = json_decode($lines[0], true);
            $row2 = json_decode($lines[1], true);
            $this->assertSame('sha1', $row1['commit_sha']);
            $this->assertSame('sha2', $row2['commit_sha']);
        } finally {
            @unlink($tmp);
        }
    }

    // ------------------------------------------------------------------
    // output-S05 — packed-refs sha, repo-root derivation, UTC stamps
    // ------------------------------------------------------------------

    public function testTimestampIsUtcWithExplicitZDesignator(): void
    {
        // The finding: date('c') emitted the HOST timezone, so the same run
        // stamped different offsets on different CI machines. Pin the exact
        // instant under a hostile non-UTC host timezone.
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $record = (new TimeseriesReporter(commitSha: 'abc', timestamp: 1700000000))
                ->buildRecord(new Report(0, 0, 0, [], Config::defaults([])));

            $this->assertSame('2023-11-14T22:13:20Z', $record['timestamp']);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testDefaultClockTimestampAlsoEndsInZ(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('US/Hawaii');
        try {
            $record = (new TimeseriesReporter(commitSha: 'abc'))
                ->buildRecord(new Report(0, 0, 0, [], Config::defaults([])));

            $this->assertStringEndsWith('Z', $record['timestamp']);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testPackedRefsResolvesSha(): void
    {
        // Common post-GC state (and what `git pack-refs --all` forces):
        // the branch exists ONLY in packed-refs — the pre-S05 reader
        // answered null there. An annotated tag makes packed-refs carry a
        // peeled '^' line the parser must skip.
        $repo = $this->makeRepo('packed');
        $sha  = $this->headSha($repo);
        $this->git($repo, ['tag', '-a', 'v1', '-m', 'release']);
        $this->git($repo, ['pack-refs', '--all']);
        $this->assertFileDoesNotExist($repo . '/.git/refs/heads/main');

        $record = $this->recordFor([$repo]);
        $this->assertSame($sha, $record['commit_sha']);
    }

    public function testForeignScanRootBeatsProcessCwd(): void
    {
        // The mislabel case: running inside repo A while ANALYZING repo B
        // (paths: ../other) must stamp B's sha, never A's.
        $repoA = $this->makeRepo('repo-a');
        $repoB = $this->makeRepo('repo-b');
        $shaA  = $this->headSha($repoA);
        $shaB  = $this->headSha($repoB);
        $this->assertNotSame($shaA, $shaB, 'distinct fixtures must have distinct shas');

        $previousCwd = getcwd();
        $this->assertNotFalse($previousCwd);
        chdir($repoA);
        try {
            $record = $this->recordFor([$repoB]);
        } finally {
            chdir($previousCwd);
        }

        $this->assertSame($shaB, $record['commit_sha']);
    }

    public function testSubdirectoryScanRootWalksUpToRepoRoot(): void
    {
        $repo = $this->makeRepo('nested');
        $this->assertNotFalse(mkdir($repo . '/sub'));
        file_put_contents($repo . '/sub/nested.php', "<?php\nfunction nested() { return 1; }\n");
        $this->git($repo, ['add', '--', 'sub/nested.php']);
        $this->git($repo, ['commit', '-q', '-m', 'nested-dir']);
        $sha = $this->headSha($repo);

        $record = $this->recordFor([$repo . '/sub']);
        $this->assertSame($sha, $record['commit_sha']);
    }

    public function testSingleFileScanRootResolvesThroughItsDirectory(): void
    {
        // 'analyze foo.php' roots are legal (FileScanner) — dirname of the
        // file must start the repo walk.
        $repo = $this->makeRepo('fileroot');
        $sha  = $this->headSha($repo);

        $record = $this->recordFor([$repo . '/dup1.php']);
        $this->assertSame($sha, $record['commit_sha']);
    }

    public function testDetachedHeadResolvesRawSha(): void
    {
        $repo = $this->makeRepo('detached');
        $sha  = $this->headSha($repo);
        $this->assertNotFalse(file_put_contents($repo . '/.git/HEAD', $sha . "\n"));

        $record = $this->recordFor([$repo]);
        $this->assertSame($sha, $record['commit_sha']);
    }

    public function testGitDirPointerFileIsFollowed(): void
    {
        // worktree/submodule layout: '.git' is a FILE naming the real git
        // dir, absolute or relative to the containing directory.
        $repo = $this->makeRepo('pointer');
        $sha  = $this->headSha($repo);

        $absolute = $this->scratchDir('pointer-abs');
        $this->assertNotFalse(file_put_contents($absolute . '/.git', 'gitdir: ' . $repo . '/.git' . "\n"));
        $this->assertSame($sha, $this->recordFor([$absolute])['commit_sha']);

        $relativeDir = $this->scratchDir('pointer-rel');
        $this->assertNotFalse(
            file_put_contents($relativeDir . '/.git', 'gitdir: ../' . basename($repo) . '/.git' . "\n"),
        );
        $this->assertSame($sha, $this->recordFor([$relativeDir])['commit_sha']);
    }

    public function testWorktreeCommondirResolvesSharedRefs(): void
    {
        // A real `git worktree`: HEAD lives in the admin dir, the branch ref
        // in the shared commondir — both must be consulted.
        $repo = $this->makeRepo('worktree');
        $sha  = $this->headSha($repo);
        $wt   = $this->scratchDir('wt');
        $this->git($repo, ['worktree', 'add', '-q', $wt, '-b', 'wt-x']);

        $record = $this->recordFor([$wt]);
        $this->assertSame($sha, $record['commit_sha']);
    }

    public function testCraftedNulHeadRefYieldsHonestNull(): void
    {
        // Fix-round-1 (LOW-1): control bytes in a HEAD-named ref must never
        // reach the loose-ref read — git's check-ref-format rejects them, so
        // screening them off loses no real sha, only the ValueError crash.
        $repo = $this->makeRepo('nul-head');
        $this->assertNotFalse(file_put_contents($repo . '/.git/HEAD', "ref: foo\0bar\n"));

        $record = $this->recordFor([$repo]);
        $this->assertNull($record['commit_sha']);
    }

    public function testCraftedNulGitDirPointerYieldsHonestNull(): void
    {
        // Same crash class through the pointer route: a '.git' file naming a
        // NUL-byte gitdir target degrades to "no repo found", never a fatal.
        $dir = $this->scratchDir('nul-pointer');
        $this->assertNotFalse(
            file_put_contents($dir . '/.git', 'gitdir: ' . $dir . '/.real' . chr(0) . 'x' . "\n"),
        );

        $record = $this->recordFor([$dir]);
        $this->assertNull($record['commit_sha']);
    }

    public function testCraftedNulCommondirDegradesToGitDirAlone(): void
    {
        // A crafted commondir (unlike trim()ed surrounding bytes, an EMBEDDED
        // NUL survives) must only drop the shared-dir search: the repo's own
        // loose refs still resolve, and realpath() never throws. The sha is
        // read BEFORE poisoning the file — live git cannot serve rev-parse
        // through a garbage commondir.
        $repo = $this->makeRepo('nul-commondir');
        $sha  = $this->headSha($repo);
        $this->assertNotFalse(file_put_contents($repo . '/.git/commondir', "../shared\0x\n"));

        $record = $this->recordFor([$repo]);
        $this->assertSame($sha, $record['commit_sha']);
    }

    public function testRepoRootOverrideResolvesWithoutScanPaths(): void
    {
        // Constructor escape hatch for producers with no usable Config
        // paths (library callers, future ReportStage wiring).
        $repo = $this->makeRepo('override');
        $sha  = $this->headSha($repo);

        $record = (new TimeseriesReporter(repoRoot: realpath($repo) ?: $repo))
            ->buildRecord(new Report(0, 0, 0, [], Config::defaults([])));
        $this->assertSame($sha, $record['commit_sha']);
    }

    public function testNonRepositoryScanRootStaysNull(): void
    {
        $dir = $this->scratchDir('nonrepo');
        // If the tmp parentage ever becomes a repo, the null expectation is
        // the environment's fault, not the code's.
        for ($walk = (string) realpath($dir);;) {
            if (file_exists($walk . '/.git')) {
                self::markTestSkipped('scratch tree sits inside a git repository: ' . $walk);
            }
            $parent = dirname($walk);
            if ($parent === $walk) {
                break;
            }
            $walk = $parent;
        }

        $record = $this->recordFor([$dir]);
        $this->assertNull($record['commit_sha']);
    }

    public function testEnvShaWinsOverFileDetection(): void
    {
        $repo = $this->makeRepo('envwin');
        putenv('GIT_COMMIT=0123456789abcdef0123456789abcdef01234567');

        $record = $this->recordFor([$repo]);
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', $record['commit_sha']);
    }

    // ------------------------------------------------------------------
    // once-S38 (once-042) — raw non-UTF-8 bytes degrade to U+FFFD,
    // never a falsy encode / blank line in the append-only JSONL
    // ------------------------------------------------------------------

    public function testRawLatin1BytesInRecordEncodeToStrictJson(): void
    {
        // The bug: a plain json_encode returns false on any invalid
        // UTF-8 byte; handed to a string parameter under strict_types
        // that was an uncaught TypeError (exit 255 mid-report). Realistic
        // carriers: a CI env sha, a crafted ref file, or pattern tags
        // that absorbed raw path bytes. The fix ships every writer
        // through Util/Json (JSON_INVALID_UTF8_SUBSTITUTE), so hostile
        // bytes become U+FFFD and the record still encodes.
        $hostile = "dead\xE9beef\xFF";
        $line    = (new TimeseriesReporter(commitSha: $hostile, timestamp: 1700000000))
            ->buildLine(new Report(0, 0, 0, [], Config::defaults([])));

        $this->assertNotSame('', $line, 'a produced line is never the silent empty string');
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        // U+FFFD is the substitution for each of the two invalid bytes.
        $this->assertSame('dead' . "\u{FFFD}" . 'beef' . "\u{FFFD}", $decoded['commit_sha']);
    }

    public function testRawBytesFromEnvShaAppendNoBlankLine(): void
    {
        // Second carrier route: GIT_COMMIT itself smuggles invalid bytes.
        // writeTo is FILE_APPEND — the pre-fix `(string) json_encode()`
        // collapse wrote a BLANK row into the series instead of failing,
        // poisoning every downstream line-oriented reader.
        putenv("GIT_COMMIT=abc\xE9def\xFF");
        $dir    = $this->scratchDir('latin1-env');
        $file   = $dir . '/metrics.jsonl';
        $report = new Report(1, 2, 0, [], Config::defaults([$dir]));

        (new TimeseriesReporter(commitSha: 'clean-sha', timestamp: 1))->writeTo($report, $file);
        (new TimeseriesReporter(timestamp: 2))->writeTo($report, $file);

        $contents = (string) file_get_contents($file);
        $rows     = explode("\n", rtrim($contents, "\n"));
        $this->assertCount(2, $rows, 'the hostile run must append a full row, not a blank line');
        foreach ($rows as $i => $row) {
            $this->assertNotSame('', $row, "row {$i} of the append-only JSONL is blank");
            $decoded = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            if ($i === 1) {
                $this->assertStringContainsString("\u{FFFD}", (string) $decoded['commit_sha']);
            }
        }
    }

    public function testUnencodablePayloadFailsLoudBeforeAnyWrite(): void
    {
        // Full-failure honest arm of the contract: line production is
        // fully evaluated BEFORE file_put_contents, so any encode-class
        // failure throws out of writeTo and the append-only file keeps
        // byte-for-byte the rows it already had. The unsound patternTag
        // (an object is no legal tag string, and — unlike scalars — is no
        // legal array offset either) stands in for "the record could not
        // be produced": reachable type confusion, same seam.
        $dir  = $this->scratchDir('encode-fail');
        $file = $dir . '/metrics.jsonl';

        $good = new Report(0, 0, 0, [], Config::defaults([$dir]));
        (new TimeseriesReporter(commitSha: 'first', timestamp: 1))->writeTo($good, $file);
        $before = (string) file_get_contents($file);

        $cluster = new Cluster('F0000000000000000', [], 1.0, true);
        $cluster->patternTags = [new \stdClass()];
        $bad = new Report(1, 1, 0, [$cluster], Config::defaults([$dir]));

        $this->expectException(\TypeError::class);
        try {
            (new TimeseriesReporter(timestamp: 2))->writeTo($bad, $file);
        } finally {
            $this->assertSame(
                $before,
                (string) file_get_contents($file),
                'a failed line must write nothing — not even a newline',
            );
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function buildReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: true))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
    }

    /**
     * @param list<string> $scanPaths
     * @return array<string,mixed>
     */
    private function recordFor(array $scanPaths): array
    {
        return (new TimeseriesReporter())->buildRecord(
            new Report(0, 0, 0, [], Config::defaults($scanPaths)),
        );
    }

    private function makeRepo(string $message): string
    {
        $dir = $this->scratchDir('repo');
        $this->git($dir, ['init', '-q', '-b', 'main']);
        file_put_contents($dir . '/dup1.php', "<?php\nfunction dup1() { return 1; }\n");
        $this->git($dir, ['add', '--', 'dup1.php']);
        $this->git($dir, ['commit', '-q', '-m', $message]);

        return $dir;
    }

    private function headSha(string $repo): string
    {
        $out = $this->git($repo, ['rev-parse', 'HEAD']);
        $this->assertNotEmpty($out);

        return trim($out[0]);
    }

    /** @param list<string> $args git subcommand argv (run inside $dir) @return list<string> */
    private function git(string $dir, array $args): array
    {
        $argv = ['git', '-C', $dir,
            '-c', 'user.email=test@phpdup.invalid',
            '-c', 'user.name=phpdup-test',
            '-c', 'commit.gpgsign=false',
            '-c', 'core.hooksPath=/dev/null',
            ...$args,
        ];
        $cmd  = implode(' ', array_map('escapeshellarg', $argv));
        $out  = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, 'git ' . implode(' ', $args) . ' failed: ' . implode("\n", $out));

        return $out;
    }

    private function scratchDir(string $tag): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-ts05-' . $tag . '-' . (++$this->dirSeq) . '-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($dir)) {
            $this->assertNotFalse(mkdir($dir, 0777, true));
        }
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
