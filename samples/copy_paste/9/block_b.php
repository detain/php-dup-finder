<?php
declare(strict_types=1);

namespace Acme\Api\Comments;

final class CommentsListEndpoint
{
    public function __construct(private readonly CommentRepository $comments)
    {
    }

    public function handle(int $articleId, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['per_page'] ?? 10)));

        $total = $this->comments->countForArticle($articleId);
        $items = $this->comments->listForArticle($articleId, $page, $perPage);

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

        return ['article_id' => $articleId, 'data' => $items, 'meta' => $meta];
    }
}
