<?php

declare(strict_types=1);

namespace App\Application\Validation;

use App\Infrastructure\Validation\Validator;

/**
 * Centralized validation service facade.
 * Eliminates duplication of Validator injection.
 */
class ValidationService
{
    private Validator $validator;

    public function __construct(Validator $validator)
    {
        $this->validator = $validator;
    }

    public function validate(string $scenario, array $data): ValidationResult
    {
        $rules = $this->getRules($scenario);

        $this->validator->validate($data, $rules);

        if ($this->validator->fails()) {
            return new ValidationResult(
                valid: false,
                errors: $this->validator->getErrors(),
            );
        }

        return new ValidationResult(valid: true);
    }

    private function getRules(string $scenario): array
    {
        return match ($scenario) {
            'registration' => [
                'email' => 'required|email|max:255',
                'password' => 'required|min:8|max:128|strong_password',
                // ...
            ],
            'product' => [
                'sku' => 'required|string|max:100',
                // ...
            ],
            default => [],
        };
    }
}
