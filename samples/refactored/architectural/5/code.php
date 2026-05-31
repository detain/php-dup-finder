<?php
declare(strict_types=1);

namespace App\Queue;

interface JobHandler
{
    public function handle(array $payload): void;
}

final class Producer
{
    public function __construct(private \Redis $redis, private string $queue) {}

    public function enqueue(array $payload): void
    {
        $payload['attempts'] = 0;
        $this->redis->lPush("queue:{$this->queue}", json_encode($payload));
    }
}

final class Worker
{
    public function __construct(
        private \Redis $redis,
        private \Psr\Log\LoggerInterface $log,
        private string $queue,
        private JobHandler $handler,
        private int $maxAttempts = 3,
    ) {}

    public function consume(int $timeoutSeconds = 5): bool
    {
        $raw = $this->redis->brPop(["queue:{$this->queue}"], $timeoutSeconds);
        if (!$raw) {
            return false;
        }
        $job = json_decode($raw[1], true);
        try {
            $this->handler->handle($job);
            $this->log->info("{$this->queue} ok");
        } catch (\Throwable $e) {
            $job['attempts'] = ($job['attempts'] ?? 0) + 1;
            $job['lastError'] = $e->getMessage();
            $target = $job['attempts'] >= $this->maxAttempts
                ? "dlq:{$this->queue}"
                : "queue:{$this->queue}";
            $this->redis->lPush($target, json_encode($job));
            $this->log->warning("{$this->queue} failed", ['attempt' => $job['attempts'], 'target' => $target]);
        }
        return true;
    }
}
