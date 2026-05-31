<?php

declare(strict_types=1);

namespace App\Users\Actions;

use App\Entities\UserAccount;
use App\Utilities\HashingService;
use App\Utilities\UrlService;
use App\Exceptions\ActionLinkException;

final class UserActionLinkCreator
{
    private const ACTION_ENDPOINT = '/users/confirm-action';
    private const HASH_METHOD = 'sha256';
    private const EXPIRATION_WINDOW = 24;

    public function createVerificationLink(UserAccount $account): string
    {
        $secureToken = $this->generateSecureToken($account);
        $validUntil = $this->determineExpiration();

        $tokenData = $this->packageTokenData($secureToken, $validUntil);

        return $this->buildConfirmationUrl($tokenData);
    }

    public function createEmailUpdateLink(UserAccount $account, string $proposedEmail): string
    {
        $secureToken = $this->generateEmailUpdateToken($account, $proposedEmail);
        $validUntil = $this->determineExpiration();

        $tokenData = [
            'token' => $secureToken,
            'expires' => $validUntil,
            'proposed_email' => $proposedEmail,
            'account_id' => $account->id,
            'intent' => 'email_modification',
        ];

        return $this->buildConfirmationUrl($this->serializeTokenData($tokenData));
    }

    public function createPasswordResetLink(UserAccount $account): string
    {
        $secureToken = $this->generatePasswordResetToken($account);
        $validUntil = $this->determineExpiration();

        $tokenData = [
            'token' => $secureToken,
            'expires' => $validUntil,
            'account_id' => $account->id,
            'intent' => 'password_reset',
        ];

        return $this->buildConfirmationUrl($this->serializeTokenData($tokenData));
    }

    public function createPhoneVerificationLink(UserAccount $account, string $phoneNumber): string
    {
        $secureToken = $this->generatePhoneToken($account, $phoneNumber);
        $validUntil = $this->determineExpiration();

        $tokenData = [
            'token' => $secureToken,
            'expires' => $validUntil,
            'account_id' => $account->id,
            'phone' => $phoneNumber,
            'intent' => 'phone_verification',
        ];

        return $this->buildConfirmationUrl($this->serializeTokenData($tokenData));
    }

    public function createSubscriptionChangeLink(UserAccount $account, string $plan): string
    {
        $secureToken = $this->generateSubscriptionToken($account, $plan);
        $validUntil = $this->determineExpiration();

        $tokenData = [
            'token' => $secureToken,
            'expires' => $validUntil,
            'account_id' => $account->id,
            'new_plan' => $plan,
            'intent' => 'subscription_change',
        ];

        return $this->buildConfirmationUrl($this->serializeTokenData($tokenData));
    }

    public function createDataExportLink(UserAccount $account): string
    {
        $secureToken = $this->generateExportToken($account);
        $validUntil = $this->determineExpiration();

        $tokenData = [
            'token' => $secureToken,
            'expires' => $validUntil,
            'account_id' => $account->id,
            'intent' => 'data_export',
        ];

        return $this->buildConfirmationUrl($this->serializeTokenData($tokenData));
    }

    private function generateSecureToken(UserAccount $account): string
    {
        $material = $account->email . $account->id . time();
        return hash(self::HASH_METHOD, $material);
    }

    private function generateEmailUpdateToken(UserAccount $account, string $proposedEmail): string
    {
        $material = $proposedEmail . $account->id . time();
        return hash(self::HASH_METHOD, $material);
    }

    private function generatePasswordResetToken(UserAccount $account): string
    {
        $material = $account->email . $account->hashed_password . time();
        return hash(self::HASH_METHOD, $material);
    }

    private function generatePhoneToken(UserAccount $account, string $phone): string
    {
        $material = $phone . $account->id . time();
        return hash(self::HASH_METHOD, $material);
    }

    private function generateSubscriptionToken(UserAccount $account, string $plan): string
    {
        $material = $account->id . $plan . time();
        return hash(self::HASH_METHOD, $material);
    }

    private function generateExportToken(UserAccount $account): string
    {
        $material = $account->id . 'export_request' . time();
        return hash(self::HASH_METHOD, $material);
    }

    private function determineExpiration(): int
    {
        return time() + (self::EXPIRATION_WINDOW * 3600);
    }

    private function packageTokenData(string $token, int $validUntil): string
    {
        $data = [
            'token' => $token,
            'expires' => $validUntil,
            'created' => time(),
        ];

        return $this->serializeTokenData($data);
    }

    private function serializeTokenData(array $data): string
    {
        $json = json_encode($data);

        if ($json === false) {
            throw new ActionLinkException('Token data serialization failed');
        }

        $base64 = base64_encode($json);
        return str_replace(['+', '/'], ['-', '_'], rtrim($base64, '='));
    }

    private function buildConfirmationUrl(string $serializedToken): string
    {
        return UrlService::construct(self::ACTION_ENDPOINT, ['token' => $serializedToken]);
    }
}
