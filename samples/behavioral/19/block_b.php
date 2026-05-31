<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Repository\ProductRepository;
use App\DTO\PaginatedResult;
use Psr\Log\LoggerInterface;

final class ProductCatalogService
{
    private const DEFAULT_PAGE_SIZE = 24;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function getPaginatedProducts(int $page = 1, int $pageSize = 24, ?int $departmentId = null): PaginatedResult
    {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);

        $offset = ($page - 1) * $pageSize;

        $this->logger->debug('Fetching paginated products', [
            'page' => $page,
            'page_size' => $pageSize,
            'offset' => $offset,
            'department_id' => $departmentId,
        ]);

        $totalCount = $this->productRepository->countByDepartment($departmentId);
        $products = $this->productRepository->findPaginated($offset, $pageSize, $departmentId);

        $totalPages = (int) ceil($totalCount / $pageSize);

        $hasNextPage = $page < $totalPages;
        $hasPreviousPage = $page > 1;

        $nextPage = $hasNextPage ? $page + 1 : null;
        $previousPage = $hasPreviousPage ? $page - 1 : null;

        $this->logger->info('Products pagination result', [
            'page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'items_count' => count($products),
        ]);

        return new PaginatedResult(
            items: $products,
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

    public function getProductsBySearch(string $query, int $page = 1, int $pageSize = 24): PaginatedResult
    {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);

        $offset = ($page - 1) * $pageSize;

        $totalCount = $this->productRepository->countBySearch($query);
        $products = $this->productRepository->findBySearchPaginated($offset, $pageSize, $query);

        $totalPages = (int) ceil($totalCount / $pageSize);

        $hasNextPage = $page < $totalPages;
        $hasPreviousPage = $page > 1;

        return new PaginatedResult(
            items: $products,
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

    public function getOnSaleProducts(int $limit = 20): array
    {
        $limit = min($limit, self::MAX_PAGE_SIZE);

        $offset = 0;

        return $this->productRepository->findOnSale($offset, $limit);
    }

    private function normalizePageSize(int $pageSize): int
    {
        if ($pageSize < 1) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return min($pageSize, self::MAX_PAGE_SIZE);
    }
}
