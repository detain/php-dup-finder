<?php
declare(strict_types=1);

namespace App\Auth\OAuth;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use App\Cache\CacheManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

abstract class AbstractOAuthProvider
{
    protected Client $httpClient;
    protected Connection $db;
    protected LoggerInterface $logger;
    protected CacheManager $cache;
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected array $scopes = [];

    abstract protected function getProviderName(): string;
    abstract protected function getAuthorizationUrlBase(): string;
    abstract protected function getTokenEndpoint(): string;
    abstract protected function getUserInfoEndpoint(): string;
    abstract protected function getRefreshTokenGrantType(): string;

    public function __construct(
        ConfigManager $config,
        Connection $db,
        LoggerInterface $logger,
        CacheManager $cache
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->clientId = $config->get($this->getProviderName() . '.client_id');
        $this->clientSecret = $config->get($this->getProviderName() . '.client_secret');
        $this->redirectUri = $config->get($this->getProviderName() . '.oauth.redirect_uri');
        
        $this->httpClient = new Client([
            'base_uri' => $this->getBaseUri(),
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    protected function getBaseUri(): string
    {
        return 'https://api.' . $this->getProviderName() . '.com/';
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $this->scopes),
            'response_type' => 'code',
            'state' => $state,
        ];
        
        return $this->getAuthorizationUrlBase() . '?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        try {
            $response = $this->httpClient->post($this->getTokenEndpoint(), [
                'form_params' => $this->buildTokenExchangeParams($code),
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $this->logger->info($this->getProviderName() . ' OAuth token exchange successful');
            
            return $this->normalizeTokenResponse($data);
            
        } catch (GuzzleException $e) {
            $this->logger->error($this->getProviderName() . ' OAuth token exchange failed', [
                'error' => $e->getMessage(),
            ]);
            throw new OAuthException(
                'Failed to exchange code for tokens: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function buildTokenExchangeParams(string $code): array
    {
        return [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];
    }

    protected function normalizeTokenResponse(array $data): array
    {
        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'id_token' => $data['id_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? null,
            'token_type' => $data['token_type'] ?? 'Bearer',
            'scope' => $data['scope'] ?? implode(' ', $this->scopes),
        ];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        if (!$this->supportsTokenRefresh()) {
            throw new OAuthException(
                $this->getProviderName() . ' OAuth does not support token refresh',
                400
            );
        }
        
        $cacheKey = $this->getProviderName() . '_token:' . md5($refreshToken);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }
        
        try {
            $response = $this->httpClient->post($this->getTokenEndpoint(), [
                'form_params' => $this->buildRefreshParams($refreshToken),
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            $tokenData = $this->normalizeTokenResponse($data);
            
            if (isset($data['expires_in'])) {
                $this->cache->set($cacheKey, $tokenData, $data['expires_in'] - 60);
            }
            
            $this->logger->info($this->getProviderName() . ' OAuth token refresh successful');
            
            return $tokenData;
            
        } catch (GuzzleException $e) {
            $this->logger->error($this->getProviderName() . ' OAuth token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            throw new OAuthException(
                'Failed to refresh access token: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function buildRefreshParams(string $refreshToken): array
    {
        return [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => $this->getRefreshTokenGrantType(),
        ];
    }

    protected function supportsTokenRefresh(): bool
    {
        return true;
    }

    public function getUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->get($this->getUserInfoEndpoint(), [
                'headers' => $this->getUserInfoHeaders($accessToken),
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error($this->getProviderName() . ' user info fetch failed', [
                'error' => $e->getMessage(),
            ]);
            throw new OAuthException(
                'Failed to get user info: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function getUserInfoHeaders(string $accessToken): array
    {
        return [
            'Authorization' => 'Bearer ' . $accessToken,
        ];
    }

    public function storeToken(int $userId, array $tokenData): bool
    {
        $sql = "INSERT INTO user_oauth_tokens (user_id, provider, access_token, refresh_token,
                                            id_token, expires_at, token_type, scope, created_at)
                VALUES (:user_id, :provider, :access_token, :refresh_token,
                        :id_token, :expires_at, :token_type, :scope, NOW())
                ON DUPLICATE KEY UPDATE
                    access_token = VALUES(access_token),
                    refresh_token = VALUES(refresh_token),
                    id_token = VALUES(id_token),
                    expires_at = VALUES(expires_at),
                    token_type = VALUES(token_type),
                    scope = VALUES(scope),
                    updated_at = NOW()";
        
        $stmt = $this->db->getPdo()->prepare($sql);
        
        return $stmt->execute([
            ':user_id' => $userId,
            ':provider' => $this->getProviderName(),
            ':access_token' => $tokenData['access_token'],
            ':refresh_token' => $tokenData['refresh_token'] ?? null,
            ':id_token' => $tokenData['id_token'] ?? null,
            ':expires_at' => isset($tokenData['expires_in']) 
                ? date('Y-m-d H:i:s', time() + $tokenData['expires_in'])
                : null,
            ':token_type' => $tokenData['token_type'] ?? 'Bearer',
            ':scope' => $tokenData['scope'] ?? null,
        ]);
    }

    public function getValidAccessToken(int $userId): ?string
    {
        $sql = "SELECT access_token, refresh_token, expires_at 
                FROM user_oauth_tokens 
                WHERE user_id = :user_id AND provider = :provider
                ORDER BY updated_at DESC
                LIMIT 1";
        
        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':provider' => $this->getProviderName(),
        ]);
        
        $token = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$token) {
            return null;
        }
        
        if ($token['expires_at'] && strtotime($token['expires_at']) > time() + 300) {
            return $token['access_token'];
        }
        
        if (empty($token['refresh_token']) || !$this->supportsTokenRefresh()) {
            return null;
        }
        
        try {
            $newTokenData = $this->refreshAccessToken($token['refresh_token']);
            $newTokenData['refresh_token'] = $token['refresh_token'];
            $this->storeToken($userId, $newTokenData);
            
            return $newTokenData['access_token'];
        } catch (OAuthException $e) {
            $this->logger->warning('Failed to refresh token for user', [
                'user_id' => $userId,
                'provider' => $this->getProviderName(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
