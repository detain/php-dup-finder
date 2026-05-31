<?php
declare(strict_types=1);

namespace App\Webhooks\Signature;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

class HmacWebhookVerifier
{
    private LoggerInterface $logger;
    private string $secret;
    private int $toleranceSeconds;
    private string $serviceName;

    public function __construct(
        string $serviceName,
        string $secret,
        LoggerInterface $logger,
        int $toleranceSeconds = 300
    ) {
        $this->serviceName = $serviceName;
        $this->secret = $secret;
        $this->logger = $logger;
        $this->toleranceSeconds = $toleranceSeconds;
    }

    public static function fromConfig(
        string $serviceName,
        ConfigManager $config,
        LoggerInterface $logger
    ): self {
        $secretKey = "webhooks.{$serviceName}.secret";
        return new self(
            $serviceName,
            $config->get($secretKey),
            $logger
        );
    }

    public function verify(array $headers, string $payload): bool
    {
        $signature = $headers['X-Webhook-Signature'] ?? null;
        
        if ($signature === null) {
            $this->logger->warning("{$this->serviceName} webhook missing signature");
            return false;
        }
        
        $timestamp = $headers['X-Webhook-Timestamp'] ?? null;
        
        if (!$this->isTimestampValid($timestamp)) {
            $this->logger->warning("{$this->serviceName} webhook timestamp validation failed");
            return false;
        }
        
        $expectedSignature = $this->computeSignature($timestamp, $payload);
        
        if (!$this->isSignatureValid($signature, $expectedSignature)) {
            $this->logger->warning("{$this->serviceName} webhook signature mismatch");
            return false;
        }
        
        $this->logger->info("{$this->serviceName} webhook signature verified");
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
