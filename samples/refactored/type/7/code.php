<?php
declare(strict_types=1);

namespace Acme\Workers;

use Acme\Queue\Channel;
use Acme\Queue\Message;
use Psr\Log\LoggerInterface;

interface JobHandler
{
    public function queue(): string;
    public function maxAttempts(): int;
    public function metricsPrefix(): string;
    /** @param array<string,mixed> $payload */
    public function handle(array $payload): void;
}

final class GenericQueueWorker
{
    public function __construct(
        private readonly Channel $channel,
        private readonly DeadLetter $dlq,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $log
    ) {
    }

    public function run(JobHandler $handler): void
    {
        $queue  = $handler->queue();
        $prefix = $handler->metricsPrefix();
        $max    = $handler->maxAttempts();

        while (true) {
            $msg = $this->channel->reserve($queue, 30);
            if ($msg === null) {
                continue;
            }
            $start = microtime(true);
            try {
                $payload = json_decode($msg->body(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new \RuntimeException('payload not an object');
                }
                $handler->handle($payload);
                $this->channel->ack($msg);
                $this->metrics->increment("{$prefix}.processed");
            } catch (\Throwable $e) {
                $this->log->error("{$queue} job failed", [
                    'id' => $msg->id(),
                    'err' => $e->getMessage(),
                ]);
                if ($msg->attempts() >= $max) {
                    $this->dlq->store($queue, $msg, $e);
                    $this->channel->discard($msg);
                    $this->metrics->increment("{$prefix}.dead");
                } else {
                    $this->channel->release($msg, min(60 * $msg->attempts(), 600));
                    $this->metrics->increment("{$prefix}.retried");
                }
            } finally {
                $this->metrics->timing("{$prefix}.duration", microtime(true) - $start);
            }
        }
    }
}
