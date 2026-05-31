<?php
declare(strict_types=1);

namespace OrderFlow\Validation;

use Psr\Log\LoggerInterface;

final class CreateShipmentValidator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function validate(array $shipmentData): ValidationResult
    {
        $errors = [];

        if (!isset($shipmentData['order_id']) || !is_numeric($shipmentData['order_id'])) {
            $errors['order_id'] = 'Valid order ID is required';
        }

        if (!isset($shipmentData['items']) || !is_array($shipmentData['items']) || empty($shipmentData['items'])) {
            $errors['items'] = 'At least one item is required';
        } else {
            foreach ($shipmentData['items'] as $index => $item) {
                $itemErrors = $this->validateShipmentItem($item, $index);
                if (!empty($itemErrors)) {
                    $errors["items.{$index}"] = $itemErrors;
                }
            }
        }

        if (isset($shipmentData['shipping_address'])) {
            $addressErrors = $this->validateAddress($shipmentData['shipping_address']);
            if (!empty($addressErrors)) {
                $errors['shipping_address'] = $addressErrors;
            }
        }

        if (isset($shipmentData['carrier'])) {
            $carrierErrors = $this->validateCarrier($shipmentData['carrier']);
            if (!empty($carrierErrors)) {
                $errors['carrier'] = $carrierErrors;
            }
        }

        $this->logger->debug('Create shipment validation completed', [
            'error_count' => count($errors),
        ]);

        return new ValidationResult(empty($errors), $errors);
    }

    private function validateShipmentItem(array $item, int $index): array
    {
        $errors = [];

        if (!isset($item['order_item_id']) || !is_numeric($item['order_item_id'])) {
            $errors[] = "order_item_id must be a valid number at index {$index}";
        }

        if (!isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] < 1) {
            $errors[] = "quantity must be a positive integer at index {$index}";
        }

        if (isset($item['weight']) && (!is_numeric($item['weight']) || $item['weight'] < 0)) {
            $errors[] = "weight must be a non-negative number at index {$index}";
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

    private function validateCarrier(array $carrier): array
    {
        $errors = [];

        if (empty(trim($carrier['name'] ?? ''))) {
            $errors['name'] = 'Carrier name is required';
        }

        if (empty(trim($carrier['service_type'] ?? ''))) {
            $errors['service_type'] = 'Service type is required';
        }

        $validServiceTypes = ['standard', 'express', 'overnight', 'freight'];
        if (!in_array($carrier['service_type'] ?? '', $validServiceTypes)) {
            $errors['service_type'] = 'Invalid service type';
        }

        if (isset($carrier['tracking_number'])) {
            if (strlen($carrier['tracking_number']) < 10) {
                $errors['tracking_number'] = 'Invalid tracking number format';
            }
        }

        return $errors;
    }
}
