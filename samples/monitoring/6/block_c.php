<?php

declare(strict_types=1);

namespace App\Queue;

class QueueMessageMetrics
{
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(MetricsCollector $metrics, LoggerInterface $logger)
    {
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function recordMessagePublished(
        string $queue,
        string $messageId,
        int $messageSize,
        string $exchange = ''
    ): void {
        $labels = [
            'queue' => $queue,
            'exchange' => $exchange ?: 'default'
        ];

        $this->metrics->incrementCounter(
            'queue_messages_published_total',
            'Total messages published to queue',
            1,
            $labels
        );

        $this->metrics->histogram(
            'queue_message_size_bytes',
            'Queue message size in bytes',
            (float)$messageSize,
            $labels
        );

        $this->metrics->gauge(
            'queue_depth',
            'Number of messages in queue',
            1,
            ['queue' => $queue]
        );

        $this->logger->debug('Message published to queue', [
            'message_id' => $messageId,
            'queue' => $queue,
            'size' => $messageSize
        ]);
    }

    public function recordMessageConsumed(
        string $queue,
        string $messageId,
        int $processingTimeMs,
        bool $success
    ): void {
        $labels = [
            'queue' => $queue,
            'success' => $success ? 'true' : 'false'
        ];

        $this->metrics->incrementCounter(
            'queue_messages_consumed_total',
            'Total messages consumed from queue',
            1,
            $labels
        );

        $this->metrics->histogram(
            'queue_message_processing_duration_milliseconds',
            'Queue message processing duration in milliseconds',
            (float)$processingTimeMs,
            $labels
        );

        $this->metrics->gauge(
            'queue_depth',
            'Number of messages in queue',
            -1,
            ['queue' => $queue]
        );

        $this->logger->debug('Message consumed from queue', [
            'message_id' => $messageId,
            'queue' => $queue,
            'processing_time_ms' => $processingTimeMs,
            'success' => $success
        ]);
    }

    public function recordMessageFailed(
        string $queue,
        string $messageId,
        string $error,
        int $retryCount,
        bool $willRetry
    ): void {
        $labels = [
            'queue' => $queue,
            'error_type' => $this->classifyQueueError($error),
            'will_retry' => $willRetry ? 'true' : 'false'
        ];

        $this->metrics->incrementCounter(
            'queue_messages_failed_total',
            'Total failed queue messages',
            1,
            $labels
        );

        $this->metrics->histogram(
            'queue_message_retry_count',
            'Retry count for failed messages',
            (float)$retryCount,
            ['queue' => $queue]
        );

        $this->logger->error('Queue message failed', [
            'message_id' => $messageId,
            'queue' => $queue,
            'error' => $error,
            'retry_count' => $retryCount,
            'will_retry' => $willRetry
        ]);
    }

    private function classifyQueueError(string $error): string
    {
        $error = strtolower($error);

        if (str_contains($error, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($error, 'max retries')) {
            return 'max_retries_exceeded';
        }

        if (str_contains($error, 'invalid message')) {
            return 'invalid_message';
        }

        if (str_contains($error, 'unavailable')) {
            return 'service_unavailable';
        }

        return 'unknown_error';
    }

    public function recordConsumerRegistration(
        string $queue,
        string $consumerId,
        string $consumerType
    ): void {
        $labels = [
            'queue' => $queue,
            'consumer_type' => $consumerType
        ];

        $this->metrics->incrementCounter(
            'queue_consumers_registered_total',
            'Total consumers registered',
            1,
            $labels
        );

        $this->metrics->gauge(
            'queue_active_consumers',
            'Active consumers for queue',
            1,
            ['queue' => $queue, 'consumer_id' => $consumerId]
        );

        $this->logger->info('Consumer registered', [
            'queue' => $queue,
            'consumer_id' => $consumerId,
            'consumer_type' => $consumerType
        ]);
    }

    public function recordConsumerDeregistration(
        string $queue,
        string $consumerId
    ): void {
        $this->metrics->gauge(
            'queue_active_consumers',
            'Active consumers for queue',
            -1,
            ['queue' => $queue, 'consumer_id' => $consumerId]
        );

        $this->logger->info('Consumer deregistered', [
            'queue' => $queue,
            'consumer_id' => $consumerId
        ]);
    }

    public function recordQueueBacklog(
        string $queue,
        int $messageCount,
        int $consumerCount
    ): void {
        $labels = ['queue' => $queue];

        $this->metrics->gauge(
            'queue_depth',
            'Number of messages in queue',
            $messageCount,
            $labels
        );

        $this->metrics->gauge(
            'queue_consumer_count',
            'Number of consumers for queue',
            $consumerCount,
            $labels
        );

        if ($consumerCount > 0) {
            $lagPerConsumer = $messageCount / $consumerCount;

            $this->metrics->gauge(
                'queue_lag_per_consumer',
                'Message lag per consumer',
                $lagPerConsumer,
                $labels
            );
        }

        if ($messageCount > 10000) {
            $this->metrics->incrementCounter(
                'queue_backlog_alerts_total',
                'Total queue backlog alerts',
                1,
                $labels
            );

            $this->logger->warning('Queue backlog high', [
                'queue' => $queue,
                'message_count' => $messageCount,
                'consumer_count' => $consumerCount
            ]);
        }
    }
}
