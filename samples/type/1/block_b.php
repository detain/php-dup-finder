<?php
declare(strict_types=1);

namespace Acme\Notifications\Sms;

use Acme\Notifications\TemplateRepository;
use Acme\Notifications\OutboundLog;
use Acme\Notifications\EventBus;
use Acme\Notifications\RetryPolicy;
use Acme\Notifications\Exceptions\DispatchException;
use Twilio\Rest\Client as TwilioClient;

final class SmsNotifier
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly OutboundLog $log,
        private readonly EventBus $events,
        private readonly RetryPolicy $retry,
        private readonly TwilioClient $twilio,
        private readonly string $fromNumber
    ) {
    }

    public function send(string $recipientId, string $templateKey, array $vars): string
    {
        $template = $this->templates->findByKey($templateKey, 'sms');
        if ($template === null) {
            throw new DispatchException("SMS template {$templateKey} missing");
        }

        $rendered = $this->render($template->body, $vars);
        $messageId = bin2hex(random_bytes(8));

        $this->log->record($messageId, 'sms', $recipientId, $templateKey, 'queued');
        $this->events->emit('notification.queued', [
            'id' => $messageId,
            'channel' => 'sms',
            'recipient' => $recipientId,
        ]);

        $attempt = 0;
        do {
            try {
                $this->twilio->messages->create($recipientId, [
                    'from' => $this->fromNumber,
                    'body' => $rendered,
                ]);
                $this->log->update($messageId, 'sent');
                $this->events->emit('notification.sent', ['id' => $messageId]);
                return $messageId;
            } catch (\Throwable $e) {
                $attempt++;
                if (!$this->retry->shouldRetry($attempt, $e)) {
                    $this->log->update($messageId, 'failed', $e->getMessage());
                    $this->events->emit('notification.failed', ['id' => $messageId]);
                    throw new DispatchException('SMS dispatch failed', 0, $e);
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
