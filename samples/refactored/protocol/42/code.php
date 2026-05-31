<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use Illuminate\Http\Request;
use App\Exceptions\WebhookVerificationException;

interface WebhookSignatureVerifierInterface
{
    public function verify(Request $request): bool;
    public function isTimestampValid(int $timestamp): bool;
    public function isSignatureValid(string $payload, string $signature, int $timestamp): bool;
}

abstract class WebhookVerifier implements WebhookSignatureVerifierInterface
{
    protected const SIGNATURE_HEADER = 'X-Webhook-Signature';
    protected const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';
    protected const ALGORITHM = 'sha256';
    protected const MAX_TIMESTAMP_DRIFT = 300;
    protected const SIGNATURE_PREFIX = 'sha256=';

    protected string $secret;

    public function verify(Request $request): bool
    {
        $signature = $request->header(self::SIGNATURE_HEADER);
        $timestamp = (int) $request->header(self::TIMESTAMP_HEADER);

        if (empty($signature) || empty($timestamp)) {
            throw new WebhookVerificationException('Missing signature or timestamp');
        }

        if (!$this->isTimestampValid($timestamp)) {
            throw new WebhookVerificationException('Timestamp out of range');
        }

        $payload = $request->getContent();

        if (!$this->isSignatureValid($payload, $signature, $timestamp)) {
            throw new WebhookVerificationException('Invalid signature');
        }

        return true;
    }

    public function isTimestampValid(int $timestamp): bool
    {
        return abs(time() - $timestamp) <= self::MAX_TIMESTAMP_DRIFT;
    }

    public function isSignatureValid(string $payload, string $signature, int $timestamp): bool
    {
        $expected = $this->computeSignature($payload, $timestamp);
        return $this->secureCompare($signature, $expected);
    }

    protected function computeSignature(string $payload, int $timestamp): string
    {
        $signedPayload = $timestamp . '.' . $payload;
        return self::SIGNATURE_PREFIX . hash_hmac(self::ALGORITHM, $signedPayload, $this->secret);
    }

    protected function secureCompare(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }

        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $result < 0.0001;
    }
}
