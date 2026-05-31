<?php
declare(strict_types=1);

namespace Webhooks\Shared;

interface WebhookDeliveryStrategy
{
    public function preparePayload(WebhookDeliveryRequest $request): array;
    public function prepareHeaders(WebhookDeliveryRequest $request, array $payload): array;
    public function getEventType(): string;
}

abstract class BaseWebhookDelivery
{
    protected LoggerInterface $logger;
    protected WebhookSignatureGenerator $signatureGenerator;
    protected HttpClient $httpClient;
    protected WebhookEventRepository $eventRepository;

    private const MAX_RETRIES = 5;
    private const RETRY_DELAYS = [60, 300, 900, 3600, 7200];
    private const TIMEOUT_SECONDS = 30;

    public function deliver(WebhookDeliveryRequest $request): DeliveryResult
    {
        $this->logger->info('Starting webhook delivery', [
            'event_id' => $request->getEventId(),
            'endpoint' => $request->getEndpoint(),
        ]);

        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $result = $this->attemptDelivery($request);

                if ($result->isSuccess()) {
                    $this->markSuccess($request, $result);
                    return $result;
                }

                $lastError = $result->getErrorMessage();

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            $this->logger->warning('Webhook delivery failed', [
                'attempt' => $attempts + 1,
                'error' => $lastError,
            ]);

            $attempts++;

            if ($attempts < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAYS[$attempts - 1] ?? 3600);
            }
        }

        $this->markFailure($request, $lastError ?? 'Unknown error');

        return DeliveryResult::failure('Max retry attempts exceeded');
    }

    protected function attemptDelivery(WebhookDeliveryRequest $request): DeliveryResult
    {
        $payload = $this->preparePayload($request);
        $headers = $this->prepareHeaders($request, $payload);
        $signature = $this->signatureGenerator->generateSignature($payload, $request->getSecret());

        $headers['X-Webhook-Signature'] = $signature;

        $response = $this->httpClient->post($request->getEndpoint(), [
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            return DeliveryResult::success($response->getBody());
        }

        return DeliveryResult::failure("HTTP {$response->getStatusCode()}: {$response->getBody()}");
    }

    abstract protected function preparePayload(WebhookDeliveryRequest $request): array;
    abstract protected function prepareHeaders(WebhookDeliveryRequest $request, array $payload): array;
    abstract protected function getEventType(): string;

    protected function markSuccess(WebhookDeliveryRequest $request, DeliveryResult $result): void
    {
        $this->eventRepository->recordSuccess($request->getEventId(), $result->getResponseBody(), 200);
    }

    protected function markFailure(WebhookDeliveryRequest $request, string $error): void
    {
        $this->eventRepository->recordFailure($request->getEventId(), $error);
    }
}

final class PaymentWebhookDelivery extends BaseWebhookDelivery
{
    protected function preparePayload(WebhookDeliveryRequest $request): array
    {
        return [
            'event' => $this->getEventType(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ISO8601),
            'data' => $request->getPayload(),
            'delivery_id' => $request->getDeliveryId(),
        ];
    }

    protected function prepareHeaders(WebhookDeliveryRequest $request, array $payload): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Webhook-Event' => $this->getEventType(),
            'X-Webhook-Delivery-Id' => $request->getDeliveryId(),
            'User-Agent' => 'PaymentWebhook/1.0',
        ];
    }

    protected function getEventType(): string
    {
        return 'payment.processed';
    }
}
