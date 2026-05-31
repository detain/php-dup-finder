<?php
declare(strict_types=1);

namespace Acme\Api\Search;

final class SearchResultsEndpoint
{
    public function __construct(private readonly SearchService $search)
    {
    }

    public function handle(array $query): array
    {
        $q = trim((string) ($query['q'] ?? ''));
        if ($q === '') {
            return ['data' => [], 'meta' => ['page' => 1, 'total' => 0]];
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['per_page'] ?? 20)));

        $resultSet = $this->search->query($q, $page, $perPage);
        $total = $resultSet->total();
        $items = $resultSet->hits();

        // ---- BEGIN copy-pasted pagination meta builder ----
        $totalPages = (int) max(1, ceil($total / $perPage));
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;
        $baseUrl = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $baseUrl = preg_replace('/([?&])page=\d+&?/', '$1', $baseUrl) ?? $baseUrl;
        $baseUrl = rtrim($baseUrl, '?&');
        $joiner = str_contains($baseUrl, '?') ? '&' : '?';
        $meta = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $hasNext,
            'has_prev' => $hasPrev,
            'next' => $hasNext ? "{$baseUrl}{$joiner}page=" . ($page + 1) : null,
            'prev' => $hasPrev ? "{$baseUrl}{$joiner}page=" . ($page - 1) : null,
        ];
        // ---- END copy-pasted pagination meta builder ----

        return ['query' => $q, 'data' => $items, 'meta' => $meta];
    }
}
