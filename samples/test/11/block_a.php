<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Services\Authentication\TokenService;
use App\Services\Authentication\AuthenticationException;
use App\Repositories\UserRepository;
use App\Models\User;

final class AuthenticationServiceTest extends TestCase
{
    private TokenService $tokenService;
    private MockObject&UserRepository $userRepository;
    private AuthenticationService $authService;

    protected function setUp(): void
    {
        $this->tokenService = new TokenService(
            secretKey: 'test-secret-key-256-bits-long-for-hs256',
            issuer: 'https://auth.example.com',
            audience: 'https://api.example.com',
            expirySeconds: 3600,
            refreshExpirySeconds: 86400,
            algorithm: 'HS256'
        );

        $this->userRepository = $this->createMock(UserRepository::class);
        $this->authService = new AuthenticationService(
            $this->tokenService,
            $this->userRepository
        );

        $this->setupTokenMockBehavior();
    }

    private function setupTokenMockBehavior(): void
    {
        $this->userRepository->method('findByEmail')
            ->willReturnCallback(function (string $email) {
                if ($email === 'valid@example.com') {
                    return $this->createTestUser(1, 'valid@example.com', 'password123');
                }
                return null;
            });

        $this->userRepository->method('findById')
            ->willReturnCallback(function (int $id) {
                if ($id === 1) {
                    return $this->createTestUser(1, 'valid@example.com', 'password123');
                }
                return null;
            });
    }

    private function createTestUser(int $id, string $email, string $passwordHash): User
    {
        $user = new User();
        $user->id = $id;
        $user->email = $email;
        $user->password_hash = password_hash($passwordHash, PASSWORD_BCRYPT);
        $user->is_active = true;
        $user->email_verified_at = new \DateTimeImmutable();
        $user->created_at = new \DateTimeImmutable();
        $user->updated_at = new \DateTimeImmutable();
        return $user;
    }

    public function testLoginWithValidCredentials(): void
    {
        $credentials = [
            'email' => 'valid@example.com',
            'password' => 'password123',
        ];

        $result = $this->authService->login($credentials);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertArrayHasKey('expires_in', $result);
        $this->assertArrayHasKey('token_type', $result);
        $this->assertArrayHasKey('user', $result);

        $this->assertEquals('Bearer', $result['token_type']);
        $this->assertEquals(3600, $result['expires_in']);
        $this->assertNotEmpty($result['access_token']);
        $this->assertNotEmpty($result['refresh_token']);
    }

    public function testLoginWithInvalidEmail(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $credentials = [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ];

        $this->authService->login($credentials);
    }

    public function testLoginWithInvalidPassword(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $credentials = [
            'email' => 'valid@example.com',
            'password' => 'wrongpassword',
        ];

        $this->authService->login($credentials);
    }

    public function testTokenRefreshWithValidToken(): void
    {
        $loginResult = $this->authService->login([
            'email' => 'valid@example.com',
            'password' => 'password123',
        ]);

        $refreshToken = $loginResult['refresh_token'];

        $result = $this->authService->refreshToken($refreshToken);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertArrayHasKey('expires_in', $result);

        $this->assertNotEquals($loginResult['access_token'], $result['access_token']);
        $this->assertNotEquals($loginResult['refresh_token'], $result['refresh_token']);
    }

    public function testTokenRefreshWithExpiredToken(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Refresh token has expired');

        $expiredToken = $this->tokenService->createRefreshToken(
            userId: 1,
            issuedAt: time() - 86401,
            expiresAt: time() - 1
        );

        $this->authService->refreshToken($expiredToken);
    }

    public function testTokenRefreshWithInvalidToken(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid refresh token');

        $this->authService->refreshToken('invalid.token.here');
    }

    public function testLogoutInvalidatesTokens(): void
    {
        $loginResult = $this->authService->login([
            'email' => 'valid@example.com',
            'password' => 'password123',
        ]);

        $accessToken = $loginResult['access_token'];
        $refreshToken = $loginResult['refresh_token'];

        $this->authService->logout($accessToken, $refreshToken);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Token has been revoked');

        $this->authService->refreshToken($refreshToken);
    }

    public function testLoginWithInactiveUser(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userRepository->method('findByEmail')
            ->willReturnCallback(function (string $email) {
                $user = $this->createTestUser(2, 'inactive@example.com', 'password123');
                $user->is_active = false;
                return $user;
            });

        $this->authService = new AuthenticationService(
            $this->tokenService,
            $this->userRepository
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Account is not active');

        $this->authService->login([
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);
    }

    public function testLoginWithUnverifiedEmail(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userRepository->method('findByEmail')
            ->willReturnCallback(function (string $email) {
                $user = $this->createTestUser(3, 'unverified@example.com', 'password123');
                $user->email_verified_at = null;
                return $user;
            });

        $this->authService = new AuthenticationService(
            $this->tokenService,
            $this->userRepository
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Email not verified');

        $this->authService->login([
            'email' => 'unverified@example.com',
            'password' => 'password123',
        ]);
    }
}
