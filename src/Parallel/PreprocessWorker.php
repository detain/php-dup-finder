<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Normalization\PluginRegistry;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;

/**
 * Per-worker preprocessing routine: parse → extract → normalize →
 * hash → ngram-fingerprint for a batch of files. Stateless; safe to
 * run inside a forked child.
 *
 * Returns a list of arrays (not Block objects) so the master can
 * deduplicate IDs after collecting batches from all workers.
 *
 * @phpstan-type PreprocessedRow array{
 *   block: Block,
 *   parse_error: ?string,
 *   skipped: bool,
 *   file: string,
 * }
 */
final class PreprocessWorker
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param list<string> $files
     * @return list<array{type: 'block'|'error'|'skipped', file: string, block?: Block, message?: string}>
     */
    public function process(array $files): array
    {
        $cache  = new AstCache($this->config->cacheDir);
        $parser = new AstParser();
        $hasher = new SubtreeHasher();

        // Cache per-config tooling so paths sharing the same effective
        // config don't pay reconstruction cost. Keyed by a small fingerprint
        // of the fields the extractor / normalizer / fingerprinter read.
        $tooling = [];
        // Build the plugin registry once per worker (plugin classes are
        // typically cheap to instantiate; we pass the same registry into
        // every Normalizer below).
        $pluginRegistry = $this->config->normalizationPlugins !== []
            ? PluginRegistry::fromClassNames($this->config->normalizationPlugins)
            : null;
        $toolFor = static function (Config $cfg) use (&$tooling, $pluginRegistry): array {
            $key = sprintf(
                '%d|%d|%s|%d|%s|%s|%d|%d',
                $cfg->minBlockSize, $cfg->maxBlockSize,
                $cfg->normalizationMode, $cfg->ngramSize,
                implode(',', $cfg->allowedKinds),
                implode(',', $cfg->normalizationPlugins),
                $cfg->dbAware ? 1 : 0,
                $cfg->trinityCollapse ? 1 : 0,
            );
            if (!isset($tooling[$key])) {
                $tooling[$key] = [
                    'extractor'  => new BlockExtractor($cfg->minBlockSize, $cfg->maxBlockSize, $cfg->allowedKinds),
                    'normalizer' => new Normalizer(
                        mode: $cfg->normalizationMode,
                        plugins: $pluginRegistry,
                        dbAware: $cfg->dbAware,
                        trinityCollapse: $cfg->trinityCollapse,
                    ),
                    'fp'         => new NgramFingerprint($cfg->ngramSize),
                ];
            }
            return $tooling[$key];
        };

        $out = [];
        foreach ($files as $path) {
            $stmts = $cache->get($path);
            if ($stmts === null) {
                $stmts = $parser->parseFile($path);
                if ($stmts === null) {
                    $out[] = ['type' => 'error', 'file' => $path, 'message' => $parser->lastError?->getMessage() ?? 'parse error'];
                    continue;
                }
                $cache->put($path, $stmts);
            }
            $cfg   = $this->config->effectiveFor($path);
            $tools = $toolFor($cfg);
            foreach ($tools['extractor']->extract($path, $stmts) as $block) {
                $tools['normalizer']->normalize($block);
                $block->structuralHash = $hasher->hash($block->canonical);
                $block->ngramBag = $tools['fp']->fingerprint($block->canonical);
                $out[] = ['type' => 'block', 'file' => $path, 'block' => $block];
            }
        }
        return $out;
    }
}
