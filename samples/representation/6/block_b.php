<?php
declare(strict_types=1);

namespace Search\Index;

final class ProductSearchDoc
{
    /** @var array<string, mixed> */
    public array $source;

    public function __construct(array $product)
    {
        if (empty($product['sku'])) {
            throw new \InvalidArgumentException('SKU required for indexing');
        }
        if ((float)$product['price'] < 0) {
            throw new \InvalidArgumentException('Price negative');
        }
        $this->source = [
            'product_id' => (int)$product['id'],
            'sku_keyword' => (string)$product['sku'],
            'title_text' => (string)$product['name'],
            'title_suggest' => array_filter(array_map('trim', preg_split('/\s+/', (string)$product['name']) ?: [])),
            'url_slug' => (string)$product['slug'],
            'price_cents' => (int) round(((float)$product['price']) * 100),
            'in_stock' => (int)$product['stock'] > 0,
            'stock_level' => (int)$product['stock'],
            'category_path' => explode('/', (string)$product['category']),
            'image' => $product['image'] ?? null,
            'indexed_at' => gmdate('c'),
        ];
    }

    public function id(): string
    {
        return (string)$this->source['product_id'];
    }

    public function toJson(): string
    {
        return json_encode($this->source, JSON_THROW_ON_ERROR);
    }
}

final class SearchIndexer
{
    public function bulkIndex(array $products): array
    {
        $docs = [];
        foreach ($products as $p) {
            $docs[] = new ProductSearchDoc($p);
        }
        return $docs;
    }
}
