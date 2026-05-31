<?php
declare(strict_types=1);

namespace Ecommerce\Core\Validation;

use Psr\Log\LoggerInterface;

final class EmailValidator
{
    private const MAX_EMAIL_LENGTH = 254;
    private const DISPOSABLE_DOMAINS = [
        'tempmail.com', 'throwaway.email', 'guerrillamail.com',
        'mailinator.com', '10minutemail.com', 'fakeinbox.com',
        'trashmail.com', 'dispostable.com', 'maildrop.cc'
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function validate(string $email, array $context = []): ValidationResult
    {
        // Check format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->log('warning', 'Invalid email format', $email, $context);
            return ValidationResult::invalid('Please provide a valid email address');
        }

        // Check disposable domain
        $domain = strtolower(substr(strrchr($email, '@'), 1) ?: '');
        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            $this->log('notice', 'Disposable email domain blocked', $email, $context);
            return ValidationResult::invalid('Disposable email addresses are not permitted');
        }

        // Check length
        if (strlen($email) > self::MAX_EMAIL_LENGTH) {
            $this->log('warning', 'Email exceeds max length', $email, $context);
            return ValidationResult::invalid('Email address exceeds maximum length');
        }

        return ValidationResult::valid();
    }

    public function isValid(string $email): bool
    {
        return $this->validate($email)->isValid();
    }

    private function log(string $level, string $message, string $email, array $context): void
    {
        $this->logger?->{$level}($message, [
            'email' => substr($email, 0, 3) . '***',
            ...$context
        ]);
    }
}
