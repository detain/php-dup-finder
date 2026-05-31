<?php
declare(strict_types=1);

namespace Validation\Shared;

interface SanitizationStrategy
{
    public function sanitizeForCreate(array $input): SanitizedInput;
    public function sanitizeForUpdate(array $input, mixed $existingEntity): SanitizedInput;
    public function validate(array $data): array;
    public function validatePartial(array $data): array;
}

abstract class BaseInputSanitizer implements SanitizationStrategy
{
    protected LoggerInterface $logger;

    public function sanitizeForCreate(array $input): SanitizedInput
    {
        $sanitized = $this->sanitize($input);

        return new SanitizedInput(
            data: $sanitized,
            violations: $this->validate($sanitized),
            sanitizedAt: new \DateTimeImmutable(),
        );
    }

    public function sanitizeForUpdate(array $input, mixed $existingEntity): SanitizedInput
    {
        $sanitized = $this->sanitizePartial($input);

        return new SanitizedInput(
            data: $sanitized,
            violations: $this->validatePartial($sanitized),
            sanitizedAt: new \DateTimeImmutable(),
        );
    }

    protected function sanitizeString(string $value, int $maxLength = 255): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        return substr($value, 0, $maxLength);
    }

    protected function sanitizeEmail(string $email): string
    {
        return filter_var(strtolower(trim($email)), FILTER_SANITIZE_EMAIL);
    }

    protected function sanitizeBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    protected function sanitizePrice(mixed $price): float
    {
        return max(0, round((float)$price, 2));
    }

    abstract protected function sanitize(array $input): array;
    abstract protected function sanitizePartial(array $input): array;
    abstract public function validate(array $data): array;
    abstract public function validatePartial(array $data): array;
}

final class UserInputSanitizer extends BaseInputSanitizer
{
    protected function sanitize(array $input): array
    {
        return [
            'email' => $this->sanitizeEmail($input['email'] ?? ''),
            'first_name' => $this->sanitizeString($input['first_name'] ?? '', 100),
            'last_name' => $this->sanitizeString($input['last_name'] ?? '', 100),
        ];
    }

    protected function sanitizePartial(array $input): array
    {
        $sanitized = [];

        if (isset($input['email'])) {
            $sanitized['email'] = $this->sanitizeEmail($input['email']);
        }

        if (isset($input['first_name'])) {
            $sanitized['first_name'] = $this->sanitizeString($input['first_name'], 100);
        }

        return $sanitized;
    }

    public function validate(array $data): array
    {
        $violations = [];

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $violations[] = 'email_invalid';
        }

        return $violations;
    }

    public function validatePartial(array $data): array
    {
        $violations = [];

        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $violations[] = 'email_invalid';
        }

        return $violations;
    }
}
