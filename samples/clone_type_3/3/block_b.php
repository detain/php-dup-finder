<?php

declare(strict_types=1);

namespace App\Api\GraphQL;

use App\Entity\ApiRequest;
use App\Repository\ApiRequestRepository;
use App\Service\InputSanitizer;
use App\Service\RateLimiter;
use Psr\Log\LoggerInterface;

final class CreateProductApiValidator
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

        if (empty($payload['name'])) {
            $errors['name'] = 'Product name is required';
        } elseif (strlen($payload['name']) > 200) {
            $errors['name'] = 'Product name cannot exceed 200 characters';
        }

        if (!isset($payload['price'])) {
            $errors['price'] = 'Price is required';
        } elseif (!is_numeric($payload['price']) || $payload['price'] < 0) {
            $errors['price'] = 'Price must be a non-negative number';
        }

        if (empty($payload['sku'])) {
            $errors['sku'] = 'SKU is required';
        } elseif (!preg_match('/^[A-Z0-9]{4,20}$/', $payload['sku'])) {
            $errors['sku'] = 'SKU must be 4-20 uppercase alphanumeric characters';
        }

        if (!isset($payload['inventory'])) {
            $errors['inventory'] = 'Inventory count is required';
        } elseif (!is_int($payload['inventory']) || $payload['inventory'] < 0) {
            $errors['inventory'] = 'Inventory must be a non-negative integer';
        }

        if (!empty($payload['category_id'])) {
            if (!is_int($payload['category_id']) || $payload['category_id'] <= 0) {
                $errors['category_id'] = 'Invalid category ID';
            }
        }

        if (!empty($payload['description'])) {
            if (strlen($payload['description']) > 5000) {
                $errors['description'] = 'Description cannot exceed 5000 characters';
            }
        }

        $this->logger->debug('CreateProduct validation completed', [
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
        return $this->rateLimiter->isAllowed('create_product', $clientId, 20, 60);
    }
}
