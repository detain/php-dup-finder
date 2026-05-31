<?php
declare(strict_types=1);

namespace App\Queue\Thumbnails;

final class ThumbnailProducer
{
    public function __construct(private \Redis $redis) {}

    public function enqueue(string $sourceUrl, int $width, int $height): void
    {
        $job = ['source' => $sourceUrl, 'w' => $width, 'h' => $height, 'attempts' => 0];
        $this->redis->lPush('queue:thumb', json_encode($job));
    }
}

final class ThumbnailWorker
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private \Redis $redis, private \Psr\Log\LoggerInterface $log) {}

    public function consume(): bool
    {
        $raw = $this->redis->brPop(['queue:thumb'], 5);
        if (!$raw) {
            return false;
        }
        $job = json_decode($raw[1], true);
        try {
            $this->process($job);
            $this->log->info('thumb generated', ['source' => $job['source']]);
        } catch (\Throwable $e) {
            $job['attempts'] = ($job['attempts'] ?? 0) + 1;
            $job['lastError'] = $e->getMessage();
            $target = $job['attempts'] >= self::MAX_ATTEMPTS ? 'dlq:thumb' : 'queue:thumb';
            $this->redis->lPush($target, json_encode($job));
            $this->log->warning('thumb failed', ['attempt' => $job['attempts'], 'target' => $target]);
        }
        return true;
    }

    private function process(array $job): void
    {
        $img = imagecreatefromstring(file_get_contents($job['source']) ?: '');
        if ($img === false) {
            throw new \RuntimeException('decode failed');
        }
        $resized = imagescale($img, (int) $job['w'], (int) $job['h']);
        imagedestroy($resized ?: $img);
    }
}
