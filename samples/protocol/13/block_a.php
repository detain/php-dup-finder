<?php
declare(strict_types=1);

namespace App\Auth\OAuth;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use App\Cache\CacheManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class GoogleOAuthProvider
{
    private Client $httpClient;
    private Connection $db;
    private LoggerInterface $logger;
    private CacheManager $cache;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private array $scopes = [
        'openid',
        'email',
        'profile',
    ];

    public function __construct(
        ConfigManager $config,
        Connection $db,
        LoggerInterface $logger,
        CacheManager $cache
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->clientId = $config->get('google.oauth.client_id');
        $this->clientSecret = $config->get('google.oauth.client_secret');
        $this->redirectUri = $config->get('google.oauth.redirect_uri');
        
        $this->httpClient = new Client([
            'base_uri' => 'https://oauth2.googleapis.com/',
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'access_type' => 'offline',
            'state' => $state,
            'prompt' => 'consent',
        ];
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        try {
            $response = $this->httpClient->post('token', [
                'form_params' => [
                    'code' => $code,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $this->redirectUri,
                    'grant_type' => 'authorization_code',
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $this->logger->info('Google OAuth token exchange successful');
            
            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'id_token' => $data['id_token'] ?? null,
                'expires_in' => $data['expires_in'],
                'token_type' => $data['token_type'] ?? 'Bearer',
                'scope' => $data['scope'] ?? implode(' ', $this->scopes),
            ];
            
        } catch (GuzzleException $e) {
            $this->logger->error('Google OAuth token exchange failed', [
                'error' => $e->getMessage(),
            ]);
            throw new OAuthException(
                'Failed to exchange code for tokens: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $cacheKey = 'google_token:' . md5($refreshToken);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }
        
        try {
            $response = $this->httpClient->post('token', [
                'form_params' => [
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'refresh_token',
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $tokenData = [
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'],
                'token_type' => $data['token_type'] ?? 'Bearer',
                'id_token' => $data['id_token'] ?? null,
            ];
            
            $this->cache->set(
                $cacheKey,
                $tokenData,
                ($data['expires_in'] ?? 3600) - 60
            );
            
            $this->logger->info('Google OAuth token refresh successful');
            
            return $tokenData;
            
        } catch (GuzzleException $e) {
            $this->logger->error('Google OAuth token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            throw new OAuthException(
                'Failed to refresh access token: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function revokeToken(string $token): bool
    {
        try {
            $response = $this->httpClient->post('revoke', [
                'form_params' => [
                    'token' => $token,
                ],
            ]);
            
            $this->logger->info('Google OAuth token revoked');
            
            return $response->getStatusCode() === 200;
            
        } catch (GuzzleException $e) {
            $this->logger->error('Google OAuth token revocation failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->get('https://www.googleapis.com/oauth2/v2/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('Google user info fetch failed', [
                'error' => $e->getMessage(),
            ]);
            throw new OAuthException(
                'Failed to get user info: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function storeToken(int $userId, array $tokenData): bool
    {
        $sql = "INSERT INTO user_oauth_tokens (user_id, provider, access_token, refresh_token,
                                            id_token, expires_at, token_type, scope, created_at)
                VALUES (:user_id, 'google', :access_token, :refresh_token,
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
            ':access_token' => $tokenData['access_token'],
            ':refresh_token' => $tokenData['refresh_token'] ?? null,
            ':id_token' => $tokenData['id_token'] ?? null,
            ':expires_at' => date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600)),
            ':token_type' => $tokenData['token_type'] ?? 'Bearer',
            ':scope' => $tokenData['scope'] ?? null,
        ]);
    }

    public function getValidAccessToken(int $userId): ?string
    {
        $sql = "SELECT access_token, refresh_token, expires_at 
                FROM user_oauth_tokens 
                WHERE user_id = :user_id AND provider = 'google'
                ORDER BY updated_at DESC
                LIMIT 1";
        
        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        
        $token = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$token) {
            return null;
        }
        
        $expiresAt = strtotime($token['expires_at']);
        
        if ($expiresAt > time() + 300) {
            return $token['access_token'];
        }
        
        if (empty($token['refresh_token'])) {
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
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
