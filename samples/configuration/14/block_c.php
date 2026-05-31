<?php

declare(strict_types=1);

namespace App\Services\Search;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Psr\Log\LoggerInterface;

final class ElasticsearchIndexManager
{
    private const INDEX_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 200;
    private const POOL_SIZE = 8;
    private const KEEP_ALIVE = 60;
    private const BULK_BATCH_SIZE = 500;
    private const REFRESH_INTERVAL = '1s';
    private const NUMBER_OF_SHARDS = 3;
    private const NUMBER_OF_REPLICAS = 1;
    private const MAX_RESULT_WINDOW = 10000;

    private Client $client;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $indexName,
        private readonly string $username,
        private readonly string $password
    ) {
        $this->client = $this->createClient();
    }

    private function createClient(): Client
    {
        return Client::fromConfig([
            'hosts' => [
                [
                    'host' => $this->host,
                    'port' => $this->port,
                    'scheme' => 'https',
                ],
            ],
            'timeout' => self::INDEX_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'retries' => self::MAX_RETRIES,
            'pool_size' => self::POOL_SIZE,
            'keep_alive' => self::KEEP_ALIVE,
            'basicAuthentication' => [
                'username' => $this->username,
                'password' => $this->password,
            ],
        ]);
    }

    public function createIndex(): bool
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $params = [
                    'index' => $this->indexName,
                    'body' => [
                        'settings' => [
                            'number_of_shards' => self::NUMBER_OF_SHARDS,
                            'number_of_replicas' => self::NUMBER_OF_REPLICAS,
                            'refresh_interval' => self::REFRESH_INTERVAL,
                            'max_result_window' => self::MAX_RESULT_WINDOW,
                            'analysis' => [
                                'analyzer' => [
                                    'default' => [
                                        'type' => 'standard',
                                    ],
                                ],
                            ],
                        ],
                        'mappings' => [
                            'properties' => [
                                'id' => ['type' => 'keyword'],
                                'title' => ['type' => 'text', 'analyzer' => 'standard'],
                                'content' => ['type' => 'text', 'analyzer' => 'standard'],
                                'created_at' => ['type' => 'date'],
                                'updated_at' => ['type' => 'date'],
                                'status' => ['type' => 'keyword'],
                            ],
                        ],
                    ],
                ];

                $response = $this->client->indices()->create($params);

                $this->logger->info('Elasticsearch index created', [
                    'index' => $this->indexName,
                    'shards' => self::NUMBER_OF_SHARDS,
                    'replicas' => self::NUMBER_OF_REPLICAS,
                    'bulk_batch_size' => self::BULK_BATCH_SIZE,
                    'timeout' => self::INDEX_TIMEOUT,
                ]);

                return $response['acknowledged'] ?? false;
            } catch (ClientResponseException $e) {
                $attempts++;
                $this->logger->error('Failed to create Elasticsearch index', [
                    'index' => $this->indexName,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                    $this->client = $this->createClient();
                }
            }
        }

        return false;
    }

    public function indexDocument(string $id, array $document): bool
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $params = [
                    'index' => $this->indexName,
                    'id' => $id,
                    'body' => $document,
                ];

                $response = $this->client->index($params);

                $this->logger->debug('Document indexed', [
                    'index' => $this->indexName,
                    'id' => $id,
                    'result' => $response['result'] ?? 'unknown',
                ]);

                return in_array($response['result'] ?? '', ['created', 'updated']);
            } catch (ClientResponseException $e) {
                $attempts++;
                $this->logger->warning('Failed to index document', [
                    'index' => $this->indexName,
                    'id' => $id,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                }
            }
        }

        return false;
    }

    public function bulkIndex(array $documents): array
    {
        $attempts = 0;
        $indexed = 0;
        $failed = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $params = ['body' => []];

                foreach ($documents as $doc) {
                    $params['body'][] = [
                        'index' => [
                            '_index' => $this->indexName,
                            '_id' => $doc['id'] ?? null,
                        ],
                    ];
                    $params['body'][] = $doc;

                    if (count($params['body']) >= self::BULK_BATCH_SIZE * 2) {
                        $response = $this->client->bulk($params);
                        $indexed += $response['items'][0]['index']['status'] ?? 0;
                        $failed += count(array_filter($response['items'], fn($item) => ($item['index']['status'] ?? 0) >= 400));
                        $params['body'] = [];
                    }
                }

                if (!empty($params['body'])) {
                    $response = $this->client->bulk($params);
                    $indexed += count(array_filter($response['items'], fn($item) => ($item['index']['status'] ?? 0) < 400));
                    $failed += count(array_filter($response['items'], fn($item) => ($item['index']['status'] ?? 0) >= 400));
                }

                $this->logger->info('Bulk indexing completed', [
                    'index' => $this->indexName,
                    'indexed' => $indexed,
                    'failed' => $failed,
                    'batch_size' => self::BULK_BATCH_SIZE,
                    'attempts' => $attempts + 1,
                ]);

                return ['indexed' => $indexed, 'failed' => $failed];
            } catch (ClientResponseException $e) {
                $attempts++;
                $this->logger->error('Bulk indexing failed', [
                    'index' => $this->indexName,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'retry_delay' => self::RETRY_DELAY,
                ]);

                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                    $this->client = $this->createClient();
                }
            }
        }

        return ['indexed' => $indexed, 'failed' => $failed];
    }

    public function search(array $query, int $from = 0, int $size = 10): array
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $params = [
                    'index' => $this->indexName,
                    'body' => [
                        'query' => $query,
                        'from' => $from,
                        'size' => min($size, 100),
                        'track_total_hits' => true,
                    ],
                ];

                $response = $this->client->search($params);

                return [
                    'hits' => array_map(fn($hit) => $hit['_source'], $response['hits']['hits']),
                    'total' => $response['hits']['total']['value'] ?? 0,
                ];
            } catch (ClientResponseException $e) {
                $attempts++;
                $this->logger->warning('Search query failed', [
                    'index' => $this->indexName,
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                }
            }
        }

        return ['hits' => [], 'total' => 0];
    }
}
