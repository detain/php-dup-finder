<?php
declare(strict_types=1);

namespace Messaging\Shared;

interface NotificationStrategy
{
    public function validate(NotificationBatch $batch): array;
    public function render(array $recipients): array;
    public function enrich(array $rendered): array;
    public function deliver(array $enriched): array;
    public function log(array $delivered): array;
}

abstract class BaseNotificationWorkflow
{
    protected LoggerInterface $logger;
    protected TemplateEngineInterface $templateEngine;
    protected NotificationRepository $repository;

    private const BATCH_SIZE = 50;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 60;

    public function execute(NotificationBatch $batch): WorkflowResult
    {
        $this->logger->info('Starting notification workflow', [
            'batch_id' => $batch->getId(),
            'count' => count($batch->getRecipients()),
        ]);

        $validated = $this->validate($batch);
        $rendered = $this->render($validated);
        $enriched = $this->enrich($rendered);
        $delivered = $this->deliver($enriched);
        $logged = $this->log($delivered);

        return new WorkflowResult(
            totalProcessed: count($batch->getRecipients()),
            successfulDeliveries: count($logged),
            failedDeliveries: count($batch->getRecipients()) - count($logged),
        );
    }

    protected function processInBatches(array $items, callable $processor): array
    {
        $results = [];
        $batches = array_chunk($items, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            foreach ($batch as $item) {
                $results[] = $processor($item);
            }
        }

        return $results;
    }

    protected function deliverWithRetry(mixed $message, DeliveryService $service): DeliveryResult
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $result = $service->send($message);

                if ($result->isSuccess()) {
                    return $result;
                }

                throw new \RuntimeException($result->getErrorMessage());

            } catch (\Throwable $e) {
                $attempts++;

                if ($attempts >= self::MAX_RETRIES) {
                    return DeliveryResult::failure($e->getMessage());
                }

                sleep(self::RETRY_DELAY);
            }
        }

        return DeliveryResult::failure('Max retries exceeded');
    }

    abstract protected function validate(NotificationBatch $batch): array;
    abstract protected function render(array $recipients): array;
    abstract protected function enrich(array $rendered): array;
    abstract protected function deliver(array $enriched): array;
    abstract protected function log(array $delivered): array;
}

final class EmailNotificationWorkflow extends BaseNotificationWorkflow
{
    private EmailTransport $transport;

    protected function validate(NotificationBatch $batch): array
    {
        return array_filter(
            $batch->getRecipients(),
            fn($r) => filter_var($r->getEmail(), FILTER_VALIDATE_EMAIL) !== false
        );
    }

    protected function render(array $recipients): array
    {
        return array_map(
            fn($r) => new RenderedEmail($r, 'Subject', 'Body'),
            $recipients
        );
    }

    protected function enrich(array $rendered): array
    {
        return array_map(
            fn($m) => new EnrichedEmail($m, bin2hex(16), []),
            $rendered
        );
    }

    protected function deliver(array $enriched): array
    {
        $delivered = [];

        foreach ($enriched as $message) {
            $result = $this->deliverWithRetry($message, $this->transport);

            if ($result->isSuccess()) {
                $delivered[] = $message;
            }
        }

        return $delivered;
    }

    protected function log(array $delivered): array
    {
        foreach ($delivered as $d) {
            $this->repository->recordDelivery($d->trackingId, $d->original->recipient->getEmail(), 'sent');
        }

        return $delivered;
    }
}
