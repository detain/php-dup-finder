<?php
declare(strict_types=1);

namespace App\Api\Pagination;

use App\Logging\LoggerInterface;

final class CursorPaginator
{
    private LoggerInterface $logger;
    private int $defaultLimit;
    private int $maxLimit;

    public function __construct(
        LoggerInterface $logger,
        int $defaultLimit = 20,
        int $maxLimit = 100
    ) {
        $this->logger = $logger;
        $this->defaultLimit = $defaultLimit;
        $this->maxLimit = $maxLimit;
    }

    public function paginate(
        array $items,
        int $total,
        ?string $cursor,
        int $limit,
        string $baseUrl
    ): array {
        $limit = min($limit, $this->maxLimit);
        
        $currentOffset = 0;
        if ($cursor !== null) {
            $currentOffset = $this->decodeCursor($cursor);
        }
        
        $hasMore = ($currentOffset + $limit) < $total;
        $hasPrevious = $currentOffset > 0;
        
        $nextCursor = null;
        if ($hasMore) {
            $nextCursor = $this->encodeCursor($currentOffset + $limit);
        }
        
        $previousCursor = null;
        if ($hasPrevious) {
            $previousCursor = $this->encodeCursor(max(0, $currentOffset - $limit));
        }
        
        $this->logger->debug('Cursor pagination computed', [
            'total' => $total,
            'offset' => $currentOffset,
            'limit' => $limit,
            'has_more' => $hasMore,
            'has_previous' => $hasPrevious,
        ]);
        
        return [
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $currentOffset,
                'has_more' => $hasMore,
                'has_previous' => $hasPrevious,
                'next' => $nextCursor ? $this->buildNextUrl($baseUrl, $nextCursor, $limit) : null,
                'previous' => $previousCursor ? $this->buildPreviousUrl($baseUrl, $previousCursor, $limit) : null,
                'first' => $this->buildFirstUrl($baseUrl, $limit),
                'last' => $this->buildLastUrl($baseUrl, $limit, $total),
            ],
        ];
    }

    public function encodeCursor(int $offset): string
    {
        return base64_encode(json_encode(['offset' => $offset]));
    }

    public function decodeCursor(string $cursor): int
    {
        $decoded = json_decode(base64_decode($cursor), true);
        return $decoded['offset'] ?? 0;
    }

    private function buildNextUrl(string $baseUrl, string $cursor, int $limit): string
    {
        return $baseUrl . '?cursor=' . urlencode($cursor) . '&limit=' . $limit;
    }

    private function buildPreviousUrl(string $baseUrl, string $cursor, int $limit): string
    {
        return $baseUrl . '?cursor=' . urlencode($cursor) . '&limit=' . $limit;
    }

    private function buildFirstUrl(string $baseUrl, int $limit): string
    {
        return $baseUrl . '?limit=' . $limit;
    }

    private function buildLastUrl(string $baseUrl, int $limit, int $total): string
    {
        $lastOffset = (int)(($total - 1) / $limit) * $limit;
        $lastCursor = $this->encodeCursor($lastOffset);
        return $baseUrl . '?cursor=' . urlencode($lastCursor) . '&limit=' . $limit;
    }

    public function getDefaultLimit(): int
    {
        return $this->defaultLimit;
    }

    public function getMaxLimit(): int
    {
        return $this->maxLimit;
    }
}
