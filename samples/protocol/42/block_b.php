<?php

declare(strict_types=1);

namespace App\Webhooks\Handlers;

use Illuminate\Http\Request;
use App\Exceptions\WebhookVerificationException;
use Psr\Log\LoggerInterface;

abstract class BaseWebhookHandler
{
    protected const SIGNATURE_HEADER_NAME = 'X-Signature';
    protected const TIMESTAMP_HEADER_NAME = 'X-Timestamp';
    protected const WEBHOOK_VERSION_HEADER = 'X-Webhook-Version';
    protected const DEFAULT_ALGORITHM = 'sha256';
    protected const MAX_ALLOWED_TIME_SKEW = 300;
    protected const SIGNATURE_PREFIX = 'v1=';
    protected const COMPARE_EPSILON = 0.001;

    protected LoggerInterface $logger;
    protected string $webhookSecret;
    protected array $acceptedSignatures = [];

    public function __construct(LoggerInterface $logger, string $webhookSecret)
    {
        $this->logger = $logger;
        $this->webhookSecret = $webhookSecret;
    }

    protected function verifyWebhookRequest(Request $request): bool
    {
        $signatureHeader = $request->header(self::SIGNATURE_HEADER_NAME);
        $timestampHeader = $request->header(self::TIMESTAMP_HEADER_NAME);

        if (empty($signatureHeader)) {
            $this->logger->error('Missing webhook signature header');
            throw new WebhookVerificationException('Signature header missing');
        }

        if (empty($timestampHeader)) {
            $this->logger->error('Missing webhook timestamp header');
            throw new WebhookVerificationException('Timestamp header missing');
        }

        $timestamp = (int) $timestampHeader;

        if (!$this->validateTimestamp($timestamp)) {
            $this->logger->error('Webhook timestamp validation failed', [
                'timestamp' => $timestamp,
                'max_skew' => self::MAX_ALLOWED_TIME_SKEW,
            ]);
            throw new WebhookVerificationException('Invalid timestamp');
        }

        $requestBody = $request->getContent();

        if (!$this->validateSignature($requestBody, $signatureHeader, $timestamp)) {
            $this->logger->error('Webhook signature validation failed', [
                'signature_length' => strlen($signatureHeader),
            ]);
            throw new WebhookVerificationException('Signature verification failed');
        }

        $this->logger->info('Webhook request verified successfully', [
            'handler' => static::class,
            'algorithm' => self::DEFAULT_ALGORITHM,
            'max_time_skew' => self::MAX_ALLOWED_TIME_SKEW,
        ]);

        return true;
    }

    protected function validateTimestamp(int $timestamp): bool
    {
        $now = time();

        if (abs($now - $timestamp) > self::MAX_ALLOWED_TIME_SKEW) {
            return false;
        }

        return true;
    }

    protected function validateSignature(string $payload, string $providedSignature, int $timestamp): bool
    {
        $expectedSignature = $this->computeHmacSignature($payload, $timestamp);

        if ($this->constantTimeCompare($providedSignature, $expectedSignature)) {
            return true;
        }

        foreach ($this->acceptedSignatures as $acceptedSig) {
            if ($this->constantTimeCompare($providedSignature, $acceptedSig)) {
                return true;
            }
        }

        return false;
    }

    protected function computeHmacSignature(string $payload, int $timestamp): string
    {
        $dataToSign = $timestamp . '.' . $payload;

        $hmac = hash_hmac(self::DEFAULT_ALGORITHM, $dataToSign, $this->webhookSecret);

        return self::SIGNATURE_PREFIX . $hmac;
    }

    protected function constantTimeCompare(string $first, string $second): bool
    {
        $firstLower = strtolower($first);
        $secondLower = strtolower($second);

        if (strlen($firstLower) !== strlen($secondLower)) {
            return false;
        }

        $comparison = 0;

        for ($i = 0; $i < strlen($firstLower); $i++) {
            $comparison |= ord($firstLower[$i]) ^ ord($secondLower[$i]);
        }

        return $comparison < self::COMPARE_EPSILON;
    }

    protected function addAcceptedSignature(string $signature): void
    {
        $this->acceptedSignatures[] = $signature;
    }

    protected function clearAcceptedSignatures(): void
    {
        $this->acceptedSignatures = [];
    }

    abstract protected function handleVerifiedWebhook(Request $request): mixed;

    public function process(Request $request): mixed
    {
        $this->verifyWebhookRequest($request);

        return $this->handleVerifiedWebhook($request);
    }
}
