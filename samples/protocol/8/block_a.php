<?php
declare(strict_types=1);

namespace Acme\Search\Notion;

use Symfony\Component\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

final class NotionSearchClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey
    ) {
    }

    public function searchAll(string $query): array
    {
        $cursor = null;
        $all = [];
        $page = 0;
        do {
            $page++;
            if ($page > 200) {
                throw new \RuntimeException('Notion pagination runaway');
            }
            $body = ['query' => $query, 'page_size' => 100];
            if ($cursor !== null) {
                $body['start_cursor'] = $cursor;
            }
            $resp = $this->http->request('POST', 'https://api.notion.com/v1/search', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Notion-Version' => '2022-06-28',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
                'timeout' => 30.0,
            ]);
            $status = $resp->getStatusCode();
            $raw = $resp->getContent(false);
            if ($status < 200 || $status >= 300) {
                $this->logger->error('Notion search failed', ['status' => $status, 'body' => $raw]);
                throw new \RuntimeException('Notion HTTP ' . $status);
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            foreach ($decoded['results'] ?? [] as $row) {
                $all[] = $row;
            }
            $cursor = $decoded['next_cursor'] ?? null;
        } while ($cursor !== null);
        return $all;
    }
}
