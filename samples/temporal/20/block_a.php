<?php
declare(strict_types=1);

namespace NewRelic\Insights\Service;

use NewRelic\Insights\Repository\EventRepository;
use NewRelic\Insights\Repository\QueryRepository;
use NewRelic\Insights\Repository\DashboardRepository;
use NewRelic\Insights\Entity\Event;
use NewRelic\Insights\Entity\SavedQuery;
use NewRelic\Insights\Entity\Dashboard;
use NewRelic\Insights\Exception\InsightsException;
use NewRelic\Insights\Service\Aggregation\EventAggregator;
use NewRelic\Insights\Service\NRQL\NrqlValidator;
use Psr\Log\LoggerInterface;

final class EventIngestionService
{
    private EventRepository $eventRepo;
    private QueryRepository $queryRepo;
    private DashboardRepository $dashboardRepo;
    private EventAggregator $aggregator;
    private NrqlValidator $nrqlValidator;
    private LoggerInterface $logger;

    public function __construct(
        EventRepository $eventRepo,
        QueryRepository $queryRepo,
        DashboardRepository $dashboardRepo,
        EventAggregator $aggregator,
        NrqlValidator $nrqlValidator,
        LoggerInterface $logger
    ) {
        $this->eventRepo = $eventRepo;
        $this->queryRepo = $queryRepo;
        $this->dashboardRepo = $dashboardRepo;
        $this->aggregator = $aggregator;
        $this->nrqlValidator = $nrqlValidator;
        $this->logger = $logger;
    }

    public function ingestEvents(string $accountId, array $events): IngestResult
    {
        $this->logger->info('Ingesting events', [
            'account_id' => $accountId,
            'event_count' => count($events)
        ]);

        $account = $this->eventRepo->findAccount($accountId);
        if ($account === null) {
            throw new InsightsException("Account not found: {$accountId}");
        }

        $rateLimit = $this->eventRepo->getHourlyEventCount($accountId);
        $hourlyLimit = $account->getEventLimitPerHour();

        if ($rateLimit + count($events) > $hourlyLimit) {
            throw new InsightsException(
                "Event limit exceeded. Current: {$rateLimit}, Limit: {$hourlyLimit}, Attempting: " . count($events)
            );
        }

        $ingestLock = $this->eventRepo->acquireIngestLock($accountId);
        if ($ingestLock === null) {
            throw new InsightsException("Could not acquire ingest lock for account: {$accountId}");
        }

        $this->logger->debug('Ingest lock acquired', ['account_id' => $accountId]);

        try {
            $insertedEvents = [];
            $failedEvents = [];

            foreach ($events as $index => $eventData) {
                try {
                    $this->validateEventData($eventData);

                    $event = Event::create([
                        'account_id' => $accountId,
                        'event_type' => $eventData['eventType'],
                        'timestamp' => $eventData['timestamp'] ?? time(),
                        'data' => json_encode($eventData['attributes'] ?? []),
                        'ingested_at' => new \DateTimeImmutable()
                    ]);

                    $savedEvent = $this->eventRepo->insert($event);
                    $insertedEvents[] = $savedEvent;

                } catch (\Throwable $e) {
                    $failedEvents[] = [
                        'index' => $index,
                        'error' => $e->getMessage()
                    ];
                    $this->logger->warning('Failed to ingest event', [
                        'index' => $index,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->eventRepo->flush();

            $aggregatedMetrics = $this->aggregator->computeMetrics($insertedEvents);
            $this->eventRepo->storeAggregatedMetrics($accountId, $aggregatedMetrics);

            $this->eventRepo->releaseIngestLock($ingestLock);

            $this->logger->info('Event ingestion completed', [
                'account_id' => $accountId,
                'inserted' => count($insertedEvents),
                'failed' => count($failedEvents)
            ]);

            return new IngestResult([
                'success' => count($failedEvents) === 0,
                'account_id' => $accountId,
                'inserted_count' => count($insertedEvents),
                'failed_count' => count($failedEvents),
                'failures' => $failedEvents
            ]);

        } catch (\Throwable $e) {
            $this->eventRepo->releaseIngestLock($ingestLock);
            $this->logger->error('Event ingestion failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function executeQuery(string $accountId, string $nrql): QueryResult
    {
        $this->logger->info('Executing NRQL query', [
            'account_id' => $accountId,
            'nrql_length' => strlen($nrql)
        ]);

        $validation = $this->nrqlValidator->validate($nrql);
        if (!$validation->isValid()) {
            throw new InsightsException('Invalid NRQL: ' . $validation->getError());
        }

        $queryLock = $this->queryRepo->acquireQueryLock($accountId);
        if ($queryLock === null) {
            throw new InsightsException("Could not acquire query lock for account: {$accountId}");
        }

        try {
            $query = SavedQuery::create([
                'account_id' => $accountId,
                'nrql' => $nrql,
                'status' => 'running',
                'started_at' => new \DateTimeImmutable()
            ]);

            $savedQuery = $this->queryRepo->save($query);

            $results = $this->executeNrqlQuery($nrql);

            $this->queryRepo->updateStatus($savedQuery->getId(), 'completed', [
                'completed_at' => new \DateTimeImmutable(),
                'result_count' => count($results)
            ]);

            $this->queryRepo->releaseQueryLock($queryLock);

            $this->logger->info('Query completed', [
                'query_id' => $savedQuery->getId(),
                'results_count' => count($results)
            ]);

            return new QueryResult([
                'success' => true,
                'query_id' => $savedQuery->getId(),
                'results' => $results,
                'metadata' => [
                    'account_id' => $accountId,
                    'executed_at' => (new \DateTimeImmutable())->format('c')
                ]
            ]);

        } catch (\Throwable $e) {
            $this->queryRepo->releaseQueryLock($queryLock);
            $this->logger->error('Query execution failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function createDashboard(string $accountId, array $dashboardData): DashboardResult
    {
        $this->logger->info('Creating dashboard', [
            'account_id' => $accountId,
            'title' => $dashboardData['title'] ?? 'Untitled'
        ]);

        $dashboard = Dashboard::create([
            'account_id' => $accountId,
            'title' => $dashboardData['title'],
            'description' => $dashboardData['description'] ?? null,
            'widgets' => json_encode($dashboardData['widgets'] ?? []),
            'visibility' => $dashboardData['visibility'] ?? 'private',
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedDashboard = $this->dashboardRepo->save($dashboard);

        $this->logger->info('Dashboard created', [
            'dashboard_id' => $savedDashboard->getId()
        ]);

        return new DashboardResult([
            'success' => true,
            'dashboard_id' => $savedDashboard->getId(),
            'dashboard_guid' => $savedDashboard->getGuid()
        ]);
    }

    private function validateEventData(array $eventData): void
    {
        if (empty($eventData['eventType'])) {
            throw new InsightsException('eventType is required');
        }

        if (!is_string($eventData['eventType']) || strlen($eventData['eventType']) > 255) {
            throw new InsightsException('eventType must be a string between 1 and 255 characters');
        }

        if (isset($eventData['timestamp']) && !is_numeric($eventData['timestamp'])) {
            throw new InsightsException('timestamp must be a numeric Unix timestamp');
        }

        if (!isset($eventData['attributes']) || !is_array($eventData['attributes'])) {
            throw new InsightsException('attributes must be an array');
        }
    }

    private function executeNrqlQuery(string $nrql): array
    {
        return [];
    }
}
