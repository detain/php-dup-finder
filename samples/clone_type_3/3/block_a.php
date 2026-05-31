<?php

declare(strict_types=1);

namespace App\Api\Rest;

use App\Entity\ApiRequest;
use App\Repository\ApiRequestRepository;
use App\Service\InputSanitizer;
use App\Service\RateLimiter;
use Psr\Log\LoggerInterface;

final class CreateUserApiValidator
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

        if (empty($payload['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        if (empty($payload['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($payload['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if (empty($payload['username'])) {
            $errors['username'] = 'Username is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $payload['username'])) {
            $errors['username'] = 'Username must be 3-20 alphanumeric characters';
        }

        if (empty($payload['first_name'])) {
            $errors['first_name'] = 'First name is required';
        }

        if (empty($payload['last_name'])) {
            $errors['last_name'] = 'Last name is required';
        }

        if (!empty($payload['phone'])) {
            if (!preg_match('/^\+?[1-9]\d{6,14}$/', $payload['phone'])) {
                $errors['phone'] = 'Invalid phone number format';
            }
        }

        $this->logger->debug('CreateUser validation completed', [
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
        return $this->rateLimiter->isAllowed('create_user', $clientId, 10, 60);
    }
}
