<?php
declare(strict_types=1);

namespace NotifyHub\Notifications\Sms;

use Psr\Log\LoggerInterface;
use NotifyHub\Notifications\Entities\Notification;
use NotifyHub\Notifications\Templates\TemplateEngine;

final class SmsNotificationService
{
    private const FROM_NUMBER = '+15551234567';
    private const FROM_NAME = 'CompanySMS';

    private const SMS_SUBJECT_MAX_LENGTH = 255;
    private const SMS_BODY_MAX_LENGTH = 1600;
    private const SMS_SINGLE_MESSAGE_MAX_LENGTH = 160;

    private const BATCH_SIZE = 50;
    private const RATE_LIMIT_PER_SECOND = 10;
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
        $this->logger->info('Sending SMS notification', [
            'notification_id' => $notification->getId(),
            'recipient' => $notification->getRecipientPhone(),
        ]);

        $this->validateSmsContent($notification);

        $renderedContent = $this->renderSmsTemplate($notification);

        $sms = $this->createSmsMessage($notification, $renderedContent);
        $result = $this->deliverSms($sms);

        $this->logger->info('SMS notification sent', [
            'notification_id' => $notification->getId(),
            'success' => $result->isSuccessful(),
        ]);

        return $result;
    }

    public function sendBatchNotifications(array $notificationIds): BatchSendResult
    {
        $this->logger->info('Sending batch SMS notifications', [
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

    private function validateSmsContent(Notification $notification): void
    {
        $subject = $notification->getSubject();
        if (strlen($subject) > self::SMS_SUBJECT_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'SMS subject exceeds maximum length of %d characters',
                self::SMS_SUBJECT_MAX_LENGTH
            ));
        }

        $body = $notification->getBody();
        if (strlen($body) > self::SMS_BODY_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'SMS body exceeds maximum length of %d characters',
                self::SMS_BODY_MAX_LENGTH
            ));
        }

        if (strlen($body) > self::SMS_SINGLE_MESSAGE_MAX_LENGTH) {
            $this->logger->info('SMS will be split into multiple messages', [
                'notification_id' => $notification->getId(),
                'message_count' => (int)ceil(strlen($body) / self::SMS_SINGLE_MESSAGE_MAX_LENGTH),
            ]);
        }
    }

    private function renderSmsTemplate(Notification $notification): RenderedTemplate
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

    private function createSmsMessage(Notification $notification, RenderedTemplate $content): SmsMessage
    {
        return new SmsMessage(
            from: self::FROM_NUMBER,
            to: $notification->getRecipientPhone(),
            body: $content->getBody(),
            headers: $this->buildSmsHeaders($notification),
        );
    }

    private function buildSmsHeaders(Notification $notification): array
    {
        return [
            self::HEADER_X_PRIORITY => (string)$notification->getPriority(),
            self::HEADER_X_MAILER => 'NotifyHub/SMS v1.0',
            'X-Notification-ID' => $notification->getId(),
            'X-Notification-Type' => $notification->getType(),
        ];
    }

    private function deliverSms(SmsMessage $sms): SendResult
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < self::RETRY_ATTEMPTS) {
            try {
                $response = $this->smsGateway->send($sms);
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
