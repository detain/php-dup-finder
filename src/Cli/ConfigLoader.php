<?php
declare(strict_types=1);

namespace Phpdup\Cli;

final class ConfigLoader
{
    /**
     * @param list<string> $paths
     * @param array<string,mixed> $overrides
     * @param list<string>|null $profileExclude  Profile-supplied exclude globs
     *                                           applied only when neither $configFile
     *                                           nor an $overrides['exclude'] is given.
     */
    public function load(array $paths, ?string $configFile, array $overrides = [], ?array $profileExclude = null): Config
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

        $flatKeys = [
            'min_block_size', 'max_block_size', 'normalization_mode',
            'similarity_threshold', 'tree_threshold', 'min_cluster_impact',
            'max_df', 'ngram_size', 'cache_dir', 'parallelism',
            'workers', 'incremental', 'lazy_ast',
            'sort', 'ted_weights', 'scorer',
            'ir_threshold', 'ml_pair_threshold', 'debug_log',
            'low_memory', 'fail_on_impact', 'max_clusters',
            'baseline', 'baseline_out', 'diff_base', 'apply',
        ];
        $resolver = new OverrideResolver($flatKeys);
        $flat = $resolver->resolve($overrides, $data, [
            'min_block_size' => $base->minBlockSize,
            'max_block_size' => $base->maxBlockSize,
            'normalization_mode' => $base->normalizationMode,
            'similarity_threshold' => $base->similarityThreshold,
            'tree_threshold' => $base->treeThreshold,
            'min_cluster_impact' => $base->minClusterImpact,
            'max_df' => $base->maxDocumentFrequency,
            'ngram_size' => $base->ngramSize,
            'cache_dir' => $base->cacheDir,
            'parallelism' => $base->parallelism,
            'workers' => $base->workers,
            'incremental' => $base->incremental,
            'lazy_ast' => $base->lazyAst,
            'sort' => $base->sort,
            'ted_weights' => $base->tedWeights,
            'scorer' => $base->scorer,
            'ir_threshold' => $base->irThreshold,
            'ml_pair_threshold' => $base->mlPairThreshold,
            'debug_log' => $base->debugLog,
            'low_memory' => $base->lowMemory,
            'fail_on_impact' => $base->failOnImpact,
            'max_clusters' => $base->maxClusters,
            'baseline' => $base->baselineFile,
            'baseline_out' => $base->baselineOutFile,
            'diff_base' => $base->diffBase,
            'apply' => $base->apply,
        ]);

        $report = is_array($data['report'] ?? null) ? $data['report'] : [];
        $htmlOverride = $overrides['html'] ?? ($report['html'] ?? null);
        $jsonOverride = $overrides['json'] ?? ($report['json'] ?? null);

        $allowedKinds = $base->allowedKinds;
        if (array_key_exists('allowed_kinds', $overrides)) {
            $allowedKinds = $overrides['allowed_kinds'];
        } elseif (array_key_exists('kinds', $data)) {
            $allowedKinds = $data['kinds'];
        }

        $optBlock = is_array($data['optional_blocks'] ?? null) ? $data['optional_blocks'] : [];

        $resolvedPaths = !empty($data['paths']) ? $data['paths'] : $base->paths;
        // Profile excludes apply when the caller didn't pin them
        // explicitly via --config / overrides — i.e. they're a *default
        // upgrade*, not a forced override.
        $resolvedExclude = !empty($data['exclude'])
            ? $data['exclude']
            : ($profileExclude !== null ? $profileExclude : $base->exclude);

        return new Config(
            paths: $resolvedPaths,
            exclude: $resolvedExclude,
            minBlockSize:                   $flat['min_block_size'],
            maxBlockSize:                   $flat['max_block_size'],
            normalizationMode:              $flat['normalization_mode'],
            similarityThreshold:            $flat['similarity_threshold'],
            treeThreshold:                  $flat['tree_threshold'],
            minClusterImpact:               $flat['min_cluster_impact'],
            maxDocumentFrequency:           $flat['max_df'],
            ngramSize:                      $flat['ngram_size'],
            cacheDir:                       $flat['cache_dir'],
            parallelism:                    $flat['parallelism'],
            htmlReportDir: $htmlOverride !== null ? (string)$htmlOverride : null,
            jsonReportFile: $jsonOverride !== null ? (string)$jsonOverride : null,
            workers:                        $flat['workers'],
            incremental:                    $flat['incremental'],
            lazyAst:                        $flat['lazy_ast'],
            allowedKinds: $allowedKinds,
            optionalBlocksEnabled:        (bool)($overrides['optional_blocks_enabled'] ?? $optBlock['enabled'] ?? $base->optionalBlocksEnabled),
            optionalBlocksContainment:    (float)($overrides['optional_blocks_containment'] ?? $optBlock['containment'] ?? $base->optionalBlocksContainment),
            optionalBlocksMinOverlap:     (float)($overrides['optional_blocks_min_overlap'] ?? $optBlock['min_overlap'] ?? $base->optionalBlocksMinOverlap),
            optionalBlocksMaxPerCluster:  (int)($overrides['optional_blocks_max_per_cluster'] ?? $optBlock['max_per_cluster'] ?? $base->optionalBlocksMaxPerCluster),
            optionalBlocksMinSegmentLength: (int)($overrides['optional_blocks_min_segment_length'] ?? $optBlock['min_segment_length'] ?? $base->optionalBlocksMinSegmentLength),
            sort:                          $flat['sort'],
            tedWeights:                    $flat['ted_weights'],
            normalizationPlugins: $this->extractNormalizationPlugins($data),
            perDirectoryOverrides: $this->discoverPerDirectoryOverrides($resolvedPaths),
            dbAware: (bool)($overrides['db_aware'] ?? $data['db_aware'] ?? $base->dbAware),
            trinityCollapse: (bool)($overrides['trinity_collapse'] ?? $data['trinity_collapse'] ?? $base->trinityCollapse),
            dbSymbolsMethods: $this->extractDbSymbols($data, $overrides, 'methods'),
            dbSymbolsFunctions: $this->extractDbSymbols($data, $overrides, 'functions'),
            scorer:                         $flat['scorer'],
            irThreshold:                    $flat['ir_threshold'],
            mlPairUrl: (string)($overrides['ml_pair_url'] ?? $data['ml_pair_url'] ?? $base->mlPairUrl),
            mlPairThreshold:               $flat['ml_pair_threshold'],
            debugLog:                      $flat['debug_log'],
            lowMemory:                     $flat['low_memory'],
            failOnImpact:                  $flat['fail_on_impact'],
            maxClusters:                   $flat['max_clusters'],
            baselineFile:                  $flat['baseline'],
            baselineOutFile:               $flat['baseline_out'],
            diffBase:                      $flat['diff_base'],
            apply:                         $flat['apply'],
        );
    }

    /**
     * Extract a DB-symbol equivalence map (option 4 of
     * docs/plans/orm-db-semantic-dedup.md) from a parsed config and
     * the override dict, lower-casing keys and discarding entries
     * whose op-tag is not one of the recognised `db.*` constants.
     *
     * The override path accepts a single composite key
     * (`db_symbols_methods` / `db_symbols_functions`) so callers
     * built from CLI flags or programmatic config can flatten the
     * nested shape.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $overrides
     * @return array<string,string>
     */
    private function extractDbSymbols(array $data, array $overrides, string $bucket): array
    {
        $direct = $overrides['db_symbols_' . $bucket] ?? null;
        if (!is_array($direct)) {
            $section = $data['db_symbols'] ?? null;
            $direct  = is_array($section) && is_array($section[$bucket] ?? null) ? $section[$bucket] : [];
        }
        $allowed = [
            \Phpdup\Normalization\DbOpRegistry::OP_READ,
            \Phpdup\Normalization\DbOpRegistry::OP_WRITE,
            \Phpdup\Normalization\DbOpRegistry::OP_DELETE,
            \Phpdup\Normalization\DbOpRegistry::OP_EXECUTE,
            \Phpdup\Normalization\DbOpRegistry::OP_QUERY,
        ];
        $out = [];
        foreach ($direct as $name => $op) {
            if (!is_string($name) || $name === '' || !is_string($op)) {
                continue;
            }
            if (!in_array($op, $allowed, true)) {
                continue;
            }
            $out[strtolower($name)] = $op;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function extractNormalizationPlugins(array $data): array
    {
        $norm = $data['normalization'] ?? null;
        if (!is_array($norm)) return [];
        $list = $norm['plugins'] ?? null;
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Walk each scan path looking for `.phpdup.json` files and return a map
     * of {realpath(dir) → override-dict}. The dicts are validated via
     * {@see validate()} so misconfigured per-directory files fail loudly
     * at load time rather than mid-pipeline.
     *
     * Found files override only the subtree rooted at their directory;
     * the actual layered merge happens in {@see Config::effectiveFor()}.
     *
     * @param list<string> $paths
     * @return array<string, array<string, mixed>>
     */
    private function discoverPerDirectoryOverrides(array $paths): array
    {
        $out = [];
        foreach ($paths as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if (!$file instanceof \SplFileInfo) continue;
                if ($file->getBasename() !== '.phpdup.json') continue;
                $decoded = json_decode((string)file_get_contents($file->getPathname()), true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('Per-directory config is not valid JSON: ' . $file->getPathname());
                }
                // Re-use the same validator so per-directory files can't
                // smuggle in misspelled keys or out-of-range values.
                $this->validate($decoded, $file->getPathname());

                $dir = realpath($file->getPath());
                if ($dir === false) {
                    continue;
                }
                $out[$dir] = $this->shapeOverrides($decoded);
            }
        }
        return $out;
    }

    /**
     * Map the on-disk JSON shape (e.g. `optional_blocks: { containment: 0.9 }`)
     * to the flat override-dict shape that {@see Config::withOverrides()} accepts
     * (e.g. `optional_blocks_containment => 0.9`).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function shapeOverrides(array $data): array
    {
        $out = [];
        foreach (['min_block_size', 'max_block_size', 'normalization_mode',
                  'similarity_threshold', 'tree_threshold', 'min_cluster_impact',
                  'max_df', 'ngram_size', 'sort',
                  'fail_on_impact', 'max_clusters', 'baseline', 'baseline_out',
                  'diff_base'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        if (array_key_exists('kinds', $data)) {
            $out['allowed_kinds'] = $data['kinds'];
        }
        if (isset($data['optional_blocks']) && is_array($data['optional_blocks'])) {
            $ob = $data['optional_blocks'];
            if (array_key_exists('enabled', $ob))            $out['optional_blocks_enabled']            = $ob['enabled'];
            if (array_key_exists('containment', $ob))        $out['optional_blocks_containment']        = $ob['containment'];
            if (array_key_exists('min_overlap', $ob))        $out['optional_blocks_min_overlap']        = $ob['min_overlap'];
            if (array_key_exists('max_per_cluster', $ob))    $out['optional_blocks_max_per_cluster']    = $ob['max_per_cluster'];
            if (array_key_exists('min_segment_length', $ob)) $out['optional_blocks_min_segment_length'] = $ob['min_segment_length'];
        }
        if (isset($data['db_symbols']) && is_array($data['db_symbols'])) {
            $ds = $data['db_symbols'];
            if (isset($ds['methods']) && is_array($ds['methods'])) {
                $out['db_symbols_methods'] = $ds['methods'];
            }
            if (isset($ds['functions']) && is_array($ds['functions'])) {
                $out['db_symbols_functions'] = $ds['functions'];
            }
        }
        if (array_key_exists('debug_log', $data)) {
            $out['debug_log'] = $data['debug_log'];
        }
        if (array_key_exists('low_memory', $data)) {
            $out['low_memory'] = $data['low_memory'];
        }
        return $out;
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
            'kinds',
            'optional_blocks',
            'sort',
            'ted_weights',
            'report',
            'normalization',
            'db_aware',
            'trinity_collapse',
            'db_symbols',
            'scorer',
            'ir_threshold',
            'ml_pair_url',
            'ml_pair_threshold',
            'debug_log',
            'low_memory',
            'fail_on_impact',
            'max_clusters',
            'baseline',
            'baseline_out',
            'diff_base', 'apply',
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
        if (array_key_exists('kinds', $data)) {
            $assertListOfStrings($data['kinds'], 'kinds');
            foreach ($data['kinds'] as $k) {
                if (!in_array($k, \Phpdup\Extraction\BlockExtractor::ALL_KINDS, true)) {
                    throw new \RuntimeException(
                        "kinds[] entry '$k' must be one of "
                        . implode('|', \Phpdup\Extraction\BlockExtractor::ALL_KINDS)
                        . $where
                    );
                }
            }
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
        if (array_key_exists('optional_blocks', $data)) {
            if (!is_array($data['optional_blocks'])) {
                throw new \RuntimeException("optional_blocks must be an object$where");
            }
            $allowed = ['enabled', 'containment', 'min_overlap', 'max_per_cluster', 'min_segment_length'];
            foreach ($data['optional_blocks'] as $k => $v) {
                if (!in_array($k, $allowed, true)) {
                    throw new \RuntimeException("Unknown config key 'optional_blocks.$k'$where");
                }
            }
            if (array_key_exists('enabled', $data['optional_blocks']) && !is_bool($data['optional_blocks']['enabled'])) {
                throw new \RuntimeException("optional_blocks.enabled must be a boolean$where");
            }
            foreach (['containment', 'min_overlap'] as $k) {
                if (array_key_exists($k, $data['optional_blocks'])) {
                    $assertFloat01($data['optional_blocks'][$k], "optional_blocks.$k");
                }
            }
            if (array_key_exists('max_per_cluster', $data['optional_blocks'])) {
                $assertInt($data['optional_blocks']['max_per_cluster'], 'optional_blocks.max_per_cluster', 0);
            }
            if (array_key_exists('min_segment_length', $data['optional_blocks'])) {
                $assertInt($data['optional_blocks']['min_segment_length'], 'optional_blocks.min_segment_length', 1);
            }
        }
        if (array_key_exists('sort', $data)) {
            if (!is_string($data['sort']) || $data['sort'] === '') {
                throw new \RuntimeException("sort must be a non-empty string$where");
            }
            try {
                \Phpdup\Reporting\ClusterSort::parse($data['sort']);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException("sort: {$e->getMessage()}$where");
            }
        }
        if (array_key_exists('ted_weights', $data)) {
            $assertEnum($data['ted_weights'], 'ted_weights', \Phpdup\Similarity\EditCostModel::MODELS);
        }
        if (array_key_exists('db_aware', $data) && !is_bool($data['db_aware'])) {
            throw new \RuntimeException("db_aware must be a boolean$where");
        }
        if (array_key_exists('trinity_collapse', $data) && !is_bool($data['trinity_collapse'])) {
            throw new \RuntimeException("trinity_collapse must be a boolean$where");
        }
        if (array_key_exists('low_memory', $data) && !is_bool($data['low_memory'])) {
            throw new \RuntimeException("low_memory must be a boolean$where");
        }
        if (array_key_exists('scorer', $data)) {
            $assertEnum($data['scorer'], 'scorer', ['default', 'ir']);
        }
        if (array_key_exists('ir_threshold', $data)) {
            $assertFloat01($data['ir_threshold'], 'ir_threshold');
        }
        if (array_key_exists('ml_pair_url', $data)) {
            if (!is_string($data['ml_pair_url'])) {
                throw new \RuntimeException("ml_pair_url must be a string$where");
            }
            if ($data['ml_pair_url'] !== ''
                && !\Phpdup\Ml\MlClient::isAllowedUrl(
                    rtrim($data['ml_pair_url'], '/') . '/score-pair',
                )
            ) {
                throw new \RuntimeException(
                    "ml_pair_url must be an http(s) URL with a non-empty host (and not 0.0.0.0)$where",
                );
            }
        }
        if (array_key_exists('ml_pair_threshold', $data)) {
            $assertFloat01($data['ml_pair_threshold'], 'ml_pair_threshold');
        }
        if (array_key_exists('fail_on_impact', $data)) {
            $assertInt($data['fail_on_impact'], 'fail_on_impact', 0);
        }
        if (array_key_exists('max_clusters', $data)) {
            $assertInt($data['max_clusters'], 'max_clusters', 0);
        }
        if (array_key_exists('baseline', $data)) {
            if (!is_string($data['baseline']) || $data['baseline'] === '') {
                throw new \RuntimeException("baseline must be a non-empty string$where");
            }
        }
        if (array_key_exists('baseline_out', $data)) {
            if (!is_string($data['baseline_out']) || $data['baseline_out'] === '') {
                throw new \RuntimeException("baseline_out must be a non-empty string$where");
            }
        }
        if (array_key_exists('db_symbols', $data)) {
            if (!is_array($data['db_symbols'])) {
                throw new \RuntimeException("db_symbols must be an object$where");
            }
            $allowedBuckets = ['methods', 'functions'];
            foreach (array_keys($data['db_symbols']) as $bucket) {
                if (!in_array($bucket, $allowedBuckets, true)) {
                    throw new \RuntimeException(
                        "Unknown config key 'db_symbols.$bucket' (allowed: methods|functions)$where",
                    );
                }
                if (!is_array($data['db_symbols'][$bucket])) {
                    throw new \RuntimeException("db_symbols.$bucket must be an object$where");
                }
                $allowedOps = [
                    \Phpdup\Normalization\DbOpRegistry::OP_READ,
                    \Phpdup\Normalization\DbOpRegistry::OP_WRITE,
                    \Phpdup\Normalization\DbOpRegistry::OP_DELETE,
                    \Phpdup\Normalization\DbOpRegistry::OP_EXECUTE,
                    \Phpdup\Normalization\DbOpRegistry::OP_QUERY,
                ];
                foreach ($data['db_symbols'][$bucket] as $name => $op) {
                    if (!is_string($name) || $name === '') {
                        throw new \RuntimeException("db_symbols.$bucket keys must be non-empty strings$where");
                    }
                    if (!is_string($op) || !in_array($op, $allowedOps, true)) {
                        throw new \RuntimeException(
                            "db_symbols.$bucket.$name must be one of " . implode('|', $allowedOps) . "$where",
                        );
                    }
                }
            }
        }
        if (array_key_exists('normalization', $data)) {
            if (!is_array($data['normalization'])) {
                throw new \RuntimeException("normalization must be an object$where");
            }
            foreach ($data['normalization'] as $k => $v) {
                if ($k !== 'plugins') {
                    throw new \RuntimeException("Unknown config key 'normalization.$k'$where");
                }
            }
            if (isset($data['normalization']['plugins'])) {
                $assertListOfStrings($data['normalization']['plugins'], 'normalization.plugins');
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
        if (array_key_exists('debug_log', $data)) {
            if (!is_string($data['debug_log']) || $data['debug_log'] === '') {
                throw new \RuntimeException("debug_log must be a non-empty string$where");
            }
        }
        if (array_key_exists('diff_base', $data)) {
            if (!is_string($data['diff_base']) || $data['diff_base'] === '') {
                throw new \RuntimeException("diff_base must be a non-empty string$where");
            }
        }
    }
}
