<?php
declare(strict_types=1);

namespace App\Webhooks\Verification;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class SlackWebhookVerifier
{
    private LoggerInterface $logger;
    private string $signingSecret;
    private int $tolerance = 300;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->signingSecret = $config->get('slack.signing_secret');
    }

    public function verify(array $headers, string $payload): bool
    {
        try {
            $signature = $headers['X-Slack-Signature'] ?? 
                         $headers['x-slack-signature'] ?? null;
            
            $timestamp = $headers['X-Slack-Request-Timestamp'] ?? 
                         $headers['x-slack-request-timestamp'] ?? null;
            
            if (empty($signature) || empty($timestamp)) {
                $this->logger->warning('Slack webhook missing headers', [
                    'has_signature' => !empty($signature),
                    'has_timestamp' => !empty($timestamp),
                ]);
                return false;
            }
            
            $timestampInt = (int)$timestamp;
            
            if (!$this->isTimestampValid($timestampInt)) {
                $this->logger->warning('Slack webhook timestamp too old or in future', [
                    'timestamp' => $timestampInt,
                    'tolerance' => $this->tolerance,
                ]);
                return false;
            }
            
            $sigBasestring = 'v0:' . $timestamp . ':' . $payload;
            $expectedSignature = 'v0=' . $this->computeSignature($sigBasestring, $this->signingSecret);
            
            if (!$this->timingSafeEquals($signature, $expectedSignature)) {
                $this->logger->warning('Slack webhook signature mismatch');
                return false;
            }
            
            $this->logger->info('Slack webhook verified successfully');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Slack webhook verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function isTimestampValid(int $timestamp): bool
    {
        $now = time();
        
        if (abs($now - $timestamp) > $this->tolerance) {
            return false;
        }
        
        return true;
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
