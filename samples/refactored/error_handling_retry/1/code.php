<?php
declare(strict_types=1);

namespace Billing\Core\Http;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class RetryableHttpClient
{
    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_BASE_DELAY_MS = 100;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function requestWithRetry(
        string $method,
        string $url,
        array $options = [],
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS
    ): Response {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                return $this->client->request($method, $url, $options);
            } catch (TransportExceptionInterface $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt >= $maxRetries) {
                    break;
                }

                $delay = $baseDelayMs * (2 ** ($attempt - 1));
                $jitter = random_int(0, (int)($baseDelayMs * 0.1 * $attempt));

                $this->logger?->warning('HTTP request failed, retrying', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay + $jitter,
                    'error' => $e->getMessage()
                ]);

                usleep(($delay + $jitter) * 1000);
            }
        }

        throw new RetryExhaustedException(
            "Failed after {$maxRetries} attempts",
            0,
            $lastException
        );
    }
}
