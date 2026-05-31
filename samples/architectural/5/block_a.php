<?php
declare(strict_types=1);

namespace App\Queue\Email;

final class EmailProducer
{
    public function __construct(private \Redis $redis) {}

    public function enqueue(string $to, string $subject, string $body): void
    {
        $job = ['to' => $to, 'subject' => $subject, 'body' => $body, 'attempts' => 0];
        $this->redis->lPush('queue:email', json_encode($job));
    }
}

final class EmailWorker
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private \Redis $redis, private \Psr\Log\LoggerInterface $log) {}

    public function consume(): bool
    {
        $raw = $this->redis->brPop(['queue:email'], 5);
        if (!$raw) {
            return false;
        }
        $job = json_decode($raw[1], true);
        try {
            $this->process($job);
            $this->log->info('email sent', ['to' => $job['to']]);
        } catch (\Throwable $e) {
            $job['attempts'] = ($job['attempts'] ?? 0) + 1;
            $job['lastError'] = $e->getMessage();
            $target = $job['attempts'] >= self::MAX_ATTEMPTS ? 'dlq:email' : 'queue:email';
            $this->redis->lPush($target, json_encode($job));
            $this->log->warning('email failed', ['attempt' => $job['attempts'], 'target' => $target]);
        }
        return true;
    }

    private function process(array $job): void
    {
        // SMTP send
        mail($job['to'], $job['subject'], $job['body']);
    }
}
