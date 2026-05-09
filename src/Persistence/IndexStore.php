<?php
declare(strict_types=1);

namespace Phpdup\Persistence;

use Phpdup\Extraction\Block;

/**
 * Disk-backed snapshot of the per-file block list, used for
 * incremental indexing: on a re-run we re-process only files whose
 * content hash has changed (added, modified, or removed).
 *
 * Layout
 * ------
 * One file under `<cacheDir>/<sha1(absolute_file_path)>.idx`. Each
 * file holds a serialized PHP array:
 *
 *     [
 *       'file_hash' => sha1(file_contents),
 *       'parser_version' => string,
 *       'config_key' => sha1(serialize(relevant_config_fields)),
 *       'blocks' => Block[]
 *     ]
 *
 * If `parser_version` or `config_key` changes, the snapshot is treated
 * as stale (cache miss). Snapshots are independent per source file so
 * adding one new file or editing one existing file invalidates only
 * that one file's snapshot — the rest replay verbatim.
 */
final class IndexStore
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly string $configKey,
    ) {
        if ($this->cacheDir !== '' && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o775, true);
        }
    }

    private const PARSER_VERSION = 'parser-v5-apted';

    public function isEnabled(): bool
    {
        return $this->cacheDir !== '' && is_dir($this->cacheDir);
    }

    /**
     * @return list<Block>|null  null on cache miss
     */
    public function load(string $filePath): ?array
    {
        if (!$this->isEnabled() || !is_file($filePath)) return null;
        $sha = sha1_file($filePath);
        if ($sha === false) return null;

        $cacheFile = $this->cacheFor($filePath);
        if (!is_file($cacheFile)) return null;

        $blob = @file_get_contents($cacheFile);
        if ($blob === false || $blob === '') return null;

        $payload = @unserialize($blob, ['allowed_classes' => true]);
        if (!is_array($payload)) return null;
        if (($payload['file_hash'] ?? null) !== $sha) return null;
        if (($payload['parser_version'] ?? null) !== self::PARSER_VERSION) return null;
        if (($payload['config_key'] ?? null) !== $this->configKey) return null;

        $blocks = $payload['blocks'] ?? null;
        return is_array($blocks) ? array_values($blocks) : null;
    }

    /** @param list<Block> $blocks */
    public function save(string $filePath, array $blocks): void
    {
        if (!$this->isEnabled() || !is_file($filePath)) return;
        $sha = sha1_file($filePath);
        if ($sha === false) return;
        $cacheFile = $this->cacheFor($filePath);
        $payload = [
            'file_hash'      => $sha,
            'parser_version' => self::PARSER_VERSION,
            'config_key'     => $this->configKey,
            'blocks'         => $blocks,
        ];
        @file_put_contents($cacheFile, serialize($payload), LOCK_EX);
    }

    private function cacheFor(string $filePath): string
    {
        return $this->cacheDir . '/' . sha1($filePath) . '.idx';
    }
}
