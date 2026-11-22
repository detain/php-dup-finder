<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Util\Json;

/**
 * Append-only JSONL: one summary row per analysis run.
 *
 * Designed to be fed into a time-series store (Elasticsearch, ClickHouse,
 * BigQuery, …) so a project can track its duplicate-debt curve over
 * commits / time. The file is append-only, so multiple CI runs can
 * write to the same path without trampling each other.
 *
 * Each row carries the run's identity (UTC ISO-8601 timestamp — never
 * the host's local offset, so rows join across machines — and the commit
 * sha from an explicit value, a CI env override, or HEAD resolution in
 * the *scanned* repository, packed refs included), the corpus shape
 * (files / blocks / parse errors), and the top-line cluster aggregates
 * (count, duplicated lines, total impact, per-tag counters).
 */
final class TimeseriesReporter
{
    use WritesReportFile;

    /**
     * CI environment keys whose value, when non-empty, stands in for the
     * commit sha. First hit wins, in this order.
     */
    private const ENV_SHA_KEYS = ['GIT_COMMIT', 'GITHUB_SHA', 'CI_COMMIT_SHA', 'BUILD_VCS_NUMBER'];

    public function __construct(
        private readonly ?string $commitSha = null,
        private readonly ?int $timestamp = null,
        // output-S05: repository root for sha detection. When null the root is
        // derived from the report's first scan path — never from the process
        // cwd, which mislabeled rows when analyzing a repo elsewhere ("../other").
        private readonly ?string $repoRoot = null,
    ) {
    }

    public function writeTo(Report $report, string $file): void
    {
        $this->ensureDir($file);
        file_put_contents($file, $this->buildLine($report) . "\n", FILE_APPEND | LOCK_EX);
    }

    public function buildLine(Report $report): string
    {
        return Json::encode($this->buildRecord($report), JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    public function buildRecord(Report $report): array
    {
        $totalImpact = 0;
        $tagCounts   = [];
        foreach ($report->clusters as $c) {
            $totalImpact += $c->impact;
            foreach ($c->patternTags as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }
        ksort($tagCounts);

        return [
            'timestamp'        => self::formatUtc($this->timestamp ?? time()),
            'commit_sha'       => $this->commitSha ?? $this->detectCommitSha($report),
            'phpdup_version'   => '0.1.0',
            'files'            => $report->files,
            'blocks'           => $report->blocks,
            'parse_errors'     => $report->parseErrors,
            'clusters'         => count($report->clusters),
            'duplicated_lines' => $report->totalDuplicatedLines(),
            'total_impact'     => $totalImpact,
            'pattern_tags'     => $tagCounts,
        ];
    }

    /**
     * ISO-8601 UTC with the explicit 'Z' designator (output-S05). The old
     * date('c', …) emitted the HOST's timezone offset, so the same run
     * stamped different instants on different CI machines and series joins
     * lost cross-machine determinism.
     */
    private static function formatUtc(int $epoch): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $epoch);
    }

    /**
     * Resolve the run's commit sha without shelling out to git, so the
     * reporter stays usable inside sandboxed CI runners. Precedence:
     * CI env override → HEAD of the repository containing the scan root
     * (a detached HEAD carries the sha itself; a symbolic HEAD resolves
     * through the loose ref file, then packed-refs — the post-`git
     * pack-refs`/auto-GC case the pre-S05 reader answered with null).
     */
    private function detectCommitSha(Report $report): ?string
    {
        foreach (self::ENV_SHA_KEYS as $key) {
            $env = getenv($key);
            if (is_string($env) && $env !== '') {
                return $env;
            }
        }

        $gitDir = $this->resolveGitDir($report);
        if ($gitDir === null) {
            return null;
        }

        $head = @file_get_contents($gitDir . '/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if ($head === '') {
            return null;
        }
        if (str_starts_with($head, 'ref: ')) {
            return $this->resolveRef($gitDir, trim(substr($head, 5)));
        }
        return $head;
    }

    /**
     * Locate the git dir of the repository being analyzed: explicit
     * repoRoot → first scan path (a single-file root resolves through its
     * containing directory — file roots are legal scan inputs) → process
     * cwd as the last resort, then walk up to the filesystem root. A
     * `.git` FILE is a gitdir pointer (worktree/submodule layout) and is
     * followed to the directory it names.
     */
    private function resolveGitDir(Report $report): ?string
    {
        $base = $this->repoRoot ?? self::firstScanPath($report) ?? getcwd();
        if (!is_string($base) || $base === '') {
            return null;
        }
        $dir = realpath($base);
        if ($dir === false) {
            return null;
        }

        for (;;) {
            $git = $dir . '/.git';
            if (is_dir($git)) {
                return $git;
            }
            if (is_file($git)) {
                return self::readGitDirPointer($git, $dir);
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /** First non-empty scan path, as a directory to start the repo walk from. */
    private static function firstScanPath(Report $report): ?string
    {
        foreach ($report->config->paths as $path) {
            if ($path === '') {
                continue;
            }
            return is_dir($path) ? $path : dirname($path);
        }
        return null;
    }

    /**
     * Follow a `.git` pointer file: `gitdir: <path>`, absolute or relative
     * to the directory that holds the pointer.
     */
    private static function readGitDirPointer(string $pointerFile, string $container): ?string
    {
        $contents = @file_get_contents($pointerFile);
        if ($contents === false) {
            return null;
        }
        $line = trim(strtok($contents, "\n") ?: '');
        if (!str_starts_with($line, 'gitdir: ')) {
            return null;
        }
        $target = trim(substr($line, strlen('gitdir: ')));
        if ($target === '') {
            return null;
        }
        $resolved = realpath($target[0] === '/' ? $target : $container . '/' . $target);
        return $resolved !== false && is_dir($resolved) ? $resolved : null;
    }

    /**
     * Resolve a ref name to its sha: loose ref file first, then packed-refs.
     * Both the git dir and its `commondir` (worktree admin dirs keep HEAD
     * locally but share refs with the main repository) are consulted.
     */
    private function resolveRef(string $gitDir, string $ref): ?string
    {
        // A crafted HEAD file must not steer reads outside the git dir.
        if ($ref === '' || $ref[0] === '/' || str_contains($ref, '..')) {
            return null;
        }

        foreach (self::refSearchDirs($gitDir) as $dir) {
            $loose = @file_get_contents($dir . '/' . $ref);
            if (is_string($loose)) {
                $sha = trim($loose);
                if ($sha !== '') {
                    return $sha;
                }
            }

            $packed = @file_get_contents($dir . '/packed-refs');
            if (!is_string($packed)) {
                continue;
            }
            foreach (explode("\n", $packed) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                    continue; // blank, header comment, or peeled-tag suffix
                }
                [$sha, $name] = array_pad(explode(' ', $line, 2), 2, '');
                if ($sha !== '' && $name === $ref) {
                    return $sha;
                }
            }
        }
        return null;
    }

    /** @return list<string> the git dir, plus its shared commondir when this is a worktree admin dir */
    private static function refSearchDirs(string $gitDir): array
    {
        $dirs   = [$gitDir];
        $common = @file_get_contents($gitDir . '/commondir');
        if (is_string($common)) {
            $target = trim($common);
            if ($target !== '') {
                $resolved = realpath($gitDir . '/' . $target);
                if ($resolved !== false && is_dir($resolved)) {
                    $dirs[] = $resolved;
                }
            }
        }
        return $dirs;
    }
}
