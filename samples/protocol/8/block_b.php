<?php
declare(strict_types=1);

namespace Acme\Search\Algolia;

use Symfony\Component\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

final class AlgoliaBrowseClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $appId,
        private readonly string $apiKey
    ) {
    }

    public function browseAll(string $index, string $filters): array
    {
        $cursor = null;
        $all = [];
        $page = 0;
        $url = sprintf('https://%s-dsn.algolia.net/1/indexes/%s/browse', strtolower($this->appId), $index);
        do {
            $page++;
            if ($page > 200) {
                throw new \RuntimeException('Algolia pagination runaway');
            }
            $body = ['filters' => $filters, 'hitsPerPage' => 100];
            if ($cursor !== null) {
                $body['cursor'] = $cursor;
            }
            $resp = $this->http->request('POST', $url, [
                'headers' => [
                    'X-Algolia-Application-Id' => $this->appId,
                    'X-Algolia-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
                'timeout' => 30.0,
            ]);
            $status = $resp->getStatusCode();
            $raw = $resp->getContent(false);
            if ($status < 200 || $status >= 300) {
                $this->logger->error('Algolia browse failed', ['status' => $status, 'body' => $raw]);
                throw new \RuntimeException('Algolia HTTP ' . $status);
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            foreach ($decoded['hits'] ?? [] as $row) {
                $all[] = $row;
            }
            $cursor = $decoded['cursor'] ?? null;
        } while ($cursor !== null);
        return $all;
    }
}
