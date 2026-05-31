<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use PHPUnit\Framework\TestCase;
use App\Models\Product;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Supplier;
use App\Services\ProductService;
use App\Exceptions\ValidationException;
use App\Exceptions\SkuAlreadyExistsException;

class ProductServiceTest extends TestCase
{
    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productService = new ProductService();
    }

    private function createValidProductData(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'PROD-' . uniqid(),
            'name' => 'Test Product',
            'description' => 'A test product description with sufficient length',
            'price' => 2999,
            'cost' => 1500,
            'currency' => 'USD',
            'tax_category' => 'standard',
            'category_id' => null,
            'manufacturer_id' => null,
            'supplier_id' => null,
            'weight' => 1.5,
            'weight_unit' => 'kg',
            'dimensions' => [
                'length' => 10,
                'width' => 5,
                'height' => 3,
                'unit' => 'cm',
            ],
            'inventory_tracked' => true,
            'inventory_count' => 100,
            'low_stock_threshold' => 10,
            'status' => 'active',
            'metadata' => [
                'source' => 'import',
                'import_batch' => null,
            ],
        ], $overrides);
    }

    private function createValidCategory(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Electronics',
            'slug' => 'electronics',
            'parent_id' => null,
            'path' => '/electronics',
            'is_active' => true,
            'sort_order' => 1,
            'metadata' => [
                'icon' => 'cpu',
                'color' => '#3498db',
            ],
        ], $overrides);
    }

    private function createValidManufacturer(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'TechCorp Industries',
            'slug' => 'techcorp',
            'country' => 'US',
            'website' => 'https://techcorp.example.com',
            'support_email' => 'support@techcorp.example.com',
            'status' => 'active',
        ], $overrides);
    }

    private function createValidSupplier(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Global Supply Co',
            'code' => 'GSC',
            'contact_email' => 'orders@globalsupply.example.com',
            'lead_time_days' => 14,
            'minimum_order_value' => 500,
            'status' => 'active',
            'addresses' => [
                [
                    'type' => 'shipping',
                    'street' => '123 Warehouse Way',
                    'city' => 'Los Angeles',
                    'state' => 'CA',
                    'zip' => '90001',
                    'country' => 'US',
                ],
            ],
        ], $overrides);
    }

    public function testCreatesProductWithValidData(): void
    {
        $productData = $this->createValidProductData([
            'sku' => 'NEW-SKU-001',
            'name' => 'Wireless Mouse',
        ]);

        $result = $this->productService->createProduct($productData);

        $this->assertInstanceOf(Product::class, $result);
        $this->assertEquals('NEW-SKU-001', $result->sku);
        $this->assertEquals('Wireless Mouse', $result->name);
        $this->assertEquals(2999, $result->price);
    }

    public function testValidatesSkuFormat(): void
    {
        $invalidSkus = [
            'invalid sku with spaces',
            'lowercase',
            'SPECIAL!@#characters',
            '',
            'a',
        ];

        foreach ($invalidSkus as $sku) {
            $productData = $this->createValidProductData(['sku' => $sku]);

            $this->expectException(ValidationException::class);
            $this->productService->createProduct($productData);
        }
    }

    public function testValidatesPriceIsPositive(): void
    {
        $invalidPrices = [-100, -1, 0];

        foreach ($invalidPrices as $price) {
            $productData = $this->createValidProductData(['price' => $price]);

            $this->expectException(ValidationException::class);
            $this->productService->createProduct($productData);
        }
    }

    public function testAssignsCategoryToProduct(): void
    {
        $categoryData = $this->createValidCategory(['id' => 10, 'name' => 'Accessories']);
        $productData = $this->createValidProductData(['category_id' => 10]);

        $product = $this->productService->createProduct($productData);

        $this->assertEquals(10, $product->category_id);
        $this->assertEquals('Accessories', $product->category->name);
    }

    public function testThrowsExceptionForDuplicateSku(): void
    {
        $existingSku = 'EXISTING-SKU-001';

        $productData = $this->createValidProductData(['sku' => $existingSku]);
        $this->productService->createProduct($productData);

        $duplicateData = $this->createValidProductData(['sku' => $existingSku]);

        $this->expectException(SkuAlreadyExistsException::class);
        $this->productService->createProduct($duplicateData);
    }
}
