<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Service\SearchIndexer;
use App\Service\PricingEngine;
use Psr\Log\LoggerInterface;

final class AssetCatalogService
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly SearchIndexer $searchIndexer,
        private readonly PricingEngine $pricingEngine,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retrieves all assets with optional filtering and sorting.
     *
     * @param array{filters?: array<string>, sort?: string, order?: string, limit?: int, offset?: int} $options
     * @return array<int, Asset>
     */
    public function getAllAssets(array $options = []): array
    {
        $filters = $options['filters'] ?? [];
        $sort = $options['sort'] ?? 'created_at';
        $order = $options['order'] ?? 'desc';
        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;

        $queryBuilder = $this->assetRepository->createQueryBuilder('a');

        foreach ($filters as $field => $value) {
            $queryBuilder->andWhere("a.{$field} = :{$field}")
                ->setParameter($field, $value);
        }

        $queryBuilder->orderBy("a.{$sort}", $order)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $assets = $queryBuilder->getQuery()->getResult();

        $this->logger->debug('Assets retrieved', [
            'count' => count($assets),
            'filters' => $filters,
        ]);

        return $assets;
    }

    /**
     * Searches assets by keyword across title and description.
     *
     * @return array<int, Asset>
     */
    public function searchAssets(string $keyword, int $limit = 20): array
    {
        $queryBuilder = $this->assetRepository->createQueryBuilder('a');

        $queryBuilder->where('a.title LIKE :keyword')
            ->orWhere('a.description LIKE :keyword')
            ->orWhere('a.filename LIKE :keyword')
            ->setParameter('keyword', '%' . $keyword . '%')
            ->setMaxResults($limit);

        $assets = $queryBuilder->getQuery()->getResult();

        $this->searchIndexer->indexAssets($assets);

        $this->logger->info('Asset search completed', [
            'keyword' => $keyword,
            'results' => count($assets),
        ]);

        return $assets;
    }

    /**
     * Retrieves featured assets based on download count.
     *
     * @return array<int, Asset>
     */
    public function getFeaturedAssets(int $limit = 10): array
    {
        $queryBuilder = $this->assetRepository->createQueryBuilder('a');

        $queryBuilder->select('a', 'SUM(d.downloadCount) as totalDownloads')
            ->leftJoin('a.downloads', 'd')
            ->groupBy('a.id')
            ->orderBy('totalDownloads', 'desc')
            ->setMaxResults($limit);

        $assets = array_map(
            fn($result) => $result[0],
            $queryBuilder->getQuery()->getResult()
        );

        foreach ($assets as $asset) {
            $asset->setFeaturedPrice(
                $this->pricingEngine->calculateFeaturedPrice($asset)
            );
        }

        $this->logger->debug('Featured assets retrieved', [
            'count' => count($assets),
        ]);

        return $assets;
    }

    /**
     * Retrieves related assets based on collection similarity.
     *
     * @return array<int, Asset>
     */
    public function getRelatedAssets(int $assetId, int $limit = 5): array
    {
        $asset = $this->assetRepository->findById($assetId);

        if ($asset === null) {
            return [];
        }

        $queryBuilder = $this->assetRepository->createQueryBuilder('a');

        $queryBuilder->where('a.collection = :collection')
            ->andWhere('a.id != :assetId')
            ->setParameter('collection', $asset->getCollection())
            ->setParameter('assetId', $assetId)
            ->setMaxResults($limit);

        $relatedAssets = $queryBuilder->getQuery()->getResult();

        $this->logger->debug('Related assets retrieved', [
            'asset_id' => $assetId,
            'count' => count($relatedAssets),
        ]);

        return $relatedAssets;
    }
}
