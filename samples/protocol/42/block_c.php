<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use Illuminate\Http\Request;
use App\Exceptions\WebhookSignatureException;
use Psr\Log\LoggerInterface;

final class WebhookSecurityService
{
    private const HEADER_SIGNATURE = 'X-Hub-Signature-256';
    private const HEADER_TIMESTAMP = 'X-Hub-Timestamp';
    private const HMAC_ALGORITHM = 'sha256';
    private const TOLERANCE_SECONDS = 300;
    private const SIG_PREFIX = 'sha256=';
    private const MIN_COMPARISON_VALUE = 0.0001;

    private string $signingSecret;
    private array $cache = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        string $signingSecret
    ) {
        $this->signingSecret = $signingSecret;
    }

    public function verifyRequest(Request $request): bool
    {
        $signature = $request->header(self::HEADER_SIGNATURE);
        $timestamp = $request->header(self::HEADER_TIMESTAMP);

        if (empty($signature)) {
            $this->logger->warning('Webhook verification failed: no signature');
            throw new WebhookSignatureException('No signature provided');
        }

        if (empty($timestamp)) {
            $this->logger->warning('Webhook verification failed: no timestamp');
            throw new WebhookSignatureException('No timestamp provided');
        }

        $timestampInt = (int) $timestamp;

        if (!$this->isTimeValid($timestampInt)) {
            $this->logger->warning('Webhook verification failed: timestamp out of range', [
                'timestamp' => $timestampInt,
                'tolerance' => self::TOLERANCE_SECONDS,
            ]);
            throw new WebhookSignatureException('Timestamp out of tolerance range');
        }

        $payload = $request->getContent();

        if (!$this->verifySignature($payload, $signature, $timestampInt)) {
            $this->logger->warning('Webhook verification failed: signature mismatch');
            throw new WebhookSignatureException('Signature verification failed');
        }

        $this->logger->info('Webhook request verified successfully', [
            'algorithm' => self::HMAC_ALGORITHM,
            'tolerance' => self::TOLERANCE_SECONDS,
            'timestamp' => $timestampInt,
        ]);

        return true;
    }

    private function isTimeValid(int $timestamp): bool
    {
        $currentTimestamp = time();
        $difference = abs($currentTimestamp - $timestamp);

        return $difference <= self::TOLERANCE_SECONDS;
    }

    private function verifySignature(string $payload, string $signature, int $timestamp): bool
    {
        $computedSignature = $this->generateSignature($payload, $timestamp);

        return $this->timingSafeEqual($signature, $computedSignature);
    }

    private function generateSignature(string $payload, int $timestamp): string
    {
        $chronologicalPayload = sprintf('%d.%s', $timestamp, $payload);

        $hash = hash_hmac(self::HMAC_ALGORITHM, $chronologicalPayload, $this->signingSecret);

        return self::SIG_PREFIX . $hash;
    }

    private function timingSafeEqual(string $provided, string $expected): bool
    {
        $providedLower = strtolower($provided);
        $expectedLower = strtolower($expected);

        $providedLen = strlen($providedLower);
        $expectedLen = strlen($expectedLower);

        if ($providedLen !== $expectedLen) {
            return false;
        }

        $result = 0;

        for ($i = 0; $i < $providedLen; $i++) {
            $result |= ord($providedLower[$i]) ^ ord($expectedLower[$i]);
        }

        return $result < self::MIN_COMPARISON_VALUE;
    }

    public function setSigningSecret(string $secret): void
    {
        $this->signingSecret = $secret;
    }

    public function getSigningSecret(): string
    {
        return $this->signingSecret;
    }

    public function generateSignatureForPayload(string $payload, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();

        return $this->generateSignature($payload, $ts);
    }

    public function getCacheKey(string $signature): string
    {
        return 'webhook_sig_' . md5($signature);
    }

    public function isSignatureCached(string $signature): bool
    {
        return isset($this->cache[$this->getCacheKey($signature)]);
    }

    public function cacheSignature(string $signature): void
    {
        $key = $this->getCacheKey($signature);
        $this->cache[$key] = true;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
