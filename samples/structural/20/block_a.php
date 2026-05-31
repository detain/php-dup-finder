<?php
declare(strict_types=1);

namespace Validation\Sanitization;

use Psr\Log\LoggerInterface;

final class UserInputSanitizer
{
    private const MAX_NAME_LENGTH = 100;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_ADDRESS_LENGTH = 500;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function sanitizeForCreate(array $input): SanitizedInput
    {
        $sanitized = [];

        $sanitized['email'] = $this->sanitizeEmail($input['email'] ?? '');
        $sanitized['first_name'] = $this->sanitizeName($input['first_name'] ?? '');
        $sanitized['last_name'] = $this->sanitizeName($input['last_name'] ?? '');
        $sanitized['phone'] = $this->sanitizePhone($input['phone'] ?? '');
        $sanitized['address'] = $this->sanitizeAddress($input['address'] ?? []);
        $sanitized['date_of_birth'] = $this->sanitizeDate($input['date_of_birth'] ?? null);
        $sanitized['password'] = $this->sanitizePassword($input['password'] ?? '');
        $sanitized['accepted_terms'] = $this->sanitizeBoolean($input['accepted_terms'] ?? false);

        $this->logger->debug('User input sanitized for create', [
            'has_email' => !empty($sanitized['email']),
        ]);

        return new SanitizedInput(
            data: $sanitized,
            violations: $this->validate($sanitized),
            sanitizedAt: new \DateTimeImmutable(),
        );
    }

    public function sanitizeForUpdate(array $input, User $existingUser): SanitizedInput
    {
        $sanitized = [];

        if (isset($input['email'])) {
            $sanitized['email'] = $this->sanitizeEmail($input['email']);
        }

        if (isset($input['first_name'])) {
            $sanitized['first_name'] = $this->sanitizeName($input['first_name']);
        }

        if (isset($input['last_name'])) {
            $sanitized['last_name'] = $this->sanitizeName($input['last_name']);
        }

        if (isset($input['phone'])) {
            $sanitized['phone'] = $this->sanitizePhone($input['phone']);
        }

        if (isset($input['address'])) {
            $sanitized['address'] = $this->sanitizeAddress($input['address']);
        }

        $this->logger->debug('User input sanitized for update', [
            'updated_fields' => array_keys($sanitized),
        ]);

        return new SanitizedInput(
            data: $sanitized,
            violations: $this->validatePartial($sanitized),
            sanitizedAt: new \DateTimeImmutable(),
        );
    }

    private function sanitizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $email = substr($email, 0, self::MAX_EMAIL_LENGTH);

        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = substr($name, 0, self::MAX_NAME_LENGTH);

        $name = preg_replace('/\s+/', ' ', $name);

        $name = preg_replace('/[<>\/\\\]/', '', $name);

        return $name;
    }

    private function sanitizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 10) {
            return sprintf('+1-%s-%s', substr($digits, 0, 3), substr($digits, 3, 3));
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return sprintf('+1-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3));
        }

        return $phone;
    }

    private function sanitizeAddress(array $address): array
    {
        return [
            'street' => $this->truncate($this->removeControlChars($address['street'] ?? ''), self::MAX_ADDRESS_LENGTH),
            'city' => $this->truncate($this->removeControlChars($address['city'] ?? ''), self::MAX_NAME_LENGTH),
            'state' => strtoupper(preg_replace('/[^A-Z]/', '', $address['state'] ?? '')),
            'postal_code' => preg_replace('/\D/', '', $address['postal_code'] ?? ''),
            'country' => strtoupper(substr($address['country'] ?? 'US', 0, 2)),
        ];
    }

    private function sanitizeDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            $parsed = new \DateTimeImmutable($date);
            return $parsed->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizePassword(string $password): string
    {
        return substr($password, 0, 128);
    }

    private function sanitizeBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function validate(array $data): array
    {
        $violations = [];

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $violations[] = 'email_invalid';
        }

        if (strlen($data['first_name']) < 1) {
            $violations[] = 'first_name_required';
        }

        if (strlen($data['last_name']) < 1) {
            $violations[] = 'last_name_required';
        }

        if (!$data['accepted_terms']) {
            $violations[] = 'terms_must_be_accepted';
        }

        return $violations;
    }

    private function validatePartial(array $data): array
    {
        $violations = [];

        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $violations[] = 'email_invalid';
        }

        return $violations;
    }

    private function sanitizeString(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    private function removeControlChars(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    private function truncate(string $value, int $maxLength): string
    {
        return substr($value, 0, $maxLength);
    }
}
