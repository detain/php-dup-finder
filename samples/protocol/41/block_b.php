<?php

declare(strict_types=1);

namespace App\Http\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class OAuthHttpClient
{
    private const DEFAULT_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;
    private const READ_TIMEOUT = 25;
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY = 500;
    private const RETRY_STATUS_CODES = [408, 429, 500, 502, 503, 504];
    private const CONNECTION_POOL_SIZE = 15;
    private const CONNECTION_KEEP_ALIVE = 45;
    private const SSL_VERIFY = true;
    private const ALLOW_REDIRECTS = true;
    private const MAX_REDIRECT_COUNT = 5;
    private const TOKEN_REFRESH_THRESHOLD = 300;

    private Client $client;
    private string $clientId;
    private string $clientSecret;
    private string $accessToken;
    private string $refreshToken;
    private ?int $tokenExpiresAt;
    private string $oauthBaseUrl;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $clientId,
        string $clientSecret,
        string $baseUrl,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->oauthBaseUrl = rtrim($baseUrl, '/');
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->tokenExpiresAt = time() + $expiresIn;

        $this->client = $this->buildClient();
    }

    private function buildClient(): Client
    {
        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            function ($retries, RequestInterface $request, ?ResponseInterface $response = null) {
                if ($retries >= self::MAX_RETRY_ATTEMPTS) {
                    return false;
                }

                if ($response && in_array($response->getStatusCode(), self::RETRY_STATUS_CODES)) {
                    return true;
                }

                return false;
            },
            function ($retries) {
                return $retries * self::RETRY_DELAY * 1000;
            }
        ));

        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            return $request->withHeader('Authorization', 'Bearer ' . $this->accessToken)
                         ->withHeader('User-Agent', 'OAuthHttpClient/2.0')
                         ->withHeader('Accept', 'application/json');
        }));

        return new Client([
            'base_uri' => $this->oauthBaseUrl,
            'timeout' => self::DEFAULT_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'read_timeout' => self::READ_TIMEOUT,
            'handler' => $stack,
            'http_errors' => true,
            'verify' => self::SSL_VERIFY,
            'pool_size' => self::CONNECTION_POOL_SIZE,
            'keep_alive' => self::CONNECTION_KEEP_ALIVE,
            'allow_redirects' => self::ALLOW_REDIRECTS ? [
                'max' => self::MAX_REDIRECT_COUNT,
                'strict' => false,
            ] : false,
        ]);
    }

    public function get(string $endpoint, array $queryParams = []): array
    {
        $this->ensureValidToken();

        $options = [];

        if (!empty($queryParams)) {
            $options['query'] = $queryParams;
        }

        return $this->executeRequest('GET', $endpoint, $options);
    }

    public function post(string $endpoint, array $data = [], array $options = []): array
    {
        $this->ensureValidToken();

        $options['json'] = $data;

        return $this->executeRequest('POST', $endpoint, $options);
    }

    public function put(string $endpoint, array $data = [], array $options = []): array
    {
        $this->ensureValidToken();

        $options['json'] = $data;

        return $this->executeRequest('PUT', $endpoint, $options);
    }

    public function delete(string $endpoint, array $options = []): array
    {
        $this->ensureValidToken();

        return $this->executeRequest('DELETE', $endpoint, $options);
    }

    private function executeRequest(string $method, string $endpoint, array $options): array
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                $response = $this->client->request($method, $endpoint, $options);

                $this->logger->info('OAuth HTTP request succeeded', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->getStatusCode(),
                    'attempts' => $attempts + 1,
                    'timeout' => self::DEFAULT_TIMEOUT,
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                ]);

                return $this->decodeResponse($response->getBody()->getContents());
            } catch (RequestException $e) {
                $attempts++;
                $statusCode = $e->getResponse()?->getStatusCode();

                $this->logger->warning('OAuth HTTP request failed', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRY_ATTEMPTS,
                    'status_code' => $statusCode,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if (!$this->isRetryable($statusCode) || $attempts >= self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }

                usleep(self::RETRY_DELAY * 1000 * $attempts);
            }
        }

        throw new \RuntimeException('Max retry attempts exceeded');
    }

    private function ensureValidToken(): void
    {
        if ($this->tokenExpiresAt === null || (time() + self::TOKEN_REFRESH_THRESHOLD) >= $this->tokenExpiresAt) {
            $this->refreshAccessToken();
        }
    }

    private function refreshAccessToken(): void
    {
        try {
            $client = new Client(['base_uri' => $this->oauthBaseUrl]);

            $response = $client->post('/oauth/token', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $data = $this->decodeResponse($response->getBody()->getContents());

            $this->accessToken = $data['access_token'];
            $this->refreshToken = $data['refresh_token'];
            $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600);

            $this->client = $this->buildClient();

            $this->logger->info('Access token refreshed successfully', [
                'expires_in' => $data['expires_in'] ?? 3600,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to refresh access token', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function isRetryable(?int $statusCode): bool
    {
        if ($statusCode === null) {
            return true;
        }

        return in_array($statusCode, self::RETRY_STATUS_CODES, true);
    }

    private function decodeResponse(string $body): array
    {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }
}
