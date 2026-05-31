<?php
declare(strict_types=1);

namespace App\Webhooks\Verification;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

interface WebhookVerifierInterface
{
    public function verify(array $headers, string $payload): bool;
    public function constructEvent(array $headers, string $payload);
}

abstract class AbstractWebhookVerifier implements WebhookVerifierInterface
{
    protected LoggerInterface $logger;
    protected string $secret;
    protected int $tolerance = 300;

    protected function __construct(
        ConfigManager $config,
        LoggerInterface $logger,
        string $configKey
    ) {
        $this->logger = $logger;
        $this->secret = $config->get($configKey);
    }

    protected function isTimestampValid(int $timestamp): bool
    {
        $now = time();
        return abs($now - $timestamp) <= $this->tolerance;
    }

    protected function timingSafeEquals(string $provided, string $expected): bool
    {
        if (strlen($provided) !== strlen($expected)) {
            return false;
        }
        
        return hash_equals($expected, $provided);
    }

    protected function computeSignature(string $payload, string $secret, string $algorithm = 'sha256'): string
    {
        return hash_hmac($algorithm, $payload, $secret);
    }

    protected function parseSignatureHeader(string $header): array
    {
        $elements = [];
        
        if (str_contains($header, ',')) {
            $pairs = explode(',', $header);
            foreach ($pairs as $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) === 2) {
                    $elements[trim($parts[0])] = trim($parts[1]);
                }
            }
        } elseif (str_contains($header, '=')) {
            $parts = explode('=', $header, 2);
            if (count($parts) === 2) {
                $elements[$parts[0]] = $parts[1];
            }
        }
        
        return $elements;
    }

    protected function verifySignature(string $payload, string $providedSignature, string $timestamp, string $algorithm = 'sha256'): bool
    {
        if (!$this->isTimestampValid((int)$timestamp)) {
            $this->logger->warning('Webhook timestamp validation failed', [
                'timestamp' => $timestamp,
                'tolerance' => $this->tolerance,
            ]);
            return false;
        }
        
        $sigBasestring = $timestamp . '.' . $payload;
        $expectedSignature = $this->computeSignature($sigBasestring, $this->secret, $algorithm);
        
        if (!$this->timingSafeEquals($providedSignature, $expectedSignature)) {
            $this->logger->warning('Webhook signature mismatch');
            return false;
        }
        
        return true;
    }

    public function constructEvent(array $headers, string $payload)
    {
        if (!$this->verify($headers, $payload)) {
            throw new \InvalidArgumentException('Invalid webhook signature');
        }
        
        return $this->parsePayload($payload);
    }

    abstract protected function parsePayload(string $payload): array;
}
