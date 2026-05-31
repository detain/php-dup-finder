<?php

declare(strict_types=1);

namespace App\Api\Request;

class ProductRequestParser
{
    private SchemaValidator $validator;

    public function __construct(SchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    public function parse(array $requestData): ParsedRequest
    {
        $errors = [];

        $productId = $requestData['params']['id'] ?? null;
        $name = $requestData['params']['name'] ?? null;
        $description = $requestData['params']['description'] ?? null;
        $priceAmount = $requestData['params']['price_amount'] ?? null;
        $priceCurrency = $requestData['params']['price_currency'] ?? null;
        $categoryId = $requestData['params']['category_id'] ?? null;
        $imageUrl = $requestData['params']['image_url'] ?? null;
        $stockQuantity = $requestData['params']['stock_quantity'] ?? null;
        $isAvailable = $requestData['params']['is_available'] ?? null;
        $tags = $requestData['params']['tags'] ?? [];

        if ($productId !== null && !$this->isValidUuid($productId)) {
            $errors['id'] = 'Invalid product ID format';
        }

        if ($name !== null && (strlen($name) < 3 || strlen($name) > 500)) {
            $errors['name'] = 'Name must be between 3 and 500 characters';
        }

        if ($description !== null && strlen($description) > 5000) {
            $errors['description'] = 'Description cannot exceed 5000 characters';
        }

        if ($priceAmount !== null && (!is_numeric($priceAmount) || $priceAmount < 0)) {
            $errors['price_amount'] = 'Price must be a non-negative number';
        }

        if ($priceCurrency !== null && strlen($priceCurrency) !== 3) {
            $errors['price_currency'] = 'Currency must be a 3-letter code';
        }

        if ($categoryId !== null && !$this->isValidUuid($categoryId)) {
            $errors['category_id'] = 'Invalid category ID format';
        }

        if ($imageUrl !== null && !$this->isValidUrl($imageUrl)) {
            $errors['image_url'] = 'Invalid URL format';
        }

        if ($stockQuantity !== null && (!is_int($stockQuantity) || $stockQuantity < 0)) {
            $errors['stock_quantity'] = 'Stock quantity must be a non-negative integer';
        }

        if ($isAvailable !== null && !is_bool($isAvailable)) {
            $errors['is_available'] = 'is_available must be a boolean';
        }

        if (!is_array($tags)) {
            $errors['tags'] = 'tags must be an array';
        } else {
            foreach ($tags as $index => $tag) {
                if (!is_string($tag) || strlen($tag) > 100) {
                    $errors["tags.{$index}"] = 'Each tag must be a string under 100 characters';
                }
            }
        }

        if (count($errors) > 0) {
            return new ParsedRequest(null, $errors);
        }

        return new ParsedRequest([
            'id' => $productId,
            'name' => $name,
            'description' => $description,
            'price_amount' => $priceAmount !== null ? (float)$priceAmount : null,
            'price_currency' => $priceCurrency,
            'category_id' => $categoryId,
            'image_url' => $imageUrl,
            'stock_quantity' => $stockQuantity,
            'is_available' => $isAvailable ?? true,
            'tags' => $tags
        ], []);
    }

    public function parseCreate(array $requestData): ParsedRequest
    {
        $errors = [];

        $name = $requestData['params']['name'] ?? null;
        $description = $requestData['params']['description'] ?? null;
        $priceAmount = $requestData['params']['price_amount'] ?? null;
        $priceCurrency = $requestData['params']['price_currency'] ?? null;
        $categoryId = $requestData['params']['category_id'] ?? null;
        $imageUrl = $requestData['params']['image_url'] ?? null;
        $stockQuantity = $requestData['params']['stock_quantity'] ?? 0;
        $tags = $requestData['params']['tags'] ?? [];

        if ($name === null) {
            $errors['name'] = 'Name is required';
        } elseif (strlen($name) < 3 || strlen($name) > 500) {
            $errors['name'] = 'Name must be between 3 and 500 characters';
        }

        if ($priceAmount === null) {
            $errors['price_amount'] = 'Price is required';
        } elseif (!is_numeric($priceAmount) || $priceAmount < 0) {
            $errors['price_amount'] = 'Price must be a non-negative number';
        }

        if ($priceCurrency === null) {
            $errors['price_currency'] = 'Currency is required';
        } elseif (strlen($priceCurrency) !== 3) {
            $errors['price_currency'] = 'Currency must be a 3-letter code';
        }

        if ($categoryId === null) {
            $errors['category_id'] = 'Category ID is required';
        } elseif (!$this->isValidUuid($categoryId)) {
            $errors['category_id'] = 'Invalid category ID format';
        }

        if ($imageUrl !== null && !$this->isValidUrl($imageUrl)) {
            $errors['image_url'] = 'Invalid URL format';
        }

        if (!is_int($stockQuantity) || $stockQuantity < 0) {
            $errors['stock_quantity'] = 'Stock quantity must be a non-negative integer';
        }

        if (!is_array($tags)) {
            $errors['tags'] = 'tags must be an array';
        }

        if (count($errors) > 0) {
            return new ParsedRequest(null, $errors);
        }

        return new ParsedRequest([
            'name' => $name,
            'description' => $description,
            'price_amount' => (float)$priceAmount,
            'price_currency' => $priceCurrency,
            'category_id' => $categoryId,
            'image_url' => $imageUrl,
            'stock_quantity' => $stockQuantity,
            'tags' => $tags
        ], []);
    }

    private function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function isValidUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
