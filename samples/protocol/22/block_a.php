<?php
declare(strict_types=1);

namespace App\Webhooks\Handlers;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class PaymentWebhookHandler
{
    private LoggerInterface $logger;
    private string $webhookSecret;
    private int $toleranceSeconds = 300;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->webhookSecret = $config->get('webhooks.payment.secret');
    }

    public function verify(array $headers, string $payload): bool
    {
        $signature = $headers['X-Webhook-Signature'] ?? null;
        $timestamp = $headers['X-Webhook-Timestamp'] ?? null;
        
        if ($signature === null || $timestamp === null) {
            $this->logger->warning('Payment webhook missing signature or timestamp');
            return false;
        }
        
        $timestampInt = (int)$timestamp;
        
        if (!$this->isTimestampValid($timestampInt)) {
            $this->logger->warning('Payment webhook timestamp outside tolerance', [
                'timestamp' => $timestampInt,
                'tolerance' => $this->toleranceSeconds,
            ]);
            return false;
        }
        
        $expectedSignature = $this->computeSignature($timestamp, $payload);
        
        if (!$this->isSignatureValid($signature, $expectedSignature)) {
            $this->logger->warning('Payment webhook signature mismatch');
            return false;
        }
        
        $this->logger->info('Payment webhook verified successfully');
        return true;
    }

    public function process(array $headers, string $payload): void
    {
        if (!$this->verify($headers, $payload)) {
            throw new \InvalidArgumentException('Invalid webhook signature');
        }
        
        $event = json_decode($payload, true);
        
        match ($event['type'] ?? 'unknown') {
            'payment.completed' => $this->handlePaymentCompleted($event),
            'payment.failed' => $this->handlePaymentFailed($event),
            'refund.processed' => $this->handleRefundProcessed($event),
            default => $this->logger->info('Unknown payment event type', ['type' => $event['type'] ?? 'unknown']),
        };
    }

    private function handlePaymentCompleted(array $event): void
    {
        $this->logger->info('Processing payment completed', [
            'payment_id' => $event['data']['payment_id'] ?? null,
            'amount' => $event['data']['amount'] ?? null,
        ]);
    }

    private function handlePaymentFailed(array $event): void
    {
        $this->logger->info('Processing payment failed', [
            'payment_id' => $event['data']['payment_id'] ?? null,
            'reason' => $event['data']['reason'] ?? null,
        ]);
    }

    private function handleRefundProcessed(array $event): void
    {
        $this->logger->info('Processing refund processed', [
            'refund_id' => $event['data']['refund_id'] ?? null,
            'amount' => $event['data']['amount'] ?? null,
        ]);
    }

    private function isTimestampValid(int $timestamp): bool
    {
        $now = time();
        return abs($now - $timestamp) <= $this->toleranceSeconds;
    }

    private function computeSignature(string $timestamp, string $payload): string
    {
        $signedPayload = $timestamp . '.' . $payload;
        return hash_hmac('sha256', $signedPayload, $this->webhookSecret);
    }

    private function isSignatureValid(string $provided, string $expected): bool
    {
        if (strlen($provided) !== strlen($expected)) {
            return false;
        }
        return hash_equals($expected, $provided);
    }
}
