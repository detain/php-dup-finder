<?php

declare(strict_types=1);

namespace App\Http\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class AuthenticatedHttpClient
{
    private const REQUEST_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;
    private const READ_TIMEOUT = 25;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 500;
    private const RETRY_ON_STATUS = [408, 429, 500, 502, 503, 504];
    private const POOL_SIZE = 20;
    private const KEEP_ALIVE = 60;
    private const VERIFY_SSL = true;
    private const FOLLOW_REDIRECTS = true;
    private const MAX_REDIRECTS = 5;

    private Client $httpClient;
    private string $baseUri;
    private string $accessToken;
    private string $tokenType;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $baseUri,
        string $accessToken,
        string $tokenType = 'Bearer'
    ) {
        $this->baseUri = rtrim($baseUri, '/');
        $this->accessToken = $accessToken;
        $this->tokenType = $tokenType;
        $this->httpClient = $this->createClient();
    }

    private function createClient(): Client
    {
        return new Client([
            'base_uri' => $this->baseUri,
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'read_timeout' => self::READ_TIMEOUT,
            'http_errors' => true,
            'verify' => self::VERIFY_SSL,
            'pool_size' => self::POOL_SIZE,
            'keep_alive' => self::KEEP_ALIVE,
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => false,
                'track_redirects' => true,
            ],
        ]);
    }

    public function get(string $uri, array $options = []): array
    {
        return $this->request('GET', $uri, $options);
    }

    public function post(string $uri, array $options = []): array
    {
        return $this->request('POST', $uri, $options);
    }

    public function put(string $uri, array $options = []): array
    {
        return $this->request('PUT', $uri, $options);
    }

    public function delete(string $uri, array $options = []): array
    {
        return $this->request('DELETE', $uri, $options);
    }

    public function patch(string $uri, array $options = []): array
    {
        return $this->request('PATCH', $uri, $options);
    }

    private function request(string $method, string $uri, array $options): array
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $options['headers'] = array_merge(
                    $options['headers'] ?? [],
                    $this->getAuthHeaders()
                );

                $options['headers']['User-Agent'] = 'AuthenticatedHttpClient/1.0';
                $options['headers']['Accept'] = 'application/json';

                $response = $this->httpClient->request($method, $uri, $options);

                $this->logger->debug('HTTP request completed', [
                    'method' => $method,
                    'uri' => $uri,
                    'status' => $response->getStatusCode(),
                    'attempts' => $attempts + 1,
                    'timeout' => self::REQUEST_TIMEOUT,
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                ]);

                return $this->parseResponse($response);
            } catch (RequestException $e) {
                $attempts++;
                $statusCode = $e->getResponse()?->getStatusCode();

                $this->logger->warning('HTTP request failed', [
                    'method' => $method,
                    'uri' => $uri,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'status_code' => $statusCode,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if (!$this->shouldRetry($statusCode, $attempts)) {
                    throw $e;
                }

                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                    $this->httpClient = $this->createClient();
                }
            } catch (GuzzleException $e) {
                $attempts++;

                $this->logger->error('Guzzle exception during request', [
                    'method' => $method,
                    'uri' => $uri,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= self::MAX_RETRIES) {
                    throw $e;
                }

                usleep(self::RETRY_DELAY * 1000 * $attempts);
            }
        }

        throw new \RuntimeException('Max retries exceeded for HTTP request');
    }

    private function getAuthHeaders(): array
    {
        return [
            'Authorization' => $this->tokenType . ' ' . $this->accessToken,
        ];
    }

    private function shouldRetry(?int $statusCode, int $attempts): bool
    {
        if ($attempts >= self::MAX_RETRIES) {
            return false;
        }

        if ($statusCode === null) {
            return true;
        }

        return in_array($statusCode, self::RETRY_ON_STATUS, true);
    }

    private function parseResponse(ResponseInterface $response): array
    {
        $body = $response->getBody()->getContents();

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }
}
