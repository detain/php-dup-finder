<?php
declare(strict_types=1);

namespace Acme\Notifications\Push;

use Acme\Notifications\TemplateRepository;
use Acme\Notifications\OutboundLog;
use Acme\Notifications\EventBus;
use Acme\Notifications\RetryPolicy;
use Acme\Notifications\Exceptions\DispatchException;
use Acme\Notifications\Fcm\FcmClient;

final class PushNotifier
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly OutboundLog $log,
        private readonly EventBus $events,
        private readonly RetryPolicy $retry,
        private readonly FcmClient $fcm
    ) {
    }

    public function send(string $recipientId, string $templateKey, array $vars): string
    {
        $template = $this->templates->findByKey($templateKey, 'push');
        if ($template === null) {
            throw new DispatchException("Push template {$templateKey} missing");
        }

        $body  = $this->render($template->body, $vars);
        $title = $this->render($template->subject ?? '', $vars);
        $messageId = bin2hex(random_bytes(8));

        $this->log->record($messageId, 'push', $recipientId, $templateKey, 'queued');
        $this->events->emit('notification.queued', [
            'id' => $messageId,
            'channel' => 'push',
            'recipient' => $recipientId,
        ]);

        $attempt = 0;
        do {
            try {
                $this->fcm->sendToToken($recipientId, [
                    'title' => $title,
                    'body'  => $body,
                ]);
                $this->log->update($messageId, 'sent');
                $this->events->emit('notification.sent', ['id' => $messageId]);
                return $messageId;
            } catch (\Throwable $e) {
                $attempt++;
                if (!$this->retry->shouldRetry($attempt, $e)) {
                    $this->log->update($messageId, 'failed', $e->getMessage());
                    $this->events->emit('notification.failed', ['id' => $messageId]);
                    throw new DispatchException('Push dispatch failed', 0, $e);
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
