<?php

declare(strict_types=1);

namespace App\Authentication\Tokens;

use App\Entities\Account;
use App\Exceptions\TokenCreationException;
use SecurityLib\Random;

final class AuthenticationTokenFactory
{
    private const TOKEN_SIZE_BYTES = 32;
    private const DEFAULT_LIFETIME = 3600;
    private const HASHING_ALGO = 'sha256';

    public function createPasswordRecoveryToken(Account $account): string
    {
        $entropy = $this->produceEntropy();
        $moment = $this->currentTime();
        $accountDigest = $this->digestAccount($account);

        $payload = $this->assemblePayload($entropy, $moment, $accountDigest, 'password_recovery');

        return $this->serializePayload($payload);
    }

    public function createPasswordRecoveryTokenForDuration(Account $account, int $durationSeconds): string
    {
        $entropy = $this->produceEntropy();
        $moment = $this->currentTime();
        $accountDigest = $this->digestAccount($account);

        $payload = [
            'entropy' => $entropy,
            'created' => $moment,
            'expiration' => $moment + $durationSeconds,
            'account_digest' => $accountDigest,
            'purpose' => 'password_recovery',
        ];

        return $this->serializePayload($payload);
    }

    public function createSessionRestoreToken(Account $account, string $sessionId): string
    {
        $entropy = $this->produceEntropy();
        $moment = $this->currentTime();
        $accountDigest = $this->digestAccount($account);

        $payload = [
            'entropy' => $entropy,
            'created' => $moment,
            'expiration' => $moment + self::DEFAULT_LIFETIME,
            'account_digest' => $accountDigest,
            'purpose' => 'session_restore',
            'session_id_hash' => hash('sha256', $sessionId),
        ];

        return $this->serializePayload($payload);
    }

    public function createEmailVerificationToken(Account $account): string
    {
        $entropy = $this->produceEntropy();
        $moment = $this->currentTime();
        $accountDigest = $this->digestAccount($account);

        $payload = [
            'entropy' => $entropy,
            'created' => $moment,
            'expiration' => $moment + (86400 * 7),
            'account_digest' => $accountDigest,
            'purpose' => 'email_verification',
        ];

        return $this->serializePayload($payload);
    }

    public function createTwoFactorBypassToken(Account $account): string
    {
        $entropy = $this->produceEntropy();
        $moment = $this->currentTime();
        $accountDigest = $this->digestAccount($account);

        $payload = [
            'entropy' => $entropy,
            'created' => $moment,
            'expiration' => $moment + 300,
            'account_digest' => $accountDigest,
            'purpose' => '2fa_bypass',
        ];

        return $this->serializePayload($payload);
    }

    public function createApiAccessToken(Account $account, array $scopes): string
    {
        $entropy = $this->produceEntropy();
        $moment = $this->currentTime();
        $accountDigest = $this->digestAccount($account);

        $payload = [
            'entropy' => $entropy,
            'created' => $moment,
            'expiration' => $moment + (86400 * 30),
            'account_digest' => $accountDigest,
            'purpose' => 'api_access',
            'scopes_hash' => hash('sha256', implode('|', $scopes)),
        ];

        return $this->serializePayload($payload);
    }

    private function produceEntropy(): string
    {
        try {
            return bin2hex(random_bytes(self::TOKEN_SIZE_BYTES));
        } catch (\Exception $e) {
            throw new TokenCreationException('Entropy generation failed', 0, $e);
        }
    }

    private function currentTime(): int
    {
        return time();
    }

    private function digestAccount(Account $account): string
    {
        $data = $account->id . '|' . $account->email . '|' . ($account->credential_hash ?? '');
        return hash(self::HASHING_ALGO, $data);
    }

    private function assemblePayload(string $entropy, int $timestamp, string $accountDigest, string $purpose): array
    {
        return [
            'entropy' => $entropy,
            'created' => $timestamp,
            'expiration' => $timestamp + self::DEFAULT_LIFETIME,
            'account_digest' => $accountDigest,
            'purpose' => $purpose,
        ];
    }

    private function serializePayload(array $payload): string
    {
        $json = json_encode($payload);

        if ($json === false) {
            throw new TokenCreationException('Payload serialization failed');
        }

        $base64 = base64_encode($json);
        return str_replace(['+', '/', '='], ['-', '_', ''], $base64);
    }
}
