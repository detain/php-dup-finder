<?php
declare(strict_types=1);

namespace Acme\Search\Indexers;

use Acme\Search\SearchClient;
use Acme\Search\Exceptions\IndexException;
use Acme\Search\Metrics;
use Acme\Catalog\ProductRepository;
use Psr\Log\LoggerInterface;

final class ProductSearchIndexer
{
    public function __construct(
        private readonly SearchClient $client,
        private readonly ProductRepository $repo,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $log,
        private readonly string $indexName = 'products_v3'
    ) {
    }

    public function reindexBatch(array $productIds): int
    {
        $bulk = $this->client->openBulk($this->indexName);
        $count = 0;
        $start = microtime(true);
        try {
            foreach ($productIds as $id) {
                $product = $this->repo->find((int)$id);
                if ($product === null) {
                    $bulk->delete((string)$id);
                    continue;
                }
                $doc = [
                    'id'         => $product->id,
                    'type'       => 'product',
                    'updated_at' => $product->updatedAt->format(DATE_ATOM),
                    'boost'      => $product->isFeatured ? 2.0 : 1.0,
                    'name'       => $product->name,
                    'sku'        => $product->sku,
                    'price'      => $product->priceCents / 100,
                    'currency'   => $product->currency,
                    'tags'       => $product->tags,
                    'in_stock'   => $product->stock > 0,
                ];
                $bulk->upsert((string)$product->id, $doc);
                $count++;
            }
            $bulk->commit();
            $this->metrics->increment('search.product.indexed', $count);
        } catch (\Throwable $e) {
            $bulk->discard();
            $this->log->error('product reindex failed', ['err' => $e->getMessage()]);
            $this->metrics->increment('search.product.failed');
            throw new IndexException('product index failed', 0, $e);
        } finally {
            $this->metrics->timing('search.product.duration', microtime(true) - $start);
        }
        return $count;
    }
}
