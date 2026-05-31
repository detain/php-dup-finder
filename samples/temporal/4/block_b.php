<?php
declare(strict_types=1);

namespace MediaProcessing\Jobs;

use League\StatsD\Client as StatsD;
use Psr\Log\LoggerInterface;

final class VideoTranscodeJob
{
    public function __construct(
        private StatsD $statsd,
        private VideoEncoder $encoder,
        private VideoCatalog $catalog,
        private LoggerInterface $log,
    ) {}

    public function run(string $videoId, string $preset): void
    {
        $start = hrtime(true);
        $this->statsd->increment('job.transcode.started');
        try {
            $manifest = $this->catalog->manifest($videoId);
            $output   = $this->encoder->encode($manifest['source'], $preset);
            $this->catalog->recordRendition($videoId, $preset, $output['url'], $output['bytes']);
            $this->statsd->increment('job.transcode.success');
        } catch (\Throwable $e) {
            $this->statsd->increment('job.transcode.failure');
            $this->log->error('transcode.failed', [
                'video' => $videoId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->statsd->timing('job.transcode.duration_ms', $elapsedMs);
            $this->log->info('transcode.duration', [
                'video' => $videoId,
                'ms'    => $elapsedMs,
            ]);
        }
    }
}
