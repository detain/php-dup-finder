<?php

declare(strict_types=1);

namespace App\Authentication\Email;

use App\Models\User;
use App\Services\TokenGenerator;
use App\Services\UrlBuilder;
use App\Exceptions\EmailGenerationException;

final class VerificationEmailGenerator
{
    private const VERIFY_ROUTE = '/auth/verify';
    private const TOKEN_LENGTH = 32;
    private const TOKEN_ALGORITHM = 'sha256';
    private const DEFAULT_EXPIRY_HOURS = 24;

    public function generateVerificationLink(User $user): string
    {
        $token = $this->createVerificationToken($user);
        $expiry = $this->calculateExpiry();

        $payload = $this->buildTokenPayload($token, $expiry);
        $encodedPayload = $this->encodePayload($payload);

        return $this->constructVerificationUrl($encodedPayload);
    }

    public function generateWelcomeLink(User $user): string
    {
        $token = $this->createWelcomeToken($user);
        $expiry = $this->calculateExpiry();

        $payload = $this->buildTokenPayload($token, $expiry);
        $encodedPayload = $this->encodePayload($payload);

        return $this->constructVerificationUrl($encodedPayload);
    }

    public function generateEmailChangeLink(User $user, string $newEmail): string
    {
        $token = $this->createEmailChangeToken($user, $newEmail);
        $expiry = $this->calculateExpiry();

        $payload = [
            'token' => $token,
            'expiry' => $expiry,
            'new_email' => $newEmail,
            'user_id' => $user->id,
            'type' => 'email_change',
        ];

        $encodedPayload = $this->encodePayload($payload);

        return $this->constructVerificationUrl($encodedPayload);
    }

    public function generatePasswordResetLink(User $user): string
    {
        $token = $this->createPasswordResetToken($user);
        $expiry = $this->calculateExpiry();

        $payload = [
            'token' => $token,
            'expiry' => $expiry,
            'user_id' => $user->id,
            'type' => 'password_reset',
        ];

        $encodedPayload = $this->encodePayload($payload);

        return $this->constructVerificationUrl($encodedPayload);
    }

    public function generateMagicLink(User $user, string $action): string
    {
        $token = $this->createMagicLinkToken($user, $action);
        $expiry = $this->calculateExpiry();

        $payload = [
            'token' => $token,
            'expiry' => $expiry,
            'user_id' => $user->id,
            'action' => $action,
            'type' => 'magic_link',
        ];

        $encodedPayload = $this->encodePayload($payload);

        return $this->constructVerificationUrl($encodedPayload);
    }

    public function generateTwoFactorSetupLink(User $user): string
    {
        $token = $this->createTwoFactorToken($user);
        $expiry = $this->calculateExpiry();

        $payload = [
            'token' => $token,
            'expiry' => $expiry,
            'user_id' => $user->id,
            'type' => '2fa_setup',
        ];

        $encodedPayload = $this->encodePayload($payload);

        return $this->constructVerificationUrl($encodedPayload);
    }

    public function generateAccountRecoveryLink(User $user): string
    {
        $token = $this->createRecoveryToken($user);
        $expiry = $this->calculateExpiry();

        $payload = [
            'token' => $token,
            'expiry' => $expiry,
            'user_id' => $user->id,
            'type' => 'account_recovery',
        ];

        $encodedPayload = $this->encodePayload($payload);

        return $this->constructVerificationUrl($encodedPayload);
    }

    private function createVerificationToken(User $user): string
    {
        $data = $user->email . $user->id . time();
        return hash(self::TOKEN_ALGORITHM, $data);
    }

    private function createWelcomeToken(User $user): string
    {
        $data = $user->email . $user->created_at . 'welcome';
        return hash(self::TOKEN_ALGORITHM, $data);
    }

    private function createEmailChangeToken(User $user, string $newEmail): string
    {
        $data = $newEmail . $user->id . time();
        return hash(self::TOKEN_ALGORITHM, $data);
    }

    private function createPasswordResetToken(User $user): string
    {
        $data = $user->email . $user->password_hash . time();
        return hash(self::TOKEN_ALGORITHM, $data);
    }

    private function createMagicLinkToken(User $user, string $action): string
    {
        $data = $user->email . $action . time();
        return hash(self::TOKEN_ALGORITHM, $data);
    }

    private function createTwoFactorToken(User $user): string
    {
        $data = $user->id . $user->email . '2fa';
        return hash(self::TOKEN_ALGORITHM, $data);
    }

    private function createRecoveryToken(User $user): string
    {
        $data = $user->id . $user->security_question . time();
        return hash(self::TOKEN_ALGORITHM, $data);
    }

    private function calculateExpiry(): int
    {
        return time() + (self::DEFAULT_EXPIRY_HOURS * 3600);
    }

    private function buildTokenPayload(string $token, int $expiry): array
    {
        return [
            'token' => $token,
            'expiry' => $expiry,
            'created' => time(),
        ];
    }

    private function encodePayload(array $payload): string
    {
        $json = json_encode($payload);

        if ($json === false) {
            throw new EmailGenerationException('Failed to encode email payload');
        }

        return rtrim(strtroyal(base64_encode($json), '+/', '-_'), '=');
    }

    private function constructVerificationUrl(string $encodedPayload): string
    {
        return UrlBuilder::build(self::VERIFY_ROUTE, ['token' => $encodedPayload]);
    }
}
