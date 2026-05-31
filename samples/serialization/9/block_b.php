<?php

declare(strict_types=1);

namespace App\Api\Transform;

class ProductApiTransformer
{
    public function transform(Product $product, string $format = 'default'): array
    {
        $base = [
            'type' => 'product',
            'id' => $product->getId(),
            'attributes' => [
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'price' => [
                    'amount' => $product->getPrice(),
                    'currency' => $product->getCurrency()
                ],
                'stock_quantity' => $product->getStockQuantity(),
                'is_available' => $product->isAvailable(),
                'tags' => $product->getTags(),
                'created_at' => $product->getCreatedAt()->format('c'),
                'updated_at' => $product->getUpdatedAt()?->format('c')
            ],
            'relationships' => [
                'category' => [
                    'data' => [
                        'type' => 'category',
                        'id' => $product->getCategoryId()
                    ]
                ]
            ]
        ];

        if ($format === 'compact') {
            return [
                'type' => 'product',
                'id' => $product->getId(),
                'attributes' => [
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'is_available' => $product->isAvailable()
                ]
            ];
        }

        if ($format === 'detailed') {
            $base['attributes']['image_url'] = $product->getImageUrl();
            $base['meta'] = [
                'created_timestamp' => $product->getCreatedAt()->getTimestamp(),
                'last_modified' => $product->getUpdatedAt()?->getTimestamp()
            ];
        }

        return $base;
    }

    public function transformMany(array $products, string $format = 'default'): array
    {
        return [
            'data' => array_map(fn(Product $p) => $this->transform($p, $format), $products),
            'meta' => [
                'count' => count($products),
                'format' => $format
            ]
        ];
    }

    public function transformForIndex(array $products): array
    {
        return [
            'data' => array_map(function (Product $p) {
                return [
                    'type' => 'product',
                    'id' => $p->getId(),
                    'attributes' => [
                        'name' => $p->getName(),
                        'price' => $p->getPrice(),
                        'currency' => $p->getCurrency(),
                        'is_available' => $p->isAvailable()
                    ]
                ];
            }, $products),
            'meta' => [
                'count' => count($products)
            ]
        ];
    }

    public function transformForShow(Product $product): array
    {
        return [
            'data' => $this->transform($product, 'detailed'),
            'meta' => [
                'timestamp' => time()
            ]
        ];
    }

    public function transformForCreate(Product $product): array
    {
        return [
            'data' => [
                'type' => 'product',
                'id' => $product->getId(),
                'attributes' => [
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'currency' => $product->getCurrency(),
                    'created_at' => $product->getCreatedAt()->format('c')
                ]
            ]
        ];
    }

    public function transformForUpdate(Product $product): array
    {
        return [
            'data' => [
                'type' => 'product',
                'id' => $product->getId(),
                'attributes' => [
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'updated_at' => $product->getUpdatedAt()?->format('c')
                ]
            ]
        ];
    }

    public function addPagination(array $products, int $total, int $page, int $perPage): array
    {
        $lastPage = (int)ceil($total / $perPage);

        return [
            'data' => array_map(fn(Product $p) => $this->transform($p), $products),
            'meta' => [
                'count' => count($products),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage
            ],
            'links' => [
                'self' => '/products?page=' . $page,
                'first' => '/products?page=1',
                'prev' => $page > 1 ? '/products?page=' . ($page - 1) : null,
                'next' => $page < $lastPage ? '/products?page=' . ($page + 1) : null,
                'last' => '/products?page=' . $lastPage
            ]
        ];
    }
}
