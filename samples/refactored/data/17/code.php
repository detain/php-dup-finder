<?php
declare(strict_types=1);

namespace ApiClient\Shared;

final class HttpStatusCodes
{
    public const OK = 200;
    public const CREATED = 201;
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const TOO_MANY_REQUESTS = 429;
    public const SERVER_ERROR = 500;
    public const GATEWAY_TIMEOUT = 504;

    public static function isRetryable(int $statusCode): bool
    {
        return in_array($statusCode, [
            self::SERVER_ERROR,
            self::GATEWAY_TIMEOUT,
            self::TOO_MANY_REQUESTS,
        ], true);
    }

    public static function isClientError(int $statusCode): bool
    {
        return $statusCode >= 400 && $statusCode < 500;
    }
}

final class HttpTimeoutConfig
{
    public const CONNECT_TIMEOUT = 5;
    public const READ_TIMEOUT = 30;
    public const TOTAL_TIMEOUT = 45;
}

final class RetryConfig
{
    public const MAX_RETRIES = 3;
    public const RETRY_DELAY_MS = 500;
}

final class CircuitBreakerConfig
{
    public const THRESHOLD = 5;
    public const TIMEOUT_SECONDS = 60;
}

interface ApiClientInterface
{
    public function makeRequest(string $url): ApiResponse;
    public function isRetryableError(int $statusCode): bool;
    public function shouldOpenCircuit(int $failureCount): bool;
}

trait HttpClientLogic
{
    private string $baseUrl;
    private string $apiKey;
    private LoggerInterface $logger;

    protected function buildUrl(string $endpoint, array $queryParams): string
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    protected function makeHttpRequest(string $url): ApiResponse
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => HttpTimeoutConfig::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => HttpTimeoutConfig::READ_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ApiClient/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new ApiException('cURL error: ' . $error);
        }

        return new ApiResponse($statusCode, $body);
    }

    protected function handleResponseErrors(ApiResponse $response, string $context): void
    {
        if ($response->getStatusCode() === HttpStatusCodes::NOT_FOUND) {
            throw new ApiException($context . ': Resource not found');
        }

        if ($response->getStatusCode() === HttpStatusCodes::UNAUTHORIZED) {
            throw new ApiException($context . ': Invalid API key');
        }

        if ($response->getStatusCode() === HttpStatusCodes::TOO_MANY_REQUESTS) {
            throw new ApiException($context . ': Rate limit exceeded');
        }

        if ($response->getStatusCode() !== HttpStatusCodes::OK) {
            throw new ApiException($context . ': API error ' . $response->getStatusCode());
        }
    }
}
