<?php

declare(strict_types=1);

namespace App\Application\Validation;

use App\Infrastructure\Validation\Validator;

/**
 * Form validation service.
 * The Validator is manually injected here, duplicated across
 * all services that perform validation.
 */
class FormValidatorService
{
    private Validator $validator;

    public function __construct(Validator $validator)
    {
        $this->validator = $validator;
    }

    public function validateRegistration(array $data): ValidationResult
    {
        $this->validator->validate($data, [
            'email' => 'required|email|max:255',
            'password' => 'required|min:8|max:128|strong_password',
            'first_name' => 'required|string|min:1|max:50',
            'last_name' => 'required|string|min:1|max:50',
            'phone' => 'sometimes|phone_number',
            'accept_terms' => 'required|accepted',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validateCheckout(array $data): ValidationResult
    {
        $this->validator->validate($data, [
            'shipping_address_id' => 'required|uuid',
            'billing_address_id' => 'required|uuid',
            'payment_method_id' => 'required|uuid',
            'shipping_method' => 'required|in:standard,express,overnight,freight',
            'coupon_code' => 'sometimes|coupon_exists',
            'notes' => 'sometimes|string|max:500',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validateProfileUpdate(array $data): ValidationResult
    {
        $this->validator->validate($data, [
            'first_name' => 'sometimes|string|min:1|max:50',
            'last_name' => 'sometimes|string|min:1|max:50',
            'phone' => 'sometimes|phone_number',
            'bio' => 'sometimes|string|max:500',
            'avatar_url' => 'sometimes|url|max:500',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validateAddress(array $data): ValidationResult
    {
        $this->validator->validate($data, [
            'street_line1' => 'required|string|min:5|max:255',
            'street_line2' => 'sometimes|string|max:255',
            'city' => 'required|string|min:2|max:100',
            'state' => 'required|string|min:2|max:100',
            'postal_code' => 'required|postal_code',
            'country_code' => 'required|country_code',
            'phone' => 'sometimes|phone_number',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    public function validatePasswordChange(array $data): ValidationResult
    {
        $this->validator->validate($data, [
            'current_password' => 'required',
            'new_password' => 'required|min:8|max:128|strong_password|different:current_password',
            'confirm_password' => 'required|same:new_password',
        ]);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }
}
