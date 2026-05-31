<?php
declare(strict_types=1);

namespace Webhooks\Delivery;

use Psr\Log\LoggerInterface;

final class PaymentWebhookDelivery
{
    private const MAX_RETRY_ATTEMPTS = 5;
    private const RETRY_DELAYS = [60, 300, 900, 3600, 7200];
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WebhookSignatureGenerator $signatureGenerator,
        private readonly HttpClient $httpClient,
        private readonly WebhookEventRepository $eventRepository,
    ) {}

    public function deliver(WebhookDeliveryRequest $request): DeliveryResult
    {
        $this->logger->info('Starting payment webhook delivery', [
            'event_id' => $request->getEventId(),
            'endpoint' => $request->getEndpoint(),
        ]);

        $attempts = 0;
        $lastError = null;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                $result = $this->attemptDelivery($request);

                if ($result->isSuccess()) {
                    $this->markDelivered($request, $result);
                    return $result;
                }

                $lastError = $result->getErrorMessage();

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger->warning('Webhook delivery attempt failed', [
                    'attempt' => $attempts + 1,
                    'error' => $e->getMessage(),
                ]);
            }

            $attempts++;

            if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                $delay = self::RETRY_DELAYS[$attempts - 1] ?? 3600;
                sleep($delay);
            }
        }

        $this->markFailed($request, $lastError);

        return DeliveryResult::failure('Max retry attempts exceeded: ' . $lastError);
    }

    private function attemptDelivery(WebhookDeliveryRequest $request): DeliveryResult
    {
        $payload = $this->preparePayload($request);
        $headers = $this->prepareHeaders($request, $payload);
        $signature = $this->signatureGenerator->generateSignature($payload, $request->getSecret());

        $headers['X-Webhook-Signature'] = $signature;

        $httpResponse = $this->httpClient->post($request->getEndpoint(), [
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if ($httpResponse->getStatusCode() >= 200 && $httpResponse->getStatusCode() < 300) {
            return DeliveryResult::success($httpResponse->getBody());
        }

        return DeliveryResult::failure(
            sprintf('HTTP %d: %s', $httpResponse->getStatusCode(), $httpResponse->getBody())
        );
    }

    private function preparePayload(WebhookDeliveryRequest $request): array
    {
        return [
            'event' => $request->getEventType(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ISO8601),
            'data' => $request->getPayload(),
            'delivery_id' => $request->getDeliveryId(),
        ];
    }

    private function prepareHeaders(WebhookDeliveryRequest $request, array $payload): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Webhook-Event' => $request->getEventType(),
            'X-Webhook-Delivery-Id' => $request->getDeliveryId(),
            'User-Agent' => 'PaymentWebhook/1.0',
        ];
    }

    private function markDelivered(WebhookDeliveryRequest $request, DeliveryResult $result): void
    {
        $this->eventRepository->recordSuccess(
            $request->getEventId(),
            $result->getResponseBody(),
            200
        );
    }

    private function markFailed(WebhookDeliveryRequest $request, ?string $error): void
    {
        $this->eventRepository->recordFailure(
            $request->getEventId(),
            $error ?? 'Unknown error'
        );
    }
}
