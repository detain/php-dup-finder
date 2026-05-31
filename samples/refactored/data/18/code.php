<?php
declare(strict_types=1);

namespace Notifications\Shared;

final class NotificationLimits
{
    public const SUBJECT_MAX_LENGTH = 255;
    public const BODY_MAX_LENGTH = 50000;
    public const HTML_BODY_MAX_LENGTH = 100000;
    public const DATA_MAX_LENGTH = 4000;
}

final class BatchConfig
{
    public const EMAIL_BATCH_SIZE = 50;
    public const SMS_BATCH_SIZE = 50;
    public const PUSH_BATCH_SIZE = 100;
    public const RATE_LIMIT_PER_SECOND = 10;
}

final class RetryConfig
{
    public const ATTEMPTS = 3;
    public const DELAY_SECONDS = 5;
}

final class TemplateConfig
{
    public const CACHE_TTL_SECONDS = 3600;
    public const VARIABLE_MAX_LENGTH = 1000;
    public const MAX_VARIABLES = 50;
}

interface NotificationSenderInterface
{
    public function send(Notification $notification): SendResult;
    public function sendBatch(array $notificationIds): BatchSendResult;
}

trait NotificationSendingLogic
{
    private NotificationLimits $limits;
    private BatchConfig $batchConfig;
    private RetryConfig $retryConfig;
    private TemplateConfig $templateConfig;
    private TemplateEngine $templateEngine;
    private LoggerInterface $logger;

    protected function validateContent(Notification $notification): void
    {
        $subject = $notification->getSubject();
        if (strlen($subject) > $this->limits::SUBJECT_MAX_LENGTH) {
            throw new \InvalidArgumentException('Subject exceeds maximum length');
        }

        $body = $notification->getBody();
        if (strlen($body) > $this->limits::BODY_MAX_LENGTH) {
            throw new \InvalidArgumentException('Body exceeds maximum length');
        }
    }

    protected function renderTemplate(Notification $notification): RenderedTemplate
    {
        $variables = $notification->getVariables();

        if (count($variables) > $this->templateConfig::MAX_VARIABLES) {
            throw new \InvalidArgumentException('Too many template variables');
        }

        foreach ($variables as $key => $value) {
            if (strlen((string)$value) > $this->templateConfig::VARIABLE_MAX_LENGTH) {
                throw new \InvalidArgumentException("Variable {$key} exceeds max length");
            }
        }

        return $this->templateEngine->render($notification->getTemplateName(), $variables);
    }

    protected function deliverWithRetry(callable $sendFn): SendResult
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->retryConfig::ATTEMPTS) {
            try {
                return $sendFn();
            } catch (\Exception $e) {
                $lastError = $e;
                $attempt++;

                if ($attempt < $this->retryConfig::ATTEMPTS) {
                    sleep($this->retryConfig::DELAY_SECONDS);
                }
            }
        }

        return SendResult::failed($lastError->getMessage());
    }

    protected function processBatch(array $notificationIds, callable $sendFn): array
    {
        $results = [];
        $processed = 0;

        foreach (array_chunk($notificationIds, $this->batchConfig::EMAIL_BATCH_SIZE) as $batch) {
            foreach ($batch as $notificationId) {
                try {
                    $notification = $this->loadNotification($notificationId);
                    $results[] = $sendFn($notification);
                } catch (\Exception $e) {
                    $results[] = SendResult::failed($e->getMessage());
                }
            }

            $processed += count($batch);
            usleep(1000000 / $this->batchConfig::RATE_LIMIT_PER_SECOND);
        }

        return $results;
    }
}
