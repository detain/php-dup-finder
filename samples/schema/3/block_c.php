<?php

declare(strict_types=1);

namespace App\Api\Schema\GraphQL;

/**
 * GraphQL schema for products.
 * This schema is duplicated from:
 * - Doctrine entity: src/Domain/Product/Entity/Product.php
 * - Database table: products
 * - Elasticsearch index mapping
 * - OpenAPI spec
 */
class ProductGraphQLSchema
{
    public static function getTypeDefinition(): string
    {
        $schema = 'type Product {' . "\n";
        $schema .= '  id: ID!' . "\n";
        $schema .= '  sku: String!' . "\n";
        $schema .= '  name: String!' . "\n";
        $schema .= '  description: String!' . "\n";
        $schema .= '  category: String!' . "\n";
        $schema .= '  categoryPath: String!' . "\n";
        $schema .= '  price: Float!' . "\n";
        $schema .= '  currency: String!' . "\n";
        $schema .= '  isActive: Boolean!' . "\n";
        $schema .= '  attributes: [ProductAttribute!]!' . "\n";
        $schema .= '  images: [ProductImage!]!' . "\n";
        $schema .= '  inventory: ProductInventory' . "\n";
        $schema .= '  rating: ProductRating' . "\n";
        $schema .= '  createdAt: DateTime!' . "\n";
        $schema .= '  updatedAt: DateTime!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'type ProductAttribute {' . "\n";
        $schema .= '  key: String!' . "\n";
        $schema .= '  value: String!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'type ProductImage {' . "\n";
        $schema .= '  url: String!' . "\n";
        $schema .= '  displayOrder: Int!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'type ProductInventory {' . "\n";
        $schema .= '  quantity: Int!' . "\n";
        $schema .= '  available: Int!' . "\n";
        $schema .= '  lowStockThreshold: Int!' . "\n";
        $schema .= '  inStock: Boolean!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'type ProductRating {' . "\n";
        $schema .= '  average: Float!' . "\n";
        $schema .= '  count: Int!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'input ProductCreateInput {' . "\n";
        $schema .= '  sku: String!' . "\n";
        $schema .= '  name: String!' . "\n";
        $schema .= '  description: String!' . "\n";
        $schema .= '  category: String!' . "\n";
        $schema .= '  price: Float!' . "\n";
        $schema .= '  currency: String' . "\n";
        $schema .= '  attributes: [ProductAttributeInput!]' . "\n";
        $schema .= '  images: [ProductImageInput!]' . "\n";
        $schema .= '  inventory: ProductInventoryInput' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'input ProductUpdateInput {' . "\n";
        $schema .= '  name: String' . "\n";
        $schema .= '  description: String' . "\n";
        $schema .= '  category: String' . "\n";
        $schema .= '  price: Float' . "\n";
        $schema .= '  isActive: Boolean' . "\n";
        $schema .= '  attributes: [ProductAttributeInput!]' . "\n";
        $schema .= '  images: [ProductImageInput!]' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'input ProductAttributeInput {' . "\n";
        $schema .= '  key: String!' . "\n";
        $schema .= '  value: String!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'input ProductImageInput {' . "\n";
        $schema .= '  url: String!' . "\n";
        $schema .= '  displayOrder: Int' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'input ProductInventoryInput {' . "\n";
        $schema .= '  quantity: Int!' . "\n";
        $schema .= '  lowStockThreshold: Int' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'type ProductConnection {' . "\n";
        $schema .= '  edges: [ProductEdge!]!' . "\n";
        $schema .= '  pageInfo: PageInfo!' . "\n";
        $schema .= '  totalCount: Int!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'type ProductEdge {' . "\n";
        $schema .= '  node: Product!' . "\n";
        $schema .= '  cursor: String!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'type Query {' . "\n";
        $schema .= '  product(id: ID!): Product' . "\n";
        $schema .= '  products(' . "\n";
        $schema .= '    first: Int' . "\n";
        $schema .= '    after: String' . "\n";
        $schema .= '    last: Int' . "\n";
        $schema .= '    before: String' . "\n";
        $schema .= '    where: ProductWhereInput' . "\n";
        $schema .= '    orderBy: ProductOrderByInput' . "\n";
        $schema .= '  ): ProductConnection!' . "\n\n";
        $schema .= '  searchProducts(' . "\n";
        $schema .= '    query: String!' . "\n";
        $schema .= '    first: Int' . "\n";
        $schema .= '    after: String' . "\n";
        $schema .= '    categories: [String!]' . "\n";
        $schema .= '    minPrice: Float' . "\n";
        $schema .= '    maxPrice: Float' . "\n";
        $schema .= '    inStock: Boolean' . "\n";
        $schema .= '  ): ProductConnection!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'type Mutation {' . "\n";
        $schema .= '  createProduct(input: ProductCreateInput!): Product!' . "\n";
        $schema .= '  updateProduct(id: ID!, input: ProductUpdateInput!): Product!' . "\n";
        $schema .= '  deleteProduct(id: ID!): Boolean!' . "\n";
        $schema .= '  activateProduct(id: ID!): Product!' . "\n";
        $schema .= '  deactivateProduct(id: ID!): Product!' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'input ProductWhereInput {' . "\n";
        $schema .= '  sku: String' . "\n";
        $schema .= '  category: String' . "\n";
        $schema .= '  categories: [String!]' . "\n";
        $schema .= '  minPrice: Float' . "\n";
        $schema .= '  maxPrice: Float' . "\n";
        $schema .= '  inStock: Boolean' . "\n";
        $schema .= '  isActive: Boolean' . "\n";
        $schema .= '  attributes: [ProductAttributeInput!]' . "\n";
        $schema .= '}' . "\n\n";
        $schema .= 'input ProductOrderByInput {' . "\n";
        $schema .= '  field: String!' . "\n";
        $schema .= '  direction: String' . "\n";
        $schema .= '}';

        return $schema;
    }

    public static function getResolverConfig(): array
    {
        return [
            'Product' => [
                'categoryPath' => function ($product) {
                    return implode('/', explode('/', $product->getCategory()));
                },
                'images' => function ($product) {
                    return $product->getImages();
                },
                'inventory' => function ($product) {
                    return $product->getInventory();
                },
            ],
        ];
    }
}
