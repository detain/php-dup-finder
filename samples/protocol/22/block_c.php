<?php
declare(strict_types=1);

namespace App\Webhooks\Handlers;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class SubscriptionWebhookHandler
{
    private LoggerInterface $logger;
    private string $webhookSecret;
    private int $toleranceSeconds = 300;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->webhookSecret = $config->get('webhooks.subscription.secret');
    }

    public function verify(array $headers, string $payload): bool
    {
        $signature = $headers['X-Webhook-Signature'] ?? null;
        $timestamp = $headers['X-Webhook-Timestamp'] ?? null;
        
        if ($signature === null || $timestamp === null) {
            $this->logger->warning('Subscription webhook missing signature or timestamp');
            return false;
        }
        
        $timestampInt = (int)$timestamp;
        
        if (!$this->isTimestampValid($timestampInt)) {
            $this->logger->warning('Subscription webhook timestamp outside tolerance', [
                'timestamp' => $timestampInt,
                'tolerance' => $this->toleranceSeconds,
            ]);
            return false;
        }
        
        $expectedSignature = $this->computeSignature($timestamp, $payload);
        
        if (!$this->isSignatureValid($signature, $expectedSignature)) {
            $this->logger->warning('Subscription webhook signature mismatch');
            return false;
        }
        
        $this->logger->info('Subscription webhook verified successfully');
        return true;
    }

    public function process(array $headers, string $payload): void
    {
        if (!$this->verify($headers, $payload)) {
            throw new \InvalidArgumentException('Invalid webhook signature');
        }
        
        $event = json_decode($payload, true);
        
        match ($event['type'] ?? 'unknown') {
            'subscription.created' => $this->handleSubscriptionCreated($event),
            'subscription.cancelled' => $this->handleSubscriptionCancelled($event),
            'subscription.renewed' => $this->handleSubscriptionRenewed($event),
            default => $this->logger->info('Unknown subscription event type', ['type' => $event['type'] ?? 'unknown']),
        };
    }

    private function handleSubscriptionCreated(array $event): void
    {
        $this->logger->info('Processing subscription created', [
            'subscription_id' => $event['data']['subscription_id'] ?? null,
            'plan' => $event['data']['plan'] ?? null,
        ]);
    }

    private function handleSubscriptionCancelled(array $event): void
    {
        $this->logger->info('Processing subscription cancelled', [
            'subscription_id' => $event['data']['subscription_id'] ?? null,
            'cancelled_at' => $event['data']['cancelled_at'] ?? null,
        ]);
    }

    private function handleSubscriptionRenewed(array $event): void
    {
        $this->logger->info('Processing subscription renewed', [
            'subscription_id' => $event['data']['subscription_id'] ?? null,
            'next_billing' => $event['data']['next_billing'] ?? null,
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
