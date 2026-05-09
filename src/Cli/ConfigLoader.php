<?php
declare(strict_types=1);

namespace Phpdup\Cli;

final class ConfigLoader
{
    /**
     * @param list<string> $paths
     * @param array<string,mixed> $overrides
     */
    public function load(array $paths, ?string $configFile, array $overrides = []): Config
    {
        $base = Config::defaults($paths);
        $data = [];

        if ($configFile !== null) {
            if (!is_file($configFile)) {
                throw new \RuntimeException("Config file not found: $configFile");
            }
            $decoded = json_decode((string)file_get_contents($configFile), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException("Config file is not valid JSON: $configFile");
            }
            $data = $decoded;
        }

        $get = function (string $key, mixed $default) use ($data, $overrides): mixed {
            if (array_key_exists($key, $overrides)) {
                return $overrides[$key];
            }
            return $data[$key] ?? $default;
        };

        $report = $data['report'] ?? [];
        $htmlOverride = $overrides['html'] ?? ($report['html'] ?? null);
        $jsonOverride = $overrides['json'] ?? ($report['json'] ?? null);

        return new Config(
            paths: !empty($data['paths']) ? $data['paths'] : $base->paths,
            exclude: !empty($data['exclude']) ? $data['exclude'] : $base->exclude,
            minBlockSize: (int)$get('min_block_size', $base->minBlockSize),
            maxBlockSize: (int)$get('max_block_size', $base->maxBlockSize),
            normalizationMode: (string)$get('normalization_mode', $base->normalizationMode),
            similarityThreshold: (float)$get('similarity_threshold', $base->similarityThreshold),
            treeThreshold: (float)$get('tree_threshold', $base->treeThreshold),
            minClusterImpact: (int)$get('min_cluster_impact', $base->minClusterImpact),
            maxDocumentFrequency: (float)$get('max_df', $base->maxDocumentFrequency),
            ngramSize: (int)$get('ngram_size', $base->ngramSize),
            cacheDir: (string)$get('cache_dir', $base->cacheDir),
            parallelism: (string)$get('parallelism', $base->parallelism),
            htmlReportDir: $htmlOverride !== null ? (string)$htmlOverride : null,
            jsonReportFile: $jsonOverride !== null ? (string)$jsonOverride : null,
            workers: (int)$get('workers', $base->workers),
            incremental: (bool)$get('incremental', $base->incremental),
            lazyAst: (bool)$get('lazy_ast', $base->lazyAst),
        );
    }
}
