<?php
declare(strict_types=1);

namespace Acme\Crm\HubSpot;

use Symfony\Component\HttpClient\HttpClientInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final class HubSpotDealClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $clientId,
        private readonly string $clientSecret
    ) {
    }

    public function createDeal(array $properties): array
    {
        $token = $this->token(false);
        $attempts = 0;
        while ($attempts < 2) {
            $attempts++;
            $resp = $this->http->request('POST', 'https://api.hubapi.com/crm/v3/objects/deals', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode(['properties' => $properties], JSON_THROW_ON_ERROR),
                'timeout' => 20.0,
            ]);
            $status = $resp->getStatusCode();
            if ($status === 401 && $attempts === 1) {
                $token = $this->token(true);
                continue;
            }
            $body = $resp->getContent(false);
            if ($status >= 200 && $status < 300) {
                return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            }
            $this->logger->error('HubSpot deal failed', ['status' => $status, 'body' => $body]);
            throw new \RuntimeException('HubSpot HTTP ' . $status);
        }
        throw new \RuntimeException('HubSpot auth retry exhausted');
    }

    private function token(bool $force): string
    {
        $item = $this->cache->getItem('oauth2.hubspot');
        if (!$force && $item->isHit()) {
            return (string) $item->get();
        }
        $resp = $this->http->request('POST', 'https://api.hubapi.com/oauth/v1/token', [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
            'timeout' => 10.0,
        ]);
        $payload = json_decode($resp->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $item->set($payload['access_token']);
        $item->expiresAfter(((int) ($payload['expires_in'] ?? 1800)) - 30);
        $this->cache->save($item);
        return (string) $payload['access_token'];
    }
}
