<?php
declare(strict_types=1);

namespace OrderFlow\Validation\Shared;

interface ValidatorInterface
{
    public function validate(array $data): ValidationResult;
}

abstract class BaseValidator implements ValidatorInterface
{
    protected LoggerInterface $logger;

    public function validate(array $data): ValidationResult
    {
        $errors = $this->validateData($data);
        $this->logger->debug(static::class . ' validation completed', ['error_count' => count($errors)]);
        return new ValidationResult(empty($errors), $errors);
    }

    abstract protected function validateData(array $data): array;

    protected function requireNumeric(array $data, string $field, string $errorMessage): void
    {
        if (!isset($data[$field]) || !is_numeric($data[$field])) {
            throw new ValidationException($field, $errorMessage);
        }
    }

    protected function validateNumericRange(array $data, string $field, float $min, float $max): void
    {
        $value = $data[$field] ?? null;
        if ($value !== null && (!is_numeric($value) || $value < $min || $value > $max)) {
            throw new ValidationException($field, "Must be between {$min} and {$max}");
        }
    }

    protected function requireNonEmptyString(array $data, string $field, string $errorMessage): void
    {
        if (empty(trim($data[$field] ?? ''))) {
            throw new ValidationException($field, $errorMessage);
        }
    }

    protected function validateStringLength(array $data, string $field, int $min, int $max): void
    {
        $value = $data[$field] ?? '';
        $length = strlen($value);
        if ($length < $min || $length > $max) {
            throw new ValidationException($field, "Length must be between {$min} and {$max}");
        }
    }

    protected function validateArrayNotEmpty(array $data, string $field, string $errorMessage): array
    {
        if (!isset($data[$field]) || !is_array($data[$field]) || empty($data[$field])) {
            throw new ValidationException($field, $errorMessage);
        }
        return $data[$field];
    }

    protected function validateArrayItems(array $items, callable $validator): array
    {
        $errors = [];
        foreach ($items as $index => $item) {
            $itemErrors = $validator($item, $index);
            if (!empty($itemErrors)) {
                $errors["items.{$index}"] = $itemErrors;
            }
        }
        return $errors;
    }

    protected function validateAddress(array $address): array
    {
        $errors = [];
        $requiredFields = ['street', 'city', 'state', 'postal_code', 'country'];

        foreach ($requiredFields as $field) {
            if (empty(trim($address[$field] ?? ''))) {
                $errors[$field] = ucfirst($field) . ' is required';
            }
        }

        return $errors;
    }

    protected function requireValidEnum(array $data, string $field, array $validValues, string $errorMessage): void
    {
        if (!in_array($data[$field] ?? '', $validValues)) {
            throw new ValidationException($field, $errorMessage);
        }
    }
}

final class CreateOrderValidator extends BaseValidator
{
    protected function validateData(array $data): array
    {
        $errors = [];

        try {
            $this->requireNumeric($data, 'customer_id', 'Valid customer ID is required');
        } catch (ValidationException $e) {
            $errors['customer_id'] = $e->getMessage();
        }

        try {
            $items = $this->validateArrayNotEmpty($data, 'items', 'At least one item is required');
            $errors = array_merge($errors, $this->validateItems($items));
        } catch (ValidationException $e) {
            $errors['items'] = $e->getMessage();
        }

        if (isset($data['shipping_address'])) {
            $errors['shipping_address'] = $this->validateAddress($data['shipping_address']);
        }

        return $errors;
    }

    private function validateItems(array $items): array
    {
        return $this->validateArrayItems($items, function ($item, $index) {
            $errors = [];
            try {
                $this->requireNumeric($item, 'product_id', '');
                $this->requireNumeric($item, 'unit_price', '');
            } catch (ValidationException $e) {
                $errors[] = $e->getMessage();
            }
            if (!isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] < 1) {
                $errors[] = "quantity must be a positive integer at index {$index}";
            }
            return $errors;
        });
    }
}
