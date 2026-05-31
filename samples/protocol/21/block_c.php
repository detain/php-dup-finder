<?php
declare(strict_types=1);

namespace App\Services\Analytics;

use App\Configuration\ConfigManager;
use App\Logging\LoggerInterface;
use App\Exceptions\AuthenticationException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnalyticsApiClient
{
    private HttpClientInterface $httpClient;
    private ConfigManager $config;
    private LoggerInterface $logger;
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;
    private string $refreshToken;
    private int $retryCount = 0;
    private int $maxRetries = 3;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = HttpClient::create();
        $this->refreshToken = $config->get('analytics.oauth_refresh_token');
    }

    public function getAccessToken(): string
    {
        if ($this->isTokenExpired() && $this->accessToken !== null) {
            $this->refreshAccessToken();
        }

        if ($this->accessToken === null) {
            $this->authenticate();
        }

        return $this->accessToken;
    }

    public function request(string $method, string $url, array $data = []): array
    {
        $token = $this->getAccessToken();
        
        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]);

            if ($response->getStatusCode() === 401) {
                if ($this->retryCount < $this->maxRetries) {
                    $this->retryCount++;
                    $this->refreshAccessToken();
                    return $this->request($method, $url, $data);
                }
                throw new AuthenticationException('Analytics API authentication failed after retries');
            }

            $this->retryCount = 0;
            return $response->toArray();

        } catch (\Exception $e) {
            $this->logger->error('Analytics API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function authenticate(): void
    {
        $clientId = $this->config->get('analytics.oauth_client_id');
        $clientSecret = $this->config->get('analytics.oauth_client_secret');
        
        $response = $this->httpClient->request('POST', $this->config->get('analytics.oauth_token_url'), [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ],
        ]);

        $data = $response->toArray();
        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600);
        
        $this->logger->info('Analytics API authenticated');
    }

    private function refreshAccessToken(): void
    {
        $clientId = $this->config->get('analytics.oauth_client_id');
        $clientSecret = $this->config->get('analytics.oauth_client_secret');
        
        $response = $this->httpClient->request('POST', $this->config->get('analytics.oauth_token_url'), [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ],
        ]);

        $data = $response->toArray();
        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600);
        
        $this->logger->info('Analytics API token refreshed', [
            'retry_count' => $this->retryCount,
        ]);
    }

    private function isTokenExpired(): bool
    {
        if ($this->tokenExpiresAt === null) {
            return true;
        }
        
        return time() >= ($this->tokenExpiresAt - 60);
    }
}
