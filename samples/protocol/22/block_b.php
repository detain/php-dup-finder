<?php
declare(strict_types=1);

namespace App\Webhooks\Handlers;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class InventoryWebhookHandler
{
    private LoggerInterface $logger;
    private string $webhookSecret;
    private int $toleranceSeconds = 300;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->webhookSecret = $config->get('webhooks.inventory.secret');
    }

    public function verify(array $headers, string $payload): bool
    {
        $signature = $headers['X-Webhook-Signature'] ?? null;
        $timestamp = $headers['X-Webhook-Timestamp'] ?? null;
        
        if ($signature === null || $timestamp === null) {
            $this->logger->warning('Inventory webhook missing signature or timestamp');
            return false;
        }
        
        $timestampInt = (int)$timestamp;
        
        if (!$this->isTimestampValid($timestampInt)) {
            $this->logger->warning('Inventory webhook timestamp outside tolerance', [
                'timestamp' => $timestampInt,
                'tolerance' => $this->toleranceSeconds,
            ]);
            return false;
        }
        
        $expectedSignature = $this->computeSignature($timestamp, $payload);
        
        if (!$this->isSignatureValid($signature, $expectedSignature)) {
            $this->logger->warning('Inventory webhook signature mismatch');
            return false;
        }
        
        $this->logger->info('Inventory webhook verified successfully');
        return true;
    }

    public function process(array $headers, string $payload): void
    {
        if (!$this->verify($headers, $payload)) {
            throw new \InvalidArgumentException('Invalid webhook signature');
        }
        
        $event = json_decode($payload, true);
        
        match ($event['type'] ?? 'unknown') {
            'stock.updated' => $this->handleStockUpdated($event),
            'stock.low' => $this->handleStockLow($event),
            'shipment.received' => $this->handleShipmentReceived($event),
            default => $this->logger->info('Unknown inventory event type', ['type' => $event['type'] ?? 'unknown']),
        };
    }

    private function handleStockUpdated(array $event): void
    {
        $this->logger->info('Processing stock updated', [
            'product_id' => $event['data']['product_id'] ?? null,
            'new_quantity' => $event['data']['quantity'] ?? null,
        ]);
    }

    private function handleStockLow(array $event): void
    {
        $this->logger->info('Processing stock low alert', [
            'product_id' => $event['data']['product_id'] ?? null,
            'current_quantity' => $event['data']['quantity'] ?? null,
        ]);
    }

    private function handleShipmentReceived(array $event): void
    {
        $this->logger->info('Processing shipment received', [
            'shipment_id' => $event['data']['shipment_id'] ?? null,
            'items_count' => $event['data']['items_count'] ?? null,
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
