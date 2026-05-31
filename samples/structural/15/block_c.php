<?php
declare(strict_types=1);

namespace Messaging\Workflow;

use Psr\Log\LoggerInterface;

final class PushNotificationWorkflow
{
    private const BATCH_SIZE = 100;
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PushTemplateEngine $templateEngine,
        private readonly PushService $pushService,
        private readonly NotificationRepository $repository,
    ) {}

    public function execute(NotificationBatch $batch): WorkflowResult
    {
        $this->logger->info('Starting push notification workflow', [
            'batch_id' => $batch->getId(),
            'recipient_count' => count($batch->getRecipients()),
        ]);

        $validatedRecipients = $this->validateRecipients($batch);
        $this->logger->debug('Recipients validated', ['count' => count($validatedRecipients)]);

        $renderedMessages = $this->renderTemplates($validatedRecipients);
        $this->logger->debug('Templates rendered', ['count' => count($renderedMessages)]);

        $enrichedMessages = $this->enrichMessages($renderedMessages);
        $this->logger->debug('Messages enriched', ['count' => count($enrichedMessages)]);

        $deliveredMessages = $this->deliverMessages($enrichedMessages);
        $this->logger->debug('Messages delivered', ['count' => count($deliveredMessages)]);

        $loggedResults = $this->logDeliveryResults($deliveredMessages);
        $this->logger->info('Push workflow completed', ['delivered' => count($loggedResults)]);

        return new WorkflowResult(
            totalProcessed: count($batch->getRecipients()),
            successfulDeliveries: count($loggedResults),
            failedDeliveries: count($batch->getRecipients()) - count($loggedResults),
        );
    }

    private function validateRecipients(NotificationBatch $batch): array
    {
        $validated = [];

        foreach ($batch->getRecipients() as $recipient) {
            if (!$this->hasValidDeviceToken($recipient->getDeviceToken())) {
                $this->logger->warning('Invalid device token skipped', [
                    'device_id' => $recipient->getDeviceId(),
                ]);
                continue;
            }

            if ($this->isNotificationDisabled($recipient->getDeviceId())) {
                $this->logger->debug('Notifications disabled for device', [
                    'device_id' => $recipient->getDeviceId(),
                ]);
                continue;
            }

            $validated[] = $recipient;
        }

        return $validated;
    }

    private function renderTemplates(array $recipients): array
    {
        $rendered = [];

        foreach ($recipients as $recipient) {
            $templateData = $this->prepareTemplateData($recipient);

            $title = $this->templateEngine->renderTitle(
                $recipient->getTemplateId(),
                $templateData
            );

            $body = $this->templateEngine->renderBody(
                $recipient->getTemplateId(),
                $templateData
            );

            $rendered[] = new RenderedPush(
                recipient: $recipient,
                title: $title,
                body: $body,
            );
        }

        return $rendered;
    }

    private function enrichMessages(array $renderedMessages): array
    {
        $enriched = [];

        foreach ($renderedMessages as $message) {
            $trackingId = bin2hex(random_bytes(16));

            $enriched[] = new EnrichedPush(
                original: $message,
                trackingId: $trackingId,
                metadata: [
                    'rendered_at' => new \DateTimeImmutable(),
                    'template_version' => $this->getTemplateVersion($message->recipient->getTemplateId()),
                ],
            );
        }

        return $enriched;
    }

    private function deliverMessages(array $enrichedMessages): array
    {
        $delivered = [];
        $batches = array_chunk($enrichedMessages, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            foreach ($batch as $message) {
                $result = $this->deliverWithRetry($message);

                if ($result->isSuccess()) {
                    $delivered[] = $message;
                } else {
                    $this->logger->error('Push delivery failed', [
                        'device_id' => $message->recipient->getDeviceId(),
                        'error' => $result->getErrorMessage(),
                    ]);
                }
            }
        }

        return $delivered;
    }

    private function deliverWithRetry(EnrichedPush $message): DeliveryResult
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                $result = $this->pushService->send($message);

                if ($result->isSuccess()) {
                    return $result;
                }

                throw new \RuntimeException($result->getErrorMessage());

            } catch (\Throwable $e) {
                $attempts++;

                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    return DeliveryResult::failure($e->getMessage());
                }

                sleep(self::RETRY_DELAY_SECONDS);
            }
        }

        return DeliveryResult::failure('Max retries exceeded');
    }

    private function logDeliveryResults(array $deliveredMessages): array
    {
        $logged = [];

        foreach ($deliveredMessages as $message) {
            $this->repository->recordDelivery(
                $message->trackingId,
                $message->recipient->getDeviceId(),
                'sent'
            );

            $logged[] = $message;
        }

        return $logged;
    }

    private function hasValidDeviceToken(?string $token): bool
    {
        return $token !== null && strlen($token) > 0;
    }

    private function isNotificationDisabled(string $deviceId): bool
    {
        return false;
    }

    private function prepareTemplateData(Recipient $recipient): array
    {
        return [
            'first_name' => $recipient->getFirstName(),
            'device_id' => $recipient->getDeviceId(),
            'custom_data' => $recipient->getCustomData(),
        ];
    }

    private function getTemplateVersion(string $templateId): string
    {
        return 'v1';
    }
}
