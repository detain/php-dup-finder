<?php

declare(strict_types=1);

namespace App\Security\Password;

use App\Models\User;
use App\Exceptions\TokenGenerationException;
use Random\RandomException;

final class PasswordResetTokenGenerator
{
    private const TOKEN_BYTES = 32;
    private const EXPIRY_SECONDS = 3600;
    private const ALGORITHM = 'sha256';

    public function generateToken(User $user): string
    {
        $randomBytes = $this->generateRandomBytes();
        $timestamp = $this->currentTimestamp();
        $userIdentifier = $this->hashUserIdentifier($user);

        $tokenData = $this->buildTokenPayload($randomBytes, $timestamp, $userIdentifier);
        $encodedToken = $this->urlSafeEncode($tokenData);

        return $encodedToken;
    }

    public function generateTokenWithCustomExpiry(User $user, int $expirySeconds): string
    {
        $randomBytes = $this->generateRandomBytes();
        $timestamp = $this->currentTimestamp();
        $userIdentifier = $this->hashUserIdentifier($user);

        $tokenData = [
            'random' => $randomBytes,
            'issued_at' => $timestamp,
            'expires_at' => $timestamp + $expirySeconds,
            'user_hash' => $userIdentifier,
            'type' => 'password_reset',
        ];

        $encodedToken = $this->urlSafeEncode($tokenData);

        return $encodedToken;
    }

    public function generateDeviceLinkToken(User $user, string $deviceId): string
    {
        $randomBytes = $this->generateRandomBytes();
        $timestamp = $this->currentTimestamp();
        $userIdentifier = $this->hashUserIdentifier($user);

        $tokenData = [
            'random' => $randomBytes,
            'issued_at' => $timestamp,
            'expires_at' => $timestamp + self::EXPIRY_SECONDS,
            'user_hash' => $userIdentifier,
            'type' => 'device_link',
            'device_id' => hash('sha256', $deviceId),
        ];

        $encodedToken = $this->urlSafeEncode($tokenData);

        return $encodedToken;
    }

    public function generateBackupCodeToken(User $user): string
    {
        $randomBytes = $this->generateRandomBytes();
        $timestamp = $this->currentTimestamp();
        $userIdentifier = $this->hashUserIdentifier($user);

        $tokenData = [
            'random' => $randomBytes,
            'issued_at' => $timestamp,
            'expires_at' => $timestamp + (86400 * 30),
            'user_hash' => $userIdentifier,
            'type' => 'backup_code',
        ];

        $encodedToken = $this->urlSafeEncode($tokenData);

        return $encodedToken;
    }

    public function generateMagicLinkToken(User $user, string $action): string
    {
        $randomBytes = $this->generateRandomBytes();
        $timestamp = $this->currentTimestamp();
        $userIdentifier = $this->hashUserIdentifier($user);

        $tokenData = [
            'random' => $randomBytes,
            'issued_at' => $timestamp,
            'expires_at' => $timestamp + self::EXPIRY_SECONDS,
            'user_hash' => $userIdentifier,
            'type' => 'magic_link',
            'action' => $action,
        ];

        $encodedToken = $this->urlSafeEncode($tokenData);

        return $encodedToken;
    }

    public function generateAccountDeletionToken(User $user): string
    {
        $randomBytes = $this->generateRandomBytes();
        $timestamp = $this->currentTimestamp();
        $userIdentifier = $this->hashUserIdentifier($user);

        $tokenData = [
            'random' => $randomBytes,
            'issued_at' => $timestamp,
            'expires_at' => $timestamp + (86400 * 7),
            'user_hash' => $userIdentifier,
            'type' => 'account_deletion',
        ];

        $encodedToken = $this->urlSafeEncode($tokenData);

        return $encodedToken;
    }

    public function generateEmailChangeToken(User $user, string $newEmail): string
    {
        $randomBytes = $this->generateRandomBytes();
        $timestamp = $this->currentTimestamp();
        $userIdentifier = $this->hashUserIdentifier($user);

        $tokenData = [
            'random' => $randomBytes,
            'issued_at' => $timestamp,
            'expires_at' => $timestamp + self::EXPIRY_SECONDS,
            'user_hash' => $userIdentifier,
            'type' => 'email_change',
            'new_email_hash' => hash('sha256', $newEmail),
        ];

        $encodedToken = $this->urlSafeEncode($tokenData);

        return $encodedToken;
    }

    private function generateRandomBytes(): string
    {
        try {
            return bin2hex(random_bytes(self::TOKEN_BYTES));
        } catch (RandomException $e) {
            throw new TokenGenerationException('Failed to generate secure random bytes', 0, $e);
        }
    }

    private function currentTimestamp(): int
    {
        return time();
    }

    private function hashUserIdentifier(User $user): string
    {
        $material = $user->id . '|' . $user->email . '|' . ($user->password_hash ?? '');
        return hash(self::ALGORITHM, $material);
    }

    private function buildTokenPayload(string $randomBytes, int $timestamp, string $userHash): array
    {
        return [
            'random' => $randomBytes,
            'issued_at' => $timestamp,
            'expires_at' => $timestamp + self::EXPIRY_SECONDS,
            'user_hash' => $userHash,
            'type' => 'password_reset',
        ];
    }

    private function urlSafeEncode(array $data): string
    {
        $json = json_encode($data);

        if ($json === false) {
            throw new TokenGenerationException('Failed to encode token data');
        }

        $encoded = base64_encode($json);
        return str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
    }
}
