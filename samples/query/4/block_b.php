<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminArticlesController
{
    private const ALLOWED_SORT = ['id', 'title', 'published_at', 'author_id'];

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '25')));
        $sortBy = (string) $request->query('sort_by', 'published_at');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc'));
        $search = trim((string) $request->query('q', ''));

        if (!in_array($sortBy, self::ALLOWED_SORT, true)) {
            $sortBy = 'published_at';
        }
        if ($sortDir !== 'asc' && $sortDir !== 'desc') {
            $sortDir = 'desc';
        }

        $query = DB::table('articles')
            ->select(['id', 'title', 'slug', 'author_id', 'published_at']);

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('title', 'LIKE', $like)
                    ->orWhere('slug', 'LIKE', $like);
            });
        }

        $page = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return new JsonResponse([
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
