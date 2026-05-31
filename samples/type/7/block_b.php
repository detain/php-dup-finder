<?php
declare(strict_types=1);

namespace Acme\Workers\Email;

use Acme\Queue\Channel;
use Acme\Queue\Message;
use Acme\Workers\DeadLetter;
use Acme\Workers\Metrics;
use Acme\Mail\Mailer;
use Psr\Log\LoggerInterface;

final class EmailWorker
{
    public function __construct(
        private readonly Channel $channel,
        private readonly DeadLetter $dlq,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $log,
        private readonly Mailer $mailer,
        private readonly int $maxAttempts = 5
    ) {
    }

    public function run(): void
    {
        while (true) {
            $msg = $this->channel->reserve('email-jobs', 30);
            if ($msg === null) {
                continue;
            }
            $start = microtime(true);
            try {
                $payload = json_decode($msg->body(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || !isset($payload['to'], $payload['subject'])) {
                    throw new \RuntimeException('payload missing to/subject');
                }
                $this->mailer->send(
                    (string)$payload['to'],
                    (string)$payload['subject'],
                    (string)($payload['body'] ?? '')
                );
                $this->channel->ack($msg);
                $this->metrics->increment('email.sent');
            } catch (\Throwable $e) {
                $this->log->error('email job failed', [
                    'id' => $msg->id(),
                    'err' => $e->getMessage(),
                ]);
                if ($msg->attempts() >= $this->maxAttempts) {
                    $this->dlq->store('email-jobs', $msg, $e);
                    $this->channel->discard($msg);
                    $this->metrics->increment('email.dead');
                } else {
                    $this->channel->release($msg, min(60 * $msg->attempts(), 600));
                    $this->metrics->increment('email.retried');
                }
            } finally {
                $this->metrics->timing('email.duration', microtime(true) - $start);
            }
        }
    }
}
