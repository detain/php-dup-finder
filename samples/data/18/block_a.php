<?php
declare(strict_types=1);

namespace NotifyHub\Notifications\Email;

use Psr\Log\LoggerInterface;
use NotifyHub\Notifications\Entities\Notification;
use NotifyHub\Notifications\Templates\TemplateEngine;

final class EmailNotificationService
{
    private const FROM_EMAIL = 'notifications@company.com';
    private const FROM_NAME = 'Company Notifications';
    private const REPLY_TO_EMAIL = 'support@company.com';

    private const EMAIL_SUBJECT_MAX_LENGTH = 255;
    private const EMAIL_BODY_MAX_LENGTH = 50000;
    private const EMAIL_HTML_BODY_MAX_LENGTH = 100000;

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
        $this->logger->info('Sending email notification', [
            'notification_id' => $notification->getId(),
            'recipient' => $notification->getRecipientEmail(),
        ]);

        $this->validateEmailContent($notification);

        $renderedContent = $this->renderEmailTemplate($notification);

        $email = $this->createEmailMessage($notification, $renderedContent);
        $result = $this->deliverEmail($email);

        $this->logger->info('Email notification sent', [
            'notification_id' => $notification->getId(),
            'success' => $result->isSuccessful(),
        ]);

        return $result;
    }

    public function sendBatchNotifications(array $notificationIds): BatchSendResult
    {
        $this->logger->info('Sending batch email notifications', [
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

    private function validateEmailContent(Notification $notification): void
    {
        $subject = $notification->getSubject();
        if (strlen($subject) > self::EMAIL_SUBJECT_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Email subject exceeds maximum length of %d characters',
                self::EMAIL_SUBJECT_MAX_LENGTH
            ));
        }

        $body = $notification->getBody();
        if (strlen($body) > self::EMAIL_BODY_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Email body exceeds maximum length of %d characters',
                self::EMAIL_BODY_MAX_LENGTH
            ));
        }

        $htmlBody = $notification->getHtmlBody() ?? '';
        if (strlen($htmlBody) > self::EMAIL_HTML_BODY_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Email HTML body exceeds maximum length of %d characters',
                self::EMAIL_HTML_BODY_MAX_LENGTH
            ));
        }
    }

    private function renderEmailTemplate(Notification $notification): RenderedTemplate
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

    private function createEmailMessage(Notification $notification, RenderedTemplate $content): EmailMessage
    {
        return new EmailMessage(
            from: self::FROM_EMAIL,
            fromName: self::FROM_NAME,
            replyTo: self::REPLY_TO_EMAIL,
            to: $notification->getRecipientEmail(),
            subject: $content->getSubject(),
            body: $content->getBody(),
            htmlBody: $content->getHtmlBody(),
            headers: $this->buildEmailHeaders($notification),
        );
    }

    private function buildEmailHeaders(Notification $notification): array
    {
        return [
            self::HEADER_X_PRIORITY => (string)$notification->getPriority(),
            self::HEADER_X_MAILER => 'NotifyHub/Email v1.0',
            self::HEADER_LIST_UNSUBSCRIBE => 'mailto:unsubscribe@company.com?subject=unsubscribe',
            'X-Notification-ID' => $notification->getId(),
            'X-Notification-Type' => $notification->getType(),
        ];
    }

    private function deliverEmail(EmailMessage $email): SendResult
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < self::RETRY_ATTEMPTS) {
            try {
                $response = $this->smtpClient->send($email);
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
