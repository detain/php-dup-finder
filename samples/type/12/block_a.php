<?php
declare(strict_types=1);

namespace OrderFlow\Validation;

use Psr\Log\LoggerInterface;

final class CreateOrderValidator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function validate(array $orderData): ValidationResult
    {
        $errors = [];

        if (!isset($orderData['customer_id']) || !is_numeric($orderData['customer_id'])) {
            $errors['customer_id'] = 'Valid customer ID is required';
        }

        if (!isset($orderData['items']) || !is_array($orderData['items']) || empty($orderData['items'])) {
            $errors['items'] = 'At least one item is required';
        } else {
            foreach ($orderData['items'] as $index => $item) {
                $itemErrors = $this->validateOrderItem($item, $index);
                if (!empty($itemErrors)) {
                    $errors["items.{$index}"] = $itemErrors;
                }
            }
        }

        if (isset($orderData['shipping_address'])) {
            $addressErrors = $this->validateAddress($orderData['shipping_address']);
            if (!empty($addressErrors)) {
                $errors['shipping_address'] = $addressErrors;
            }
        }

        if (isset($orderData['payment_method'])) {
            $paymentErrors = $this->validatePaymentMethod($orderData['payment_method']);
            if (!empty($paymentErrors)) {
                $errors['payment_method'] = $paymentErrors;
            }
        }

        $this->logger->debug('Create order validation completed', [
            'error_count' => count($errors),
        ]);

        return new ValidationResult(empty($errors), $errors);
    }

    private function validateOrderItem(array $item, int $index): array
    {
        $errors = [];

        if (!isset($item['product_id']) || !is_numeric($item['product_id'])) {
            $errors[] = "product_id must be a valid number at index {$index}";
        }

        if (!isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] < 1) {
            $errors[] = "quantity must be a positive integer at index {$index}";
        }

        if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || $item['unit_price'] < 0) {
            $errors[] = "unit_price must be a non-negative number at index {$index}";
        }

        return $errors;
    }

    private function validateAddress(array $address): array
    {
        $errors = [];

        if (empty(trim($address['street'] ?? ''))) {
            $errors['street'] = 'Street address is required';
        }

        if (empty(trim($address['city'] ?? ''))) {
            $errors['city'] = 'City is required';
        }

        if (empty(trim($address['state'] ?? ''))) {
            $errors['state'] = 'State is required';
        }

        if (empty(trim($address['postal_code'] ?? ''))) {
            $errors['postal_code'] = 'Postal code is required';
        }

        if (empty(trim($address['country'] ?? ''))) {
            $errors['country'] = 'Country is required';
        }

        return $errors;
    }

    private function validatePaymentMethod(array $paymentMethod): array
    {
        $errors = [];

        if (empty(trim($paymentMethod['type'] ?? ''))) {
            $errors['type'] = 'Payment type is required';
        }

        $validTypes = ['credit_card', 'debit_card', 'paypal', 'bank_transfer'];
        if (!in_array($paymentMethod['type'] ?? '', $validTypes)) {
            $errors['type'] = 'Invalid payment type';
        }

        if (($paymentMethod['type'] ?? '') === 'credit_card') {
            if (empty($paymentMethod['card_number'] ?? '')) {
                $errors['card_number'] = 'Card number is required';
            }
            if (empty($paymentMethod['expiry_month'] ?? '') || !is_numeric($paymentMethod['expiry_month'])) {
                $errors['expiry_month'] = 'Valid expiry month is required';
            }
            if (empty($paymentMethod['expiry_year'] ?? '') || !is_numeric($paymentMethod['expiry_year'])) {
                $errors['expiry_year'] = 'Valid expiry year is required';
            }
        }

        return $errors;
    }
}
