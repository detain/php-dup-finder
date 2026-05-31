<?php

declare(strict_types=1);

namespace App\Validation\Email;

use Psr\Log\LoggerInterface;

interface EmailValidatorInterface
{
    public function isValidEmail(string $email): bool;
    public function parseEmail(string $email): ?array;
}

abstract class AbstractEmailValidator implements EmailValidatorInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

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

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        return substr($local, 0, 2) . '***@' . $domain;
    }
}

final class FilterEmailValidator extends AbstractEmailValidator
{
    public function isValidEmail(string $email): bool
    {
        $sanitized = trim(strtolower($email));

        if ($sanitized === '') {
            return false;
        }

        return filter_var($sanitized, FILTER_VALIDATE_EMAIL) !== false;
    }
}

final class RegexEmailValidator extends AbstractEmailValidator
{
    public function isValidEmail(string $email): bool
    {
        $sanitized = trim(strtolower($email));

        if ($sanitized === '' || strlen($sanitized) > 254) {
            return false;
        }

        $pattern = '/^[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i';

        return preg_match($pattern, $sanitized) === 1;
    }
}

final class ManualEmailValidator extends AbstractEmailValidator
{
    public function isValidEmail(string $email): bool
    {
        $sanitized = trim(strtolower($email));

        if ($sanitized === '' || strlen($sanitized) > 254) {
            return false;
        }

        $atPos = strpos($sanitized, '@');
        if ($atPos === false || $atPos === 0 || $atPos === strlen($sanitized) - 1) {
            return false;
        }

        return $this->isValidLocalPart(substr($sanitized, 0, $atPos))
            && $this->isValidDomainPart(substr($sanitized, $atPos + 1));
    }

    private function isValidLocalPart(string $local): bool
    {
        return strlen($local) <= 64
            && preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local) === 1;
    }

    private function isValidDomainPart(string $domain): bool
    {
        return strlen($domain) <= 253
            && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $domain) === 1;
    }
}

final class EmailValidationOrchestrator
{
    /** @var EmailValidatorInterface[] */
    private array $validators = [];

    public function registerValidator(EmailValidatorInterface $validator): void
    {
        $this->validators[] = $validator;
    }

    public function isValidEmail(string $email): bool
    {
        foreach ($this->validators as $validator) {
            if (!$validator->isValidEmail($email)) {
                return false;
            }
        }

        return true;
    }
}
