<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminUsersController
{
    private const ALLOWED_SORT = ['id', 'email', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', '25')));
        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc'));
        $search = trim((string) $request->query('q', ''));

        if (!in_array($sortBy, self::ALLOWED_SORT, true)) {
            $sortBy = 'created_at';
        }
        if ($sortDir !== 'asc' && $sortDir !== 'desc') {
            $sortDir = 'desc';
        }

        $query = DB::table('users')
            ->select(['id', 'email', 'display_name', 'role', 'created_at']);

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('email', 'LIKE', $like)
                    ->orWhere('display_name', 'LIKE', $like);
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
