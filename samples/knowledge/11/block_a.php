<?php
declare(strict_types=1);

namespace App\User\Validation;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PasswordConstraintValidator extends ConstraintValidator
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;
    public const REQUIRE_UPPERCASE = true;
    public const REQUIRE_LOWERCASE = true;
    public const REQUIRE_DIGIT = true;
    public const REQUIRE_SPECIAL = true;

    public function validate($value, Constraint $constraint): void
    {
        if (!$value instanceof PasswordValueObject) {
            throw new \InvalidArgumentException('Expected PasswordValueObject');
        }

        $password = $value->getPlainText();

        if (strlen($password) < self::MIN_LENGTH) {
            $this->context->buildViolation('Password must be at least {{ limit }} characters')
                ->setParameter('{{ limit }}', (string) self::MIN_LENGTH)
                ->addViolation();
            return;
        }

        if (strlen($password) > self::MAX_LENGTH) {
            $this->context->buildViolation('Password cannot exceed {{ limit }} characters')
                ->setParameter('{{ limit }}', (string) self::MAX_LENGTH)
                ->addViolation();
            return;
        }

        if (self::REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $this->context->buildViolation('Password must contain at least one uppercase letter')
                ->addViolation();
        }

        if (self::REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $this->context->buildViolation('Password must contain at least one lowercase letter')
                ->addViolation();
        }

        if (self::REQUIRE_DIGIT && !preg_match('/[0-9]/', $password)) {
            $this->context->buildViolation('Password must contain at least one digit')
                ->addViolation();
        }

        if (self::REQUIRE_SPECIAL && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $this->context->buildViolation('Password must contain at least one special character')
                ->addViolation();
        }

        $commonPasswords = $this->getCommonPasswords();
        if (in_array(strtolower($password), $commonPasswords, true)) {
            $this->context->buildViolation('This password is too common and cannot be used')
                ->addViolation();
        }
    }

    private function getCommonPasswords(): array
    {
        return [
            'password', 'password123', '12345678', 'qwertyui',
            'letmein', 'welcome', 'monkey', 'dragon',
            'master', 'admin123', 'login123'
        ];
    }
}

final class PasswordValueObject
{
    private const MAX_LENGTH = 128;

    private function __construct(public readonly string $plainText)
    {
    }

    public static function create(string $plainText): self
    {
        if (strlen($plainText) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Password exceeds maximum length of 128 characters');
        }

        return new self($plainText);
    }

    public function getPlainText(): string
    {
        return $this->plainText;
    }

    public function getHash(): string
    {
        return password_hash($this->plainText, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
}
