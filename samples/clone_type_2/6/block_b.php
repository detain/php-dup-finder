<?php

declare(strict_types=1);

namespace App\Validation;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Exception\ValidationException;

final class CustomerValidator
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
    ) {}

    public function validateCreate(array $data): Customer
    {
        $errors = [];

        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } elseif ($this->customerRepository->findByEmail($data['email']) !== null) {
            $errors['email'] = 'Email already exists';
        }

        if (empty($data['company_name'])) {
            $errors['company_name'] = 'Company name is required';
        } elseif (strlen($data['company_name']) < 2) {
            $errors['company_name'] = 'Company name must be at least 2 characters';
        } elseif (strlen($data['company_name']) > 200) {
            $errors['company_name'] = 'Company name must not exceed 200 characters';
        } elseif ($this->customerRepository->findByCompanyName($data['company_name']) !== null) {
            $errors['company_name'] = 'Company name already exists';
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

        if (isset($data['credit_limit']) && $data['credit_limit'] < 0) {
            $errors['credit_limit'] = 'Credit limit cannot be negative';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return new Customer($data['email'], $data['company_name'], $data['password'], $data['credit_limit'] ?? 0);
    }

    public function validateUpdate(Customer $customer, array $data): Customer
    {
        $errors = [];

        if (isset($data['email']) && $data['email'] !== $customer->getEmail()) {
            if (empty($data['email'])) {
                $errors['email'] = 'Email cannot be empty';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            } elseif ($this->customerRepository->findByEmail($data['email']) !== null) {
                $errors['email'] = 'Email already exists';
            }
        }

        if (isset($data['company_name']) && $data['company_name'] !== $customer->getCompanyName()) {
            if (empty($data['company_name'])) {
                $errors['company_name'] = 'Company name cannot be empty';
            } elseif (strlen($data['company_name']) < 2) {
                $errors['company_name'] = 'Company name must be at least 2 characters';
            } elseif ($this->customerRepository->findByCompanyName($data['company_name']) !== null) {
                $errors['company_name'] = 'Company name already exists';
            }
        }

        if (isset($data['credit_limit']) && $data['credit_limit'] < 0) {
            $errors['credit_limit'] = 'Credit limit cannot be negative';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $customer->update($data);
    }
}
