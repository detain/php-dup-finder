<?php
declare(strict_types=1);

namespace Acme\Api\Pagination;

final class PaginationMetaBuilder
{
    public function build(int $page, int $perPage, int $total, string $requestUri): array
    {
        $totalPages = (int) max(1, ceil($total / $perPage));
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;
        $baseUrl = preg_replace('/([?&])page=\d+&?/', '$1', $requestUri) ?? $requestUri;
        $baseUrl = rtrim($baseUrl, '?&');
        $joiner = str_contains($baseUrl, '?') ? '&' : '?';

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $hasNext,
            'has_prev' => $hasPrev,
            'next' => $hasNext ? "{$baseUrl}{$joiner}page=" . ($page + 1) : null,
            'prev' => $hasPrev ? "{$baseUrl}{$joiner}page=" . ($page - 1) : null,
        ];
    }
}

final class ArticlesListEndpoint
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly PaginationMetaBuilder $pagination,
    ) {
    }

    public function handle(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 25)));
        $tag = (string) ($query['tag'] ?? '');

        $total = $tag !== '' ? $this->articles->countByTag($tag) : $this->articles->countAll();
        $items = $tag !== ''
            ? $this->articles->listByTag($tag, $page, $perPage)
            : $this->articles->listAll($page, $perPage);

        $meta = $this->pagination->build($page, $perPage, $total, (string) ($_SERVER['REQUEST_URI'] ?? ''));
        return ['data' => $items, 'meta' => $meta];
    }
}
