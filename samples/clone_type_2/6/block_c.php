<?php

declare(strict_types=1);

namespace App\Validation;

use App\Entity\Vendor;
use App\Repository\VendorRepository;
use App\Exception\ValidationException;

final class VendorValidator
{
    public function __construct(
        private readonly VendorRepository $vendorRepository,
    ) {}

    public function validateCreate(array $data): Vendor
    {
        $errors = [];

        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } elseif ($this->vendorRepository->findByEmail($data['email']) !== null) {
            $errors['email'] = 'Email already exists';
        }

        if (empty($data['business_name'])) {
            $errors['business_name'] = 'Business name is required';
        } elseif (strlen($data['business_name']) < 2) {
            $errors['business_name'] = 'Business name must be at least 2 characters';
        } elseif (strlen($data['business_name']) > 200) {
            $errors['business_name'] = 'Business name must not exceed 200 characters';
        } elseif ($this->vendorRepository->findByBusinessName($data['business_name']) !== null) {
            $errors['business_name'] = 'Business name already exists';
        }

        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        } elseif (!preg_match('/[A-Z]/', $data['password'])) {
            $errors['password'] = 'Password must contain at least one uppercase letter';
        } elseif (!preg_match('/[a-z]/', $data['password'])) {
            $errors['password'] = 'Password must contain at least one lowercase letter';
        } elseif (!preg_match('/[0-9]/', $data['password'])) {
            $errors['password'] = 'Password must contain at least one number';
        }

        if (isset($data['tax_id']) && !$this->isValidTaxId($data['tax_id'])) {
            $errors['tax_id'] = 'Invalid tax ID format';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return new Vendor($data['email'], $data['business_name'], $data['password'], $data['tax_id'] ?? null);
    }

    public function validateUpdate(Vendor $vendor, array $data): Vendor
    {
        $errors = [];

        if (isset($data['email']) && $data['email'] !== $vendor->getEmail()) {
            if (empty($data['email'])) {
                $errors['email'] = 'Email cannot be empty';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            } elseif ($this->vendorRepository->findByEmail($data['email']) !== null) {
                $errors['email'] = 'Email already exists';
            }
        }

        if (isset($data['business_name']) && $data['business_name'] !== $vendor->getBusinessName()) {
            if (empty($data['business_name'])) {
                $errors['business_name'] = 'Business name cannot be empty';
            } elseif (strlen($data['business_name']) < 2) {
                $errors['business_name'] = 'Business name must be at least 2 characters';
            } elseif ($this->vendorRepository->findByBusinessName($data['business_name']) !== null) {
                $errors['business_name'] = 'Business name already exists';
            }
        }

        if (isset($data['tax_id']) && !$this->isValidTaxId($data['tax_id'])) {
            $errors['tax_id'] = 'Invalid tax ID format';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $vendor->update($data);
    }

    private function isValidTaxId(string $taxId): bool
    {
        return preg_match('/^[0-9]{2}-?[0-9]{7}$/', $taxId) === 1;
    }
}
