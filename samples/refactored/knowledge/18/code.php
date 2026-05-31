<?php
declare(strict_types=1);

namespace App\User\Policy;

final class EmailPolicy
{
    public const MAX_LENGTH = 254;
    public const MIN_LENGTH = 5;
    public const PATTERN = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/';

    private const DEFAULT_BLOCKED_DOMAINS = [
        'tempmail.com', 'throwaway.com', 'fakeinbox.com',
        'mailinator.com', 'guerrillamail.com', '10minutemail.com',
        'temp-mail.org', 'getnada.com', 'mohmal.com'
    ];

    public function __construct(
        public readonly int $maxLength = self::MAX_LENGTH,
        public readonly int $minLength = self::MIN_LENGTH,
        public readonly string $pattern = self::PATTERN,
        public readonly array $blockedDomains = self::DEFAULT_BLOCKED_DOMAINS,
        public readonly bool $allowDisposable = false
    ) {}

    public static function fromConfig(array $config): self
    {
        $email = $config['email'] ?? [];

        return new self(
            maxLength: $email['max_length'] ?? self::MAX_LENGTH,
            minLength: $email['min_length'] ?? self::MIN_LENGTH,
            blockedDomains: $email['blocked_domains'] ?? self::DEFAULT_BLOCKED_DOMAINS,
            allowDisposable: $email['allow_disposable'] ?? false
        );
    }

    public function validate(string $email): EmailValidationResult
    {
        $errors = [];

        if (strlen($email) < $this->minLength) {
            $errors[] = "Email must be at least {$this->minLength} characters";
        }

        if (strlen($email) > $this->maxLength) {
            $errors[] = "Email cannot exceed {$this->maxLength} characters";
        }

        if (!preg_match($this->pattern, $email)) {
            $errors[] = 'Invalid email format';
        }

        if (!$this->allowDisposable && $this->isDisposableDomain($email)) {
            $errors[] = 'Disposable email addresses are not allowed';
        }

        return new EmailValidationResult(
            isValid: empty($errors),
            errors: $errors
        );
    }

    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function isDisposableDomain(string $email): bool
    {
        $domain = $this->extractDomain($email);
        return in_array(strtolower($domain), $this->blockedDomains, true);
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? '';
    }
}
