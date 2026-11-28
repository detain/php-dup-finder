<?php
declare(strict_types=1);

namespace Phpdup\Git;

use Phpdup\Architecture\Finding;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;

/**
 * Item 4.2 — correlate clone clusters against git history.
 *
 * The doc's exact pattern: "One twin was fixed in March, the other was
 * last touched two years ago" is the shape behind all 14 phlix bugs.
 * Opt-in (`history: true` / `--history`), default OFF, and it fails OPEN
 * everywhere: no git binary, no repository, untracked files, corrupt
 * blame streams, timed-out commands — each degrades to an honest
 * 'unmeasurable' row plus one aggregated note, never a fatal, never an
 * exit-code change.
 *
 * Per candidate cluster (>= 2 distinct member files) it answers the
 * doc's four questions:
 *  1. last-changed date per member        (one `blame --porcelain` per file, cached)
 *  2. members changed ALONE in the window (blame commits cross-checked against
 *     ONE `git log -M --name-only --since` per repo, the doc's design)
 *  3. whether a lone commit message matches fix|security|bug
 *  4. how often members changed in the same commit (co-change counts)
 *
 * Rename awareness comes from two legs: `log -M` lists a rename commit
 * under its new path, and blame's `previous <sha> <path>` boundary
 * headers hand us the old names, so a touch recorded under an ancestor
 * name still counts for the file that inherited it.
 *
 * Verdicts: recent_lone_fix > recently_diverged > in_sync > long_drifted,
 * with unmeasurable whenever the history cannot honestly answer. The
 * fix|security|bug pattern raises a WARNING finding on the cluster
 * (history-lone-fix); plain divergence raises a note — riding the
 * existing architecturalFindings path (the DeclaredTwin precedent). Where
 * the cluster already carries a DriftAnalyzer (item 4.1) finding, the
 * message says so: a lone fix corroborating structural drift is the
 * strongest single signal this tool can emit.
 */
final class ClusterHistoryAnalyzer
{
    public const VERDICT_RECENT_LONE_FIX   = 'recent_lone_fix';
    public const VERDICT_RECENTLY_DIVERGED = 'recently_diverged';
    public const VERDICT_IN_SYNC           = 'in_sync';
    public const VERDICT_LONG_DRIFTED      = 'long_drifted';
    public const VERDICT_UNMEASURABLE      = 'unmeasurable';

    /** Machine-checkable lone-fix detector from the doc's design section. */
    public const FIX_PATTERN = '/fix|security|bug/i';

    /** Presentation caps — the table is rendered, history is unbounded. */
    public const MAX_ROWS     = 100;
    public const MAX_COCHANGE = 16;
    public const SHA_ABBREV   = 12;

    private readonly HistoryCache $cache;
    private readonly int $windowMonths;
    private readonly int $cutoffTime;

    /** @var array<string, array{root: string, head: string}|null> abs dir -> repo resolution */
    private array $repoByDir = [];

    /** @var array<string, BlameIndex|null> abs file -> parsed blame (null = unreadable) */
    private array $blameByFile = [];

    /** @var array<string, string> abs file -> unmeasurable bucket */
    private array $issueBuckets = [];

    /** @var bool|null git binary availability, probed once */
    private ?bool $gitAvailable = null;

    /** @var array<string, list<array{sha: string, time: int, subject: string, files: list<string>}>|null> root -> log window (null = leg unavailable) */
    private array $logByRepo = [];

    /** @var array<string, string> root -> HEAD sha */
    private array $headByRoot = [];

    /** @var array<string, array{verdict: string, reason: string|null, members: list<array{file: string, last: array{sha: string, time: int, msg: string}|null, lone: array{sha: string, time: int, subject: string, fix: bool}|null}>, coChange: list<array{a: string, b: string, times: int}>, activity: bool}> cluster id -> facts */
    private array $clusterFacts = [];

    public function __construct(Config $config)
    {
        $this->cache         = new HistoryCache($config->cacheDir);
        $this->windowMonths  = max(1, $config->historyWindowMonths);
        // Snap the window to a UTC day boundary so the cache key (and the
        // --since argument) is stable for the whole calendar day.
        $this->cutoffTime = (int)strtotime('midnight', strtotime('-' . $this->windowMonths . ' months'));
    }

    /**
     * Correlate the given (post-baseline-split) clusters. Read-only over
     * the clusters; results land in rows plus per-cluster facts that
     * annotateClusters() consumes.
     *
     * @param list<Cluster> $clusters
     */
    public function correlate(array $clusters): ClusterHistory
    {
        $candidates = [];
        foreach ($clusters as $cluster) {
            if (count($this->distinctFiles($cluster)) >= 2) {
                $candidates[] = $cluster;
            }
        }

        if ($candidates !== []) {
            $this->probeGit();
        }

        $rows = [];
        foreach ($candidates as $cluster) {
            $facts                      = $this->analyzeCluster($cluster);
            $this->clusterFacts[$cluster->id] = $facts;
            $rows[]                     = $this->buildRow($cluster, $facts);
        }

        $omitted = 0;
        if (count($rows) > self::MAX_ROWS) {
            $omitted = count($rows) - self::MAX_ROWS;
            $rows    = array_slice($rows, 0, self::MAX_ROWS);
        }

        return new ClusterHistory(
            rows:         $rows,
            windowMonths: $this->windowMonths,
            omitted:      $omitted,
            notes:        $this->notes(),
        );
    }

    /**
     * Raise the doc's findings on clusters whose verdict says a twin was
     * fixed alone or diverged. DeclaredTwin precedent: findings append to
     * the cluster's architectural list and flow through every renderer.
     *
     * @param list<Cluster> $clusters
     */
    public function annotateClusters(array $clusters): void
    {
        if ($this->clusterFacts === []) {
            throw new \LogicException('annotateClusters() called before correlate() — no verdicts exist yet');
        }
        foreach ($clusters as $cluster) {
            $facts = $this->clusterFacts[$cluster->id] ?? null;
            if ($facts === null) {
                continue;
            }
            if ($facts['verdict'] === self::VERDICT_RECENT_LONE_FIX) {
                $lone = $this->fixLoneOf($facts);
                if ($lone === null) {
                    continue;
                }
                $others = [];
                foreach ($facts['members'] as $member) {
                    if ($member['file'] === $lone['file']) {
                        continue;
                    }
                    $others[] = $member['file'] . ' ' . $this->describeLast($member['last']);
                }
                $message = sprintf(
                    '%s was changed alone in %s (%s, "%s") while %s — the fix-one-twin pattern behind classic clone drift',
                    $lone['file'],
                    substr($lone['lone']['sha'], 0, self::SHA_ABBREV),
                    $this->formatDate($lone['lone']['time']),
                    $lone['lone']['subject'],
                    $others === [] ? 'its twins have no dated history' : implode(', while ', array_slice($others, 0, 3)),
                );
                if ($this->hasDriftFinding($cluster)) {
                    $message .= '; corroborated by structural drift analysis (item 4.1)';
                }
                $cluster->architecturalFindings[] = new Finding(
                    analyzer: 'ClusterHistoryAnalyzer',
                    code: 'history-lone-fix',
                    message: $message,
                    severity: Finding::SEVERITY_WARNING,
                    suggestion: 'Port the lone fix to the remaining clone members, or extract the shared logic so the drift cannot recur.',
                );
            } elseif ($facts['verdict'] === self::VERDICT_RECENTLY_DIVERGED) {
                $lone = $this->anyLoneOf($facts);
                if ($lone === null) {
                    continue;
                }
                $cluster->architecturalFindings[] = new Finding(
                    analyzer: 'ClusterHistoryAnalyzer',
                    code: 'history-divergence',
                    message: sprintf(
                        '%s diverged in the last %d months (alone in %s, "%s") while its clone twins moved separately',
                        $lone['file'],
                        $this->windowMonths,
                        substr($lone['lone']['sha'], 0, self::SHA_ABBREV),
                        $lone['lone']['subject'],
                    ),
                    severity: Finding::SEVERITY_NOTE,
                    suggestion: 'Review whether the lone change should apply to the whole cluster.',
                );
            }
        }
    }

    /**
     * Aggregated posture notes — the skip-with-note family (scanNotes).
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];
        if ($this->gitAvailable === false) {
            $notes[] = 'history: the git binary is unavailable — every cluster reports unmeasurable';
            return $notes;
        }
        $counts = [
            'no_repository'        => 0,
            'untracked_files'      => 0,
            'git_timeout'          => 0,
            'history_unparseable'  => 0,
            'cross_repository_cluster' => 0,
            'unresolvable_files'   => 0,
        ];
        // Cluster-scoped honesty: count what the verdicts actually answer.
        // (issueBuckets stays a per-file diagnostic, never a note count.)
        foreach ($this->clusterFacts as $facts) {
            if ($facts['verdict'] !== self::VERDICT_UNMEASURABLE || !is_string($facts['reason'])) {
                continue;
            }
            if (isset($counts[$facts['reason']])) {
                $counts[$facts['reason']]++;
            }
        }
        if ($counts['no_repository'] > 0) {
            $notes[] = sprintf('history: %d clusters have members outside any git repository — those clusters report unmeasurable', $counts['no_repository']);
        }
        if ($counts['untracked_files'] > 0) {
            $notes[] = sprintf('history: %d clusters have untracked members — those clusters report unmeasurable', $counts['untracked_files']);
        }
        if ($counts['git_timeout'] > 0) {
            $notes[] = sprintf('history: %d clusters hit a git deadline (15s) — those clusters report unmeasurable', $counts['git_timeout']);
        }
        if ($counts['history_unparseable'] > 0) {
            $notes[] = sprintf('history: %d clusters returned unreadable blame — those clusters report unmeasurable', $counts['history_unparseable']);
        }
        if ($counts['cross_repository_cluster'] > 0) {
            $notes[] = sprintf('history: %d clusters mix members from different repositories — those clusters report unmeasurable', $counts['cross_repository_cluster']);
        }
        if ($counts['unresolvable_files'] > 0) {
            $notes[] = sprintf('history: %d clusters could not resolve two distinct member files — those clusters report unmeasurable', $counts['unresolvable_files']);
        }
        return $notes;
    }

    // ------------------------------------------------------------------
    // fact collection
    // ------------------------------------------------------------------

    /**
     * @return array{verdict: string, reason: string|null, members: list<array{file: string, last: array{sha: string, time: int, msg: string}|null, lone: array{sha: string, time: int, subject: string, fix: bool}|null}>, coChange: list<array{a: string, b: string, times: int}>, activity: bool}
     */
    private function analyzeCluster(Cluster $cluster): array
    {
        /** @var array<string, list<array{start: int, end: int}>> $spansByFile */
        $spansByFile = [];
        foreach ($cluster->members as $member) {
            $abs = realpath($member->file);
            if ($abs !== false && is_file($abs)) {
                $spansByFile[$abs][] = ['start' => $member->range->start, 'end' => $member->range->end];
            }
        }
        if (count($spansByFile) < 2) {
            return $this->unmeasurable('unresolvable_files');
        }

        /** @var array<string, string> $relByFile */
        $relByFile   = [];
        /** @var array<string, list<string>> $aliasByRel */
        $aliasByRel  = [];
        $root        = null;
        foreach (array_keys($spansByFile) as $abs) {
            $repo = $this->resolveRepo($abs);
            if ($repo === null) {
                return $this->unmeasurable($this->issueBuckets[$abs] ?? 'no_repository');
            }
            if ($root !== null && $repo['root'] !== $root) {
                return $this->unmeasurable('cross_repository_cluster');
            }
            $root = $repo['root'];
            $blame = $this->blameFor($abs);
            if ($blame === null) {
                return $this->unmeasurable($this->issueBuckets[$abs] ?? 'history_unparseable');
            }
            $rel             = $this->relOf($repo['root'], $abs);
            $relByFile[$abs] = $rel;
            $aliasByRel[$rel] = $this->collectAliases($rel, $blame);
        }

        $log     = $this->logFor((string)$root);
        $members = [];
        /** @var list<int|null> $memberTimes */
        $memberTimes = [];
        foreach ($spansByFile as $abs => $spans) {
            $blame = $this->blameFor($abs);
            $rel   = $relByFile[$abs];
            $last  = null;
            $shas  = [];
            if ($blame !== null) {
                foreach ($spans as $span) {
                    $touch = $blame->lastTouch($span['start'], $span['end']);
                    if ($touch !== null && ($last === null || $touch['time'] > $last['time'])) {
                        $last = $touch;
                    }
                    foreach ($blame->commitsInRange($span['start'], $span['end']) as $sha) {
                        $shas[$sha] = true;
                    }
                }
            }
            $members[] = [
                'file' => $rel,
                'last' => $last,
                'lone' => $this->loneCommit($log, $rel, $aliasByRel, $shas),
            ];
            $memberTimes[] = $last['time'] ?? null;
        }

        $activity = $log === null ? $this->blameActivity($memberTimes) : $this->logActivity($log, $aliasByRel);

        $facts = [
            'members'  => $members,
            'activity' => $activity,
        ];
        $classified = self::classify($facts, $log !== null, $memberTimes, $this->cutoffTime);
        return array_merge($facts, $classified, [
            'coChange' => $log === null ? [] : $this->coChange($log, $aliasByRel),
        ]);
    }

    /**
     * Pure verdict law (unit-testable without git): given collected
     * facts, which verdict does the doc's taxonomy choose?
     *
     * Degraded mode (log leg unavailable) loses the lone-ness proof but
     * keeps the dates: twins whose LAST committed change straddles the
     * window edge still diverge honestly, and "recent without a lone
     * claim" is reported as in_sync — absence of divergence evidence,
     * never a fabricated divergence. recent_lone_fix is unreachable
     * without the log leg, which is exactly right: the fix word lives in
     * commit subjects, lone-ness in file sets.
     *
     * @param array{members: list<array{file: string, last: array{sha: string, time: int, msg: string}|null, lone: mixed}>, activity: bool} $facts
     * @param list<int|null> $memberTimes
     * @return array{verdict: string, reason: string|null}
     */
    public static function classify(array $facts, bool $logAvailable, array $memberTimes, int $cutoffTime): array
    {
        foreach ($facts['members'] as $member) {
            if ($member['last'] === null) {
                return ['verdict' => self::VERDICT_UNMEASURABLE, 'reason' => 'uncommitted_member'];
            }
        }
        if (!$facts['activity']) {
            return ['verdict' => self::VERDICT_LONG_DRIFTED, 'reason' => null];
        }
        $anyLone = false;
        foreach ($facts['members'] as $member) {
            $lone = $member['lone'];
            if (!is_array($lone)) {
                continue;
            }
            $anyLone = true;
            if ($lone['fix'] === true) {
                return ['verdict' => self::VERDICT_RECENT_LONE_FIX, 'reason' => null];
            }
        }
        if ($anyLone) {
            return ['verdict' => self::VERDICT_RECENTLY_DIVERGED, 'reason' => null];
        }
        if (!$logAvailable) {
            $recent = 0;
            foreach ($memberTimes as $time) {
                if ($time !== null && $time >= $cutoffTime) {
                    $recent++;
                }
            }
            if ($recent > 0 && $recent < count($memberTimes)) {
                // Dates alone straddle the window edge: twins moved apart.
                return ['verdict' => self::VERDICT_RECENTLY_DIVERGED, 'reason' => 'dates_only_divergence'];
            }
            return ['verdict' => self::VERDICT_IN_SYNC, 'reason' => null];
        }
        return ['verdict' => self::VERDICT_IN_SYNC, 'reason' => null];
    }

    /**
     * @return array{verdict: string, reason: string|null, members: list<array{file: string, last: array{sha: string, time: int, msg: string}|null, lone: array{sha: string, time: int, subject: string, fix: bool}|null}>, coChange: list<array{a: string, b: string, times: int}>, activity: bool}
     */
    private function unmeasurable(string $reason): array
    {
        return [
            'verdict'   => self::VERDICT_UNMEASURABLE,
            'reason'    => $reason,
            'members'   => [],
            'coChange'  => [],
            'activity'  => false,
        ];
    }

    // ------------------------------------------------------------------
    // git legs (each cached, each fail-open)
    // ------------------------------------------------------------------

    private function probeGit(): void
    {
        if ($this->gitAvailable !== null) {
            return;
        }
        [, , $code] = GitCli::run(['git', '--version']);
        $this->gitAvailable = $code === 0;
    }

    /**
     * @return array{root: string, head: string}|null
     */
    private function resolveRepo(string $absFile): ?array
    {
        $dir = dirname($absFile);
        if (array_key_exists($dir, $this->repoByDir)) {
            return $this->repoByDir[$dir];
        }
        $this->probeGit();
        $resolved = null;
        if ($this->gitAvailable === true) {
            $root = GitCli::findRepositoryRoot($dir);
            if ($root === null) {
                $this->issueBuckets[$absFile] = 'no_repository';
            } else {
                $head = $this->headByRoot[$root] ?? GitCli::headSha($root);
                if ($head === null) {
                    $this->issueBuckets[$absFile] = 'no_repository';
                } else {
                    $this->headByRoot[$root]      = $head;
                    $this->repoByDir[$dir]        = ['root' => $root, 'head' => $head];
                    return $this->repoByDir[$dir];
                }
            }
        } else {
            $this->issueBuckets[$absFile] = 'git_unavailable';
        }
        $this->repoByDir[$dir] = null;
        return $resolved;
    }

    /** Parsed blame for one absolute file, cache-backed; null = unreadable. */
    private function blameFor(string $absFile): ?BlameIndex
    {
        if (array_key_exists($absFile, $this->blameByFile)) {
            return $this->blameByFile[$absFile];
        }
        $result = null;
        $repo   = $this->resolveRepo($absFile);
        if ($repo !== null) {
            $rel    = $this->relOf($repo['root'], $absFile);
            $key    = $this->cache->blameKey($repo['head'], $absFile);
            $cached = $this->cache->loadBlame($key, $repo['head'], $rel);
            if ($cached !== null) {
                $result = $cached;
            } else {
                [$stdout, , $code] = GitCli::run([
                    'git', '-C', $repo['root'], 'blame', '--porcelain', '--', $rel,
                ]);
                if ($code === 0) {
                    $parsed = BlameIndex::parse($stdout);
                    if ($parsed !== null) {
                        $result = $parsed;
                        $this->cache->storeBlame($key, $repo['head'], $rel, $parsed->lineSha, $parsed->commits, $parsed->previousPaths);
                    } else {
                        $this->issueBuckets[$absFile] = 'history_unparseable';
                    }
                } elseif ($code === GitCli::CODE_TIMEOUT) {
                    $this->issueBuckets[$absFile] = 'git_timeout';
                } else {
                    $this->issueBuckets[$absFile] = 'untracked_files';
                }
            }
        }
        $this->blameByFile[$absFile] = $result;
        return $result;
    }

    /**
     * The ONE `git log -M --name-only --since` per repo, cache-backed.
     * null = log leg unavailable; callers degrade to dates-only law.
     *
     * @return list<array{sha: string, time: int, subject: string, files: list<string>}>|null
     */
    private function logFor(string $root): ?array
    {
        if (array_key_exists($root, $this->logByRepo)) {
            return $this->logByRepo[$root];
        }
        $records = null;
        $head    = $this->headByRoot[$root] ?? GitCli::headSha($root);
        if ($head !== null) {
            $this->headByRoot[$root] = $head;
            $key                     = $this->cache->logKey($head, $this->cutoffTime);
            $cached                  = $this->cache->loadLog($key, $head, $this->cutoffTime);
            if ($cached !== null) {
                $records = $cached;
            } else {
                [$stdout, , $code] = GitCli::run([
                    'git', '-C', $root, 'log', '-M', '--name-only',
                    '--since=@' . $this->cutoffTime,
                    '--format=%x1e%H %ct %s',
                    '--',
                ]);
                if ($code === 0) {
                    $records = $this->parseLog($stdout);
                    $this->cache->storeLog($key, $head, $this->cutoffTime, $records);
                } elseif ($code !== GitCli::CODE_TIMEOUT) {
                    // e.g. a repository without commits: a real zero answer.
                    $records = [];
                }
            }
        }
        $this->logByRepo[$root] = $records;
        return $records;
    }

    /** @return list<array{sha: string, time: int, subject: string, files: list<string>}> */
    private function parseLog(string $stdout): array
    {
        $records = [];
        foreach (explode("\x1e", $stdout) as $chunk) {
            $chunk = trim($chunk, "\n");
            if ($chunk === '') {
                continue;
            }
            $lines  = explode("\n", $chunk);
            $header = array_shift($lines);
            if (is_string($header) && preg_match('/^([0-9a-f]{40}) (\d+) (.*)$/', $header, $m) === 1) {
                $files = [];
                foreach ($lines as $file) {
                    $file = trim($file);
                    if ($file !== '') {
                        $files[] = $file;
                    }
                }
                $records[] = [
                    'sha'     => $m[1],
                    'time'    => (int)$m[2],
                    'subject' => $m[3],
                    'files'   => $files,
                ];
            }
        }
        return $records;
    }

    // ------------------------------------------------------------------
    // cluster-level computations
    // ------------------------------------------------------------------

    /**
     * The lone commit for one member file: the latest blame commit of
     * its lines that sits inside the window AND touched no other cluster
     * file (rename-aliased). null when no commit answers that description.
     *
     * @param list<array{sha: string, time: int, subject: string, files: list<string>}>|null $log
     * @param array<string, list<string>> $aliasByRel
     * @param array<string, true> $blameShas
     * @return array{sha: string, time: int, subject: string, fix: bool}|null
     */
    private function loneCommit(?array $log, string $rel, array $aliasByRel, array $blameShas): ?array
    {
        if ($log === null || $blameShas === []) {
            return null;
        }
        $bySha = [];
        foreach ($log as $record) {
            $bySha[$record['sha']] = $record;
        }
        $best = null;
        foreach (array_keys($blameShas) as $sha) {
            $record = $bySha[$sha] ?? null;
            if ($record === null || $record['time'] < $this->cutoffTime) {
                continue; // outside the window or beyond log reach: no lone claim
            }
            if (!$this->touches($record['files'], $aliasByRel[$rel] ?? [$rel])) {
                continue; // the record does not list this file
            }
            $touchedOther = false;
            foreach ($aliasByRel as $otherRel => $aliases) {
                if ($otherRel === $rel) {
                    continue;
                }
                if ($this->touches($record['files'], $aliases)) {
                    $touchedOther = true;
                    break;
                }
            }
            if ($touchedOther) {
                continue; // moved with a twin — not lone
            }
            if ($best === null || $record['time'] > $best['time']) {
                $best = [
                    'sha'     => $record['sha'],
                    'time'    => $record['time'],
                    'subject' => $record['subject'],
                    'fix'     => preg_match(self::FIX_PATTERN, $record['subject']) === 1,
                ];
            }
        }
        return $best;
    }

    /**
     * @param list<string>                                                    $recordFiles
     * @param list<string>                                                    $aliases
     */
    private function touches(array $recordFiles, array $aliases): bool
    {
        foreach ($recordFiles as $file) {
            if (in_array($file, $aliases, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{sha: string, time: int, subject: string, files: list<string>}> $log
     * @param array<string, list<string>>                                               $aliasByRel
     */
    private function logActivity(array $log, array $aliasByRel): bool
    {
        foreach ($log as $record) {
            foreach ($aliasByRel as $aliases) {
                if ($this->touches($record['files'], $aliases)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<int|null> $memberTimes */
    private function blameActivity(array $memberTimes): bool
    {
        foreach ($memberTimes as $time) {
            if ($time !== null && $time >= $this->cutoffTime) {
                return true;
            }
        }
        return false;
    }

    /**
     * How often each file pair moved in the SAME commit (doc question 4),
     * within the window, rename-aliased, capped for presentation.
     *
     * @param list<array{sha: string, time: int, subject: string, files: list<string>}> $log
     * @param array<string, list<string>> $aliasByRel
     * @return list<array{a: string, b: string, times: int}>
     */
    private function coChange(array $log, array $aliasByRel): array
    {
        $rels  = array_keys($aliasByRel);
        sort($rels);
        $counts = [];
        foreach ($log as $record) {
            $hit = [];
            foreach ($rels as $rel) {
                if ($this->touches($record['files'], $aliasByRel[$rel])) {
                    $hit[] = $rel;
                }
            }
            $n = count($hit);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $key = $hit[$i] . "\0" . $hit[$j];
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }
        $rows = [];
        foreach ($counts as $key => $times) {
            [$a, $b] = explode("\0", (string)$key, 2);
            $rows[] = ['a' => $a, 'b' => $b, 'times' => $times];
        }
        usort($rows, static function (array $x, array $y): int {
            $byTimes = $y['times'] <=> $x['times'];
            return $byTimes !== 0 ? $byTimes : strcmp($x['a'] . "\0" . $x['b'], $y['a'] . "\0" . $y['b']);
        });
        return array_slice($rows, 0, self::MAX_COCHANGE);
    }

    /**
     * @param array{verdict: string, reason: string|null, members: list<array{file: string, last: array{sha: string, time: int, msg: string}|null, lone: array{sha: string, time: int, subject: string, fix: bool}|null}>, coChange: list<array{a: string, b: string, times: int}>, activity: bool} $facts
     * @return array{cluster: string, verdict: string, reason: string|null, members: list<array{file: string, last_sha: string|null, last_date: string|null, lone: array{sha: string, date: string, subject: string, fix: bool}|null}>, co_change: list<array{a: string, b: string, times: int}>}
     */
    private function buildRow(Cluster $cluster, array $facts): array
    {
        $members = [];
        foreach ($facts['members'] as $member) {
            $lone = is_array($member['lone']) ? $member['lone'] : null;
            $last = is_array($member['last']) ? $member['last'] : null;
            $members[] = [
                'file'      => $member['file'],
                'last_sha'  => $last === null ? null : substr($last['sha'], 0, self::SHA_ABBREV),
                'last_date' => $last === null ? null : $this->formatDate($last['time']),
                'lone'      => $lone === null ? null : [
                    'sha'     => substr($lone['sha'], 0, self::SHA_ABBREV),
                    'date'    => $this->formatDate($lone['time']),
                    'subject' => $lone['subject'],
                    'fix'     => $lone['fix'],
                ],
            ];
        }
        return [
            'cluster'   => $cluster->id,
            'verdict'   => $facts['verdict'],
            'reason'    => $facts['reason'],
            'members'   => $members,
            'co_change' => $facts['coChange'],
        ];
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function distinctFiles(Cluster $cluster): array
    {
        $files = [];
        foreach ($cluster->members as $member) {
            $abs = realpath($member->file);
            if ($abs !== false) {
                $files[$abs] = true;
            }
        }
        $list = array_keys($files);
        sort($list);
        return array_map('strval', $list);
    }

    private function relOf(string $root, string $abs): string
    {
        return ltrim(substr($abs, strlen($root)), '/');
    }

    /**
     * Alias set for a file: its current path plus every previous name
     * blame saw crossing rename boundaries into it.
     *
     * @return list<string>
     */
    private function collectAliases(string $rel, BlameIndex $blame): array
    {
        $set = [$rel];
        foreach ($blame->previousPaths as $prev) {
            $set[] = $prev;
        }
        $unique = array_values(array_unique($set));
        sort($unique);
        return $unique;
    }

    private function formatDate(int $time): string
    {
        return gmdate('Y-m-d', $time);
    }

    /** @param array{sha: string, time: int, msg: string}|null $last */
    private function describeLast(?array $last): string
    {
        if ($last === null) {
            return 'has no committed history';
        }
        return 'last changed ' . $this->formatDate($last['time']);
    }

    /**
     * @param array{verdict: string, reason: string|null, members: list<array{file: string, last: array{sha: string, time: int, msg: string}|null, lone: array{sha: string, time: int, subject: string, fix: bool}|null}>, coChange: list<array{a: string, b: string, times: int}>, activity: bool} $facts
     * @return array{file: string, lone: array{sha: string, time: int, subject: string, fix: bool}}|null
     */
    private function fixLoneOf(array $facts): ?array
    {
        $best = null;
        foreach ($facts['members'] as $member) {
            $lone = $member['lone'];
            if (!is_array($lone) || $lone['fix'] !== true) {
                continue;
            }
            if ($best === null || $lone['time'] > $best['lone']['time']) {
                $best = ['file' => $member['file'], 'lone' => $lone];
            }
        }
        return $best;
    }

    /**
     * @param array{verdict: string, reason: string|null, members: list<array{file: string, last: array{sha: string, time: int, msg: string}|null, lone: array{sha: string, time: int, subject: string, fix: bool}|null}>, coChange: list<array{a: string, b: string, times: int}>, activity: bool} $facts
     * @return array{file: string, lone: array{sha: string, time: int, subject: string, fix: bool}}|null
     */
    private function anyLoneOf(array $facts): ?array
    {
        $best = null;
        foreach ($facts['members'] as $member) {
            $lone = $member['lone'];
            if (!is_array($lone)) {
                continue;
            }
            if ($best === null || $lone['time'] > $best['lone']['time']) {
                $best = ['file' => $member['file'], 'lone' => $lone];
            }
        }
        return $best;
    }

    private function hasDriftFinding(Cluster $cluster): bool
    {
        foreach ($cluster->architecturalFindings as $finding) {
            if (str_starts_with($finding->code, 'drift-')) {
                return true;
            }
        }
        return false;
    }
}
