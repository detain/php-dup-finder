<?php
declare(strict_types=1);

namespace Acme\Workers\Report;

use Acme\Queue\Channel;
use Acme\Queue\Message;
use Acme\Workers\DeadLetter;
use Acme\Workers\Metrics;
use Acme\Reporting\ReportBuilder;
use Psr\Log\LoggerInterface;

final class ReportWorker
{
    public function __construct(
        private readonly Channel $channel,
        private readonly DeadLetter $dlq,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $log,
        private readonly ReportBuilder $builder,
        private readonly int $maxAttempts = 3
    ) {
    }

    public function run(): void
    {
        while (true) {
            $msg = $this->channel->reserve('report-jobs', 30);
            if ($msg === null) {
                continue;
            }
            $start = microtime(true);
            try {
                $payload = json_decode($msg->body(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || !isset($payload['report_id'], $payload['user_id'])) {
                    throw new \RuntimeException('payload missing report_id/user_id');
                }
                $this->builder->build(
                    (int)$payload['report_id'],
                    (int)$payload['user_id']
                );
                $this->channel->ack($msg);
                $this->metrics->increment('report.built');
            } catch (\Throwable $e) {
                $this->log->error('report job failed', [
                    'id' => $msg->id(),
                    'err' => $e->getMessage(),
                ]);
                if ($msg->attempts() >= $this->maxAttempts) {
                    $this->dlq->store('report-jobs', $msg, $e);
                    $this->channel->discard($msg);
                    $this->metrics->increment('report.dead');
                } else {
                    $this->channel->release($msg, min(60 * $msg->attempts(), 600));
                    $this->metrics->increment('report.retried');
                }
            } finally {
                $this->metrics->timing('report.duration', microtime(true) - $start);
            }
        }
    }
}
