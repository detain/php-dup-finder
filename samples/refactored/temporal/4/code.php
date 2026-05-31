<?php
declare(strict_types=1);

namespace MediaProcessing\Jobs;

use League\StatsD\Client as StatsD;
use Psr\Log\LoggerInterface;

final class JobTimer
{
    public function __construct(private StatsD $statsd, private LoggerInterface $log) {}

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function timed(string $jobName, array $context, callable $work)
    {
        $start = hrtime(true);
        $this->statsd->increment("job.{$jobName}.started");
        try {
            $result = $work();
            $this->statsd->increment("job.{$jobName}.success");
            return $result;
        } catch (\Throwable $e) {
            $this->statsd->increment("job.{$jobName}.failure");
            $this->log->error("{$jobName}.failed", $context + ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->statsd->timing("job.{$jobName}.duration_ms", $ms);
            $this->log->info("{$jobName}.duration", $context + ['ms' => $ms]);
        }
    }
}

final class ThumbnailGenerationJob
{
    public function __construct(private JobTimer $timer, private ImageProcessor $images) {}

    public function run(string $assetId, int $width, int $height): void
    {
        $this->timer->timed('thumbnail', ['asset' => $assetId], function () use ($assetId, $width, $height) {
            $original = $this->images->load($assetId);
            $resized  = $this->images->resize($original, $width, $height);
            $this->images->store($assetId, $resized, "{$width}x{$height}");
        });
    }
}
