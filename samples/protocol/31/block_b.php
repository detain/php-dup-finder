<?php
declare(strict_types=1);

namespace App\Services\GraphQL;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnalyticsGraphQLClient
{
    private HttpClientInterface $httpClient;
    private ConfigManager $config;
    private LoggerInterface $logger;
    private array $queryBatch = [];
    private int $batchSize = 10;
    private int $batchDelayMs = 50;

    public function __construct(
        HttpClientInterface $httpClient,
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function query(string $query, array $variables = [], string $operationName = ''): array
    {
        $this->queryBatch[] = [
            'query' => $query,
            'variables' => $variables,
            'operationName' => $operationName,
        ];
        
        if (count($this->queryBatch) >= $this->batchSize) {
            return $this->executeBatch();
        }
        
        usleep($this->batchDelayMs * 1000);
        
        if (count($this->queryBatch) > 0) {
            return $this->executeBatch();
        }
        
        return [];
    }

    private function executeBatch(): array
    {
        if (empty($this->queryBatch)) {
            return [];
        }
        
        $batch = $this->queryBatch;
        $this->queryBatch = [];
        
        try {
            $this->logger->debug('Executing GraphQL batch', [
                'query_count' => count($batch),
            ]);
            
            $payload = array_map(function ($item) {
                return [
                    'query' => $item['query'],
                    'variables' => $item['variables'],
                    'operationName' => $item['operationName'] ?: null,
                ];
            }, $batch);
            
            $response = $this->httpClient->request('POST', $this->config->get('graphql.endpoint'), [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            $results = $response->toArray();
            
            $this->logger->info('GraphQL batch executed', [
                'query_count' => count($batch),
            ]);
            
            if (count($results) === 1) {
                return $results[0] ?? [];
            }
            
            return $results;
            
        } catch (\Exception $e) {
            $this->logger->error('GraphQL batch execution failed', [
                'error' => $e->getMessage(),
                'query_count' => count($batch),
            ]);
            throw $e;
        }
    }

    public function getReport(string $reportId): array
    {
        $query = '
            query GetReport($id: ID!) {
                report(id: $id) {
                    id
                    name
                    metrics
                    generatedAt
                }
            }
        ';
        
        $result = $this->query($query, ['id' => $reportId], 'GetReport');
        
        return $result['data']['report'] ?? [];
    }

    public function getDashboard(string $dashboardId): array
    {
        $query = '
            query GetDashboard($id: ID!) {
                dashboard(id: $id) {
                    id
                    title
                    widgets {
                        id
                        type
                        data
                    }
                }
            }
        ';
        
        $result = $this->query($query, ['id' => $dashboardId], 'GetDashboard');
        
        return $result['data']['dashboard'] ?? [];
    }
}
