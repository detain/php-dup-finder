<?php

declare(strict_types=1);

namespace App\Validation\Email;

use Psr\Log\LoggerInterface;

final class EmailValidatorA
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Validates email using FILTER_VALIDATE_EMAIL.
     *
     * This approach uses PHP's built-in filter to validate email format.
     * It's the most concise and maintained validation approach.
     */
    public function isValidEmail(string $email): bool
    {
        $sanitized = trim(strtolower($email));

        if ($sanitized === '') {
            return false;
        }

        $validated = filter_var($sanitized, FILTER_VALIDATE_EMAIL);

        if ($validated === false) {
            $this->logger->debug('Email validation failed via filter', [
                'email' => $this->maskEmail($sanitized),
            ]);
            return false;
        }

        $this->logger->debug('Email validated successfully', [
            'email' => $this->maskEmail($sanitized),
        ]);

        return true;
    }

    /**
     * Validates email and returns detailed result with parts.
     */
    public function parseEmail(string $email): ?array
    {
        if (!$this->isValidEmail($email)) {
            return null;
        }

        $sanitized = trim(strtolower($email));
        $parts = explode('@', $sanitized);

        return [
            'local' => $parts[0],
            'domain' => $parts[1] ?? '',
            'full' => $sanitized,
        ];
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        return substr($local, 0, 2) . '***@' . $domain;
    }
}
