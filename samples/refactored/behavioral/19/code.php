<?php

declare(strict_types=1);

namespace App\Service\Pagination;

use Psr\Log\LoggerInterface;

final class UnifiedPaginationService
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    /** @var array<string, array{count: callable(mixed): int, find: callable(int, int, mixed): array}> */
    private array $entityHandlers = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->registerHandlers();
    }

    private function registerHandlers(): void
    {
        $this->entityHandlers['article'] = [
            'count' => fn($categoryId) => \App\Repository\ArticleRepository::countByCategory($categoryId),
            'find' => fn($offset, $limit, $categoryId) => \App\Repository\ArticleRepository::findPaginated($offset, $limit, $categoryId),
        ];

        $this->entityHandlers['product'] = [
            'count' => fn($departmentId) => \App\Repository\ProductRepository::countByDepartment($departmentId),
            'find' => fn($offset, $limit, $departmentId) => \App\Repository\ProductRepository::findPaginated($offset, $limit, $departmentId),
        ];

        $this->entityHandlers['order'] = [
            'count' => fn($userId) => \App\Repository\OrderRepository::countByUser($userId),
            'find' => fn($offset, $limit, $userId) => \App\Repository\OrderRepository::findByUserPaginated($userId, $offset, $limit),
        ];
    }

    public function paginate(string $entityType, int $page = 1, int $pageSize = 20, mixed $filter = null): PaginatedResult
    {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);
        $offset = ($page - 1) * $pageSize;

        $handler = $this->entityHandlers[$entityType] ?? null;

        if ($handler === null) {
            throw new \InvalidArgumentException("Unknown entity type: {$entityType}");
        }

        $this->logger->debug("Fetching paginated {$entityType}", [
            'page' => $page,
            'page_size' => $pageSize,
            'offset' => $offset,
        ]);

        $totalCount = $handler['count']($filter);
        $items = $handler['find']($offset, $pageSize, $filter);

        return $this->buildPaginatedResult($items, $page, $pageSize, $totalCount);
    }

    public function paginateWithDateRange(
        string $entityType,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $page = 1,
        int $pageSize = 20
    ): PaginatedResult {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);
        $offset = ($page - 1) * $pageSize;

        $repository = $this->getRepository($entityType);
        $totalCount = $repository->countByDateRange($startDate, $endDate);
        $items = $repository->findByDateRangePaginated($offset, $pageSize, $startDate, $endDate);

        return $this->buildPaginatedResult($items, $page, $pageSize, $totalCount);
    }

    public function paginateWithSearch(
        string $entityType,
        string $query,
        int $page = 1,
        int $pageSize = 20
    ): PaginatedResult {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);
        $offset = ($page - 1) * $pageSize;

        $repository = $this->getRepository($entityType);
        $totalCount = $repository->countBySearch($query);
        $items = $repository->findBySearchPaginated($offset, $pageSize, $query);

        return $this->buildPaginatedResult($items, $page, $pageSize, $totalCount);
    }

    private function buildPaginatedResult(array $items, int $page, int $pageSize, int $totalCount): PaginatedResult
    {
        $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $pageSize) : 0;
        $hasNextPage = $page < $totalPages;
        $hasPreviousPage = $page > 1;

        return new PaginatedResult(
            items: $items,
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

    private function normalizePageSize(int $pageSize): int
    {
        if ($pageSize < 1) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return min($pageSize, self::MAX_PAGE_SIZE);
    }

    private function getRepository(string $entityType): object
    {
        return match ($entityType) {
            'article' => new \App\Repository\ArticleRepository(),
            'product' => new \App\Repository\ProductRepository(),
            'order' => new \App\Repository\OrderRepository(),
            default => throw new \InvalidArgumentException("Unknown entity type: {$entityType}"),
        };
    }
}
