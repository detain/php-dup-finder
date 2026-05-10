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
 *
 * Optimization: integer IDs are maintained alongside string IDs for
 * faster set operations in callers like generateCandidatePairs().
 */
final class NgramInvertedIndex
{
    /** @var array<string,list<string>> ngram → block ids */
    private array $postings = [];
    /** @var array<string,list<int>> ngram → integer block ids */
    private array $intPostings = [];
    /** @var array<int,string> integer id → string id */
    private array $intToString = [];
    /** @var array<string,int> string id → integer id */
    private array $stringToInt = [];
    private int $blockCount = 0;
    private int $nextIntId = 0;

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
        $this->intPostings = [];
        $this->intToString = [];
        $this->stringToInt = [];
        $this->blockCount = $index->size();
        $this->nextIntId = 0;
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

        // Assign integer ID for this block
        $intId = $this->nextIntId++;
        $this->stringToInt[$b->id] = $intId;
        $this->intToString[$intId] = $b->id;

        foreach ($b->ngramBag as $gram => $count) {
            $this->postings[$gram][] = $b->id;
            $this->intPostings[$gram][] = $intId;
        }
    }

    /**
     * @param array<string,bool>|null $skipIds Block IDs to exclude from results (e.g. exact duplicates already clustered)
     * @param array<int,bool>|null $skipIntIds Integer IDs to exclude from results (when $asIntIds is true)
     * @return list<string|int> candidate block IDs that share at least one rare ngram with $block
     */
    public function candidatesFor(Block $block, float $maxDocumentFrequency, ?array $skipIds = null, ?array $skipIntIds = null, bool $asIntIds = false): array
    {
        if ($block->ngramBag === null) {
            return [];
        }
        $maxDf = max(1, (int)floor($this->blockCount * $maxDocumentFrequency));
        $seen = [];
        $candidates = [];

        // When returning int IDs, use intPostings and int-based skip set
        $postings = $asIntIds ? $this->intPostings : $this->postings;
        $blockKey = $asIntIds ? ($this->stringToInt[$block->id] ?? null) : $block->id;

        foreach (array_keys($block->ngramBag) as $gram) {
            $posting = $postings[$gram] ?? [];
            if (count($posting) > $maxDf) {
                continue; // too common to be informative
            }
            foreach ($posting as $otherId) {
                if ($blockKey !== null && $otherId === $blockKey) {
                    continue;
                }
                if ($asIntIds) {
                    if ($skipIntIds !== null && isset($skipIntIds[$otherId])) {
                        continue;
                    }
                } else {
                    if ($skipIds !== null && isset($skipIds[$otherId])) {
                        continue;
                    }
                }
                if (!isset($seen[$otherId])) {
                    $seen[$otherId] = true;
                    $candidates[] = $otherId;
                }
            }
        }
        return $candidates;
    }

    private function computeCacheKey(BlockIndex $index): string
    {
        $key = '';
        foreach ($index->all() as $b) {
            $key = sha1($key . $b->structuralHash);
        }
        return $key;
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
        $this->intPostings = $payload['intPostings'] ?? [];
        $this->intToString = $payload['intToString'] ?? [];
        $this->stringToInt = $payload['stringToInt'] ?? [];
        $this->blockCount = $payload['blockCount'] ?? 0;
        $this->nextIntId = $payload['nextIntId'] ?? 0;

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
            'intPostings' => $this->intPostings,
            'intToString' => $this->intToString,
            'stringToInt' => $this->stringToInt,
            'blockCount'  => $this->blockCount,
            'nextIntId'   => $this->nextIntId,
            'blockHashes' => $blockHashes,
        ];

        @file_put_contents($cacheFile, serialize($payload), LOCK_EX);
    }

}
