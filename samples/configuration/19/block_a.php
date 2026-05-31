<?php

declare(strict_types=1);

namespace App\Http\Validation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UserRegistrationValidator
{
    private const MIN_PASSWORD_LENGTH = 8;
    private const MAX_PASSWORD_LENGTH = 128;
    private const PASSWORD_REQUIRE_UPPERCASE = true;
    private const PASSWORD_REQUIRE_LOWERCASE = true;
    private const PASSWORD_REQUIRE_NUMBER = true;
    private const PASSWORD_REQUIRE_SPECIAL = true;
    private const MIN_USERNAME_LENGTH = 3;
    private const MAX_USERNAME_LENGTH = 32;
    private const USERNAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';
    private const MIN_NAME_LENGTH = 2;
    private const MAX_NAME_LENGTH = 100;
    private const EMAIL_MAX_LENGTH = 255;
    private const EMAIL_PATTERN = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
    private const PHONE_PATTERN = '/^\+?[1-9]\d{1,14}$/';
    private const AGE_MIN = 13;
    private const AGE_MAX = 120;
    private const VALIDATION_CUSTOM_MESSAGES = true;
    private const VALIDATION_BAIL_ON_FIRST = false;
    private const VALIDATION_STOP_ON_FIRST_FAILURE = false;

    public function validate(Request $request): array
    {
        $rules = $this->getRules();
        $messages = $this->getMessages();
        $attributes = $this->getAttributes();

        $validator = validator($request->all(), $rules, $messages, $attributes);

        if (self::VALIDATION_BAIL_ON_FIRST) {
            $validator->stopOnFirstFailure();
        }

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->toArray(),
            ];
        }

        return [
            'valid' => true,
            'data' => $validator->validated(),
        ];
    }

    public function validatePassword(string $password): array
    {
        $errors = [];

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'][] = sprintf(
                'Password must be at least %d characters long',
                self::MIN_PASSWORD_LENGTH
            );
        }

        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $errors['password'][] = sprintf(
                'Password must not exceed %d characters',
                self::MAX_PASSWORD_LENGTH
            );
        }

        if (self::PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors['password'][] = 'Password must contain at least one uppercase letter';
        }

        if (self::PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors['password'][] = 'Password must contain at least one lowercase letter';
        }

        if (self::PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors['password'][] = 'Password must contain at least one number';
        }

        if (self::PASSWORD_REQUIRE_SPECIAL && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $errors['password'][] = 'Password must contain at least one special character';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function validateUsername(string $username): array
    {
        $errors = [];

        if (strlen($username) < self::MIN_USERNAME_LENGTH) {
            $errors['username'][] = sprintf(
                'Username must be at least %d characters long',
                self::MIN_USERNAME_LENGTH
            );
        }

        if (strlen($username) > self::MAX_USERNAME_LENGTH) {
            $errors['username'][] = sprintf(
                'Username must not exceed %d characters',
                self::MAX_USERNAME_LENGTH
            );
        }

        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            $errors['username'][] = 'Username may only contain letters, numbers, hyphens, and underscores';
        }

        $reservedUsernames = ['admin', 'root', 'system', 'moderator', 'support'];

        if (in_array(strtolower($username), $reservedUsernames, true)) {
            $errors['username'][] = 'This username is reserved and cannot be registered';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function validateEmail(string $email): array
    {
        $errors = [];

        if (strlen($email) > self::EMAIL_MAX_LENGTH) {
            $errors['email'][] = sprintf(
                'Email must not exceed %d characters',
                self::EMAIL_MAX_LENGTH
            );
        }

        if (!preg_match(self::EMAIL_PATTERN, $email)) {
            $errors['email'][] = 'Please enter a valid email address';
        }

        $disposableDomains = ['tempmail.com', 'throwaway.com', 'mailinator.com'];

        $emailParts = explode('@', $email);
        if (count($emailParts) === 2 && in_array($emailParts[1], $disposableDomains, true)) {
            $errors['email'][] = 'Disposable email addresses are not allowed';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function getRules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'min:' . self::MIN_USERNAME_LENGTH,
                'max:' . self::MAX_USERNAME_LENGTH,
                'regex:' . self::USERNAME_PATTERN,
            ],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:' . self::EMAIL_MAX_LENGTH,
            ],
            'password' => [
                'required',
                'string',
                'min:' . self::MIN_PASSWORD_LENGTH,
                'max:' . self::MAX_PASSWORD_LENGTH,
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[!@#$%^&*(),.?":{}|<>]/',
            ],
            'password_confirmation' => [
                'required',
                'same:password',
            ],
            'name' => [
                'required',
                'string',
                'min:' . self::MIN_NAME_LENGTH,
                'max:' . self::MAX_NAME_LENGTH,
            ],
            'phone' => [
                'nullable',
                'string',
                'regex:' . self::PHONE_PATTERN,
            ],
            'age' => [
                'nullable',
                'integer',
                'min:' . self::AGE_MIN,
                'max:' . self::AGE_MAX,
            ],
            'terms' => [
                'required',
                'accepted',
            ],
        ];
    }

    private function getMessages(): array
    {
        return [
            'username.required' => 'Username is required',
            'username.min' => sprintf('Username must be at least %d characters', self::MIN_USERNAME_LENGTH),
            'username.max' => sprintf('Username must not exceed %d characters', self::MAX_USERNAME_LENGTH),
            'password.required' => 'Password is required',
            'password.min' => sprintf('Password must be at least %d characters', self::MIN_PASSWORD_LENGTH),
            'password.regex' => 'Password does not meet complexity requirements',
            'email.email' => 'Please enter a valid email address',
            'terms.accepted' => 'You must accept the terms and conditions',
        ];
    }

    private function getAttributes(): array
    {
        return [
            'password_confirmation' => 'password confirmation',
        ];
    }

    public function getMinPasswordLength(): int
    {
        return self::MIN_PASSWORD_LENGTH;
    }

    public function getPasswordRequirements(): array
    {
        return [
            'min_length' => self::MIN_PASSWORD_LENGTH,
            'require_uppercase' => self::PASSWORD_REQUIRE_UPPERCASE,
            'require_lowercase' => self::PASSWORD_REQUIRE_LOWERCASE,
            'require_number' => self::PASSWORD_REQUIRE_NUMBER,
            'require_special' => self::PASSWORD_REQUIRE_SPECIAL,
        ];
    }
}
