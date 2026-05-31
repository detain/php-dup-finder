<?php

declare(strict_types=1);

namespace App\Infrastructure\ExternalIntegrations;

use App\Infrastructure\Http\HttpClientInterface;

/**
 * Base integration service with shared HTTP client configuration.
 * Centralizes HttpClientInterface injection.
 */
abstract class BaseApiIntegration
{
    protected const BASE_URL = '';
    protected const TIMEOUT_SECONDS = 30;

    protected HttpClientInterface $httpClient;
    protected string $apiKey;

    public function __construct(HttpClientInterface $httpClient, string $apiKey)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
    }

    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        $response = $this->httpClient->request($method, self::BASE_URL . $endpoint, [
            'headers' => $this->getDefaultHeaders(),
            'json' => $data,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if (!$response->isSuccessful()) {
            throw new ApiException("API request failed: {$response->getErrorMessage()}");
        }

        return $response->getJson();
    }
}
