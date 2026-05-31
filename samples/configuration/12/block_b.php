<?php

declare(strict_types=1);

namespace App\Services\ExternalApi;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Psr\Log\LoggerInterface;

final class ThirdPartyIntegrationService
{
    private const API_RATE_LIMIT = 100;
    private const API_RATE_WINDOW = 60;
    private const API_BURST_CAPACITY = 20;
    private const API_BACKOFF_SECONDS = 30;
    private const API_THROTTLE_STRATEGY = 'token_bucket';

    private Client $httpClient;
    private array $tokenBucket = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $apiBaseUrl,
        private readonly string $apiKey
    ) {
        $this->httpClient = new Client([
            'base_uri' => $this->apiBaseUrl,
            'timeout' => 30,
        ]);
        $this->initializeTokenBucket();
    }

    private function initializeTokenBucket(): void
    {
        $this->tokenBucket = [
            'tokens' => self::API_RATE_LIMIT,
            'max_tokens' => self::API_RATE_LIMIT,
            'refill_rate' => self::API_RATE_LIMIT / self::API_RATE_WINDOW,
            'last_refill' => microtime(true),
            'burst_used' => 0,
        ];
    }

    public function fetchInventory(string $productId): array
    {
        $this->checkRateLimit();

        try {
            $response = $this->httpClient->get('/api/v2/inventory/' . $productId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $this->logger->info('Inventory fetched successfully', [
                'product_id' => $productId,
                'tokens_remaining' => $this->tokenBucket['tokens'],
            ]);

            return $data;
        } catch (TransferException $e) {
            $this->logger->error('Failed to fetch inventory', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'rate_limit' => self::API_RATE_LIMIT,
                'burst' => self::API_BURST_CAPACITY,
            ]);
            throw $e;
        }
    }

    public function syncOrders(array $orderIds): array
    {
        $this->checkRateLimit();

        try {
            $response = $this->httpClient->post('/api/v2/orders/sync', [
                'json' => ['order_ids' => $orderIds],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $this->logger->info('Orders synced', [
                'count' => count($orderIds),
                'tokens_remaining' => $this->tokenBucket['tokens'],
            ]);

            return $result;
        } catch (TransferException $e) {
            $this->logger->error('Failed to sync orders', [
                'order_count' => count($orderIds),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function checkRateLimit(): void
    {
        $now = microtime(true);
        $timePassed = $now - $this->tokenBucket['last_refill'];

        $refillAmount = $this->tokenBucket['refill_rate'] * $timePassed;
        $this->tokenBucket['tokens'] = min(
            $this->tokenBucket['max_tokens'],
            $this->tokenBucket['tokens'] + $refillAmount
        );
        $this->tokenBucket['last_refill'] = $now;

        $maxRequests = self::API_RATE_LIMIT;
        $burstCapacity = self::API_BURST_CAPACITY;
        $backoffSeconds = self::API_BACKOFF_SECONDS;

        $effectiveLimit = $maxRequests + $burstCapacity;

        if ($this->tokenBucket['tokens'] < 1) {
            $waitTime = (1 - $this->tokenBucket['tokens']) / $this->tokenBucket['refill_rate'];

            $this->logger->warning('API rate limit reached, backing off', [
                'wait_time' => $waitTime,
                'tokens' => $this->tokenBucket['tokens'],
                'backoff' => $backoffSeconds,
            ]);

            usleep((int) ($waitTime * 1000000));
            $this->tokenBucket['tokens'] = 0;
        }

        if ($this->tokenBucket['burst_used'] >= $burstCapacity) {
            $burstBackoff = $backoffSeconds * ($this->tokenBucket['burst_used'] - $burstCapacity + 1);

            $this->logger->info('Burst capacity exceeded, applying backoff', [
                'burst_used' => $this->tokenBucket['burst_used'],
                'backoff_time' => $burstBackoff,
            ]);

            sleep(min($burstBackoff, $backoffSeconds * 3));
        }

        $this->tokenBucket['tokens']--;
        $this->tokenBucket['burst_used']++;
    }
}
