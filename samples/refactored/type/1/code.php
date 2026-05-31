<?php
declare(strict_types=1);

namespace Acme\Notifications;

use Acme\Notifications\Exceptions\DispatchException;

interface NotificationTransport
{
    public function channelName(): string;
    public function deliver(string $recipientId, string $renderedSubject, string $renderedBody): void;
}

final class NotificationDispatcher
{
    /** @param array<string, NotificationTransport> $transports */
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly OutboundLog $log,
        private readonly EventBus $events,
        private readonly RetryPolicy $retry,
        private readonly array $transports
    ) {
    }

    public function send(string $channel, string $recipientId, string $templateKey, array $vars): string
    {
        if (!isset($this->transports[$channel])) {
            throw new DispatchException("No transport for channel {$channel}");
        }
        $transport = $this->transports[$channel];

        $template = $this->templates->findByKey($templateKey, $channel);
        if ($template === null) {
            throw new DispatchException("Template {$templateKey} missing for {$channel}");
        }

        $body    = $this->render($template->body, $vars);
        $subject = $this->render($template->subject ?? '', $vars);
        $messageId = bin2hex(random_bytes(8));

        $this->log->record($messageId, $channel, $recipientId, $templateKey, 'queued');
        $this->events->emit('notification.queued', [
            'id' => $messageId,
            'channel' => $channel,
            'recipient' => $recipientId,
        ]);

        $attempt = 0;
        do {
            try {
                $transport->deliver($recipientId, $subject, $body);
                $this->log->update($messageId, 'sent');
                $this->events->emit('notification.sent', ['id' => $messageId]);
                return $messageId;
            } catch (\Throwable $e) {
                $attempt++;
                if (!$this->retry->shouldRetry($attempt, $e)) {
                    $this->log->update($messageId, 'failed', $e->getMessage());
                    $this->events->emit('notification.failed', ['id' => $messageId]);
                    throw new DispatchException("{$channel} dispatch failed", 0, $e);
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
