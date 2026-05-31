<?php
declare(strict_types=1);

namespace App\Product\Policy;

final class ProductCodePolicy
{
    public function __construct(
        public readonly int $minLength = 3,
        public readonly int $maxLength = 30,
        public readonly string $pattern = '^[A-Z]{2,4}[0-9]{4,8}[A-Z0-9]{0,2}$',
        public readonly int $prefixMinLength = 2,
        public readonly int $prefixMaxLength = 4,
        public readonly int $numberMinLength = 4,
        public readonly int $numberMaxLength = 8,
        public readonly int $suffixMaxLength = 2
    ) {}

    public static function fromConfig(array $config): self
    {
        $code = $config['product_code'] ?? [];

        return new self(
            minLength: $code['min_length'] ?? 3,
            maxLength: $code['max_length'] ?? 30,
            pattern: $code['pattern'] ?? '^[A-Z]{2,4}[0-9]{4,8}[A-Z0-9]{0,2}$',
            prefixMinLength: $code['prefix_min_length'] ?? 2,
            prefixMaxLength: $code['prefix_max_length'] ?? 4,
            numberMinLength: $code['number_min_length'] ?? 4,
            numberMaxLength: $code['number_max_length'] ?? 8,
            suffixMaxLength: $code['suffix_max_length'] ?? 2
        );
    }

    public function validate(string $code): ValidationResult
    {
        $errors = [];

        if (strlen($code) < $this->minLength) {
            $errors[] = "Product code must be at least {$this->minLength} characters";
        }

        if (strlen($code) > $this->maxLength) {
            $errors[] = "Product code cannot exceed {$this->maxLength} characters";
        }

        if (!preg_match("/^{$this->pattern}$/", $code)) {
            $errors[] = 'Product code format is invalid';
        }

        if (!$this->hasValidStructure($code)) {
            $errors[] = 'Product code structure is invalid';
        }

        return new ValidationResult(
            isValid: empty($errors),
            errors: $errors
        );
    }

    private function hasValidStructure(string $code): bool
    {
        $prefixPattern = "[A-Z]{{$this->prefixMinLength},{$this->prefixMaxLength}}";
        $numberPattern = "[0-9]{{$this->numberMinLength},{$this->numberMaxLength}}";
        $suffixPattern = "[A-Z0-9]{0,{$this->suffixMaxLength}}";

        $fullPattern = "/^{$prefixPattern}{$numberPattern}{$suffixPattern}$/";

        return (bool) preg_match($fullPattern, $code);
    }
}
