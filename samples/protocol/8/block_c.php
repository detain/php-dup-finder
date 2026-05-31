<?php
declare(strict_types=1);

namespace Acme\Wiki\Confluence;

use Symfony\Component\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

final class ConfluencePageClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $site,
        private readonly string $token
    ) {
    }

    public function listAllPages(string $spaceKey): array
    {
        $cursor = null;
        $all = [];
        $page = 0;
        $url = 'https://' . $this->site . '.atlassian.net/wiki/api/v2/pages';
        do {
            $page++;
            if ($page > 200) {
                throw new \RuntimeException('Confluence pagination runaway');
            }
            $query = ['space-id' => $spaceKey, 'limit' => 100];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $resp = $this->http->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
                'timeout' => 30.0,
            ]);
            $status = $resp->getStatusCode();
            $raw = $resp->getContent(false);
            if ($status < 200 || $status >= 300) {
                $this->logger->error('Confluence list failed', ['status' => $status, 'body' => $raw]);
                throw new \RuntimeException('Confluence HTTP ' . $status);
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            foreach ($decoded['results'] ?? [] as $row) {
                $all[] = $row;
            }
            $cursor = $decoded['_links']['next-cursor'] ?? null;
        } while ($cursor !== null);
        return $all;
    }
}
