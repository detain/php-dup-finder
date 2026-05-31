<?php

declare(strict_types=1);

namespace App\Services\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class ApiIntegrationClient
{
    private const REQUEST_TIMEOUT = 30;
    private const CONNECTION_TIMEOUT = 10;
    private const SOCKET_TIMEOUT = 25;
    private const MAX_RETRY_COUNT = 3;
    private const RETRY_WAIT_TIME = 500;
    private const RETRYABLE_HTTP_CODES = [408, 429, 500, 502, 503, 504];
    private const CONNECTION_POOL_MAX_SIZE = 25;
    private const CONNECTION_LIFETIME = 60;
    private const SSL_CERT_VERIFICATION = true;
    private const ENABLE_REDIRECTS = true;
    private const REDIRECT_LIMIT = 5;

    private Client $client;
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;
    private string $signatureVersion;
    private ?string $signature;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $baseUrl,
        string $apiKey,
        string $apiSecret,
        string $signatureVersion = 'v2'
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->signatureVersion = $signatureVersion;

        $this->client = $this->initializeClient();
    }

    private function initializeClient(): Client
    {
        return new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => self::CONNECTION_TIMEOUT,
            'read_timeout' => self::SOCKET_TIMEOUT,
            'http_errors' => true,
            'verify' => self::SSL_CERT_VERIFICATION,
            'pool_size' => self::CONNECTION_POOL_MAX_SIZE,
            'keep_alive' => self::CONNECTION_LIFETIME,
            'allow_redirects' => self::ENABLE_REDIRECTS ? [
                'max' => self::REDIRECT_LIMIT,
                'strict' => false,
                'track_redirects' => true,
            ] : false,
        ]);
    }

    public function call(string $method, string $endpoint, array $payload = [], array $headers = []): array
    {
        $requestHeaders = $this->buildHeaders($headers);

        $options = [
            'headers' => $requestHeaders,
        ];

        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $options['json'] = $payload;
        } elseif (in_array(strtoupper($method), ['GET', 'DELETE']) && !empty($payload)) {
            $options['query'] = $payload;
        }

        return $this->performRequest($method, $endpoint, $options);
    }

    private function buildHeaders(array $additionalHeaders): array
    {
        $timestamp = (string) time();
        $nonce = $this->generateNonce();

        $signature = $this->calculateSignature($timestamp, $nonce);
        $this->signature = $signature;

        return array_merge([
            'Authorization' => 'HMAC ' . $this->apiKey . ':' . $signature,
            'X-API-Key' => $this->apiKey,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature-Version' => $this->signatureVersion,
            'User-Agent' => 'ApiIntegrationClient/2.0',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $additionalHeaders);
    }

    private function calculateSignature(string $timestamp, string $nonce): string
    {
        $stringToSign = $timestamp . ':' . $nonce . ':' . $this->apiSecret;

        return base64_encode(hash_hmac('sha256', $stringToSign, $this->apiSecret, true));
    }

    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function performRequest(string $method, string $endpoint, array $options): array
    {
        $retryCount = 0;

        while ($retryCount < self::MAX_RETRY_COUNT) {
            try {
                $response = $this->client->request(strtoupper($method), $endpoint, $options);

                $this->logger->info('API call successful', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status_code' => $response->getStatusCode(),
                    'retries' => $retryCount,
                    'timeout' => self::REQUEST_TIMEOUT,
                    'connect_timeout' => self::CONNECTION_TIMEOUT,
                ]);

                return $this->processResponse($response->getBody()->getContents());
            } catch (TransferException $e) {
                $retryCount++;
                $statusCode = $this->extractStatusCode($e);

                $this->logger->warning('API call failed, retrying', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'retry' => $retryCount,
                    'max_retries' => self::MAX_RETRY_COUNT,
                    'status_code' => $statusCode,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_WAIT_TIME,
                ]);

                if (!$this->isRetryableError($statusCode, $e) || $retryCount >= self::MAX_RETRY_COUNT) {
                    throw $e;
                }

                usleep(self::RETRY_WAIT_TIME * 1000 * $retryCount);
            }
        }

        throw new \RuntimeException('API call failed after max retries');
    }

    private function isRetryableError(?int $statusCode, TransferException $e): bool
    {
        if ($statusCode === null) {
            return true;
        }

        if (in_array($statusCode, self::RETRYABLE_HTTP_CODES, true)) {
            return true;
        }

        $errorMessage = strtolower($e->getMessage());

        return str_contains($errorMessage, 'timeout') ||
               str_contains($errorMessage, 'connection');
    }

    private function extractStatusCode(TransferException $e): ?int
    {
        if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    private function processResponse(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode JSON response: ' . json_last_error_msg());
        }

        return $decoded;
    }

    public function setApiKey(string $key, string $secret): void
    {
        $this->apiKey = $key;
        $this->apiSecret = $secret;
        $this->client = $this->initializeClient();
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
