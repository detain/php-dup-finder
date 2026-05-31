<?php

declare(strict_types=1);

namespace App\Api;

use Psr\Log\LoggerInterface;

interface ValidatorInterface
{
    public function validate(array $payload): array;
    public function sanitize(array $payload): array;
    public function checkRateLimit(string $clientId): bool;
}

interface ValidationRuleInterface
{
    public function validate(mixed $value): ?string;
    public function getFieldName(): string;
}

abstract class AbstractValidator implements ValidatorInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    final public function validate(array $payload): array
    {
        $errors = [];
        $rules = $this->getValidationRules();

        foreach ($rules as $rule) {
            $fieldName = $rule->getFieldName();
            $value = $payload[$fieldName] ?? null;
            $error = $rule->validate($value);

            if ($error !== null) {
                $errors[$fieldName] = $error;
            }
        }

        $this->logValidationComplete(count($errors));

        return $errors;
    }

    abstract protected function getValidationRules(): array;

    protected function logValidationComplete(int $errorCount): void
    {
        $this->logger->debug(static::class . ' validation completed', [
            'error_count' => $errorCount,
        ]);
    }
}

final class CreateUserValidator extends AbstractValidator
{
    private array $rules;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->rules = $this->buildRules();
    }

    protected function getValidationRules(): array
    {
        return $this->rules;
    }

    private function buildRules(): array
    {
        return [
            new RequiredStringRule('email', function ($v) {
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    return 'Invalid email format';
                }
                return null;
            }),
            new RequiredStringRule('password', function ($v) {
                if (strlen($v) < 8) {
                    return 'Password must be at least 8 characters';
                }
                return null;
            }),
            new RequiredStringRule('username', function ($v) {
                if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $v)) {
                    return 'Username must be 3-20 alphanumeric characters';
                }
                return null;
            }),
            new RequiredStringRule('first_name'),
            new RequiredStringRule('last_name'),
            new OptionalStringRule('phone', function ($v) {
                if (!preg_match('/^\+?[1-9]\d{6,14}$/', $v)) {
                    return 'Invalid phone number format';
                }
                return null;
            }),
        ];
    }
}

final class ValidationRuleFactory
{
    public static function required(string $fieldName, ?callable $validator = null): ValidationRuleInterface
    {
        return new RequiredStringRule($fieldName, $validator);
    }

    public static function optional(string $fieldName, ?callable $validator = null): ValidationRuleInterface
    {
        return new OptionalStringRule($fieldName, $validator);
    }

    public static function numeric(string $fieldName, ?float $min = null, ?float $max = null): ValidationRuleInterface
    {
        return new NumericRule($fieldName, $min, $max);
    }

    public static function integer(string $fieldName, ?int $min = null, ?int $max = null): ValidationRuleInterface
    {
        return new IntegerRule($fieldName, $min, $max);
    }

    public static function email(string $fieldName): ValidationRuleInterface
    {
        return new RequiredStringRule($fieldName, function ($v) {
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return 'Invalid email format';
            }
            return null;
        });
    }
}
