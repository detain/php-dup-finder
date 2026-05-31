<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ArticleRepository
{
    public function findPublishedById(int $id): ?Article
    {
        return Article::query()
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('is_published', '=', true)
            ->where('publish_at', '<=', now())
            ->whereRaw('(publish_until IS NULL OR publish_until > ?)', [now()])
            ->first();
    }

    public function findPublishedBySlug(string $slug): ?Article
    {
        return Article::query()
            ->where('slug', '=', $slug)
            ->where('deleted_at', '=', null)
            ->where('is_published', '=', true)
            ->where('publish_at', '<=', now())
            ->whereRaw('(publish_until IS NULL OR publish_until > ?)', [now()])
            ->first();
    }

    public function getPublishedArticles(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = Article::query()
            ->where('deleted_at', '=', null)
            ->where('is_published', '=', true)
            ->where('publish_at', '<=', now())
            ->whereRaw('(publish_until IS NULL OR publish_until > ?)', [now()]);

        if (!empty($filters['category_id'])) {
            $query->where('category_id', '=', $filters['category_id']);
        }

        if (!empty($filters['author_id'])) {
            $query->where('author_id', '=', $filters['author_id']);
        }

        if (!empty($filters['tag'])) {
            $query->whereHas('tags', function ($q) use ($filters) {
                $q->where('name', '=', $filters['tag']);
            });
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'LIKE', $searchTerm)
                  ->orWhere('excerpt', 'LIKE', $searchTerm)
                  ->orWhere('content', 'LIKE', $searchTerm);
            });
        }

        if (!empty($filters['featured'])) {
            $query->where('is_featured', '=', $filters['featured']);
        }

        if (!empty($filters['created_after'])) {
            $query->where('created_at', '>=', $filters['created_after']);
        }

        if (!empty($filters['created_before'])) {
            $query->where('created_at', '<=', $filters['created_before']);
        }

        $sortField = $filters['sort_by'] ?? 'publish_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        $allowedSortFields = ['publish_at', 'created_at', 'title', 'view_count'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'publish_at';
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $items = $query
            ->select(['id', 'title', 'slug', 'excerpt', 'author_id', 'category_id', 'publish_at', 'view_count', 'is_featured'])
            ->with(['author:id,name,avatar', 'category:id,name,slug'])
            ->orderBy($sortField, $sortDirection)
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function getFeaturedArticles(int $limit = 10): Collection
    {
        return Article::query()
            ->where('deleted_at', '=', null)
            ->where('is_published', '=', true)
            ->where('is_featured', '=', true)
            ->where('publish_at', '<=', now())
            ->whereRaw('(publish_until IS NULL OR publish_until > ?)', [now()])
            ->orderBy('publish_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getRelatedArticles(int $articleId, int $limit = 5): Collection
    {
        $article = $this->findPublishedById($articleId);

        if (!$article) {
            return new Collection();
        }

        return Article::query()
            ->where('id', '!=', $articleId)
            ->where('deleted_at', '=', null)
            ->where('is_published', '=', true)
            ->where('publish_at', '<=', now())
            ->where('category_id', '=', $article->category_id)
            ->orderBy('publish_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function incrementViewCount(int $id): bool
    {
        return DB::table('articles')
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('is_published', '=', true)
            ->increment('view_count') > 0;
    }

    public function countPublishedByAuthor(int $authorId): int
    {
        return Article::query()
            ->where('author_id', '=', $authorId)
            ->where('deleted_at', '=', null)
            ->where('is_published', '=', true)
            ->where('publish_at', '<=', now())
            ->count();
    }
}
