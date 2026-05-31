<?php

declare(strict_types=1);

namespace App\Api\Request;

class UserRequestParser
{
    private SchemaValidator $validator;

    public function __construct(SchemaValidator $validator)
    {
        $this->validator = $validator;
    }

    public function parse(array $requestData): ParsedRequest
    {
        $errors = [];

        $userId = $requestData['params']['id'] ?? null;
        $email = $requestData['params']['email'] ?? null;
        $name = $requestData['params']['name'] ?? null;
        $avatarUrl = $requestData['params']['avatar_url'] ?? null;
        $isActive = $requestData['params']['is_active'] ?? null;
        $roles = $requestData['params']['roles'] ?? [];

        if ($userId !== null && !$this->isValidUuid($userId)) {
            $errors['id'] = 'Invalid user ID format';
        }

        if ($email !== null && !$this->isValidEmail($email)) {
            $errors['email'] = 'Invalid email format';
        }

        if ($name !== null && (strlen($name) < 2 || strlen($name) > 255)) {
            $errors['name'] = 'Name must be between 2 and 255 characters';
        }

        if ($avatarUrl !== null && !$this->isValidUrl($avatarUrl)) {
            $errors['avatar_url'] = 'Invalid URL format';
        }

        if ($isActive !== null && !is_bool($isActive)) {
            $errors['is_active'] = 'is_active must be a boolean';
        }

        if (!is_array($roles)) {
            $errors['roles'] = 'roles must be an array';
        } else {
            foreach ($roles as $index => $role) {
                if (!is_string($role)) {
                    $errors["roles.{$index}"] = 'Each role must be a string';
                }
            }
        }

        if (count($errors) > 0) {
            return new ParsedRequest(null, $errors);
        }

        return new ParsedRequest([
            'id' => $userId,
            'email' => $email,
            'name' => $name,
            'avatar_url' => $avatarUrl,
            'is_active' => $isActive ?? true,
            'roles' => $roles
        ], []);
    }

    public function parseCreate(array $requestData): ParsedRequest
    {
        $errors = [];

        $email = $requestData['params']['email'] ?? null;
        $name = $requestData['params']['name'] ?? null;
        $avatarUrl = $requestData['params']['avatar_url'] ?? null;
        $isActive = $requestData['params']['is_active'] ?? true;
        $roles = $requestData['params']['roles'] ?? [];

        if ($email === null) {
            $errors['email'] = 'Email is required';
        } elseif (!$this->isValidEmail($email)) {
            $errors['email'] = 'Invalid email format';
        }

        if ($name === null) {
            $errors['name'] = 'Name is required';
        } elseif (strlen($name) < 2 || strlen($name) > 255) {
            $errors['name'] = 'Name must be between 2 and 255 characters';
        }

        if ($avatarUrl !== null && !$this->isValidUrl($avatarUrl)) {
            $errors['avatar_url'] = 'Invalid URL format';
        }

        if (!is_bool($isActive)) {
            $errors['is_active'] = 'is_active must be a boolean';
        }

        if (!is_array($roles)) {
            $errors['roles'] = 'roles must be an array';
        } else {
            foreach ($roles as $index => $role) {
                if (!is_string($role)) {
                    $errors["roles.{$index}"] = 'Each role must be a string';
                }
            }
        }

        if (count($errors) > 0) {
            return new ParsedRequest(null, $errors);
        }

        return new ParsedRequest([
            'email' => $email,
            'name' => $name,
            'avatar_url' => $avatarUrl,
            'is_active' => $isActive,
            'roles' => $roles
        ], []);
    }

    public function parseUpdate(array $requestData): ParsedRequest
    {
        $errors = [];

        $userId = $requestData['params']['id'] ?? null;
        $email = $requestData['params']['email'] ?? null;
        $name = $requestData['params']['name'] ?? null;
        $avatarUrl = $requestData['params']['avatar_url'] ?? null;
        $isActive = $requestData['params']['is_active'] ?? null;
        $roles = $requestData['params']['roles'] ?? null;

        if ($userId === null) {
            $errors['id'] = 'User ID is required for update';
        } elseif (!$this->isValidUuid($userId)) {
            $errors['id'] = 'Invalid user ID format';
        }

        if ($email !== null && !$this->isValidEmail($email)) {
            $errors['email'] = 'Invalid email format';
        }

        if ($name !== null && (strlen($name) < 2 || strlen($name) > 255)) {
            $errors['name'] = 'Name must be between 2 and 255 characters';
        }

        if ($avatarUrl !== null && !$this->isValidUrl($avatarUrl)) {
            $errors['avatar_url'] = 'Invalid URL format';
        }

        if ($isActive !== null && !is_bool($isActive)) {
            $errors['is_active'] = 'is_active must be a boolean';
        }

        if ($roles !== null && !is_array($roles)) {
            $errors['roles'] = 'roles must be an array';
        }

        if (count($errors) > 0) {
            return new ParsedRequest(null, $errors);
        }

        return new ParsedRequest([
            'id' => $userId,
            'email' => $email,
            'name' => $name,
            'avatar_url' => $avatarUrl,
            'is_active' => $isActive,
            'roles' => $roles
        ], []);
    }

    private function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function isValidEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isValidUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
