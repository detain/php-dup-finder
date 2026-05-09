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
            $this->validate($decoded, $configFile);
            $data = $decoded;
        }

        $get = function (string $key, mixed $default) use ($data, $overrides): mixed {
            if (array_key_exists($key, $overrides)) {
                return $overrides[$key];
            }
            return $data[$key] ?? $default;
        };

        $report = is_array($data['report'] ?? null) ? $data['report'] : [];
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

    /**
     * Validate a parsed config payload against the documented schema.
     *
     * Mirrors docs/config-schema.json — kept in sync by hand because we don't
     * want a JSON-Schema validator dependency just for config loading.
     *
     * @param array<string,mixed> $data
     * @throws \RuntimeException with a field-path on the first violation found.
     */
    public function validate(array $data, ?string $source = null): void
    {
        $where = $source !== null ? " in $source" : '';

        $known = [
            'paths', 'exclude',
            'min_block_size', 'max_block_size',
            'normalization_mode',
            'similarity_threshold', 'tree_threshold',
            'min_cluster_impact', 'max_df', 'ngram_size',
            'cache_dir', 'parallelism', 'workers',
            'incremental', 'lazy_ast',
            'report',
        ];
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $known, true)) {
                throw new \RuntimeException("Unknown config key '$k'$where");
            }
        }

        $assertListOfStrings = static function (mixed $v, string $path) use ($where): void {
            if (!is_array($v)) {
                throw new \RuntimeException("$path must be an array$where");
            }
            $i = 0;
            foreach ($v as $key => $item) {
                if ($key !== $i++) {
                    throw new \RuntimeException("$path must be a list (no string keys)$where");
                }
                if (!is_string($item) || $item === '') {
                    throw new \RuntimeException("$path must contain non-empty strings$where");
                }
            }
        };

        if (array_key_exists('paths', $data)) {
            $assertListOfStrings($data['paths'], 'paths');
            if ($data['paths'] === []) {
                throw new \RuntimeException("paths must not be empty$where");
            }
        }
        if (array_key_exists('exclude', $data)) {
            $assertListOfStrings($data['exclude'], 'exclude');
        }

        $assertInt = static function (mixed $v, string $path, int $min, ?int $max = null) use ($where): void {
            if (!is_int($v)) {
                throw new \RuntimeException("$path must be an integer$where");
            }
            if ($v < $min) {
                throw new \RuntimeException("$path must be >= $min$where");
            }
            if ($max !== null && $v > $max) {
                throw new \RuntimeException("$path must be <= $max$where");
            }
        };
        $assertFloat01 = static function (mixed $v, string $path) use ($where): void {
            if (!is_int($v) && !is_float($v)) {
                throw new \RuntimeException("$path must be a number$where");
            }
            $f = (float)$v;
            if ($f < 0.0 || $f > 1.0) {
                throw new \RuntimeException("$path must be in [0, 1]$where");
            }
        };
        $assertEnum = static function (mixed $v, string $path, array $allowed) use ($where): void {
            if (!in_array($v, $allowed, true)) {
                throw new \RuntimeException(
                    "$path must be one of " . implode('|', $allowed) . "$where"
                );
            }
        };

        $hasMin = array_key_exists('min_block_size', $data);
        $hasMax = array_key_exists('max_block_size', $data);
        if ($hasMin) {
            $assertInt($data['min_block_size'], 'min_block_size', 1);
        }
        if ($hasMax) {
            $assertInt($data['max_block_size'], 'max_block_size', 1);
        }
        if ($hasMin && $hasMax && (int)$data['min_block_size'] > (int)$data['max_block_size']) {
            throw new \RuntimeException("min_block_size must be <= max_block_size$where");
        }
        if (array_key_exists('normalization_mode', $data)) {
            $assertEnum($data['normalization_mode'], 'normalization_mode', ['strict', 'default', 'aggressive']);
        }
        if (array_key_exists('similarity_threshold', $data)) {
            $assertFloat01($data['similarity_threshold'], 'similarity_threshold');
        }
        if (array_key_exists('tree_threshold', $data)) {
            $assertFloat01($data['tree_threshold'], 'tree_threshold');
        }
        if (array_key_exists('min_cluster_impact', $data)) {
            $assertInt($data['min_cluster_impact'], 'min_cluster_impact', 0);
        }
        if (array_key_exists('max_df', $data)) {
            $assertFloat01($data['max_df'], 'max_df');
        }
        if (array_key_exists('ngram_size', $data)) {
            $assertInt($data['ngram_size'], 'ngram_size', 2, 10);
        }
        if (array_key_exists('cache_dir', $data) && !is_string($data['cache_dir'])) {
            throw new \RuntimeException("cache_dir must be a string$where");
        }
        if (array_key_exists('parallelism', $data)) {
            $assertEnum($data['parallelism'], 'parallelism', ['auto', 'off', 'manual']);
        }
        if (array_key_exists('workers', $data)) {
            $assertInt($data['workers'], 'workers', 0);
        }
        foreach (['incremental', 'lazy_ast'] as $boolKey) {
            if (array_key_exists($boolKey, $data) && !is_bool($data[$boolKey])) {
                throw new \RuntimeException("$boolKey must be a boolean$where");
            }
        }
        if (array_key_exists('report', $data)) {
            if (!is_array($data['report'])) {
                throw new \RuntimeException("report must be an object$where");
            }
            foreach ($data['report'] as $k => $v) {
                if (!in_array($k, ['html', 'json'], true)) {
                    throw new \RuntimeException("Unknown config key 'report.$k'$where");
                }
                if (!is_string($v)) {
                    throw new \RuntimeException("report.$k must be a string$where");
                }
            }
        }
    }
}
