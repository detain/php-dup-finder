<?php
declare(strict_types=1);

namespace Acme\Cms\Api\Articles;

final class ArrayPager
{
    public function __construct(private readonly \PDO $pdo, private readonly string $baseUrl) {}

    /** @return array{data:list<array<string,mixed>>,meta:array{pagination:array{total:int,per_page:int,current_page:int,total_pages:int}},links:array{first:string,prev:?string,next:?string,last:string}} */
    public function listing(int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $count   = (int) $this->pdo->query('SELECT COUNT(*) FROM articles WHERE published = 1')->fetchColumn();
        $stmt    = $this->pdo->prepare(
            'SELECT id, title, slug, excerpt, published_at FROM articles
             WHERE published = 1
             ORDER BY published_at DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $data = array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'title'        => (string) $r['title'],
                'slug'         => (string) $r['slug'],
                'excerpt'      => (string) $r['excerpt'],
                'published_at' => (string) $r['published_at'],
            ];
        }, $rows);
        $totalPages = (int) max(1, ceil($count / $perPage));
        $linkFor = fn(int $p): string => $this->baseUrl . '?page=' . $p . '&per_page=' . $perPage;
        return [
            'data' => $data,
            'meta' => ['pagination' => [
                'total'        => $count,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $totalPages,
            ]],
            'links' => [
                'first' => $linkFor(1),
                'prev'  => $page > 1 ? $linkFor($page - 1) : null,
                'next'  => $page < $totalPages ? $linkFor($page + 1) : null,
                'last'  => $linkFor($totalPages),
            ],
        ];
    }
}
