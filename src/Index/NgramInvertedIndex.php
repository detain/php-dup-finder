<?php
declare(strict_types=1);

namespace Phpdup\Index;

use Phpdup\Extraction\Block;

/**
 * Inverted index from canonical n-gram → block ids.
 *
 * Used to generate the candidate-pair list for near-duplicate
 * comparison without doing all-pairs Jaccard. For each block we look
 * up all blocks sharing at least one *rare* n-gram (a gram appearing
 * in fewer than maxDocumentFrequency × N blocks). This is the
 * standard rare-gram pre-filter for large clone-detection corpora —
 * keeps candidate generation near linear in the corpus size.
 *
 * Disk caching: the built postings list is serialized to a cache file
 * keyed by a hash of all block structural hashes. Any change to any
 * block's structural hash invalidates the cache.
 */
final class NgramInvertedIndex
{
    /** @var array<string,list<string>> ngram → block ids */
    private array $postings = [];
    private int $blockCount = 0;

    public function __construct(
        private readonly string $cacheDir = '',
    ) {
    }

    /**
     * @param callable(int $indexed, int $total): void|null $progressCallback
     */
    public function build(BlockIndex $index, ?callable $progressCallback = null): void
    {
        $cacheKey = $this->computeCacheKey($index);

        if ($this->loadFromCache($cacheKey)) {
            $indexed = $this->blockCount;
            if ($progressCallback !== null) {
                $progressCallback($indexed, $this->blockCount);
            }
            return;
        }

        $this->postings = [];
        $this->blockCount = $index->size();
        $indexed = 0;
        $allHashes = [];
        foreach ($index->all() as $b) {
            $allHashes[] = $b->structuralHash;
            $this->indexBlock($b);
            $indexed++;
            if ($progressCallback !== null) {
                $progressCallback($indexed, $this->blockCount);
            }
        }

        $this->saveToCache($cacheKey, $allHashes);
    }

    private function indexBlock(Block $b): void
    {
        if ($b->ngramBag === null) {
            return;
        }
        foreach (array_keys($b->ngramBag) as $gram) {
            $this->postings[$gram][] = $b->id;
        }
    }

    /**
     * @return \Generator<string> candidate block ids that share at least one
     *                             rare ngram with $block, excluding $block itself.
     */
    public function candidatesFor(Block $block, float $maxDocumentFrequency): \Generator
    {
        if ($block->ngramBag === null) {
            return;
        }
        $maxDf = max(1, (int)floor($this->blockCount * $maxDocumentFrequency));
        $seen = [];
        foreach (array_keys($block->ngramBag) as $gram) {
            $posting = $this->postings[$gram] ?? [];
            if (count($posting) > $maxDf) {
                continue; // too common to be informative
            }
            foreach ($posting as $otherId) {
                if ($otherId === $block->id) {
                    continue;
                }
                if (!isset($seen[$otherId])) {
                    $seen[$otherId] = true;
                    yield $otherId;
                }
            }
        }
    }

    private function computeCacheKey(BlockIndex $index): string
    {
        $hashes = [];
        foreach ($index->all() as $b) {
            $hashes[] = $b->structuralHash;
        }
        return sha1(implode('|', $hashes));
    }

    private function cacheFilePath(string $cacheKey): string
    {
        return $this->cacheDir . '/' . $cacheKey . '.ngram-idx';
    }

    private function isCacheEnabled(): bool
    {
        return $this->cacheDir !== '' && is_dir($this->cacheDir);
    }

    /**
     * @return bool true if cache was loaded, false otherwise
     */
    private function loadFromCache(string $cacheKey): bool
    {
        if (!$this->isCacheEnabled()) {
            return false;
        }

        $cacheFile = $this->cacheFilePath($cacheKey);
        if (!is_file($cacheFile)) {
            return false;
        }

        $blob = @file_get_contents($cacheFile);
        if ($blob === false || $blob === '') {
            return false;
        }

        $payload = @unserialize($blob);
        if (!is_array($payload)) {
            return false;
        }

        $this->postings = $payload['postings'] ?? [];
        $this->blockCount = $payload['blockCount'] ?? 0;

        return true;
    }

    /**
     * @param list<string> $blockHashes
     */
    private function saveToCache(string $cacheKey, array $blockHashes): void
    {
        if (!$this->isCacheEnabled()) {
            return;
        }

        $cacheFile = $this->cacheFilePath($cacheKey);
        $payload = [
            'postings'    => $this->postings,
            'blockCount'  => $this->blockCount,
            'blockHashes' => $blockHashes,
        ];

        @file_put_contents($cacheFile, serialize($payload), LOCK_EX);
    }

}
