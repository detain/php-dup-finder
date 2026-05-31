<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use App\Validation\Rules\ProductRules;
use App\Validation\Validator;
use App\Exceptions\ValidationException;

class ProductValidationRulesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new Validator();
    }

    public function testSkuValidation(): void
    {
        $validSkus = [
            'PROD-001',
            'WIDGET-BLUE-L',
            'ITEM/SIMPLE',
            'SKU.123.456',
        ];

        foreach ($validSkus as $sku) {
            $result = $this->validator->validate($sku, [ProductRules::SKU]);
            $this->assertTrue($result, "SKU {$sku} should be valid");
        }

        $invalidSkus = [
            'lowercase',
            'invalid sku with spaces',
            '',
            str_repeat('A', 65),
        ];

        foreach ($invalidSkus as $sku) {
            $result = $this->validator->validate($sku, [ProductRules::SKU]);
            $this->assertFalse($result, "SKU should be invalid");
        }
    }

    public function testPriceValidation(): void
    {
        $validPrices = [
            0,
            1,
            99,
            999999,
            99.99,
            9999.99,
        ];

        foreach ($validPrices as $price) {
            $result = $this->validator->validate($price, [ProductRules::PRICE_POSITIVE]);
            $this->assertTrue($result, "Price {$price} should be valid");
        }

        $invalidPrices = [
            -1,
            -0.01,
            -100,
        ];

        foreach ($invalidPrices as $price) {
            $result = $this->validator->validate($price, [ProductRules::PRICE_POSITIVE]);
            $this->assertFalse($result, "Price should be invalid");
        }
    }

    public function testWeightValidation(): void
    {
        $validWeights = [
            0.5,
            1,
            100,
            99.99,
        ];

        foreach ($validWeights as $weight) {
            $result = $this->validator->validate($weight, [ProductRules::WEIGHT_POSITIVE]);
            $this->assertTrue($result, "Weight {$weight} should be valid");
        }

        $invalidWeights = [
            0,
            -1,
            -0.5,
        ];

        foreach ($invalidWeights as $weight) {
            $result = $this->validator->validate($weight, [ProductRules::WEIGHT_POSITIVE]);
            $this->assertFalse($result, "Weight should be invalid");
        }
    }

    public function testDimensionsValidation(): void
    {
        $validDimensions = [
            ['length' => 10, 'width' => 5, 'height' => 3],
            ['length' => 100, 'width' => 50, 'height' => 25],
        ];

        foreach ($validDimensions as $dimensions) {
            $result = $this->validator->validate($dimensions, [ProductRules::DIMENSIONS]);
            $this->assertTrue($result, 'Dimensions should be valid');
        }

        $invalidDimensions = [
            ['length' => -1, 'width' => 5, 'height' => 3],
            ['length' => 10, 'width' => 0, 'height' => 3],
            ['length' => 10, 'width' => 5, 'height' => -1],
        ];

        foreach ($invalidDimensions as $dimensions) {
            $result = $this->validator->validate($dimensions, [ProductRules::DIMENSIONS]);
            $this->assertFalse($result, 'Dimensions should be invalid');
        }
    }

    public function testCurrencyValidation(): void
    {
        $validCurrencies = [
            'USD',
            'EUR',
            'GBP',
            'JPY',
            'CAD',
        ];

        foreach ($validCurrencies as $currency) {
            $result = $this->validator->validate($currency, [ProductRules::CURRENCY]);
            $this->assertTrue($result, "Currency {$currency} should be valid");
        }

        $invalidCurrencies = [
            'usd',
            'invalid',
            'USDD',
            '',
        ];

        foreach ($invalidCurrencies as $currency) {
            $result = $this->validator->validate($currency, [ProductRules::CURRENCY]);
            $this->assertFalse($result, "Currency should be invalid");
        }
    }

    public function testInventoryCountValidation(): void
    {
        $validCounts = [
            0,
            1,
            100,
            999999,
        ];

        foreach ($validCounts as $count) {
            $result = $this->validator->validate($count, [ProductRules::INVENTORY_COUNT]);
            $this->assertTrue($result, "Count {$count} should be valid");
        }

        $invalidCounts = [
            -1,
            -100,
        ];

        foreach ($invalidCounts as $count) {
            $result = $this->validator->validate($count, [ProductRules::INVENTORY_COUNT]);
            $this->assertFalse($result, "Count should be invalid");
        }
    }

    public function testCombinedProductValidation(): void
    {
        $validData = [
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'price' => 2999,
            'weight' => 1.5,
            'currency' => 'USD',
            'inventory_count' => 100,
        ];

        $result = $this->validator->validateMany($validData, [
            'sku' => [ProductRules::SKU, ProductRules::REQUIRED],
            'name' => [ProductRules::REQUIRED],
            'price' => [ProductRules::PRICE_POSITIVE, ProductRules::REQUIRED],
            'weight' => [ProductRules::WEIGHT_POSITIVE],
            'currency' => [ProductRules::CURRENCY],
            'inventory_count' => [ProductRules::INVENTORY_COUNT],
        ]);

        $this->assertTrue($result);
    }

    public function testCombinedValidationReturnsErrors(): void
    {
        $invalidData = [
            'sku' => 'lowercase',
            'name' => '',
            'price' => -100,
            'currency' => 'INVALID',
        ];

        try {
            $this->validator->validateMany($invalidData, [
                'sku' => [ProductRules::SKU, ProductRules::REQUIRED],
                'name' => [ProductRules::REQUIRED],
                'price' => [ProductRules::PRICE_POSITIVE, ProductRules::REQUIRED],
                'currency' => [ProductRules::CURRENCY],
            ]);

            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('sku', $errors);
            $this->assertArrayHasKey('name', $errors);
            $this->assertArrayHasKey('price', $errors);
            $this->assertArrayHasKey('currency', $errors);
        }
    }
}
