<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Repository\OrderRepository;
use App\DTO\PaginatedResult;
use Psr\Log\LoggerInterface;

final class OrderHistoryService
{
    private const DEFAULT_PAGE_SIZE = 15;
    private const MAX_PAGE_SIZE = 50;

    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function getPaginatedOrders(int $userId, int $page = 1, int $pageSize = 15): PaginatedResult
    {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);

        $offset = ($page - 1) * $pageSize;

        $this->logger->debug('Fetching paginated orders', [
            'user_id' => $userId,
            'page' => $page,
            'page_size' => $pageSize,
            'offset' => $offset,
        ]);

        $totalCount = $this->orderRepository->countByUser($userId);
        $orders = $this->orderRepository->findByUserPaginated($userId, $offset, $pageSize);

        $totalPages = (int) ceil($totalCount / $pageSize);

        $hasNextPage = $page < $totalPages;
        $hasPreviousPage = $page > 1;

        $nextPage = $hasNextPage ? $page + 1 : null;
        $previousPage = $hasPreviousPage ? $page - 1 : null;

        $this->logger->info('Orders pagination result', [
            'user_id' => $userId,
            'page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'items_count' => count($orders),
        ]);

        return new PaginatedResult(
            items: $orders,
            page: $page,
            pageSize: $pageSize,
            totalCount: $totalCount,
            totalPages: $totalPages,
            hasNextPage: $hasNextPage,
            hasPreviousPage: $hasPreviousPage,
            nextPage: $nextPage,
            previousPage: $previousPage,
        );
    }

    public function getOrdersByStatus(string $status, int $page = 1, int $pageSize = 15): PaginatedResult
    {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);

        $offset = ($page - 1) * $pageSize;

        $totalCount = $this->orderRepository->countByStatus($status);
        $orders = $this->orderRepository->findByStatusPaginated($status, $offset, $pageSize);

        $totalPages = (int) ceil($totalCount / $pageSize);

        $hasNextPage = $page < $totalPages;
        $hasPreviousPage = $page > 1;

        return new PaginatedResult(
            items: $orders,
            page: $page,
            pageSize: $pageSize,
            totalCount: $totalCount,
            totalPages: $totalPages,
            hasNextPage: $hasNextPage,
            hasPreviousPage: $hasPreviousPage,
            nextPage: $hasNextPage ? $page + 1 : null,
            previousPage: $hasPreviousPage ? $page - 1 : null,
        );
    }

    public function getRecentOrders(int $userId, int $limit = 5): array
    {
        $limit = min($limit, self::MAX_PAGE_SIZE);

        $offset = 0;

        return $this->orderRepository->findRecentByUser($userId, $offset, $limit);
    }

    private function normalizePageSize(int $pageSize): int
    {
        if ($pageSize < 1) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return min($pageSize, self::MAX_PAGE_SIZE);
    }
}
