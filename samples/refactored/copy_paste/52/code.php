<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\EmailMessage;
use Psr\Log\LoggerInterface;

abstract class NotificationService
{
    protected const EMAIL_ENABLED = true;
    protected const SENDER_EMAIL = 'noreply@example.com';
    protected const SENDER_NAME = 'System';
    protected const BATCH_SIZE = 50;
    protected const MAX_RETRIES = 3;
    protected const RETRY_DELAY = 1000;
    protected const TRACK_OPEN_RATE = true;
    protected const TRACK_CLICK_RATE = true;
    protected const ADD_UNSUBSCRIBE_LINK = true;
    protected const UNSUBSCRIBE_URL = 'https://example.com/unsubscribe';
    protected const MAX_RECIPIENTS = 100;
    protected const TIMEOUT_SECONDS = 30;
    protected const PRIORITY_HIGH = 1;
    protected const PRIORITY_NORMAL = 3;
    protected const PRIORITY_LOW = 5;

    protected LoggerInterface $logger;

    protected function buildEmail(string $to, string $subject, string $template, array $variables, int $priority = self::PRIORITY_NORMAL): EmailMessage
    {
        $message = new EmailMessage();
        $message->setTo($to);
        $message->setSubject($subject);
        $message->setTemplate($template);
        $message->setVariables($variables);
        $message->setFrom(self::SENDER_EMAIL, self::SENDER_NAME);
        $message->setPriority($priority);

        if (self::ADD_UNSUBSCRIBE_LINK) {
            $message->addHeader('List-Unsubscribe', self::UNSUBSCRIBE_URL . '?email=' . urlencode($to));
        }

        if (self::TRACK_OPEN_RATE) {
            $message->addHeader('X-Track-Opens', 'true');
        }

        return $message;
    }

    protected function sendWithRetry(EmailMessage $message, array $context): bool
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $this->mailer->send($message);
                $this->logger->info('Email sent', ['attempt' => $attempt + 1, 'context' => $context]);
                return true;
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt >= self::MAX_RETRIES) {
                    return false;
                }
                usleep(self::RETRY_DELAY * 1000 * $attempt);
            }
        }

        return false;
    }

    abstract protected function loadTemplate(string $name): string;
    abstract protected function prepareVariables(mixed $entity): array;
}
