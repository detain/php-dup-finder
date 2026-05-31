<?php

declare(strict_types=1);

namespace App\Domain\Shared\Schema;

/**
 * Centralized Product schema registry.
 * Single source of truth for all Product schema definitions.
 */
final class ProductSchemaRegistry
{
    public const TABLE_NAME = 'products';
    public const ELASTICSEARCH_INDEX = 'products';
    public const GRAPHQL_TYPE = 'Product';

    public static function getDatabaseColumns(): array
    {
        return [
            'id' => ['type' => 'char', 'length' => 36, 'primary' => true],
            'sku' => ['type' => 'varchar', 'length' => 100, 'unique' => true],
            'name' => ['type' => 'varchar', 'length' => 255],
            'description' => ['type' => 'text'],
            'category' => ['type' => 'varchar', 'length' => 100, 'index' => true],
            'price' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2],
            'currency' => ['type' => 'char', 'length' => 3],
            'is_active' => ['type' => 'boolean'],
            'attributes' => ['type' => 'json'],
        ];
    }

    public static function getElasticsearchMapping(): array
    {
        return [
            'properties' => [
                'id' => ['type' => 'keyword'],
                'sku' => ['type' => 'keyword'],
                'name' => ['type' => 'text'],
                'description' => ['type' => 'text'],
                'category' => ['type' => 'keyword'],
                'price' => ['type' => 'scaled_float'],
            ],
        ];
    }

    public static function getGraphQLFields(): array
    {
        return [
            'id' => 'ID!',
            'sku' => 'String!',
            'name' => 'String!',
            'description' => 'String!',
            'category' => 'String!',
            'price' => 'Float!',
        ];
    }
}
