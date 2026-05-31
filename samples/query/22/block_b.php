<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EventRepository
{
    public function findUpcomingById(int $id): ?Event
    {
        return Event::query()
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'published')
            ->where('start_date', '>', now())
            ->first();
    }

    public function findUpcomingBySlug(string $slug): ?Event
    {
        return Event::query()
            ->where('slug', '=', $slug)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'published')
            ->where('start_date', '>', now())
            ->first();
    }

    public function getUpcomingEvents(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = Event::query()
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'published')
            ->where('start_date', '>', now());

        if (!empty($filters['venue_id'])) {
            $query->where('venue_id', '=', $filters['venue_id']);
        }

        if (!empty($filters['organizer_id'])) {
            $query->where('organizer_id', '=', $filters['organizer_id']);
        }

        if (!empty($filters['category'])) {
            $query->where('category', '=', $filters['category']);
        }

        if (!empty($filters['is_virtual'])) {
            $query->where('is_virtual', '=', $filters['is_virtual']);
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'LIKE', $searchTerm)
                  ->orWhere('description', 'LIKE', $searchTerm)
                  ->orWhere('venue_name', 'LIKE', $searchTerm);
            });
        }

        if (!empty($filters['price_min'])) {
            $query->where('ticket_price', '>=', $filters['price_min']);
        }

        if (!empty($filters['price_max'])) {
            $query->where('ticket_price', '<=', $filters['price_max']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('start_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('end_date', '<=', $filters['date_to']);
        }

        $sortField = $filters['sort_by'] ?? 'start_date';
        $sortDirection = $filters['sort_direction'] ?? 'asc';

        $allowedSortFields = ['start_date', 'end_date', 'title', 'ticket_price', 'created_at'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'start_date';
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $items = $query
            ->select(['id', 'title', 'slug', 'venue_id', 'organizer_id', 'start_date', 'end_date', 'ticket_price', 'capacity', 'is_virtual'])
            ->with(['venue:id,name,address', 'organizer:id,name,email'])
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

    public function getFeaturedEvents(int $limit = 10): Collection
    {
        return Event::query()
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'published')
            ->where('is_featured', '=', true)
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->limit($limit)
            ->get();
    }

    public function getEventsByVenue(int $venueId, int $limit = 20): Collection
    {
        return Event::query()
            ->where('venue_id', '=', $venueId)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'published')
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->limit($limit)
            ->get();
    }

    public function updateAvailableTickets(int $id, int $change): bool
    {
        return DB::table('events')
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'published')
            ->whereRaw('available_tickets + ? <= capacity', [$change])
            ->increment('available_tickets', $change) > 0;
    }

    public function countUpcomingByOrganizer(int $organizerId): int
    {
        return Event::query()
            ->where('organizer_id', '=', $organizerId)
            ->where('deleted_at', '=', null)
            ->where('status', '=', 'published')
            ->where('start_date', '>', now())
            ->count();
    }
}
