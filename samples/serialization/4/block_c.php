<?php

declare(strict_types=1);

namespace App\Api\Request;

class OrderRequestParser
{
    private SchemaValidator $validator;

    public function __construct(SchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    public function parse(array $requestData): ParsedRequest
    {
        $errors = [];

        $orderId = $requestData['params']['id'] ?? null;
        $userId = $requestData['params']['user_id'] ?? null;
        $items = $requestData['params']['items'] ?? null;
        $totalAmount = $requestData['params']['total_amount'] ?? null;
        $totalCurrency = $requestData['params']['total_currency'] ?? null;
        $status = $requestData['params']['status'] ?? null;

        if ($orderId !== null && !$this->isValidUuid($orderId)) {
            $errors['id'] = 'Invalid order ID format';
        }

        if ($userId !== null && !$this->isValidUuid($userId)) {
            $errors['user_id'] = 'Invalid user ID format';
        }

        if ($items !== null && !is_array($items)) {
            $errors['items'] = 'items must be an array';
        } elseif (is_array($items)) {
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $errors["items.{$index}"] = 'Each item must be an object';
                    continue;
                }

                if (!isset($item['product_id']) || !$this->isValidUuid($item['product_id'])) {
                    $errors["items.{$index}.product_id"] = 'Invalid product ID';
                }

                if (!isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] < 1) {
                    $errors["items.{$index}.quantity"] = 'Quantity must be a positive integer';
                }

                if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || $item['unit_price'] < 0) {
                    $errors["items.{$index}.unit_price"] = 'Unit price must be a non-negative number';
                }
            }
        }

        if ($totalAmount !== null && (!is_numeric($totalAmount) || $totalAmount < 0)) {
            $errors['total_amount'] = 'Total amount must be a non-negative number';
        }

        if ($totalCurrency !== null && strlen($totalCurrency) !== 3) {
            $errors['total_currency'] = 'Currency must be a 3-letter code';
        }

        if ($status !== null) {
            $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
            if (!in_array($status, $validStatuses, true)) {
                $errors['status'] = 'Invalid status value';
            }
        }

        if (count($errors) > 0) {
            return new ParsedRequest(null, $errors);
        }

        return new ParsedRequest([
            'id' => $orderId,
            'user_id' => $userId,
            'items' => $items,
            'total_amount' => $totalAmount !== null ? (float)$totalAmount : null,
            'total_currency' => $totalCurrency,
            'status' => $status
        ], []);
    }

    public function parseCreate(array $requestData): ParsedRequest
    {
        $errors = [];

        $userId = $requestData['params']['user_id'] ?? null;
        $items = $requestData['params']['items'] ?? null;
        $totalAmount = $requestData['params']['total_amount'] ?? null;
        $totalCurrency = $requestData['params']['total_currency'] ?? null;

        if ($userId === null) {
            $errors['user_id'] = 'User ID is required';
        } elseif (!$this->isValidUuid($userId)) {
            $errors['user_id'] = 'Invalid user ID format';
        }

        if ($items === null) {
            $errors['items'] = 'Order items are required';
        } elseif (!is_array($items) || count($items) === 0) {
            $errors['items'] = 'Order must have at least one item';
        } else {
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $errors["items.{$index}"] = 'Each item must be an object';
                    continue;
                }

                if (!isset($item['product_id']) || !$this->isValidUuid($item['product_id'])) {
                    $errors["items.{$index}.product_id"] = 'Invalid product ID';
                }

                if (!isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] < 1) {
                    $errors["items.{$index}.quantity"] = 'Quantity must be a positive integer';
                }

                if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || $item['unit_price'] < 0) {
                    $errors["items.{$index}.unit_price"] = 'Unit price must be a non-negative number';
                }
            }
        }

        if ($totalAmount === null) {
            $errors['total_amount'] = 'Total amount is required';
        } elseif (!is_numeric($totalAmount) || $totalAmount < 0) {
            $errors['total_amount'] = 'Total amount must be a non-negative number';
        }

        if ($totalCurrency === null) {
            $errors['total_currency'] = 'Currency is required';
        } elseif (strlen($totalCurrency) !== 3) {
            $errors['total_currency'] = 'Currency must be a 3-letter code';
        }

        if (count($errors) > 0) {
            return new ParsedRequest(null, $errors);
        }

        return new ParsedRequest([
            'user_id' => $userId,
            'items' => $items,
            'total_amount' => (float)$totalAmount,
            'total_currency' => $totalCurrency
        ], []);
    }

    private function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
