<?php

declare(strict_types=1);

namespace App\Http\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class PaymentGatewayClient
{
    private const REQUEST_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 200;
    private const POOL_CONNECTIONS = 20;
    private const KEEP_ALIVE = 60;

    private Client $httpClient;
    private readonly string $baseUrl;
    private readonly string $apiKey;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $gatewayUrl = 'https://api.payments.example.com',
        string $apiKey = ''
    ) {
        $this->baseUrl = rtrim($gatewayUrl, '/');
        $this->apiKey = $apiKey;
        $this->httpClient = $this->createClient();
    }

    public function charge(string $customerId, float $amount, string $currency): array
    {
        $payload = [
            'customer_id' => $customerId,
            'amount' => $amount * 100,
            'currency' => strtoupper($currency),
            'timestamp' => time(),
        ];

        return $this->post('/v1/charges', $payload);
    }

    public function refund(string $chargeId, ?float $amount = null): array
    {
        $payload = ['charge_id' => $chargeId];

        if ($amount !== null) {
            $payload['amount'] = (int) ($amount * 100);
        }

        return $this->post('/v1/refunds', $payload);
    }

    public function getCharge(string $chargeId): array
    {
        return $this->get('/v1/charges/' . $chargeId);
    }

    private function get(string $endpoint): array
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $response = $this->httpClient->get($this->baseUrl . $endpoint);
                return $this->parseResponse($response);
            } catch (GuzzleException $e) {
                $attempts++;
                $this->logger->error('Payment API GET request failed', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= self::MAX_RETRIES) {
                    throw $e;
                }

                usleep(self::RETRY_DELAY * 1000 * $attempts);
                $this->httpClient = $this->createClient();
            }
        }

        throw new \RuntimeException('Max retries exceeded');
    }

    private function post(string $endpoint, array $data): array
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $response = $this->httpClient->post(
                    $this->baseUrl . $endpoint,
                    ['json' => $data]
                );

                return $this->parseResponse($response);
            } catch (GuzzleException $e) {
                $attempts++;
                $this->logger->error('Payment API POST request failed', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= self::MAX_RETRIES) {
                    throw $e;
                }

                usleep(self::RETRY_DELAY * 1000 * $attempts);
                $this->httpClient = $this->createClient();
            }
        }

        throw new \RuntimeException('Max retries exceeded');
    }

    private function createClient(): Client
    {
        $handlerStack = HandlerStack::create();

        $retryMiddleware = Middleware::retry(
            function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?\Exception $e = null
            ) use (&$retryDelay) {
                if ($retries >= self::MAX_RETRIES) {
                    return false;
                }

                $delay = self::RETRY_DELAY * $retries;
                usleep($delay * 1000);

                $this->logger->debug('Retrying payment request', [
                    'retry' => $retries + 1,
                    'max_retries' => self::MAX_RETRIES,
                    'delay_ms' => $delay,
                ]);

                return true;
            },
            function (int $retries) {
                return $retries * self::RETRY_DELAY;
            }
        );

        $handlerStack->push($retryMiddleware);

        $handlerStack->push(Middleware::mapRequest(function (RequestInterface $request) {
            return $request
                ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('User-Agent', 'PaymentGatewayClient/2.0');
        }));

        return new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'handler' => $handlerStack,
            'pool_size' => self::POOL_CONNECTIONS,
            'keep_alive' => self::KEEP_ALIVE,
        ]);
    }

    private function parseResponse(ResponseInterface $response): array
    {
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to parse payment response', [
                'error' => json_last_error_msg(),
                'body' => substr($body, 0, 500),
            ]);
            throw new \RuntimeException('Invalid JSON response from payment gateway');
        }

        return $data;
    }
}
