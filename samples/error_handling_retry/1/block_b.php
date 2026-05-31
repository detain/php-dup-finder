<?php
declare(strict_types=1);

namespace Logistics\Shipping;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

final class ShippingRateClient
{
    private const MAX_RETRY_ATTEMPTS = 3;
    private const INITIAL_BACKOFF_MS = 200;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Connection $database,
        private readonly LoggerInterface $logger
    ) {}

    public function getRates(ShipmentRequest $request): RatesResult
    {
        $retryCount = 0;
        $exception = null;

        while ($retryCount < self::MAX_RETRY_ATTEMPTS) {
            try {
                $response = $this->client->request('POST', $this->buildRatesUrl(), [
                    'headers' => [
                        'X-Shipping-API-Key' => $this->getApiKey(),
                        'Accept' => 'application/json'
                    ],
                    'json' => $request->toArray(),
                    'timeout' => 45
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 500) {
                    throw new ShippingApiException(
                        "Shipping API returned error: {$statusCode}",
                        $statusCode
                    );
                }

                if ($statusCode >= 400) {
                    $this->logger->warning('Shipping API rejected request', [
                        'status' => $statusCode,
                        'response' => $response->getContent(false)
                    ]);
                    return RatesResult::clientError($statusCode);
                }

                $rates = $response->toArray()['rates'] ?? [];
                return RatesResult::success($rates);

            } catch (TransportExceptionInterface $e) {
                $exception = $e;
                $retryCount++;

                if ($retryCount < self::MAX_RETRY_ATTEMPTS) {
                    $backoffDelay = self::INITIAL_BACKOFF_MS * (2 ** ($retryCount - 1));
                    $randomJitter = random_int(0, (int)(self::INITIAL_BACKOFF_MS * 0.15 * $retryCount));

                    $this->logger->notice('Shipping API request failed, retrying', [
                        'attempt' => $retryCount,
                        'delay_ms' => $backoffDelay + $randomJitter,
                        'error' => $e->getMessage()
                    ]);

                    usleep(($backoffDelay + $randomJitter) * 1000);
                }
            } catch (HttpExceptionInterface $e) {
                $this->logger->error('Shipping API HTTP error', [
                    'status' => $e->getResponse()->getStatusCode(),
                    'message' => $e->getMessage()
                ]);
                return RatesResult::error($e->getMessage());
            }
        }

        $this->logger->error('Shipping rates request failed after all retries', [
            'request' => $request->toArray(),
            'total_attempts' => $retryCount,
            'last_error' => $exception?->getMessage()
        ]);

        return RatesResult::maxRetriesExceeded($exception);

    }

    private function buildRatesUrl(): string
    {
        return ($_ENV['SHIPPING_ENV'] ?? 'production') === 'sandbox'
            ? 'https://sandbox.shipping-api.example.com/v1/rates'
            : 'https://api.shipping-api.example.com/v1/rates';
    }

    private function getApiKey(): string
    {
        return $_ENV['SHIPPING_API_KEY'] ?? '';
    }
}
