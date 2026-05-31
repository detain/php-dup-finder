<?php
declare(strict_types=1);

namespace MediaProcessing\Jobs;

use League\StatsD\Client as StatsD;
use Psr\Log\LoggerInterface;

final class ThumbnailGenerationJob
{
    public function __construct(
        private StatsD $statsd,
        private ImageProcessor $images,
        private LoggerInterface $log,
    ) {}

    public function run(string $assetId, int $width, int $height): void
    {
        $start = hrtime(true);
        $this->statsd->increment('job.thumbnail.started');
        try {
            $original = $this->images->load($assetId);
            $resized  = $this->images->resize($original, $width, $height);
            $this->images->store($assetId, $resized, "{$width}x{$height}");
            $this->statsd->increment('job.thumbnail.success');
        } catch (\Throwable $e) {
            $this->statsd->increment('job.thumbnail.failure');
            $this->log->error('thumbnail.failed', [
                'asset'  => $assetId,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->statsd->timing('job.thumbnail.duration_ms', $elapsedMs);
            $this->log->info('thumbnail.duration', [
                'asset' => $assetId,
                'ms'    => $elapsedMs,
            ]);
        }
    }
}
