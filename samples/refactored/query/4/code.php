<?php
declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminGridResponder
{
    /**
     * @param list<string> $allowedSort
     * @param list<string> $searchColumns
     */
    public function respond(
        Request $request,
        Builder $query,
        array $allowedSort,
        array $searchColumns,
        string $defaultSort
    ): JsonResponse {
        $perPage = max(1, min(100, (int) $request->query('per_page', '25')));
        $sortBy = (string) $request->query('sort_by', $defaultSort);
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc'));
        $search = trim((string) $request->query('q', ''));

        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = $defaultSort;
        }
        if ($sortDir !== 'asc' && $sortDir !== 'desc') {
            $sortDir = 'desc';
        }

        if ($search !== '' && $searchColumns !== []) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(function ($q) use ($like, $searchColumns): void {
                foreach ($searchColumns as $i => $col) {
                    $i === 0 ? $q->where($col, 'LIKE', $like) : $q->orWhere($col, 'LIKE', $like);
                }
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
