<?php

declare(strict_types=1);

namespace App\Validation\Email;

use Psr\Log\LoggerInterface;

final class EmailValidatorC
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Validates email using manual parsing and validation rules.
     *
     * This approach manually validates each component of the email
     * including length limits and character restrictions.
     */
    public function isValidEmail(string $email): bool
    {
        $sanitized = trim(strtolower($email));

        if ($sanitized === '') {
            return false;
        }

        if (strlen($sanitized) > 254) {
            $this->logger->debug('Email too long', ['length' => strlen($sanitized)]);
            return false;
        }

        $atPos = strpos($sanitized, '@');

        if ($atPos === false || $atPos === 0 || $atPos === strlen($sanitized) - 1) {
            $this->logger->debug('Email missing or invalid @ position', [
                'email' => $this->maskEmail($sanitized),
            ]);
            return false;
        }

        $localPart = substr($sanitized, 0, $atPos);
        $domainPart = substr($sanitized, $atPos + 1);

        if (!$this->isValidLocalPart($localPart) || !$this->isValidDomainPart($domainPart)) {
            return false;
        }

        $this->logger->debug('Email validated successfully', [
            'email' => $this->maskEmail($sanitized),
        ]);

        return true;
    }

    private function isValidLocalPart(string $local): bool
    {
        if (strlen($local) > 64) {
            return false;
        }

        if (!preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local)) {
            return false;
        }

        return true;
    }

    private function isValidDomainPart(string $domain): bool
    {
        if (strlen($domain) > 253) {
            return false;
        }

        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $domain)) {
            return false;
        }

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
