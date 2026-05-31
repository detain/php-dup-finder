<?php
declare(strict_types=1);

namespace Validation\Shared;

final class ValidationLengths
{
    public const USERNAME_MIN = 3;
    public const USERNAME_MAX = 32;
    public const PASSWORD_MIN = 8;
    public const PASSWORD_MAX = 128;
    public const EMAIL_MAX = 255;
    public const PHONE_MIN = 10;
    public const PHONE_MAX = 15;
    public const FIRST_NAME_MAX = 100;
    public const LAST_NAME_MAX = 100;
    public const ADDRESS_MAX = 500;
    public const BIO_MAX = 1000;
}

final class ValidationPatterns
{
    public const USERNAME = '/^[a-zA-Z0-9_-]+$/';
    public const PASSWORD = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/';
    public const EMAIL = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
    public const PHONE = '/^\+?[1-9]\d{9,14}$/';
}

final class FileUploadConstraints
{
    public const MAX_SIZE_MB = 5;
    public const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif'];
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];
}

interface StringValidatorInterface
{
    public function validateMinLength(string $value, int $min, string $fieldName): ?string;
    public function validateMaxLength(string $value, int $max, string $fieldName): ?string;
    public function validatePattern(string $value, string $pattern, string $errorMessage): ?string;
}

trait StringValidationLogic
{
    private ValidationLengths $lengths;
    private ValidationPatterns $patterns;

    protected function validateUsername(string $username): ?string
    {
        if (strlen($username) < $this->lengths::USERNAME_MIN) {
            return sprintf('Username must be at least %d characters', $this->lengths::USERNAME_MIN);
        }
        if (strlen($username) > $this->lengths::USERNAME_MAX) {
            return sprintf('Username cannot exceed %d characters', $this->lengths::USERNAME_MAX);
        }
        if (!preg_match($this->patterns::USERNAME, $username)) {
            return 'Username can only contain letters, numbers, underscores, and hyphens';
        }
        return null;
    }

    protected function validatePassword(string $password): ?string
    {
        if (strlen($password) < $this->lengths::PASSWORD_MIN) {
            return sprintf('Password must be at least %d characters', $this->lengths::PASSWORD_MIN);
        }
        if (strlen($password) > $this->lengths::PASSWORD_MAX) {
            return sprintf('Password cannot exceed %d characters', $this->lengths::PASSWORD_MAX);
        }
        if (!preg_match($this->patterns::PASSWORD, $password)) {
            return 'Password must contain uppercase, lowercase, number, and special character';
        }
        return null;
    }

    protected function validateEmail(string $email): ?string
    {
        if (strlen($email) > $this->lengths::EMAIL_MAX) {
            return sprintf('Email cannot exceed %d characters', $this->lengths::EMAIL_MAX);
        }
        if (!preg_match($this->patterns::EMAIL, $email)) {
            return 'Please enter a valid email address';
        }
        return null;
    }

    protected function validatePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        if (strlen($phone) < $this->lengths::PHONE_MIN) {
            return sprintf('Phone must be at least %d digits', $this->lengths::PHONE_MIN);
        }
        if (strlen($phone) > $this->lengths::PHONE_MAX) {
            return sprintf('Phone cannot exceed %d digits', $this->lengths::PHONE_MAX);
        }
        if (!preg_match($this->patterns::PHONE, $phone)) {
            return 'Please enter a valid phone number';
        }
        return null;
    }

    protected function validateFileUpload(array $file): ?string
    {
        $maxBytes = FileUploadConstraints::MAX_SIZE_MB * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            return sprintf('File cannot exceed %d MB', FileUploadConstraints::MAX_SIZE_MB);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, FileUploadConstraints::ALLOWED_IMAGE_TYPES, true)) {
            return 'File must be a JPEG, PNG, or GIF image';
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, FileUploadConstraints::ALLOWED_EXTENSIONS, true)) {
            return 'File must have a valid image extension';
        }

        return null;
    }
}
