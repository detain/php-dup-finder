<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
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
        $cache     = new AstCache($this->config->cacheDir);
        $parser    = new AstParser();
        $extractor = new BlockExtractor(
            $this->config->minBlockSize,
            $this->config->maxBlockSize,
            $this->config->allowedKinds,
        );
        $normalizer= new Normalizer($this->config->normalizationMode);
        $hasher    = new SubtreeHasher();
        $fp        = new NgramFingerprint($this->config->ngramSize);
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
            foreach ($extractor->extract($path, $stmts) as $block) {
                $normalizer->normalize($block);
                $block->structuralHash = $hasher->hash($block->canonical);
                $block->ngramBag = $fp->fingerprint($block->canonical);
                $out[] = ['type' => 'block', 'file' => $path, 'block' => $block];
            }
        }
        return $out;
    }
}
