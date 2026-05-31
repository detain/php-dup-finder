<?php
declare(strict_types=1);

namespace Acme\Workers\Image;

use Acme\Queue\Channel;
use Acme\Queue\Message;
use Acme\Workers\DeadLetter;
use Acme\Workers\Metrics;
use Acme\Imaging\ImagePipeline;
use Psr\Log\LoggerInterface;

final class ImageWorker
{
    public function __construct(
        private readonly Channel $channel,
        private readonly DeadLetter $dlq,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $log,
        private readonly ImagePipeline $pipeline,
        private readonly int $maxAttempts = 5
    ) {
    }

    public function run(): void
    {
        while (true) {
            $msg = $this->channel->reserve('image-jobs', 30);
            if ($msg === null) {
                continue;
            }
            $start = microtime(true);
            try {
                $payload = json_decode($msg->body(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || !isset($payload['source'], $payload['target'])) {
                    throw new \RuntimeException('payload missing source/target');
                }
                $this->pipeline->resize((string)$payload['source'], (string)$payload['target']);
                $this->channel->ack($msg);
                $this->metrics->increment('image.processed');
            } catch (\Throwable $e) {
                $this->log->error('image job failed', [
                    'id' => $msg->id(),
                    'err' => $e->getMessage(),
                ]);
                if ($msg->attempts() >= $this->maxAttempts) {
                    $this->dlq->store('image-jobs', $msg, $e);
                    $this->channel->discard($msg);
                    $this->metrics->increment('image.dead');
                } else {
                    $this->channel->release($msg, min(60 * $msg->attempts(), 600));
                    $this->metrics->increment('image.retried');
                }
            } finally {
                $this->metrics->timing('image.duration', microtime(true) - $start);
            }
        }
    }
}
