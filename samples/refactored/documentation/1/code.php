<?php

declare(strict_types=1);

namespace App\Api\Documentation;

/**
 * Reusable API documentation trait that provides consistent documentation
 * patterns across all controller endpoints.
 */
trait ApiDocumentationTrait
{
    /**
     * Standard registration parameters shared across User, Customer, Admin registration.
     */
    protected function getRegistrationDocBlock(): array
    {
        return [
            'email' => 'string (required) User\'s email address, must be unique',
            'password' => 'string (required) Minimum 8 characters with uppercase, lowercase, numeric',
            'firstName' => 'string (required) 1-50 characters',
            'lastName' => 'string (required) 1-50 characters',
            'phoneNumber' => 'string (optional) E.164 formatted phone number',
            'marketingOptIn' => 'bool (optional, default false)',
        ];
    }

    /**
     * Standard response fields for creation endpoints.
     */
    protected function getCreatedResponseDocBlock(string $entityType): array
    {
        return [
            'id' => "string UUID of created {$entityType}",
            'createdAt' => 'string ISO 8601 timestamp',
        ];
    }

    /**
     * Standard error responses for duplicate entry conflicts.
     */
    protected function getDuplicateErrorDocBlock(string $field): array
    {
        return [
            'error' => "{$field}_already_exists",
            'message' => "A record with this {$field} already exists",
        ];
    }
}
