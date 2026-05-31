<?php
declare(strict_types=1);

namespace PartnerPortal\Vendor\Validator;

use Psr\Log\LoggerInterface;
use PartnerPortal\Vendor\Entities\VendorApplication;

final class VendorApplicationValidator
{
    private const USERNAME_MIN_LENGTH = 3;
    private const USERNAME_MAX_LENGTH = 32;
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_MAX_LENGTH = 128;
    private const EMAIL_MAX_LENGTH = 255;
    private const PHONE_MIN_LENGTH = 10;
    private const PHONE_MAX_LENGTH = 15;
    private const FIRST_NAME_MAX_LENGTH = 100;
    private const LAST_NAME_MAX_LENGTH = 100;
    private const ADDRESS_MAX_LENGTH = 500;
    private const BIO_MAX_LENGTH = 1000;

    private const USERNAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';
    private const PASSWORD_PATTERN = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/';
    private const EMAIL_PATTERN = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
    private const PHONE_PATTERN = '/^\+?[1-9]\d{9,14}$/';

    private const MAX_UPLOAD_SIZE_MB = 5;
    private const ALLOWED_AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/gif'];
    private const ALLOWED_AVATAR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function validate(VendorApplication $application): ValidationResult
    {
        $errors = [];

        $vendorNameResult = $this->validateVendorName($application->getVendorName());
        if ($vendorNameResult !== null) {
            $errors['vendor_name'] = $vendorNameResult;
        }

        $passwordResult = $this->validatePassword($application->getPassword());
        if ($passwordResult !== null) {
            $errors['password'] = $passwordResult;
        }

        $emailResult = $this->validateEmail($application->getBusinessEmail());
        if ($emailResult !== null) {
            $errors['email'] = $emailResult;
        }

        $phoneResult = $this->validatePhone($application->getBusinessPhone());
        if ($phoneResult !== null) {
            $errors['phone'] = $phoneResult;
        }

        $contactResult = $this->validateName($application->getContactFirstName(), $application->getContactLastName());
        if ($contactResult !== null) {
            $errors['contact_name'] = $contactResult;
        }

        $this->logger->debug('Vendor application validation completed', [
            'vendor_name' => $application->getVendorName(),
            'error_count' => count($errors),
        ]);

        return new ValidationResult(empty($errors), $errors);
    }

    private function validateVendorName(string $vendorName): ?string
    {
        if (strlen($vendorName) < self::USERNAME_MIN_LENGTH) {
            return sprintf('Vendor name must be at least %d characters', self::USERNAME_MIN_LENGTH);
        }
        if (strlen($vendorName) > self::USERNAME_MAX_LENGTH) {
            return sprintf('Vendor name cannot exceed %d characters', self::USERNAME_MAX_LENGTH);
        }
        if (!preg_match(self::USERNAME_PATTERN, $vendorName)) {
            return 'Vendor name can only contain letters, numbers, underscores, and hyphens';
        }
        return null;
    }

    private function validatePassword(string $password): ?string
    {
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            return sprintf('Password must be at least %d characters', self::PASSWORD_MIN_LENGTH);
        }
        if (strlen($password) > self::PASSWORD_MAX_LENGTH) {
            return sprintf('Password cannot exceed %d characters', self::PASSWORD_MAX_LENGTH);
        }
        if (!preg_match(self::PASSWORD_PATTERN, $password)) {
            return 'Password must contain uppercase, lowercase, number, and special character';
        }
        return null;
    }

    private function validateEmail(string $email): ?string
    {
        if (strlen($email) > self::EMAIL_MAX_LENGTH) {
            return sprintf('Email cannot exceed %d characters', self::EMAIL_MAX_LENGTH);
        }
        if (!preg_match(self::EMAIL_PATTERN, $email)) {
            return 'Please enter a valid business email address';
        }
        return null;
    }

    private function validatePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        if (strlen($phone) < self::PHONE_MIN_LENGTH) {
            return sprintf('Phone number must be at least %d digits', self::PHONE_MIN_LENGTH);
        }
        if (strlen($phone) > self::PHONE_MAX_LENGTH) {
            return sprintf('Phone number cannot exceed %d digits', self::PHONE_MAX_LENGTH);
        }
        if (!preg_match(self::PHONE_PATTERN, $phone)) {
            return 'Please enter a valid phone number';
        }
        return null;
    }

    private function validateName(string $firstName, string $lastName): ?string
    {
        if (strlen($firstName) > self::FIRST_NAME_MAX_LENGTH) {
            return sprintf('First name cannot exceed %d characters', self::FIRST_NAME_MAX_LENGTH);
        }
        if (strlen($lastName) > self::LAST_NAME_MAX_LENGTH) {
            return sprintf('Last name cannot exceed %d characters', self::LAST_NAME_MAX_LENGTH);
        }
        return null;
    }

    public function validateLogoUpload(array $file): ?string
    {
        if ($file['size'] > self::MAX_UPLOAD_SIZE_MB * 1024 * 1024) {
            return sprintf('Company logo cannot exceed %d MB', self::MAX_UPLOAD_SIZE_MB);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_AVATAR_TYPES, true)) {
            return 'Company logo must be a JPEG, PNG, or GIF image';
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_AVATAR_EXTENSIONS, true)) {
            return 'Company logo must have a valid image extension';
        }

        return null;
    }
}
