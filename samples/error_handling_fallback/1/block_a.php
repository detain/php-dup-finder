<?php
declare(strict_types=1);

namespace Caching\Product;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Predis\Client as RedisClient;

final class ProductCatalogService
{
    private const CACHE_PREFIX = 'product:';
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly RedisClient $redis,
        private readonly LoggerInterface $logger,
        private readonly ProductSearchClient $searchClient
    ) {}

    public function findById(int $productId): ?Product
    {
        $cacheKey = self::CACHE_PREFIX . $productId;

        // Try cache first
        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                $data = json_decode($cached, true);
                return $this->hydrateProduct($data);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Cache read failed, falling back to database', [
                'key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
        }

        // Cache miss - fetch from database
        $product = $this->entityManager->find(Product::class, $productId);

        if ($product === null) {
            return null;
        }

        // Populate cache
        try {
            $cacheData = $this->serializeProduct($product);
            $this->redis->setex($cacheKey, self::CACHE_TTL_SECONDS, json_encode($cacheData));
        } catch (\Exception $e) {
            $this->logger->warning('Cache write failed', [
                'key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
        }

        return $product;
    }

    public function getFeaturedProducts(int $limit = 10): array
    {
        $cacheKey = 'featured_products:limit:' . $limit;

        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                $productIds = json_decode($cached, true);

                // Fetch from DB using cached IDs
                return $this->entityManager
                    ->createQueryBuilder()
                    ->select('p')
                    ->from(Product::class, 'p')
                    ->where('p.id IN (:ids)')
                    ->setParameter('ids', $productIds)
                    ->getQuery()
                    ->getResult();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Featured products cache read failed', [
                'error' => $e->getMessage()
            ]);
        }

        // Fallback: query database directly
        $products = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.featured = :featured')
            ->setParameter('featured', true)
            ->setMaxResults($limit)
            ->orderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        // Cache the product IDs
        try {
            $productIds = array_map(fn($p) => $p->getId(), $products);
            $this->redis->setex($cacheKey, 300, json_encode($productIds));
        } catch (\Exception $e) {
            $this->logger->warning('Featured products cache write failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $products;
    }

    public function search(string $query): array
    {
        $cacheKey = 'search:' . md5($query);

        // Try cache first
        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                $productIds = json_decode($cached, true);
                return $this->loadProductsByIds($productIds);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Search cache read failed', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback to search service
        try {
            $searchResults = $this->searchClient->search($query);

            try {
                $productIds = array_column($searchResults, 'id');
                $this->redis->setex($cacheKey, 600, json_encode($productIds));
            } catch (\Exception $e) {
                $this->logger->warning('Search cache write failed', [
                    'error' => $e->getMessage()
                ]);
            }

            return $this->loadProductsByIds($productIds);

        } catch (\Exception $e) {
            $this->logger->error('Search service unavailable, falling back to DB LIKE search', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            // Final fallback: database LIKE search
            return $this->entityManager
                ->createQueryBuilder()
                ->select('p')
                ->from(Product::class, 'p')
                ->where('p.name LIKE :query OR p.description LIKE :query')
                ->setParameter('query', '%' . $query . '%')
                ->setMaxResults(50)
                ->getQuery()
                ->getResult();
        }
    }

    private function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'sku' => $product->getSku(),
            'category' => $product->getCategory()?->getName()
        ];
    }

    private function hydrateProduct(array $data): Product
    {
        return $this->entityManager->find(Product::class, $data['id']);
    }

    private function loadProductsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
