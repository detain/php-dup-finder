<?php
declare(strict_types=1);

namespace App\Auth\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class EmailAddressValidator extends ConstraintValidator
{
    public const MAX_LENGTH = 254;
    public const MIN_LENGTH = 5;
    public const PATTERN = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/';

    public const DISPOSABLE_EMAIL_DOMAINS = [
        'tempmail.com', 'throwaway.com', 'fakeinbox.com',
        'mailinator.com', 'guerrillamail.com', '10minutemail.com',
        'temp-mail.org', 'getnada.com', 'mohmal.com'
    ];

    public function validate($value, Constraint $constraint): void
    {
        if (empty($value)) {
            $this->context->buildViolation('Email address is required')
                ->addViolation();
            return;
        }

        if (!is_string($value)) {
            $this->context->buildViolation('Email must be a string')
                ->addViolation();
            return;
        }

        if (strlen($value) > self::MAX_LENGTH) {
            $this->context->buildViolation(
                'Email cannot exceed {{ limit }} characters'
            )->setParameter('{{ limit }}', (string) self::MAX_LENGTH)
                ->addViolation();
        }

        if (strlen($value) < self::MIN_LENGTH) {
            $this->context->buildViolation(
                'Email must be at least {{ limit }} characters'
            )->setParameter('{{ limit }}', (string) self::MIN_LENGTH)
                ->addViolation();
        }

        if (!preg_match(self::PATTERN, $value)) {
            $this->context->buildViolation('Invalid email format')
                ->addViolation();
            return;
        }

        if ($this->containsInvalidCharacters($value)) {
            $this->context->buildViolation(
                'Email contains invalid characters'
            )->addViolation();
        }

        $domain = $this->extractDomain($value);
        if ($this->isDisposableEmailDomain($domain)) {
            $this->context->buildViolation(
                'Disposable email addresses are not allowed'
            )->addViolation();
        }
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? '';
    }

    private function containsInvalidCharacters(string $email): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $email);
    }

    private function isDisposableEmailDomain(string $domain): bool
    {
        $domain = strtolower($domain);
        return in_array($domain, self::DISPOSABLE_EMAIL_DOMAINS, true);
    }
}
