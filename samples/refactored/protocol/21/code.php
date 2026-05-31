<?php
declare(strict_types=1);

namespace App\Services\OAuth;

use App\Configuration\ConfigManager;
use App\Logging\LoggerInterface;
use App\Exceptions\AuthenticationException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OAuthTokenManager
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;
    private int $retryCount = 0;

    public function __construct(
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        LoggerInterface $logger,
        int $maxRetries = 3,
        ?HttpClientInterface $httpClient = null
    ) {
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    public function getAccessToken(): string
    {
        if ($this->isTokenExpired() && $this->accessToken !== null) {
            $this->refreshToken();
        }

        if ($this->accessToken === null) {
            $this->authenticate();
        }

        return $this->accessToken;
    }

    public function requestWithAuth(string $method, string $url, array $data = []): array
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
                    $this->refreshToken();
                    return $this->requestWithAuth($method, $url, $data);
                }
                throw new AuthenticationException("Authentication failed after {$this->maxRetries} retries");
            }

            $this->retryCount = 0;
            return $response->toArray();

        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('OAuth request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function authenticate(): void
    {
        $response = $this->httpClient->request('POST', $this->tokenUrl, [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        $data = $response->toArray();
        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600);
        
        $this->logger->info('OAuth token authenticated');
    }

    private function refreshToken(): void
    {
        $response = $this->httpClient->request('POST', $this->tokenUrl, [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        $data = $response->toArray();
        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600);
        
        $this->logger->info('OAuth token refreshed', [
            'retry_count' => $this->retryCount,
        ]);
    }

    private function isTokenExpired(): bool
    {
        return $this->tokenExpiresAt !== null && time() >= ($this->tokenExpiresAt - 60);
    }
}
