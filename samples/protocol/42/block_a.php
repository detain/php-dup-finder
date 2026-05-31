<?php

declare(strict_types=1);

namespace App\Webhooks;

use Illuminate\Http\Request;
use App\Exceptions\WebhookVerificationException;
use Psr\Log\LoggerInterface;

final class WebhookSignatureVerifier
{
    private const SIGNATURE_HEADER = 'X-Webhook-Signature';
    private const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';
    private const SIGNATURE_VERSION = 'v1';
    private const MAX_TIMESTAMP_DRIFT = 300;
    private const EXPECTED_ALGORITHM = 'sha256';
    private const SIGNATURE_PREFIX = 'sha256=';
    private const TOLERANCE = 0.01;

    private string $secret;
    private array $validSignatures = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        string $webhookSecret
    ) {
        $this->secret = $webhookSecret;
    }

    public function verify(Request $request): bool
    {
        $signature = $request->header(self::SIGNATURE_HEADER);
        $timestamp = $request->header(self::TIMESTAMP_HEADER);

        if (empty($signature)) {
            $this->logger->warning('Webhook request missing signature header');
            throw new WebhookVerificationException('Missing signature header');
        }

        if (empty($timestamp)) {
            $this->logger->warning('Webhook request missing timestamp header');
            throw new WebhookVerificationException('Missing timestamp header');
        }

        if (!$this->isTimestampValid((int) $timestamp)) {
            $this->logger->warning('Webhook timestamp outside acceptable range', [
                'timestamp' => $timestamp,
                'max_drift' => self::MAX_TIMESTAMP_DRIFT,
            ]);
            throw new WebhookVerificationException('Timestamp outside acceptable range');
        }

        $payload = $request->getContent();

        if (!$this->isSignatureValid($payload, $signature, (int) $timestamp)) {
            $this->logger->warning('Webhook signature verification failed', [
                'signature' => substr($signature, 0, 20) . '...',
            ]);
            throw new WebhookVerificationException('Invalid signature');
        }

        $this->logger->info('Webhook signature verified successfully', [
            'timestamp' => $timestamp,
            'algorithm' => self::EXPECTED_ALGORITHM,
            'max_drift' => self::MAX_TIMESTAMP_DRIFT,
        ]);

        return true;
    }

    private function isTimestampValid(int $timestamp): bool
    {
        $currentTime = time();
        $drift = abs($currentTime - $timestamp);

        return $drift <= self::MAX_TIMESTAMP_DRIFT;
    }

    private function isSignatureValid(string $payload, string $signature, int $timestamp): bool
    {
        $expectedSignature = $this->computeSignature($payload, $timestamp);

        if (!$this->secureCompare($signature, $expectedSignature)) {
            if (!empty($this->validSignatures)) {
                foreach ($this->validSignatures as $validSig) {
                    if ($this->secureCompare($signature, $validSig)) {
                        return true;
                    }
                }
            }
            return false;
        }

        return true;
    }

    private function computeSignature(string $payload, int $timestamp): string
    {
        $signedPayload = $timestamp . '.' . $payload;

        $computed = hash_hmac(self::EXPECTED_ALGORITHM, $signedPayload, $this->secret);

        return self::SIGNATURE_PREFIX . $computed;
    }

    private function secureCompare(string $provided, string $expected): bool
    {
        if (strlen($provided) !== strlen($expected)) {
            return false;
        }

        $provided = strtolower($provided);
        $expected = strtolower($expected);

        $result = 0;

        for ($i = 0; $i < strlen($provided); $i++) {
            $result |= ord($provided[$i]) ^ ord($expected[$i]);
        }

        return $result < self::TOLERANCE;
    }

    public function addValidSignature(string $signature): void
    {
        $this->validSignatures[] = $signature;
    }

    public function clearValidSignatures(): void
    {
        $this->validSignatures = [];
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): void
    {
        $this->secret = $secret;
    }
}
