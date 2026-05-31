<?php
declare(strict_types=1);

namespace App\Webhooks\Handlers;

use App\Logging\LoggerInterface;

interface WebhookVerifierInterface
{
    public function verify(array $headers, string $payload): bool;
    public function process(array $headers, string $payload): void;
}

abstract class AbstractWebhookHandler implements WebhookVerifierInterface
{
    protected LoggerInterface $logger;
    protected string $webhookSecret;
    protected int $toleranceSeconds = 300;

    protected function verifySignature(array $headers, string $payload): bool
    {
        $signature = $headers['X-Webhook-Signature'] ?? null;
        $timestamp = $headers['X-Webhook-Timestamp'] ?? null;
        
        if ($signature === null || $timestamp === null) {
            $this->logger->warning('Webhook missing signature or timestamp');
            return false;
        }
        
        $timestampInt = (int)$timestamp;
        
        if (!$this->isTimestampValid($timestampInt)) {
            $this->logger->warning('Webhook timestamp outside tolerance', [
                'timestamp' => $timestampInt,
                'tolerance' => $this->toleranceSeconds,
            ]);
            return false;
        }
        
        $expectedSignature = $this->computeSignature($timestamp, $payload);
        
        if (!$this->isSignatureValid($signature, $expectedSignature)) {
            $this->logger->warning('Webhook signature mismatch');
            return false;
        }
        
        $this->logger->info('Webhook verified successfully');
        return true;
    }

    protected function isTimestampValid(int $timestamp): bool
    {
        $now = time();
        return abs($now - $timestamp) <= $this->toleranceSeconds;
    }

    protected function computeSignature(string $timestamp, string $payload): string
    {
        $signedPayload = $timestamp . '.' . $payload;
        return hash_hmac('sha256', $signedPayload, $this->webhookSecret);
    }

    protected function isSignatureValid(string $provided, string $expected): bool
    {
        if (strlen($provided) !== strlen($expected)) {
            return false;
        }
        return hash_equals($expected, $provided);
    }

    public function process(array $headers, string $payload): void
    {
        if (!$this->verifySignature($headers, $payload)) {
            throw new \InvalidArgumentException('Invalid webhook signature');
        }
        
        $event = json_decode($payload, true);
        $this->handleEvent($event);
    }

    abstract protected function handleEvent(array $event): void;
}
