<?php
declare(strict_types=1);

namespace CRM\Contacts;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Elasticsearch\Common\Exceptions\Missing404Exception;

final class ContactSearchService
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ElasticsearchClient $elasticsearch
    ) {}

    public function search(Request $request): SearchResult
    {
        $query = $request->query->get('q', '');
        $filters = $this->extractFilters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));

        try {
            $searchParams = [
                'index' => 'contacts',
                'body' => [
                    'query' => $this->buildQuery($query, $filters),
                    'from' => ($page - 1) * $limit,
                    'size' => $limit,
                    'sort' => [['score' => 'desc'], ['last_contacted_at' => 'desc']]
                ]
            ];

            $response = $this->elasticsearch->search($searchParams);

            $hits = $response['hits']['hits'] ?? [];
            $total = $response['hits']['total']['value'] ?? 0;

            $this->logger->debug('Contact search completed', [
                'query' => $query,
                'filters' => $filters,
                'results_count' => count($hits),
                'total_matches' => $total
            ]);

            return SearchResult::success(
                array_map(fn($hit) => $hit['_source'], $hits),
                $total,
                $page,
                $limit
            );

        } catch (Missing404Exception $e) {
            $this->logger->warning('Elasticsearch index not found', [
                'index' => 'contacts',
                'query' => $query
            ]);
            return SearchResult::empty();

        } catch (ConnectionErrorException $e) {
            $this->logger->error('Elasticsearch connection error during search', [
                'query' => $query,
                'filters' => $filters,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return SearchResult::failure('Search service temporarily unavailable');

        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error during contact search', [
                'query' => $query,
                'filters' => $filters,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return SearchResult::failure('Search failed due to an unexpected error');
        }
    }

    public function indexContact(Contact $contact): IndexResult
    {
        try {
            $this->elasticsearch->index([
                'index' => 'contacts',
                'id' => $contact->getId(),
                'body' => $contact->toSearchableArray()
            ]);

            $this->logger->info('Contact indexed successfully', [
                'contact_id' => $contact->getId()
            ]);

            return IndexResult::success();

        } catch (ConnectionErrorException $e) {
            $this->logger->error('Failed to index contact: connection error', [
                'contact_id' => $contact->getId(),
                'error' => $e->getMessage()
            ]);
            return IndexResult::failure('Indexing service unavailable');

        } catch (\Throwable $e) {
            $this->logger->error('Failed to index contact', [
                'contact_id' => $contact->getId(),
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return IndexResult::failure('Failed to index contact');
        }
    }

    private function buildQuery(string $query, array $filters): array
    {
        // Query building implementation
        return ['bool' => ['must' => [['query_string' => ['query' => $query]]]]];
    }

    private function extractFilters(Request $request): array
    {
        return [
            'status' => $request->query->get('status'),
            'tags' => $request->query->all('tags', []),
            'created_after' => $request->query->get('created_after')
        ];
    }
}
