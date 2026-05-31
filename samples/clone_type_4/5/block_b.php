<?php

declare(strict_types=1);

namespace App\Validation\Email;

use Psr\Log\LoggerInterface;

final class EmailValidatorB
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Validates email using regex pattern matching.
     *
     * This approach uses a comprehensive regex pattern to validate
     * the email format with specific rules for local and domain parts.
     */
    public function isValidEmail(string $email): bool
    {
        $sanitized = trim(strtolower($email));

        if ($sanitized === '') {
            return false;
        }

        $pattern = '/^[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i';

        if (!preg_match($pattern, $sanitized)) {
            $this->logger->debug('Email validation failed via regex', [
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
