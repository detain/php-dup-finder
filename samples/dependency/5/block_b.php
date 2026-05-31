<?php

declare(strict_types=1);

namespace App\Application\Validation;

use App\Infrastructure\Validation\Validator;

/**
 * API request validation service.
 * The Validator is manually injected here, duplicated from
 * FormValidatorService and other validation services.
 */
class ApiValidatorService
{
    private Validator $validator;

    public function __construct(Validator $validator)
    {
        $this->validator = $validator;
    }

    public function validateProductSearch(array $params): ValidationResult
    {
        $this->validator->validate($params, [
            'q' => 'sometimes|string|min:2|max:200',
            'category' => 'sometimes|string|slug',
            'brand' => 'sometimes|array',
            'brand.*' => 'string|slug',
            'min_price' => 'sometimes|numeric|min:0',
            'max_price' => 'sometimes|numeric|min:0',
            'min_rating' => 'sometimes|numeric|min:1|max:5',
            'in_stock' => 'sometimes|boolean',
            'sort_by' => 'sometimes|in:relevance,price_asc,price_desc,rating,newest',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        if (isset($params['min_price']) && isset($params['max_price'])) {
            if ($params['min_price'] > $params['max_price']) {
                $this->validator->addError('min_price', 'Minimum price cannot exceed maximum price');
                return new ValidationResult(
                    valid: false,
                    errors: $this->validator->getErrors(),
                );
            }
        }

        return new ValidationResult(valid: true);
    }

    public function validateProductCreate(array $data): ValidationResult
    {
        $this->validator->validate($data, [
            'sku' => 'required|string|max:100|unique_product_sku',
            'name' => 'required|string|min:3|max:200',
            'description' => 'required|string|min:10|max:5000',
            'price' => 'required|numeric|min:0.01|max:999999.99',
            'currency' => 'required|currency_code',
            'category_id' => 'required|uuid|exists:categories,id',
            'inventory' => 'required|integer|min:0',
            'low_stock_threshold' => 'sometimes|integer|min:0',
            'images' => 'sometimes|array|max:8',
            'images.*' => 'url',
            'attributes' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validateOrderCreate(array $data): ValidationResult
    {
        $this->validator->validate($data, [
            'customer_id' => 'required|uuid',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'items.*.unit_price' => 'required|numeric|min:0',
            'shipping_address_id' => 'required|uuid',
            'billing_address_id' => 'required|uuid',
            'payment_method_id' => 'required|uuid',
            'shipping_method' => 'required|in:standard,express,overnight,freight',
            'coupon_code' => 'sometimes|string|max:50',
            'notes' => 'sometimes|string|max:500',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        $totalAmount = $this->calculateTotal($data['items']);
        $maxOrderAmount = 999999.99;

        if ($totalAmount > $maxOrderAmount) {
            $this->validator->addError('items', 'Order total exceeds maximum allowed amount');
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validatePaymentProcess(array $data): ValidationResult
    {
        $this->validator->validate($data, [
            'order_id' => 'required|uuid|exists:orders,id',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'currency' => 'sometimes|currency_code',
            'payment_method' => 'required|array',
            'payment_method.type' => 'required|in:card,bank_account,paypal',
            'payment_method.token' => 'required|string',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    private function calculateTotal(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $total += $item['quantity'] * $item['unit_price'];
        }

        return $total;
    }
}
