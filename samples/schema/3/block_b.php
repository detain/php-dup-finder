<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

/**
 * Elasticsearch index mapping for products.
 * This mapping is duplicated from:
 * - Doctrine entity: src/Domain/Product/Entity/Product.php
 * - Database table: products
 * - GraphQL schema
 * - OpenAPI spec
 *
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/mapping.html
 */
class ProductIndexMapping
{
    public const INDEX_NAME = 'products';

    public static function getMapping(): array
    {
        return [
            'settings' => [
                'number_of_shards' => 3,
                'number_of_replicas' => 1,
                'analysis' => [
                    'analyzer' => [
                        'product_analyzer' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'asciifolding', 'product_stemmer'],
                        ],
                    ],
                    'filter' => [
                        'product_stemmer' => [
                            'type' => 'stemmer',
                            'language' => 'english',
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'keyword'],
                    'sku' => ['type' => 'keyword'],
                    'name' => [
                        'type' => 'text',
                        'analyzer' => 'product_analyzer',
                        'fields' => [
                            'keyword' => ['type' => 'keyword'],
                            'suggest' => [
                                'type' => 'completion',
                                'analyzer' => 'simple',
                            ],
                        ],
                    ],
                    'description' => [
                        'type' => 'text',
                        'analyzer' => 'product_analyzer',
                    ],
                    'category' => ['type' => 'keyword'],
                    'category_path' => ['type' => 'keyword'],
                    'price' => ['type' => 'scaled_float', 'scaling_factor' => 100],
                    'currency' => ['type' => 'keyword'],
                    'is_active' => ['type' => 'boolean'],
                    'attributes' => [
                        'type' => 'nested',
                        'properties' => [
                            'key' => ['type' => 'keyword'],
                            'value' => ['type' => 'keyword'],
                        ],
                    ],
                    'images' => [
                        'type' => 'nested',
                        'properties' => [
                            'url' => ['type' => 'keyword'],
                            'display_order' => ['type' => 'integer'],
                        ],
                    ],
                    'inventory' => [
                        'properties' => [
                            'quantity' => ['type' => 'integer'],
                            'available' => ['type' => 'integer'],
                            'low_stock_threshold' => ['type' => 'integer'],
                            'in_stock' => ['type' => 'boolean'],
                        ],
                    ],
                    'rating' => [
                        'properties' => [
                            'average' => ['type' => 'float'],
                            'count' => ['type' => 'integer'],
                        ],
                    ],
                    'search_vector' => [
                        'type' => 'text',
                        'analyzer' => 'product_analyzer',
                    ],
                    'created_at' => ['type' => 'date'],
                    'updated_at' => ['type' => 'date'],
                ],
            ],
        ];
    }

    public static function getSearchableAttributes(): array
    {
        return [
            'name^3',
            'description^1',
            'sku^2',
            'category^1',
            'attributes.value^0.5',
        ];
    }

    public static function getFilterableAttributes(): array
    {
        return [
            'category',
            'price',
            'currency',
            'is_active',
            'inventory.in_stock',
            'rating.average',
        ];
    }

    public static function getSortableAttributes(): array
    {
        return [
            'price',
            'created_at',
            'updated_at',
            'name.keyword',
            'rating.average',
        ];
    }
}
