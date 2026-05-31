<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\SearchIndexer;
use App\Service\PricingEngine;
use Psr\Log\LoggerInterface;

final class ProductCatalogService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly SearchIndexer $searchIndexer,
        private readonly PricingEngine $pricingEngine,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retrieves all products with optional filtering and sorting.
     *
     * @param array{filters?: array<string>, sort?: string, order?: string, limit?: int, offset?: int} $options
     * @return array<int, Product>
     */
    public function getAllProducts(array $options = []): array
    {
        $filters = $options['filters'] ?? [];
        $sort = $options['sort'] ?? 'created_at';
        $order = $options['order'] ?? 'desc';
        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;

        $queryBuilder = $this->productRepository->createQueryBuilder('p');

        foreach ($filters as $field => $value) {
            $queryBuilder->andWhere("p.{$field} = :{$field}")
                ->setParameter($field, $value);
        }

        $queryBuilder->orderBy("p.{$sort}", $order)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $products = $queryBuilder->getQuery()->getResult();

        $this->logger->debug('Products retrieved', [
            'count' => count($products),
            'filters' => $filters,
        ]);

        return $products;
    }

    /**
     * Searches products by keyword across name and description.
     *
     * @return array<int, Product>
     */
    public function searchProducts(string $keyword, int $limit = 20): array
    {
        $queryBuilder = $this->productRepository->createQueryBuilder('p');

        $queryBuilder->where('p.name LIKE :keyword')
            ->orWhere('p.description LIKE :keyword')
            ->orWhere('p.sku LIKE :keyword')
            ->setParameter('keyword', '%' . $keyword . '%')
            ->setMaxResults($limit);

        $products = $queryBuilder->getQuery()->getResult();

        $this->searchIndexer->indexProducts($products);

        $this->logger->info('Product search completed', [
            'keyword' => $keyword,
            'results' => count($products),
        ]);

        return $products;
    }

    /**
     * Retrieves featured products based on sales velocity.
     *
     * @return array<int, Product>
     */
    public function getFeaturedProducts(int $limit = 10): array
    {
        $queryBuilder = $this->productRepository->createQueryBuilder('p');

        $queryBuilder->select('p', 'SUM(s.quantity) as totalSold')
            ->leftJoin('p.sales', 's')
            ->groupBy('p.id')
            ->orderBy('totalSold', 'desc')
            ->setMaxResults($limit);

        $products = array_map(
            fn($result) => $result[0],
            $queryBuilder->getQuery()->getResult()
        );

        foreach ($products as $product) {
            $product->setFeaturedPrice(
                $this->pricingEngine->calculateFeaturedPrice($product)
            );
        }

        $this->logger->debug('Featured products retrieved', [
            'count' => count($products),
        ]);

        return $products;
    }

    /**
     * Retrieves related products based on category similarity.
     *
     * @return array<int, Product>
     */
    public function getRelatedProducts(int $productId, int $limit = 5): array
    {
        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            return [];
        }

        $queryBuilder = $this->productRepository->createQueryBuilder('p');

        $queryBuilder->where('p.category = :category')
            ->andWhere('p.id != :productId')
            ->setParameter('category', $product->getCategory())
            ->setParameter('productId', $productId)
            ->setMaxResults($limit);

        $relatedProducts = $queryBuilder->getQuery()->getResult();

        $this->logger->debug('Related products retrieved', [
            'product_id' => $productId,
            'count' => count($relatedProducts),
        ]);

        return $relatedProducts;
    }
}
