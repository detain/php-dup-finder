<?php

declare(strict_types=1);

namespace App\Shared;

use Psr\Log\LoggerInterface;

interface ValidationRuleInterface
{
    public function validate(mixed $value): ?string;
}

final class LengthRule implements ValidationRuleInterface
{
    private int $min;
    private int $max;

    public function __construct(int $min = 0, int $max = PHP_INT_MAX)
    {
        $this->min = $min;
        $this->max = $max;
    }

    public function validate(mixed $value): ?string
    {
        $length = strlen(trim((string) $value));

        if ($length < $this->min) {
            return "Value is too short (minimum: {$this->min})";
        }

        if ($length > $this->max) {
            return "Value is too long (maximum: {$this->max})";
        }

        return null;
    }
}

final class PatternRule implements ValidationRuleInterface
{
    private string $pattern;
    private string $description;

    public function __construct(string $pattern, string $description = 'invalid format')
    {
        $this->pattern = $pattern;
        $this->description = $description;
    }

    public function validate(mixed $value): ?string
    {
        if (!preg_match($this->pattern, (string) $value)) {
            return $this->description;
        }
        return null;
    }
}

final class RangeRule implements ValidationRuleInterface
{
    private float $min;
    private float $max;

    public function __construct(float $min = 0, float $max = PHP_FLOAT_MAX)
    {
        $this->min = $min;
        $this->max = $max;
    }

    public function validate(mixed $value): ?string
    {
        $numericValue = (float) $value;

        if ($numericValue < $this->min || $numericValue > $this->max) {
            return "Value must be between {$this->min} and {$this->max}";
        }

        return null;
    }
}

final class FieldValidator
{
    /** @var array<string, ValidationRuleInterface[]> */
    private array $fieldRules = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerRule(string $fieldName, ValidationRuleInterface $rule): void
    {
        if (!isset($this->fieldRules[$fieldName])) {
            $this->fieldRules[$fieldName] = [];
        }
        $this->fieldRules[$fieldName][] = $rule;
    }

    public function validate(array $data): void
    {
        foreach ($this->fieldRules as $fieldName => $rules) {
            if (!isset($data[$fieldName])) {
                continue;
            }

            foreach ($rules as $rule) {
                $error = $rule->validate($data[$fieldName]);
                if ($error !== null) {
                    throw new \InvalidArgumentException("{$fieldName}: {$error}");
                }
            }
        }
    }
}
