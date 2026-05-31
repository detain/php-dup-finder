<?php
declare(strict_types=1);

namespace App\Auth\OAuth;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use App\Cache\CacheManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class FacebookOAuthProvider
{
    private Client $httpClient;
    private Connection $db;
    private LoggerInterface $logger;
    private CacheManager $cache;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private array $scopes = ['email', 'public_profile'];

    public function __construct(
        ConfigManager $config,
        Connection $db,
        LoggerInterface $logger,
        CacheManager $cache
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->clientId = $config->get('facebook.app_id');
        $this->clientSecret = $config->get('facebook.app_secret');
        $this->redirectUri = $config->get('facebook.oauth.redirect_uri');
        
        $this->httpClient = new Client([
            'base_uri' => 'https://graph.facebook.com/v18.0/',
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(',', $this->scopes),
            'response_type' => 'code',
            'state' => $state,
        ];
        
        return 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        try {
            $response = $this->httpClient->get('oauth/access_token', [
                'query' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $this->redirectUri,
                    'code' => $code,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $this->logger->info('Facebook OAuth token exchange successful');
            
            return [
                'access_token' => $data['access_token'],
                'refresh_token' => null,
                'expires_in' => $data['expires_in'] ?? null,
                'token_type' => 'bearer',
                'scope' => $data['scope'] ?? implode(',', $this->scopes),
            ];
            
        } catch (GuzzleException $e) {
            $this->logger->error('Facebook OAuth token exchange failed', [
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
        $cacheKey = 'facebook_token:' . md5($refreshToken);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }
        
        try {
            $response = $this->httpClient->get('oauth/access_token', [
                'query' => [
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'fb_exchange_token' => $refreshToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $tokenData = [
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'] ?? 5184000,
                'token_type' => 'bearer',
            ];
            
            if (isset($data['expires_in'])) {
                $this->cache->set($cacheKey, $tokenData, $data['expires_in'] - 60);
            }
            
            $this->logger->info('Facebook OAuth token refresh successful');
            
            return $tokenData;
            
        } catch (GuzzleException $e) {
            $this->logger->error('Facebook OAuth token refresh failed', [
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
            $response = $this->httpClient->delete('me/permissions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);
            
            $this->logger->info('Facebook OAuth token revoked');
            
            return $response->getStatusCode() === 200;
            
        } catch (GuzzleException $e) {
            $this->logger->error('Facebook OAuth token revocation failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->get('me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'fields' => 'id,name,email,picture,first_name,last_name',
                ],
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('Facebook user info fetch failed', [
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
                VALUES (:user_id, 'facebook', :access_token, :refresh_token,
                        NULL, :expires_at, :token_type, :scope, NOW())
                ON DUPLICATE KEY UPDATE
                    access_token = VALUES(access_token),
                    refresh_token = VALUES(refresh_token),
                    expires_at = VALUES(expires_at),
                    token_type = VALUES(token_type),
                    scope = VALUES(scope),
                    updated_at = NOW()";
        
        $stmt = $this->db->getPdo()->prepare($sql);
        
        return $stmt->execute([
            ':user_id' => $userId,
            ':access_token' => $tokenData['access_token'],
            ':refresh_token' => $tokenData['refresh_token'] ?? null,
            ':expires_at' => isset($tokenData['expires_in']) 
                ? date('Y-m-d H:i:s', time() + $tokenData['expires_in'])
                : null,
            ':token_type' => $tokenData['token_type'] ?? 'bearer',
            ':scope' => $tokenData['scope'] ?? null,
        ]);
    }

    public function getValidAccessToken(int $userId): ?string
    {
        $sql = "SELECT access_token, refresh_token, expires_at 
                FROM user_oauth_tokens 
                WHERE user_id = :user_id AND provider = 'facebook'
                ORDER BY updated_at DESC
                LIMIT 1";
        
        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        
        $token = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$token) {
            return null;
        }
        
        if ($token['expires_at'] && strtotime($token['expires_at']) > time() + 300) {
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
