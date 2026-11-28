<?php
declare(strict_types=1);

namespace Phpdup\Git;

/**
 * Item 4.2 — on-disk sidecar cache for blame/log answers.
 *
 * The doc prescribes a cache keyed by repo@sha + file under cache_dir:
 * a blame answer for (HEAD sha, path) is immutable history, so entries
 * never expire — they simply stop being found when HEAD moves. Layout:
 *
 *   <cache_dir>/history/blame/<head8>-<sha256(abs path)>.json
 *   <cache_dir>/history/log/<head8>-<since day>.json
 *
 * Every failure mode (missing dir, unwritable tmp, truncated JSON, shape
 * drift) degrades to a cache miss: git re-answers in-process. The cache
 * is an optimisation, never a source of truth, and never an error.
 */
final class HistoryCache
{
    private const VERSION = 1;

    private readonly string $dir;

    /** An empty cache dir means the run is cacheless; disabled() says so. */
    public function __construct(string $cacheDir)
    {
        $this->dir = $cacheDir === '' ? '' : rtrim($cacheDir, '/\\') . '/history';
    }

    public function disabled(): bool
    {
        return $this->dir === '';
    }

    public function blameKey(string $headSha, string $absPath): string
    {
        return substr($headSha, 0, 8) . '-' . hash('sha256', $absPath);
    }

    public function logKey(string $headSha, int $sinceDay): string
    {
        return substr($headSha, 0, 8) . '-' . $sinceDay;
    }

    /**
     * @return BlameIndex|null a hit, or null on miss/corruption
     */
    public function loadBlame(string $key, string $headSha, string $relPath): ?BlameIndex
    {
        $decoded = $this->readJson($this->dir . '/blame/' . $key . '.json');
        if ($decoded === null) {
            return null;
        }
        if (
            ($decoded['v'] ?? null) !== self::VERSION
            || ($decoded['head'] ?? null) !== $headSha
            || ($decoded['rel'] ?? null) !== $relPath
            || !isset($decoded['lines']) || !is_array($decoded['lines'])
            || !isset($decoded['commits']) || !is_array($decoded['commits'])
            || !isset($decoded['previous']) || !is_array($decoded['previous'])
        ) {
            return null;
        }
        /** @var array<int, string|null> $lineSha */
        $lineSha = [];
        foreach ($decoded['lines'] as $line => $sha) {
            if ($sha !== null && !is_string($sha)) {
                return null;
            }
            $lineSha[(int)$line] = $sha;
        }
        /** @var array<string, array{author: string, time: int, msg: string}> $commits */
        $commits = [];
        foreach ($decoded['commits'] as $sha => $meta) {
            if (
                !is_string($sha) || !is_array($meta)
                || !isset($meta['author'], $meta['time'], $meta['msg'])
                || !is_string($meta['author']) || !is_int($meta['time']) || !is_string($meta['msg'])
            ) {
                return null;
            }
            $commits[$sha] = ['author' => $meta['author'], 'time' => $meta['time'], 'msg' => $meta['msg']];
        }
        /** @var list<string> $previous */
        $previous = [];
        foreach ($decoded['previous'] as $path) {
            if (!is_string($path)) {
                return null;
            }
            $previous[] = $path;
        }
        return BlameIndex::fromParts($lineSha, $commits, $previous);
    }

    /**
     * @param array<int, string|null> $lineSha
     * @param array<string, array{author: string, time: int, msg: string}> $commits
     * @param list<string> $previous
     */
    public function storeBlame(string $key, string $headSha, string $relPath, array $lineSha, array $commits, array $previous): void
    {
        $this->writeJson($this->dir . '/blame/' . $key . '.json', [
            'v'        => self::VERSION,
            'head'     => $headSha,
            'rel'      => $relPath,
            'lines'    => $lineSha,
            'commits'  => $commits,
            'previous' => $previous,
        ]);
    }

    /**
     * @return list<array{sha: string, time: int, subject: string, files: list<string>}>|null
     */
    public function loadLog(string $key, string $headSha, int $sinceDay): ?array
    {
        $decoded = $this->readJson($this->dir . '/log/' . $key . '.json');
        if ($decoded === null) {
            return null;
        }
        if (
            ($decoded['v'] ?? null) !== self::VERSION
            || ($decoded['head'] ?? null) !== $headSha
            || ($decoded['since'] ?? null) !== $sinceDay
            || !isset($decoded['records']) || !is_array($decoded['records'])
        ) {
            return null;
        }
        $records = [];
        foreach ($decoded['records'] as $record) {
            if (
                !is_array($record) || count($record) !== 4
                || !isset($record[0], $record[1], $record[2], $record[3])
                || !is_string($record[0]) || !is_int($record[1]) || !is_string($record[2]) || !is_array($record[3])
            ) {
                return null;
            }
            $files = [];
            foreach ($record[3] as $file) {
                if (!is_string($file)) {
                    return null;
                }
                $files[] = $file;
            }
            $records[] = ['sha' => $record[0], 'time' => $record[1], 'subject' => $record[2], 'files' => $files];
        }
        return $records;
    }

    /**
     * @param list<array{sha: string, time: int, subject: string, files: list<string>}> $records
     */
    public function storeLog(string $key, string $headSha, int $sinceDay, array $records): void
    {
        $serialised = [];
        foreach ($records as $record) {
            $serialised[] = [$record['sha'], $record['time'], $record['subject'], $record['files']];
        }
        $this->writeJson($this->dir . '/log/' . $key . '.json', [
            'v'       => self::VERSION,
            'head'    => $headSha,
            'since'   => $sinceDay,
            'records' => $serialised,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if ($this->disabled() || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        if ($this->disabled()) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp  = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }
}
