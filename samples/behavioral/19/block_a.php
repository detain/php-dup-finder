<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Repository\ArticleRepository;
use App\DTO\PaginatedResult;
use Psr\Log\LoggerInterface;

final class ArticleListingService
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function getPaginatedArticles(int $page = 1, int $pageSize = 20, ?int $categoryId = null): PaginatedResult
    {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);

        $offset = ($page - 1) * $pageSize;

        $this->logger->debug('Fetching paginated articles', [
            'page' => $page,
            'page_size' => $pageSize,
            'offset' => $offset,
            'category_id' => $categoryId,
        ]);

        $totalCount = $this->articleRepository->countByCategory($categoryId);
        $articles = $this->articleRepository->findPaginated($offset, $pageSize, $categoryId);

        $totalPages = (int) ceil($totalCount / $pageSize);

        $hasNextPage = $page < $totalPages;
        $hasPreviousPage = $page > 1;

        $nextPage = $hasNextPage ? $page + 1 : null;
        $previousPage = $hasPreviousPage ? $page - 1 : null;

        $this->logger->info('Articles pagination result', [
            'page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'items_count' => count($articles),
        ]);

        return new PaginatedResult(
            items: $articles,
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

    public function getArticlesByDateRange(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $page = 1,
        int $pageSize = 20
    ): PaginatedResult {
        $page = max(1, $page);
        $pageSize = $this->normalizePageSize($pageSize);

        $offset = ($page - 1) * $pageSize;

        $totalCount = $this->articleRepository->countByDateRange($startDate, $endDate);
        $articles = $this->articleRepository->findByDateRangePaginated($offset, $pageSize, $startDate, $endDate);

        $totalPages = (int) ceil($totalCount / $pageSize);

        $hasNextPage = $page < $totalPages;
        $hasPreviousPage = $page > 1;

        return new PaginatedResult(
            items: $articles,
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

    public function getFeaturedArticles(int $limit = 10): array
    {
        $limit = min($limit, self::MAX_PAGE_SIZE);

        $offset = 0;

        return $this->articleRepository->findFeatured($offset, $limit);
    }

    private function normalizePageSize(int $pageSize): int
    {
        if ($pageSize < 1) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return min($pageSize, self::MAX_PAGE_SIZE);
    }
}
