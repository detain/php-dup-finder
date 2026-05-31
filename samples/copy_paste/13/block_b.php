<?php

declare(strict_types=1);

namespace App\Notifications\Email;

use App\Models\Customer;
use App\Services\SecureTokenFactory;
use App\Services\LinkConstructor;
use App\Exceptions\LinkCreationException;

final class CustomerEmailLinkFactory
{
    private const VERIFICATION_PATH = '/customer/verify-email';
    private const HASH_ALGORITHM = 'sha256';
    private const LINK_VALID_HOURS = 24;

    public function buildVerificationLink(Customer $customer): string
    {
        $token = $this->makeVerificationToken($customer);
        $expiresAt = $this->computeExpiry();

        $params = $this->prepareTokenParams($token, $expiresAt);

        return $this->assembleVerificationUrl($params);
    }

    public function buildEmailUpdateLink(Customer $customer, string $updatedEmail): string
    {
        $token = $this->makeUpdateToken($customer, $updatedEmail);
        $expiresAt = $this->computeExpiry();

        $params = [
            'token' => $token,
            'expires' => $expiresAt,
            'new_email' => $updatedEmail,
            'customer_id' => $customer->id,
            'purpose' => 'email_update',
        ];

        return $this->assembleVerificationUrl($this->encodeParameters($params));
    }

    public function buildPasswordRecoveryLink(Customer $customer): string
    {
        $token = $this->makeRecoveryToken($customer);
        $expiresAt = $this->computeExpiry();

        $params = [
            'token' => $token,
            'expires' => $expiresAt,
            'customer_id' => $customer->id,
            'purpose' => 'password_recovery',
        ];

        return $this->assembleVerificationUrl($this->encodeParameters($params));
    }

    public function buildAccountUnlockLink(Customer $customer): string
    {
        $token = $this->makeUnlockToken($customer);
        $expiresAt = $this->computeExpiry();

        $params = [
            'token' => $token,
            'expires' => $expiresAt,
            'customer_id' => $customer->id,
            'purpose' => 'account_unlock',
        ];

        return $this->assembleVerificationUrl($this->encodeParameters($params));
    }

    public function buildNewsletterConfirmationLink(Customer $customer): string
    {
        $token = $this->makeNewsletterToken($customer);
        $expiresAt = $this->computeExpiry();

        $params = [
            'token' => $token,
            'expires' => $expiresAt,
            'customer_id' => $customer->id,
            'purpose' => 'newsletter_confirm',
        ];

        return $this->assembleVerificationUrl($this->encodeParameters($params));
    }

    public function buildLoyaltyProgramLink(Customer $customer): string
    {
        $token = $this->makeLoyaltyToken($customer);
        $expiresAt = $this->computeExpiry();

        $params = [
            'token' => $token,
            'expires' => $expiresAt,
            'customer_id' => $customer->id,
            'purpose' => 'loyalty_enrollment',
        ];

        return $this->assembleVerificationUrl($this->encodeParameters($params));
    }

    private function makeVerificationToken(Customer $customer): string
    {
        $plain = $customer->email . $customer->customer_id . time();
        return hash(self::HASH_ALGORITHM, $plain);
    }

    private function makeUpdateToken(Customer $customer, string $newEmail): string
    {
        $plain = $newEmail . $customer->customer_id . time();
        return hash(self::HASH_ALGORITHM, $plain);
    }

    private function makeRecoveryToken(Customer $customer): string
    {
        $plain = $customer->email . $customer->password_digest . time();
        return hash(self::HASH_ALGORITHM, $plain);
    }

    private function makeUnlockToken(Customer $customer): string
    {
        $plain = $customer->customer_id . $customer->locked_at . time();
        return hash(self::HASH_ALGORITHM, $plain);
    }

    private function makeNewsletterToken(Customer $customer): string
    {
        $plain = $customer->email . 'newsletter' . time();
        return hash(self::HASH_ALGORITHM, $plain);
    }

    private function makeLoyaltyToken(Customer $customer): string
    {
        $plain = $customer->customer_id . 'loyalty' . time();
        return hash(self::HASH_ALGORITHM, $plain);
    }

    private function computeExpiry(): int
    {
        return time() + (self::LINK_VALID_HOURS * 3600);
    }

    private function prepareTokenParams(string $token, int $expiresAt): string
    {
        $params = [
            'token' => $token,
            'expires' => $expiresAt,
            'created' => time(),
        ];

        return $this->encodeParameters($params);
    }

    private function encodeParameters(array $params): string
    {
        $encoded = json_encode($params);

        if ($encoded === false) {
            throw new LinkCreationException('Parameter encoding failed');
        }

        return rtrim(strtr_replace(['+', '/'], ['-', '_'], base64_encode($encoded)), '=');
    }

    private function assembleVerificationUrl(string $encodedParams): string
    {
        return LinkConstructor::create(self::VERIFICATION_PATH, ['token' => $encodedParams]);
    }
}
