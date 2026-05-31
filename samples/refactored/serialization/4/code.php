<?php

declare(strict_types=1);

namespace App\Api\Request;

class ParsedRequest
{
    public function __construct(
        public readonly ?array $data,
        public readonly array $errors
    ) {}

    public function isValid(): bool
    {
        return count($this->errors) === 0 && $this->data !== null;
    }
}

abstract class AbstractRequestParser
{
    protected SchemaValidator $validator;

    public function __construct(SchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    abstract public function parse(array $requestData): ParsedRequest;
    abstract public function parseCreate(array $requestData): ParsedRequest;

    protected function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    protected function isValidEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function isValidUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    protected function buildValidationResult(array $data, array $errors): ParsedRequest
    {
        if (count($errors) > 0) {
            return new ParsedRequest(null, $errors);
        }

        return new ParsedRequest($data, []);
    }
}

class UserRequestParser extends AbstractRequestParser
{
    public function parse(array $requestData): ParsedRequest
    {
        $errors = [];
        $data = [];

        $data['id'] = $requestData['params']['id'] ?? null;
        $data['email'] = $requestData['params']['email'] ?? null;
        $data['name'] = $requestData['params']['name'] ?? null;
        $data['avatar_url'] = $requestData['params']['avatar_url'] ?? null;
        $data['is_active'] = $requestData['params']['is_active'] ?? null;
        $data['roles'] = $requestData['params']['roles'] ?? [];

        if ($data['id'] !== null && !$this->isValidUuid($data['id'])) {
            $errors['id'] = 'Invalid user ID format';
        }

        if ($data['email'] !== null && !$this->isValidEmail($data['email'])) {
            $errors['email'] = 'Invalid email format';
        }

        if ($data['name'] !== null && $this->validateStringLength($data['name'], 2, 255)) {
            $errors['name'] = 'Name must be between 2 and 255 characters';
        }

        if ($data['avatar_url'] !== null && !$this->isValidUrl($data['avatar_url'])) {
            $errors['avatar_url'] = 'Invalid URL format';
        }

        $this->validateArrayOfStrings($data['roles'], 'roles', $errors);

        return $this->buildValidationResult($data, $errors);
    }

    public function parseCreate(array $requestData): ParsedRequest
    {
        $errors = [];
        $data = [];

        $data['email'] = $requestData['params']['email'] ?? null;
        $data['name'] = $requestData['params']['name'] ?? null;
        $data['avatar_url'] = $requestData['params']['avatar_url'] ?? null;
        $data['is_active'] = $requestData['params']['is_active'] ?? true;
        $data['roles'] = $requestData['params']['roles'] ?? [];

        if ($data['email'] === null) {
            $errors['email'] = 'Email is required';
        } elseif (!$this->isValidEmail($data['email'])) {
            $errors['email'] = 'Invalid email format';
        }

        if ($data['name'] === null) {
            $errors['name'] = 'Name is required';
        } elseif ($this->validateStringLength($data['name'], 2, 255)) {
            $errors['name'] = 'Name must be between 2 and 255 characters';
        }

        if ($data['avatar_url'] !== null && !$this->isValidUrl($data['avatar_url'])) {
            $errors['avatar_url'] = 'Invalid URL format';
        }

        $this->validateArrayOfStrings($data['roles'], 'roles', $errors);

        return $this->buildValidationResult($data, $errors);
    }

    protected function validateStringLength(string $value, int $min, int $max): bool
    {
        return strlen($value) < $min || strlen($value) > $max;
    }

    protected function validateArrayOfStrings(array $items, string $fieldName, array &$errors): void
    {
        if (!is_array($items)) {
            $errors[$fieldName] = "{$fieldName} must be an array";
            return;
        }

        foreach ($items as $index => $item) {
            if (!is_string($item)) {
                $errors["{$fieldName}.{$index}"] = "Each {$fieldName} item must be a string";
            }
        }
    }
}

class RequestParserRegistry
{
    private array $parsers = [];

    public function register(string $entityType, AbstractRequestParser $parser): void
    {
        $this->parsers[$entityType] = $parser;
    }

    public function getParser(string $entityType): ?AbstractRequestParser
    {
        return $this->parsers[$entityType] ?? null;
    }

    public function parse(string $entityType, array $requestData): ParsedRequest
    {
        $parser = $this->getParser($entityType);

        if ($parser === null) {
            throw new \InvalidArgumentException("No parser for type: {$entityType}");
        }

        return $parser->parse($requestData);
    }
}
