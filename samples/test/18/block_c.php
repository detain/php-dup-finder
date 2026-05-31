<?php

declare(strict_types=1);

namespace Tests\Shared\Validation;

trait ValidationTestDataProvider
{
    protected function getValidTestCases(string $rule): array
    {
        return match ($rule) {
            'email' => [
                'user@example.com',
                'test.user@domain.org',
                'name+tag@company.co.uk',
            ],
            'password' => [
                'SecurePass123!',
                'C0mpl3x#Str0ng',
                'MyP@ssw0rd2024',
            ],
            'name' => [
                "O'Connor",
                'José García',
                'Mary-Jane',
            ],
            'phone' => [
                '+1-555-123-4567',
                '+44 20 7946 0958',
            ],
            'sku' => [
                'PROD-001',
                'WIDGET-BLUE-L',
            ],
            'price' => [
                0, 1, 99, 9999.99,
            ],
            default => [],
        };
    }

    protected function getInvalidTestCases(string $rule): array
    {
        return match ($rule) {
            'email' => [
                'notanemail',
                '@nodomain.com',
                '',
            ],
            'password' => [
                'short',
                '12345678',
                'alllowercase',
            ],
            'name' => [
                '',
                'A',
                str_repeat('A', 256),
            ],
            'phone' => [
                '123',
                'abc-def-ghij',
                '',
            ],
            'sku' => [
                'lowercase',
                'invalid sku with spaces',
                '',
            ],
            'price' => [
                -1, -0.01, -100,
            ],
            default => [],
        };
    }

    protected function runValidationTests(Validator $validator, string $rule): void
    {
        foreach ($this->getValidTestCases($rule) as $value) {
            $result = $validator->validate($value, [$rule]);
            $this->assertTrue($result, "Value should be valid: " . json_encode($value));
        }

        foreach ($this->getInvalidTestCases($rule) as $value) {
            $result = $validator->validate($value, [$rule]);
            $this->assertFalse($result, "Value should be invalid: " . json_encode($value));
        }
    }
}

class UserValidationRulesTest extends TestCase
{
    use ValidationTestDataProvider;

    public function testEmailValidation(): void
    {
        $this->runValidationTests($this->validator, 'email');
    }
}
