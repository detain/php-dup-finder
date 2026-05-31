<?php
declare(strict_types=1);

namespace App\Webhooks\Verification;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class StripeWebhookVerifier
{
    private LoggerInterface $logger;
    private string $webhookSecret;
    private int $tolerance = 300;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->webhookSecret = $config->get('stripe.webhook_secret');
    }

    public function verify(array $headers, string $payload): bool
    {
        try {
            $signature = $headers['Stripe-Signature'] ?? $headers['stripe-signature'] ?? null;
            
            if (empty($signature)) {
                $this->logger->warning('Stripe webhook missing signature header');
                return false;
            }
            
            $elements = $this->parseSignatureHeader($signature);
            
            if (!isset($elements['t']) || !isset($elements['v1'])) {
                $this->logger->warning('Stripe webhook invalid signature format');
                return false;
            }
            
            $timestamp = (int)$elements['t'];
            $providedSignature = $elements['v1'];
            
            if (!$this->isTimestampValid($timestamp)) {
                $this->logger->warning('Stripe webhook timestamp too old', [
                    'timestamp' => $timestamp,
                    'tolerance' => $this->tolerance,
                ]);
                return false;
            }
            
            $expectedSignature = $this->computeSignature(
                $timestamp . '.' . $payload,
                $this->webhookSecret
            );
            
            if (!$this->timingSafeEquals($providedSignature, $expectedSignature)) {
                $this->logger->warning('Stripe webhook signature mismatch');
                return false;
            }
            
            $this->logger->info('Stripe webhook verified successfully');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Stripe webhook verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function parseSignatureHeader(string $header): array
    {
        $elements = [];
        $pairs = explode(',', $header);
        
        foreach ($pairs as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $elements[trim($parts[0])] = trim($parts[1]);
            }
        }
        
        return $elements;
    }

    private function isTimestampValid(int $timestamp): bool
    {
        $now = time();
        return abs($now - $timestamp) <= $this->tolerance;
    }

    private function computeSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    private function timingSafeEquals(string $provided, string $expected): bool
    {
        if (strlen($provided) !== strlen($expected)) {
            return false;
        }
        
        return hash_equals($expected, $provided);
    }

    public function constructEvent(array $headers, string $payload)
    {
        if (!$this->verify($headers, $payload)) {
            throw new \InvalidArgumentException('Invalid webhook signature');
        }
        
        return json_decode($payload, true);
    }
}
