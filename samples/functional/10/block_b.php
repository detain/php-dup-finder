<?php
declare(strict_types=1);

namespace Acme\Cms\Api\Articles;

use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\TransformerAbstract;

final class FractalPager
{
    public function __construct(
        private readonly \Illuminate\Database\Eloquent\Builder $articles,
        private readonly Manager $fractal,
        private readonly string $baseUrl,
    ) {}

    /** @return array<string,mixed> */
    public function paginate(int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $paginator = $this->articles
            ->where('published', true)
            ->orderByDesc('published_at')
            ->paginate($perPage, ['*'], 'page', $page);
        $paginator->setPath($this->baseUrl);
        $paginator->appends(['per_page' => $perPage]);
        $transformer = new class extends TransformerAbstract {
            /** @return array<string,mixed> */
            public function transform(\stdClass $article): array
            {
                return [
                    'id'           => (int) $article->id,
                    'title'        => (string) $article->title,
                    'slug'         => (string) $article->slug,
                    'excerpt'      => (string) $article->excerpt,
                    'published_at' => (string) $article->published_at,
                ];
            }
        };
        $resource = new Collection($paginator->items(), $transformer);
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));
        $payload = $this->fractal->createData($resource)->toArray();
        $totalPages = (int) max(1, ceil($paginator->total() / $perPage));
        return [
            'data' => $payload['data'] ?? [],
            'meta' => ['pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $perPage,
                'current_page' => $paginator->currentPage(),
                'total_pages'  => $totalPages,
            ]],
            'links' => [
                'first' => $paginator->url(1),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
                'last'  => $paginator->url($totalPages),
            ],
        ];
    }
}
