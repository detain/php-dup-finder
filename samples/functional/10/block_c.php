<?php
declare(strict_types=1);

namespace Acme\Cms\Api\Articles;

final class JsonApiPager
{
    public function __construct(private readonly \Doctrine\DBAL\Connection $db, private readonly string $baseUrl) {}

    /** @return array<string,mixed> */
    public function build(int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $totalRow = $this->db->fetchAssociative('SELECT COUNT(*) AS c FROM articles WHERE published = 1');
        $total    = (int) ($totalRow['c'] ?? 0);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, title, slug, excerpt, published_at FROM articles
             WHERE published = 1
             ORDER BY published_at DESC
             LIMIT :lim OFFSET :off',
            ['lim' => $perPage, 'off' => $offset],
            ['lim' => \PDO::PARAM_INT, 'off' => \PDO::PARAM_INT],
        );
        $resources = [];
        foreach ($rows as $row) {
            $resources[] = [
                'type'       => 'articles',
                'id'         => (string) $row['id'],
                'attributes' => [
                    'title'        => (string) $row['title'],
                    'slug'         => (string) $row['slug'],
                    'excerpt'      => (string) $row['excerpt'],
                    'published_at' => (string) $row['published_at'],
                ],
            ];
        }
        $totalPages = (int) max(1, ceil($total / $perPage));
        $url = fn(int $p): string => $this->baseUrl . '?page[number]=' . $p . '&page[size]=' . $perPage;
        return [
            'data'  => $resources,
            'meta'  => ['pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $totalPages,
            ]],
            'links' => [
                'first' => $url(1),
                'prev'  => $page > 1 ? $url($page - 1) : null,
                'next'  => $page < $totalPages ? $url($page + 1) : null,
                'last'  => $url($totalPages),
            ],
        ];
    }
}
