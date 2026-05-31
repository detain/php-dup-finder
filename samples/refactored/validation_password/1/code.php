<?php
declare(strict_types=1);

namespace Billing\Core\Security;

use Psr\Log\LoggerInterface;

final class PasswordPolicy
{
    private const MIN_LENGTH = 8;
    private const MAX_LENGTH = 128;
    private const COMMON_PASSWORDS = [
        'password', '12345678', 'qwerty123', 'letmein', 'welcome123',
        'admin123', 'password123', 'iloveyou', 'sunshine', 'princess'
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function validate(string $password): PolicyViolationList
    {
        $violations = new PolicyViolationList();

        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            $violations->add('Password must be at least %d characters', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            $violations->add('Password must not exceed %d characters', self::MAX_LENGTH);
        }

        if (!preg_match('/[A-Z]/u', $password)) {
            $violations->add('Must contain at least one uppercase letter');
        }
        if (!preg_match('/[a-z]/u', $password)) {
            $violations->add('Must contain at least one lowercase letter');
        }
        if (!preg_match('/[0-9]/u', $password)) {
            $violations->add('Must contain at least one number');
        }
        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'"\|,.<>\/?]/u', $password)) {
            $violations->add('Must contain at least one special character');
        }

        if (preg_match('/^(.)\1{2,}$/u', $password)) {
            $violations->add('Cannot contain 3+ repeated characters');
        }

        $passwordLower = mb_strtolower($password);
        foreach (self::COMMON_PASSWORDS as $common) {
            if (str_contains($passwordLower, $common)) {
                $violations->add('Password contains a common word or pattern');
                break;
            }
        }

        return $violations;
    }

    public function isStrong(string $password): bool
    {
        return $this->validate($password)->isEmpty();
    }
}
