<?php

declare(strict_types=1);

namespace App\Webhook;

class WebhookMetricsRecorder
{
    private PrometheusClient $prometheus;
    private AlertManager $alerts;
    private LoggerInterface $logger;

    public function __construct(
        PrometheusClient $prometheus,
        AlertManager $alerts,
        LoggerInterface $logger
    ) {
        $this->prometheus = $prometheus;
        $this->alerts = $alerts;
        $this->logger = $logger;
    }

    public function recordWebhookReceived(
        string $provider,
        string $eventType,
        string $webhookId
    ): void {
        $labels = [
            'provider' => $provider,
            'event_type' => $eventType
        ];

        $this->prometheus->incrementCounter(
            'webhooks_received_total',
            'Total webhooks received',
            1,
            $labels
        );

        $this->prometheus->incrementGauge(
            'webhooks_processing',
            'Webhooks currently being processed',
            1,
            $labels
        );

        $this->logger->info('Webhook received', [
            'provider' => $provider,
            'event_type' => $eventType,
            'webhook_id' => $webhookId
        ]);
    }

    public function recordWebhookProcessed(
        string $provider,
        string $eventType,
        string $webhookId,
        float $processingTimeMs,
        bool $success,
        ?string $error = null
    ): void {
        $labels = [
            'provider' => $provider,
            'event_type' => $eventType,
            'success' => $success ? 'true' : 'false'
        ];

        $this->prometheus->incrementCounter(
            'webhooks_processed_total',
            'Total webhooks processed',
            1,
            $labels
        );

        $this->prometheus->recordHistogram(
            'webhook_processing_duration_seconds',
            'Webhook processing duration',
            $processingTimeMs / 1000,
            $labels
        );

        $this->prometheus->decrementGauge(
            'webhooks_processing',
            'Webhooks currently being processed',
            1,
            ['provider' => $provider, 'event_type' => $eventType]
        );

        if (!$success) {
            $this->recordWebhookError($provider, $eventType, $error);
        }

        $this->logger->info('Webhook processed', [
            'provider' => $provider,
            'event_type' => $eventType,
            'webhook_id' => $webhookId,
            'processing_time_ms' => $processingTimeMs,
            'success' => $success
        ]);
    }

    public function recordWebhookError(
        string $provider,
        string $eventType,
        ?string $error
    ): void {
        $labels = [
            'provider' => $provider,
            'event_type' => $eventType,
            'error_type' => $this->classifyError($error)
        ];

        $this->prometheus->incrementCounter(
            'webhooks_errors_total',
            'Total webhook errors',
            1,
            $labels
        );

        $this->checkErrorThreshold($provider, $eventType);

        $this->logger->error('Webhook error', [
            'provider' => $provider,
            'event_type' => $eventType,
            'error' => $error
        ]);
    }

    private function classifyError(?string $error): string
    {
        if ($error === null) {
            return 'unknown';
        }

        $error = strtolower($error);

        if (str_contains($error, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($error, 'authentication') || str_contains($error, 'unauthorized')) {
            return 'auth_error';
        }

        if (str_contains($error, 'validation')) {
            return 'validation_error';
        }

        if (str_contains($error, 'duplicate')) {
            return 'duplicate_event';
        }

        if (str_contains($error, 'rate limit')) {
            return 'rate_limit';
        }

        return 'other_error';
    }

    private function checkErrorThreshold(string $provider, string $eventType): void
    {
        $recentErrors = $this->prometheus->getCounterValue(
            'webhooks_errors_total',
            ['provider' => $provider, 'event_type' => $eventType]
        );

        $recentTotal = $this->prometheus->getCounterValue(
            'webhooks_received_total',
            ['provider' => $provider, 'event_type' => $eventType]
        );

        if ($recentTotal === 0) {
            return;
        }

        $errorRate = $recentErrors / $recentTotal;

        if ($errorRate > 0.1) {
            $this->prometheus->incrementCounter(
                'webhook_alerts_total',
                'Webhook alerts triggered',
                1,
                ['alert_type' => 'high_error_rate']
            );

            $this->alerts->send('critical', 'Webhook error rate threshold exceeded', [
                'provider' => $provider,
                'event_type' => $eventType,
                'error_rate' => round($errorRate * 100, 2)
            ]);
        }
    }

    public function recordWebhookRetry(
        string $provider,
        string $eventType,
        string $webhookId,
        int $attemptNumber
    ): void {
        $labels = [
            'provider' => $provider,
            'event_type' => $eventType,
            'attempt' => (string)$attemptNumber
        ];

        $this->prometheus->incrementCounter(
            'webhooks_retries_total',
            'Webhook retry attempts',
            1,
            $labels
        );

        $this->logger->info('Webhook retry scheduled', [
            'provider' => $provider,
            'event_type' => $eventType,
            'webhook_id' => $webhookId,
            'attempt' => $attemptNumber
        ]);
    }

    public function recordSignatureValidation(
        string $provider,
        bool $valid,
        float $validationTimeMs
    ): void {
        $labels = [
            'provider' => $provider,
            'valid' => $valid ? 'true' : 'false'
        ];

        $this->prometheus->incrementCounter(
            'webhook_signature_validations_total',
            'Webhook signature validations',
            1,
            $labels
        );

        $this->prometheus->recordHistogram(
            'webhook_signature_validation_duration_seconds',
            'Signature validation duration',
            $validationTimeMs / 1000,
            $labels
        );
    }

    public function recordPayloadParsing(
        string $provider,
        bool $success,
        float $parsingTimeMs,
        int $payloadSize
    ): void {
        $labels = [
            'provider' => $provider,
            'success' => $success ? 'true' : 'false'
        ];

        $this->prometheus->incrementCounter(
            'webhook_payload_parsing_total',
            'Webhook payload parsing attempts',
            1,
            $labels
        );

        $this->prometheus->recordHistogram(
            'webhook_payload_parsing_duration_seconds',
            'Payload parsing duration',
            $parsingTimeMs / 1000,
            $labels
        );

        $this->prometheus->recordHistogram(
            'webhook_payload_size_bytes',
            'Webhook payload size',
            (float)$payloadSize,
            $labels
        );
    }
}
