<?php

declare(strict_types=1);

namespace App\Api\Grpc;

use App\Entity\ApiRequest;
use App\Repository\ApiRequestRepository;
use App\Service\InputSanitizer;
use App\Service\RateLimiter;
use Psr\Log\LoggerInterface;

final class CreateOrderApiValidator
{
    public function __construct(
        private readonly ApiRequestRepository $requestRepository,
        private readonly InputSanitizer $inputSanitizer,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    public function validate(array $payload): array
    {
        $errors = [];

        if (empty($payload['customer_id'])) {
            $errors['customer_id'] = 'Customer ID is required';
        } elseif (!is_int($payload['customer_id']) || $payload['customer_id'] <= 0) {
            $errors['customer_id'] = 'Customer ID must be a positive integer';
        }

        if (empty($payload['items']) || !is_array($payload['items'])) {
            $errors['items'] = 'Order items are required';
        } elseif (count($payload['items']) === 0) {
            $errors['items'] = 'Order must contain at least one item';
        } else {
            foreach ($payload['items'] as $index => $item) {
                if (!isset($item['product_id'])) {
                    $errors["items.{$index}.product_id"] = 'Product ID is required';
                }
                if (!isset($item['quantity'])) {
                    $errors["items.{$index}.quantity"] = 'Quantity is required';
                } elseif (!is_int($item['quantity']) || $item['quantity'] <= 0) {
                    $errors["items.{$index}.quantity"] = 'Quantity must be a positive integer';
                }
            }
        }

        if (empty($payload['shipping_address'])) {
            $errors['shipping_address'] = 'Shipping address is required';
        }

        if (!empty($payload['shipping_address'])) {
            $address = $payload['shipping_address'];
            if (empty($address['street']) || empty($address['city']) || empty($address['postal_code'])) {
                $errors['shipping_address'] = 'Shipping address must include street, city, and postal code';
            }
        }

        $this->logger->debug('CreateOrder validation completed', [
            'error_count' => count($errors),
        ]);

        return $errors;
    }

    public function sanitize(array $payload): array
    {
        return $this->inputSanitizer->sanitizeArray($payload);
    }

    public function checkRateLimit(string $clientId): bool
    {
        return $this->rateLimiter->isAllowed('create_order', $clientId, 5, 60);
    }
}
