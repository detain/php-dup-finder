<?php

declare(strict_types=1);

namespace Tests\Unit\User;

use PHPUnit\Framework\TestCase;
use App\Models\User;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Permission;
use App\Services\UserService;
use App\Exceptions\ValidationException;
use App\Exceptions\EmailAlreadyExistsException;

class UserServiceTest extends TestCase
{
    private UserService $userService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userService = new UserService();
    }

    private function createValidUserData(array $overrides = []): array
    {
        return array_merge([
            'email' => 'testuser@example.com',
            'password' => 'SecurePass123!',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+1-555-123-4567',
            'timezone' => 'America/New_York',
            'locale' => 'en_US',
            'is_active' => true,
            'email_verified_at' => null,
            'organization_id' => null,
            'metadata' => [
                'source' => 'organic',
                'referral_code' => null,
                'utm_campaign' => null,
            ],
        ], $overrides);
    }

    private function createValidOrganization(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'status' => 'active',
            'plan' => 'enterprise',
            'max_users' => 100,
            'settings' => [
                'require_2fa' => false,
                'sso_enabled' => false,
                'ip_whitelist' => [],
            ],
        ], $overrides);
    }

    private function createValidRole(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Member',
            'slug' => 'member',
            'description' => 'Standard member role',
            'permissions' => ['read', 'write', 'comment'],
            'is_system' => false,
            'priority' => 10,
        ], $overrides);
    }

    public function testCreatesUserWithValidData(): void
    {
        $userData = $this->createValidUserData([
            'email' => 'newuser@example.com',
            'organization_id' => 1,
        ]);

        $result = $this->userService->createUser($userData);

        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals('newuser@example.com', $result->email);
        $this->assertTrue(password_verify('SecurePass123!', $result->password_hash));
    }

    public function testValidatesEmailFormat(): void
    {
        $invalidEmails = [
            'notanemail',
            'missing@domain',
            '@nodomain.com',
            'spaces in@email.com',
            '',
        ];

        foreach ($invalidEmails as $email) {
            $userData = $this->createValidUserData(['email' => $email]);

            $this->expectException(ValidationException::class);
            $this->userService->createUser($userData);
        }
    }

    public function testValidatesPasswordStrength(): void
    {
        $weakPasswords = [
            'short',
            '12345678',
            'alllowercase',
            'ALLUPPERCASE',
            'NoNumbersOrSymbols',
        ];

        foreach ($weakPasswords as $password) {
            $userData = $this->createValidUserData(['password' => $password]);

            $this->expectException(ValidationException::class);
            $this->userService->createUser($userData);
        }
    }

    public function testAssignsOrganizationToUser(): void
    {
        $orgData = $this->createValidOrganization(['id' => 5, 'name' => 'Acme Corp']);
        $userData = $this->createValidUserData(['organization_id' => 5]);

        $user = $this->userService->createUser($userData);

        $this->assertEquals(5, $user->organization_id);
        $this->assertEquals('Acme Corp', $user->organization->name);
    }

    public function testThrowsExceptionForDuplicateEmail(): void
    {
        $existingEmail = 'existing@example.com';

        $userData = $this->createValidUserData(['email' => $existingEmail]);
        $this->userService->createUser($userData);

        $duplicateData = $this->createValidUserData(['email' => $existingEmail]);

        $this->expectException(EmailAlreadyExistsException::class);
        $this->userService->createUser($duplicateData);
    }
}
