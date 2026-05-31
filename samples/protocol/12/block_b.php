<?php
declare(strict_types=1);

namespace App\Webhooks\Verification;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class GitHubWebhookVerifier
{
    private LoggerInterface $logger;
    private string $webhookSecret;
    private int $tolerance = 300;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->webhookSecret = $config->get('github.webhook_secret');
    }

    public function verify(array $headers, string $payload): bool
    {
        try {
            $signature = $headers['X-Hub-Signature-256'] ?? 
                          $headers['x-hub-signature-256'] ?? null;
            
            if (empty($signature)) {
                $signature = $headers['X-Hub-Signature'] ?? 
                             $headers['x-hub-signature'] ?? null;
                $algorithm = 'sha1';
            } else {
                $algorithm = 'sha256';
            }
            
            if (empty($signature)) {
                $this->logger->warning('GitHub webhook missing signature header');
                return false;
            }
            
            $elements = $this->parseSignatureHeader($signature);
            
            if (!isset($elements['t']) || !isset($elements[$algorithm === 'sha256' ? 'sha256' : 'sha1'])) {
                $this->logger->warning('GitHub webhook invalid signature format');
                return false;
            }
            
            $timestamp = (int)$elements['t'];
            $providedSignature = $elements[$algorithm === 'sha256' ? 'sha256' : 'sha1'];
            
            if (!$this->isTimestampValid($timestamp)) {
                $this->logger->warning('GitHub webhook timestamp too old', [
                    'timestamp' => $timestamp,
                    'tolerance' => $this->tolerance,
                ]);
                return false;
            }
            
            $expectedSignature = $this->computeSignature(
                $algorithm,
                $timestamp . '.' . $payload,
                $this->webhookSecret
            );
            
            if (!$this->timingSafeEquals($providedSignature, $expectedSignature)) {
                $this->logger->warning('GitHub webhook signature mismatch');
                return false;
            }
            
            $this->logger->info('GitHub webhook verified successfully');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('GitHub webhook verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function parseSignatureHeader(string $header): array
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
        } else {
            $parts = explode('=', $header, 2);
            if (count($parts) === 2) {
                $elements[$parts[0]] = $parts[1];
            }
        }
        
        return $elements;
    }

    private function isTimestampValid(int $timestamp): bool
    {
        $now = time();
        return abs($now - $timestamp) <= $this->tolerance;
    }

    private function computeSignature(string $algorithm, string $payload, string $secret): string
    {
        $algo = $algorithm === 'sha256' ? 'sha256' : 'sha1';
        return hash_hmac($algo, $payload, $secret);
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
