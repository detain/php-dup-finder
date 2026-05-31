<?php
declare(strict_types=1);

namespace Acme\Notifications\Email;

use Acme\Notifications\TemplateRepository;
use Acme\Notifications\OutboundLog;
use Acme\Notifications\EventBus;
use Acme\Notifications\RetryPolicy;
use Acme\Notifications\Exceptions\DispatchException;

final class EmailNotifier
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly OutboundLog $log,
        private readonly EventBus $events,
        private readonly RetryPolicy $retry,
        private readonly \PHPMailer $mailer
    ) {
    }

    public function send(string $recipientId, string $templateKey, array $vars): string
    {
        $template = $this->templates->findByKey($templateKey, 'email');
        if ($template === null) {
            throw new DispatchException("Email template {$templateKey} missing");
        }

        $rendered = $this->render($template->body, $vars);
        $subject  = $this->render($template->subject ?? '', $vars);
        $messageId = bin2hex(random_bytes(8));

        $this->log->record($messageId, 'email', $recipientId, $templateKey, 'queued');
        $this->events->emit('notification.queued', [
            'id' => $messageId,
            'channel' => 'email',
            'recipient' => $recipientId,
        ]);

        $attempt = 0;
        do {
            try {
                $this->mailer->clearAddresses();
                $this->mailer->addAddress($recipientId);
                $this->mailer->Subject = $subject;
                $this->mailer->Body    = $rendered;
                $this->mailer->send();
                $this->log->update($messageId, 'sent');
                $this->events->emit('notification.sent', ['id' => $messageId]);
                return $messageId;
            } catch (\Throwable $e) {
                $attempt++;
                if (!$this->retry->shouldRetry($attempt, $e)) {
                    $this->log->update($messageId, 'failed', $e->getMessage());
                    $this->events->emit('notification.failed', ['id' => $messageId]);
                    throw new DispatchException('Email dispatch failed', 0, $e);
                }
                usleep($this->retry->backoffMicros($attempt));
            }
        } while (true);
    }

    private function render(string $body, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $body = str_replace('{{' . $k . '}}', (string)$v, $body);
        }
        return $body;
    }
}
