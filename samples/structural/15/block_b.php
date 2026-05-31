<?php
declare(strict_types=1);

namespace Messaging\Workflow;

use Psr\Log\LoggerInterface;

final class SmsNotificationWorkflow
{
    private const BATCH_SIZE = 50;
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_SECONDS = 60;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SmsTemplateEngine $templateEngine,
        private readonly SmsGateway $gateway,
        private readonly NotificationRepository $repository,
    ) {}

    public function execute(NotificationBatch $batch): WorkflowResult
    {
        $this->logger->info('Starting SMS notification workflow', [
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
        $this->logger->info('SMS workflow completed', ['delivered' => count($loggedResults)]);

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
            if (!$this->isValidPhoneNumber($recipient->getPhone())) {
                $this->logger->warning('Invalid phone number skipped', [
                    'phone' => $recipient->getPhone(),
                ]);
                continue;
            }

            if ($this->isOptedOut($recipient->getPhone())) {
                $this->logger->debug('Opted-out recipient skipped', [
                    'phone' => $recipient->getPhone(),
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

            $content = $this->templateEngine->render(
                $recipient->getTemplateId(),
                $templateData
            );

            $rendered[] = new RenderedSms(
                recipient: $recipient,
                content: $content,
            );
        }

        return $rendered;
    }

    private function enrichMessages(array $renderedMessages): array
    {
        $enriched = [];

        foreach ($renderedMessages as $message) {
            $trackingId = bin2hex(random_bytes(16));

            $enriched[] = new EnrichedSms(
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
                    $this->logger->error('SMS delivery failed', [
                        'recipient' => $message->recipient->getPhone(),
                        'error' => $result->getErrorMessage(),
                    ]);
                }
            }
        }

        return $delivered;
    }

    private function deliverWithRetry(EnrichedSms $message): DeliveryResult
    {
        $attempts = 0;

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                $result = $this->gateway->send($message);

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
                $message->recipient->getPhone(),
                'sent'
            );

            $logged[] = $message;
        }

        return $logged;
    }

    private function isValidPhoneNumber(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) >= 10 && strlen($digits) <= 15;
    }

    private function isOptedOut(string $phone): bool
    {
        return false;
    }

    private function prepareTemplateData(Recipient $recipient): array
    {
        return [
            'first_name' => $recipient->getFirstName(),
            'phone' => $recipient->getPhone(),
            'custom_data' => $recipient->getCustomData(),
        ];
    }

    private function getTemplateVersion(string $templateId): string
    {
        return 'v1';
    }
}
