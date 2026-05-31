<?php
declare(strict_types=1);

namespace App\Auth\OAuth;

use App\Database\Connection;
use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use App\Cache\CacheManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class GitHubOAuthProvider
{
    private Client $httpClient;
    private Connection $db;
    private LoggerInterface $logger;
    private CacheManager $cache;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private array $scopes = ['read:user', 'user:email'];

    public function __construct(
        ConfigManager $config,
        Connection $db,
        LoggerInterface $logger,
        CacheManager $cache
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->clientId = $config->get('github.client_id');
        $this->clientSecret = $config->get('github.client_secret');
        $this->redirectUri = $config->get('github.oauth.redirect_uri');
        
        $this->httpClient = new Client([
            'base_uri' => 'https://github.com/',
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
        ];
        
        return 'https://github.com/login/oauth/authorize?' . http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        try {
            $response = $this->httpClient->post('login/oauth/access_token', [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $this->redirectUri,
                    'code' => $code,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $this->logger->info('GitHub OAuth token exchange successful');
            
            return [
                'access_token' => $data['access_token'],
                'refresh_token' => null,
                'expires_in' => null,
                'token_type' => $data['token_type'] ?? 'bearer',
                'scope' => $data['scope'] ?? implode(' ', $this->scopes),
            ];
            
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub OAuth token exchange failed', [
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
        throw new OAuthException(
            'GitHub OAuth does not support token refresh',
            400
        );
    }

    public function revokeToken(string $token): bool
    {
        try {
            $response = $this->httpClient->delete('applications/' . $this->clientId . '/tokens/' . $token, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                ],
            ]);
            
            $this->logger->info('GitHub OAuth token revoked');
            
            return $response->getStatusCode() === 204;
            
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub OAuth token revocation failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->get('api/v3/user', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/vnd.github+json',
                ],
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub user info fetch failed', [
                'error' => $e->getMessage(),
            ]);
            throw new OAuthException(
                'Failed to get user info: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function getUserEmails(string $accessToken): array
    {
        try {
            $response = $this->httpClient->get('api/v3/user/emails', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/vnd.github+json',
                ],
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (GuzzleException $e) {
            $this->logger->error('GitHub user emails fetch failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function storeToken(int $userId, array $tokenData): bool
    {
        $sql = "INSERT INTO user_oauth_tokens (user_id, provider, access_token, refresh_token,
                                            id_token, expires_at, token_type, scope, created_at)
                VALUES (:user_id, 'github', :access_token, :refresh_token,
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
            ':expires_at' => null,
            ':token_type' => $tokenData['token_type'] ?? 'bearer',
            ':scope' => $tokenData['scope'] ?? null,
        ]);
    }

    public function getValidAccessToken(int $userId): ?string
    {
        $sql = "SELECT access_token, refresh_token, expires_at 
                FROM user_oauth_tokens 
                WHERE user_id = :user_id AND provider = 'github'
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
        
        return $token['access_token'];
    }
}
