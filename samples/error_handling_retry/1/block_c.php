<?php
declare(strict_types=1);

namespace Inventory\Sync;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class InventorySyncService
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_BASE_DELAY = 150;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly InventoryCalculator $calculator
    ) {}

    public function syncProductQuantity(int $productId): SyncResult
    {
        $attemptNumber = 0;
        $lastError = null;

        while ($attemptNumber < self::MAX_ATTEMPTS) {
            try {
                $response = $this->httpClient->request(
                    'GET',
                    $this->constructInventoryUrl($productId),
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->fetchApiToken(),
                            'Content-Type' => 'application/json'
                        ],
                        'timeout' => 20
                    ]
                );

                if ($response->getStatusCode() === 429) {
                    $retryAfter = (int) $response->headers->get('Retry-After', 60);
                    $this->logger->info('Rate limited by inventory API', [
                        'retry_after' => $retryAfter
                    ]);
                    sleep($retryAfter);
                    continue;
                }

                $responseData = $response->toArray();
                $externalQuantity = $responseData['quantity'] ?? 0;

                // Update local inventory
                $updated = $this->calculator->updateLocalQuantity($productId, $externalQuantity);

                $this->logger->info('Inventory sync completed', [
                    'product_id' => $productId,
                    'external_qty' => $externalQuantity,
                    'updated' => $updated
                ]);

                return SyncResult::success($updated);

            } catch (TransportExceptionInterface $e) {
                $lastError = $e;
                $attemptNumber++;

                if ($attemptNumber < self::MAX_ATTEMPTS) {
                    $waitTime = self::RETRY_BASE_DELAY * (2 ** ($attemptNumber - 1));
                    $jitterAmount = random_int(0, (int)(self::RETRY_BASE_DELAY * 0.1 * $attemptNumber));

                    $this->logger->warning('Inventory sync attempt failed', [
                        'product_id' => $productId,
                        'attempt' => $attemptNumber,
                        'delay_ms' => $waitTime + $jitterAmount,
                        'error' => $e->getMessage()
                    ]);

                    usleep(($waitTime + $jitterAmount) * 1000);
                }
            }
        }

        // Log failure after exhausting retries
        $this->logger->error('Product inventory sync failed after maximum retries', [
            'product_id' => $productId,
            'total_attempts' => $attemptNumber,
            'final_error' => $lastError?->getMessage()
        ]);

        // Mark for manual review
        $this->entityManager->getRepository(Product::class)
            ->find($productId)
            ?->setSyncStatus('failed')
            ?->setSyncRetryCount($attemptNumber);

        $this->entityManager->flush();

        return SyncResult::failure($lastError);

    }

    private function constructInventoryUrl(int $productId): string
    {
        $baseUrl = $_ENV['INVENTORY_API_BASE'] ?? 'https://inventory.example.com/api/v1';
        return "{$baseUrl}/products/{$productId}/quantity";
    }

    private function fetchApiToken(): string
    {
        return $_ENV['INVENTORY_API_TOKEN'] ?? '';
    }
}
