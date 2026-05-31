<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\CatalogItemInterface;
use App\Repository\CatalogRepositoryInterface;
use App\Service\SearchIndexerInterface;
use App\Service\PricingEngineInterface;
use Psr\Log\LoggerInterface;

final class CatalogService
{
    public function __construct(
        private readonly CatalogRepositoryInterface $repository,
        private readonly SearchIndexerInterface $searchIndexer,
        private readonly PricingEngineInterface $pricingEngine,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retrieves all catalog items with optional filtering and sorting.
     *
     * @param array<string, mixed> $options
     * @return array<int, CatalogItemInterface>
     */
    public function getAll(array $options = []): array
    {
        $filters = $options['filters'] ?? [];
        $sort = $options['sort'] ?? 'created_at';
        $order = $options['order'] ?? 'desc';
        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;

        $queryBuilder = $this->repository->createQueryBuilder('c');

        foreach ($filters as $field => $value) {
            $queryBuilder->andWhere("c.{$field} = :{$field}")
                ->setParameter($field, $value);
        }

        $queryBuilder->orderBy("c.{$sort}", $order)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $items = $queryBuilder->getQuery()->getResult();

        $this->logger->debug('Catalog items retrieved', [
            'count' => count($items),
            'filters' => $filters,
        ]);

        return $items;
    }

    /**
     * Searches catalog items by keyword across searchable fields.
     *
     * @return array<int, CatalogItemInterface>
     */
    public function search(string $keyword, int $limit = 20): array
    {
        $searchableFields = $this->repository->getSearchableFields();
        $queryBuilder = $this->repository->createQueryBuilder('c');

        $conditions = [];
        foreach ($searchableFields as $field) {
            $conditions[] = "c.{$field} LIKE :keyword";
        }

        $queryBuilder->where(implode(' OR ', $conditions))
            ->setParameter('keyword', '%' . $keyword . '%')
            ->setMaxResults($limit);

        $items = $queryBuilder->getQuery()->getResult();
        $this->searchIndexer->index($items);

        $this->logger->info('Catalog search completed', [
            'keyword' => $keyword,
            'results' => count($items),
        ]);

        return $items;
    }

    /**
     * Retrieves featured items based on popularity metric.
     *
     * @return array<int, CatalogItemInterface>
     */
    public function getFeatured(int $limit = 10): array
    {
        $popularityJoin = $this->repository->getPopularityJoinField();
        $popularityAlias = $this->repository->getPopularityAlias();

        $queryBuilder = $this->repository->createQueryBuilder('c');

        $queryBuilder->select('c', "SUM({$popularityAlias}.quantity) as totalPopularity")
            ->leftJoin("c.{$popularityJoin}", $popularityAlias)
            ->groupBy('c.id')
            ->orderBy('totalPopularity', 'desc')
            ->setMaxResults($limit);

        $items = array_map(
            fn($result) => $result[0],
            $queryBuilder->getQuery()->getResult()
        );

        foreach ($items as $item) {
            $item->setFeaturedPrice(
                $this->pricingEngine->calculateFeaturedPrice($item)
            );
        }

        $this->logger->debug('Featured items retrieved', [
            'count' => count($items),
        ]);

        return $items;
    }

    /**
     * Retrieves related items based on categorization.
     *
     * @return array<int, CatalogItemInterface>
     */
    public function getRelated(int $itemId, int $limit = 5): array
    {
        $item = $this->repository->findById($itemId);

        if ($item === null) {
            return [];
        }

        $categoryField = $this->repository->getCategoryField();
        $queryBuilder = $this->repository->createQueryBuilder('c');

        $queryBuilder->where("c.{$categoryField} = :category")
            ->andWhere('c.id != :itemId')
            ->setParameter('category', $item->getCategory())
            ->setParameter('itemId', $itemId)
            ->setMaxResults($limit);

        $relatedItems = $queryBuilder->getQuery()->getResult();

        $this->logger->debug('Related items retrieved', [
            'item_id' => $itemId,
            'count' => count($relatedItems),
        ]);

        return $relatedItems;
    }
}
