<?php
declare(strict_types=1);

namespace OrderFlow\Validation;

use Psr\Log\LoggerInterface;

final class CreateReturnValidator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function validate(array $returnData): ValidationResult
    {
        $errors = [];

        if (!isset($returnData['order_id']) || !is_numeric($returnData['order_id'])) {
            $errors['order_id'] = 'Valid order ID is required';
        }

        if (!isset($returnData['items']) || !is_array($returnData['items']) || empty($returnData['items'])) {
            $errors['items'] = 'At least one item is required';
        } else {
            foreach ($returnData['items'] as $index => $item) {
                $itemErrors = $this->validateReturnItem($item, $index);
                if (!empty($itemErrors)) {
                    $errors["items.{$index}"] = $itemErrors;
                }
            }
        }

        if (isset($returnData['shipping_address'])) {
            $addressErrors = $this->validateAddress($returnData['shipping_address']);
            if (!empty($addressErrors)) {
                $errors['shipping_address'] = $addressErrors;
            }
        }

        if (isset($returnData['reason'])) {
            $reasonErrors = $this->validateReturnReason($returnData['reason']);
            if (!empty($reasonErrors)) {
                $errors['reason'] = $reasonErrors;
            }
        }

        $this->logger->debug('Create return validation completed', [
            'error_count' => count($errors),
        ]);

        return new ValidationResult(empty($errors), $errors);
    }

    private function validateReturnItem(array $item, int $index): array
    {
        $errors = [];

        if (!isset($item['order_item_id']) || !is_numeric($item['order_item_id'])) {
            $errors[] = "order_item_id must be a valid number at index {$index}";
        }

        if (!isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] < 1) {
            $errors[] = "quantity must be a positive integer at index {$index}";
        }

        if (isset($item['reason']) && strlen($item['reason']) > 500) {
            $errors[] = "reason cannot exceed 500 characters at index {$index}";
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

    private function validateReturnReason(array $reason): array
    {
        $errors = [];

        if (empty(trim($reason['category'] ?? ''))) {
            $errors['category'] = 'Return category is required';
        }

        $validCategories = ['defective', 'wrong_item', 'changed_mind', 'not_as_described', 'damaged'];
        if (!in_array($reason['category'] ?? '', $validCategories)) {
            $errors['category'] = 'Invalid return category';
        }

        if (isset($reason['description']) && strlen($reason['description']) < 10) {
            $errors['description'] = 'Description must be at least 10 characters';
        }

        if (isset($reason['description']) && strlen($reason['description']) > 1000) {
            $errors['description'] = 'Description cannot exceed 1000 characters';
        }

        return $errors;
    }
}
