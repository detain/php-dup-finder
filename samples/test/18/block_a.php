<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use App\Validation\Rules\UserRules;
use App\Validation\Validator;
use App\Exceptions\ValidationException;

class UserValidationRulesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new Validator();
    }

    public function testEmailValidation(): void
    {
        $validEmails = [
            'user@example.com',
            'test.user@domain.org',
            'name+tag@company.co.uk',
        ];

        foreach ($validEmails as $email) {
            $result = $this->validator->validate($email, [UserRules::EMAIL]);
            $this->assertTrue($result, "Email {$email} should be valid");
        }

        $invalidEmails = [
            'notanemail',
            'missing@domain',
            '@nodomain.com',
            'spaces in@email.com',
            '',
            'a@b',
        ];

        foreach ($invalidEmails as $email) {
            $result = $this->validator->validate($email, [UserRules::EMAIL]);
            $this->assertFalse($result, "Email {$email} should be invalid");
        }
    }

    public function testPasswordStrengthValidation(): void
    {
        $validPasswords = [
            'SecurePass123!',
            'C0mpl3x#Str0ng',
            'MyP@ssw0rd2024',
        ];

        foreach ($validPasswords as $password) {
            $result = $this->validator->validate($password, [UserRules::PASSWORD_STRENGTH]);
            $this->assertTrue($result, "Password should be valid");
        }

        $invalidPasswords = [
            'short',
            '12345678',
            'alllowercase',
            'ALLUPPERCASE',
            'NoNumbers123',
            'NoSpecial!',
        ];

        foreach ($invalidPasswords as $password) {
            $result = $this->validator->validate($password, [UserRules::PASSWORD_STRENGTH]);
            $this->assertFalse($result, "Password should be invalid");
        }
    }

    public function testNameValidation(): void
    {
        $validNames = [
            "O'Connor",
            'José García',
            'Mary-Jane',
            'Dr. Smith',
            "O'Brien",
        ];

        foreach ($validNames as $name) {
            $result = $this->validator->validate($name, [UserRules::NAME]);
            $this->assertTrue($result, "Name {$name} should be valid");
        }

        $invalidNames = [
            '',
            'A',
            str_repeat('A', 256),
            'Name123',
        ];

        foreach ($invalidNames as $name) {
            $result = $this->validator->validate($name, [UserRules::NAME]);
            $this->assertFalse($result, "Name should be invalid");
        }
    }

    public function testPhoneNumberValidation(): void
    {
        $validPhones = [
            '+1-555-123-4567',
            '+44 20 7946 0958',
            '(555) 123-4567',
            '555-123-4567',
            '+1 (555) 123-4567',
        ];

        foreach ($validPhones as $phone) {
            $result = $this->validator->validate($phone, [UserRules::PHONE]);
            $this->assertTrue($result, "Phone {$phone} should be valid");
        }

        $invalidPhones = [
            '123',
            'abc-def-ghij',
            '',
            '+1',
        ];

        foreach ($invalidPhones as $phone) {
            $result = $this->validator->validate($phone, [UserRules::PHONE]);
            $this->assertFalse($result, "Phone should be invalid");
        }
    }

    public function testTimezoneValidation(): void
    {
        $validTimezones = [
            'America/New_York',
            'Europe/London',
            'Asia/Tokyo',
            'UTC',
            'Pacific/Honolulu',
        ];

        foreach ($validTimezones as $tz) {
            $result = $this->validator->validate($tz, [UserRules::TIMEZONE]);
            $this->assertTrue($result, "Timezone {$tz} should be valid");
        }

        $invalidTimezones = [
            'Invalid/Zone',
            'America',
            'EST',
            '',
        ];

        foreach ($invalidTimezones as $tz) {
            $result = $this->validator->validate($tz, [UserRules::TIMEZONE]);
            $this->assertFalse($result, "Timezone should be invalid");
        }
    }

    public function testUrlValidation(): void
    {
        $validUrls = [
            'https://example.com',
            'http://sub.domain.org/path',
            'https://company.io/api/v2',
        ];

        foreach ($validUrls as $url) {
            $result = $this->validator->validate($url, [UserRules::URL]);
            $this->assertTrue($result, "URL {$url} should be valid");
        }

        $invalidUrls = [
            'not a url',
            'ftp://example.com',
            'example.com',
            '',
        ];

        foreach ($invalidUrls as $url) {
            $result = $this->validator->validate($url, [UserRules::URL]);
            $this->assertFalse($result, "URL should be invalid");
        }
    }

    public function testCombinedUserRegistrationValidation(): void
    {
        $validData = [
            'email' => 'newuser@example.com',
            'password' => 'SecurePass123!',
            'name' => 'John Doe',
            'phone' => '+1-555-123-4567',
            'timezone' => 'America/New_York',
        ];

        $result = $this->validator->validateMany($validData, [
            'email' => [UserRules::EMAIL, UserRules::REQUIRED],
            'password' => [UserRules::PASSWORD_STRENGTH, UserRules::REQUIRED],
            'name' => [UserRules::NAME, UserRules::REQUIRED],
            'phone' => [UserRules::PHONE],
            'timezone' => [UserRules::TIMEZONE],
        ]);

        $this->assertTrue($result);
    }

    public function testCombinedValidationReturnsErrors(): void
    {
        $invalidData = [
            'email' => 'notvalid',
            'password' => 'weak',
            'name' => '',
        ];

        try {
            $this->validator->validateMany($invalidData, [
                'email' => [UserRules::EMAIL, UserRules::REQUIRED],
                'password' => [UserRules::PASSWORD_STRENGTH, UserRules::REQUIRED],
                'name' => [UserRules::NAME, UserRules::REQUIRED],
            ]);

            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('email', $errors);
            $this->assertArrayHasKey('password', $errors);
            $this->assertArrayHasKey('name', $errors);
        }
    }
}
