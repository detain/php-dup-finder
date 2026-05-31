<?php
declare(strict_types=1);

namespace Acme\Http\OAuth2;

use Symfony\Component\HttpClient\HttpClientInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final class OAuth2ClientCredentialsClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $cacheKey
    ) {
    }

    public function request(string $method, string $url, array $body): array
    {
        $token = $this->token(false);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $resp = $this->http->request($method, $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
                'timeout' => 20.0,
            ]);
            $status = $resp->getStatusCode();
            if ($status === 401 && $attempt === 1) {
                $token = $this->token(true);
                continue;
            }
            $raw = $resp->getContent(false);
            if ($status >= 200 && $status < 300) {
                return $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
            $this->logger->error($this->cacheKey . ' failed', ['status' => $status, 'body' => $raw]);
            throw new \RuntimeException($this->cacheKey . ' HTTP ' . $status);
        }
        throw new \RuntimeException($this->cacheKey . ' auth retry exhausted');
    }

    private function token(bool $force): string
    {
        $item = $this->cache->getItem($this->cacheKey);
        if (!$force && $item->isHit()) {
            return (string) $item->get();
        }
        $resp = $this->http->request('POST', $this->tokenUrl, [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
            'timeout' => 10.0,
        ]);
        $payload = json_decode($resp->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $item->set($payload['access_token']);
        $item->expiresAfter(((int) ($payload['expires_in'] ?? 3600)) - 30);
        $this->cache->save($item);
        return (string) $payload['access_token'];
    }
}
