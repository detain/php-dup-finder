<?php
declare(strict_types=1);

namespace NotifyHub\Notifications\Push;

use Psr\Log\LoggerInterface;
use NotifyHub\Notifications\Entities\Notification;
use NotifyHub\Notifications\Templates\TemplateEngine;

final class PushNotificationService
{
    private const FROM_APPLICATION = 'company-push-app';
    private const FROM_NAME = 'Company Push';

    private const PUSH_SUBJECT_MAX_LENGTH = 255;
    private const PUSH_BODY_MAX_LENGTH = 500;
    private const PUSH_DATA_MAX_LENGTH = 4000;

    private const BATCH_SIZE = 100;
    private const RATE_LIMIT_PER_SECOND = 50;
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_SECONDS = 5;

    private const TEMPLATE_ENGINE_CACHE_TTL_SECONDS = 3600;
    private const TEMPLATE_VARIABLE_MAX_LENGTH = 1000;
    private const TEMPLATE_MAX_VARIABLES = 50;

    private const HEADER_X_PRIORITY = 'X-Priority';
    private const HEADER_X_MAILER = 'X-Mailer';
    private const HEADER_LIST_UNSUBSCRIBE = 'List-Unsubscribe';

    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendNotification(Notification $notification): SendResult
    {
        $this->logger->info('Sending push notification', [
            'notification_id' => $notification->getId(),
            'recipient' => $notification->getDeviceToken(),
        ]);

        $this->validatePushContent($notification);

        $renderedContent = $this->renderPushTemplate($notification);

        $push = $this->createPushMessage($notification, $renderedContent);
        $result = $this->deliverPush($push);

        $this->logger->info('Push notification sent', [
            'notification_id' => $notification->getId(),
            'success' => $result->isSuccessful(),
        ]);

        return $result;
    }

    public function sendBatchNotifications(array $notificationIds): BatchSendResult
    {
        $this->logger->info('Sending batch push notifications', [
            'count' => count($notificationIds),
        ]);

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach (array_chunk($notificationIds, self::BATCH_SIZE) as $batch) {
            $batchResults = $this->processBatch($batch);
            foreach ($batchResults as $result) {
                if ($result->isSuccessful()) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
                $results[] = $result;
            }

            usleep(1000000 / self::RATE_LIMIT_PER_SECOND);
        }

        return new BatchSendResult($results, $successCount, $failureCount);
    }

    private function validatePushContent(Notification $notification): void
    {
        $subject = $notification->getSubject();
        if (strlen($subject) > self::PUSH_SUBJECT_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Push subject exceeds maximum length of %d characters',
                self::PUSH_SUBJECT_MAX_LENGTH
            ));
        }

        $body = $notification->getBody();
        if (strlen($body) > self::PUSH_BODY_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Push body exceeds maximum length of %d characters',
                self::PUSH_BODY_MAX_LENGTH
            ));
        }

        $data = $notification->getDataPayload() ?? [];
        $dataString = json_encode($data);
        if (strlen($dataString) > self::PUSH_DATA_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Push data payload exceeds maximum length of %d characters',
                self::PUSH_DATA_MAX_LENGTH
            ));
        }
    }

    private function renderPushTemplate(Notification $notification): RenderedTemplate
    {
        $variables = $notification->getVariables();
        if (count($variables) > self::TEMPLATE_MAX_VARIABLES) {
            throw new \InvalidArgumentException(sprintf(
                'Too many template variables (max: %d)',
                self::TEMPLATE_MAX_VARIABLES
            ));
        }

        foreach ($variables as $key => $value) {
            if (strlen((string)$value) > self::TEMPLATE_VARIABLE_MAX_LENGTH) {
                throw new \InvalidArgumentException(sprintf(
                    'Template variable "%s" exceeds maximum length',
                    $key
                ));
            }
        }

        return $this->templateEngine->render(
            $notification->getTemplateName(),
            $variables
        );
    }

    private function createPushMessage(Notification $notification, RenderedTemplate $content): PushMessage
    {
        return new PushMessage(
            application: self::FROM_APPLICATION,
            deviceToken: $notification->getDeviceToken(),
            title: $content->getSubject(),
            body: $content->getBody(),
            data: $notification->getDataPayload(),
            headers: $this->buildPushHeaders($notification),
        );
    }

    private function buildPushHeaders(Notification $notification): array
    {
        return [
            self::HEADER_X_PRIORITY => (string)$notification->getPriority(),
            self::HEADER_X_MAILER => 'NotifyHub/Push v1.0',
            'X-Notification-ID' => $notification->getId(),
            'X-Notification-Type' => $notification->getType(),
        ];
    }

    private function deliverPush(PushMessage $push): SendResult
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < self::RETRY_ATTEMPTS) {
            try {
                $response = $this->pushGateway->send($push);
                return SendResult::successful($response->getMessageId());
            } catch (\Exception $e) {
                $lastError = $e;
                $attempt++;

                if ($attempt < self::RETRY_ATTEMPTS) {
                    sleep(self::RETRY_DELAY_SECONDS);
                }
            }
        }

        return SendResult::failed($lastError->getMessage());
    }

    private function processBatch(array $notificationIds): array
    {
        $results = [];

        foreach ($notificationIds as $notificationId) {
            try {
                $notification = $this->loadNotification($notificationId);
                $results[] = $this->sendNotification($notification);
            } catch (\Exception $e) {
                $results[] = SendResult::failed($e->getMessage());
            }
        }

        return $results;
    }
}
