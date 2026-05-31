<?php
declare(strict_types=1);

namespace App\Webhooks\Signature;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class InventoryWebhookVerifier
{
    private LoggerInterface $logger;
    private string $secret;
    private int $toleranceSeconds = 300;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->secret = $config->get('webhooks.inventory.secret');
    }

    public function verify(array $headers, string $payload): bool
    {
        $signature = $headers['X-Webhook-Signature'] ?? null;
        
        if ($signature === null) {
            $this->logger->warning('Inventory webhook missing signature');
            return false;
        }
        
        $timestamp = $headers['X-Webhook-Timestamp'] ?? null;
        
        if (!$this->isTimestampValid($timestamp)) {
            $this->logger->warning('Inventory webhook timestamp validation failed');
            return false;
        }
        
        $expectedSignature = $this->computeSignature($timestamp, $payload);
        
        if (!$this->isSignatureValid($signature, $expectedSignature)) {
            $this->logger->warning('Inventory webhook signature mismatch');
            return false;
        }
        
        $this->logger->info('Inventory webhook signature verified');
        return true;
    }

    private function isTimestampValid(?string $timestamp): bool
    {
        if ($timestamp === null) {
            return false;
        }
        
        $timestampInt = (int)$timestamp;
        $now = time();
        
        return abs($now - $timestampInt) <= $this->toleranceSeconds;
    }

    private function computeSignature(string $timestamp, string $payload): string
    {
        $payloadToSign = $timestamp . '.' . $payload;
        return hash_hmac('sha256', $payloadToSign, $this->secret);
    }

    private function isSignatureValid(string $provided, string $expected): bool
    {
        if (strlen($provided) !== strlen($expected)) {
            return false;
        }
        
        return hash_equals($expected, $provided);
    }

    public function process(array $headers, string $payload, callable $handler): void
    {
        if (!$this->verify($headers, $payload)) {
            throw new \InvalidArgumentException('Invalid webhook signature');
        }
        
        $event = json_decode($payload, true);
        $handler($event);
    }
}
