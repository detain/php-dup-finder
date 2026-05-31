<?php
declare(strict_types=1);

namespace Acme\Cms\Api;

interface PageSource
{
    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function fetch(int $offset, int $limit): array;
}

final class PaginatedResponseBuilder
{
    public function __construct(private readonly string $baseUrl) {}

    /** @return array<string,mixed> */
    public function build(PageSource $source, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $result  = $source->fetch(($page - 1) * $perPage, $perPage);
        $total   = $result['total'];
        $pages   = (int) max(1, ceil($total / $perPage));
        $link    = fn(int $p): string => $this->baseUrl . '?page=' . $p . '&per_page=' . $perPage;
        return [
            'data'  => $result['rows'],
            'meta'  => ['pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $pages,
            ]],
            'links' => [
                'first' => $link(1),
                'prev'  => $page > 1 ? $link($page - 1) : null,
                'next'  => $page < $pages ? $link($page + 1) : null,
                'last'  => $link($pages),
            ],
        ];
    }
}
