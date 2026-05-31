<?php

declare(strict_types=1);

namespace App\Api\Hal;

class ProductHalBuilder
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function build(Product $product): array
    {
        $hal = [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'price' => [
                'amount' => $product->getPrice(),
                'currency' => $product->getCurrency()
            ],
            'is_available' => $product->isAvailable(),
            'stock_quantity' => $product->getStockQuantity(),
            'tags' => $product->getTags(),
            'created_at' => $product->getCreatedAt()->format('c'),
            'updated_at' => $product->getUpdatedAt()?->format('c'),
            '_links' => [
                'self' => [
                    'href' => $this->buildSelfLink($product->getId())
                ],
                'edit' => [
                    'href' => $this->buildEditLink($product->getId())
                ],
                'category' => [
                    'href' => $this->buildCategoryLink($product->getCategoryId())
                ],
                'image' => [
                    'href' => $product->getImageUrl()
                ]
            ],
            '_embedded' => []
        ];

        $hal['_links']['curies'] = [
            [
                'name' => 'api',
                'href' => $this->baseUrl . '/docs/rels/{rel}',
                'templated' => true
            ]
        ];

        return $hal;
    }

    public function buildCollection(array $products, int $total, int $page, int $perPage): array
    {
        $hal = [
            'count' => count($products),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            '_links' => [
                'self' => [
                    'href' => $this->buildCollectionLink($page, $perPage)
                ]
            ],
            '_embedded' => [
                'products' => array_map(fn(Product $p) => $this->build($p), $products)
            ]
        ];

        if ($page > 1) {
            $hal['_links']['prev'] = [
                'href' => $this->buildCollectionLink($page - 1, $perPage)
            ];
        }

        if ($page * $perPage < $total) {
            $hal['_links']['next'] = [
                'href' => $this->buildCollectionLink($page + 1, $perPage)
            ];
        }

        $hal['_links']['first'] = [
            'href' => $this->buildCollectionLink(1, $perPage)
        ];

        $hal['_links']['last'] = [
            'href' => $this->buildCollectionLink((int)ceil($total / $perPage), $perPage)
        ];

        $hal['_links']['curies'] = [
            [
                'name' => 'api',
                'href' => $this->baseUrl . '/docs/rels/{rel}',
                'templated' => true
            ]
        ];

        return $hal;
    }

    private function buildSelfLink(string $id): string
    {
        return $this->baseUrl . '/products/' . $id;
    }

    private function buildEditLink(string $id): string
    {
        return $this->baseUrl . '/products/' . $id . '/edit';
    }

    private function buildCategoryLink(string $categoryId): string
    {
        return $this->baseUrl . '/categories/' . $categoryId;
    }

    private function buildCollectionLink(int $page, int $perPage): string
    {
        return $this->baseUrl . '/products?page=' . $page . '&per_page=' . $perPage;
    }
}
