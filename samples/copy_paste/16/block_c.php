<?php

declare(strict_types=1);

namespace App\Users\Security;

use App\Models\PortalUser;
use App\Exceptions\TokenException;

final class UserRecoveryTokenManager
{
    private const BYTE_COUNT = 32;
    private const LIFESPAN_SECONDS = 3600;
    private const HASH_FUNCTION = 'sha256';

    public function forgePasswordResetToken(PortalUser $user): string
    {
        $bytes = $this->generateSecureBytes();
        $now = $this->currentUnixTime();
        $identityHash = $this->computeIdentityHash($user);

        $tokenBundle = [
            'bytes' => $bytes,
            'timestamp' => $now,
            'expires' => $now + self::LIFESPAN_SECONDS,
            'identity' => $identityHash,
            'intent' => 'password_reset',
        ];

        return $this->encodeBundle($tokenBundle);
    }

    public function forgePasswordResetTokenWithDuration(PortalUser $user, int $seconds): string
    {
        $bytes = $this->generateSecureBytes();
        $now = $this->currentUnixTime();
        $identityHash = $this->computeIdentityHash($user);

        $tokenBundle = [
            'bytes' => $bytes,
            'timestamp' => $now,
            'expires' => $now + $seconds,
            'identity' => $identityHash,
            'intent' => 'password_reset',
        ];

        return $this->encodeBundle($tokenBundle);
    }

    public function forgeSecurityQuestionResetToken(PortalUser $user): string
    {
        $bytes = $this->generateSecureBytes();
        $now = $this->currentUnixTime();
        $identityHash = $this->computeIdentityHash($user);

        $tokenBundle = [
            'bytes' => $bytes,
            'timestamp' => $now,
            'expires' => $now + (86400),
            'identity' => $identityHash,
            'intent' => 'security_question_reset',
        ];

        return $this->encodeBundle($tokenBundle);
    }

    public function forgePhoneChangeToken(PortalUser $user, string $newPhone): string
    {
        $bytes = $this->generateSecureBytes();
        $now = $this->currentUnixTime();
        $identityHash = $this->computeIdentityHash($user);

        $tokenBundle = [
            'bytes' => $bytes,
            'timestamp' => $now,
            'expires' => $now + self::LIFESPAN_SECONDS,
            'identity' => $identityHash,
            'intent' => 'phone_change',
            'phone_digest' => hash('sha256', $newPhone),
        ];

        return $this->encodeBundle($tokenBundle);
    }

    public function forgeAccountClosureToken(PortalUser $user): string
    {
        $bytes = $this->generateSecureBytes();
        $now = $this->currentUnixTime();
        $identityHash = $this->computeIdentityHash($user);

        $tokenBundle = [
            'bytes' => $bytes,
            'timestamp' => $now,
            'expires' => $now + (86400 * 3),
            'identity' => $identityHash,
            'intent' => 'account_closure',
        ];

        return $this->encodeBundle($tokenBundle);
    }

    public function forgeMfaRecoveryCodesToken(PortalUser $user): string
    {
        $bytes = $this->generateSecureBytes();
        $now = $this->currentUnixTime();
        $identityHash = $this->computeIdentityHash($user);

        $tokenBundle = [
            'bytes' => $bytes,
            'timestamp' => $now,
            'expires' => $now + (86400 * 30),
            'identity' => $identityHash,
            'intent' => 'mfa_recovery_codes',
        ];

        return $this->encodeBundle($tokenBundle);
    }

    private function generateSecureBytes(): string
    {
        try {
            return bin2hex(random_bytes(self::BYTE_COUNT));
        } catch (\Exception $e) {
            throw new TokenException('Could not generate secure bytes', 0, $e);
        }
    }

    private function currentUnixTime(): int
    {
        return time();
    }

    private function computeIdentityHash(PortalUser $user): string
    {
        $材料 = $user->id . '|' . $user->email . '|' . ($user->password_digest ?? '');
        return hash(self::HASH_FUNCTION, $材料);
    }

    private function encodeBundle(array $bundle): string
    {
        $json = json_encode($bundle);

        if ($json === false) {
            throw new TokenException('Token bundle encoding failed');
        }

        $encoded = base64_encode($json);
        return strtr($encoded, '+/', '-_');
    }
}
