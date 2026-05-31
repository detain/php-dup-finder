<?php
declare(strict_types=1);

namespace MediaProcessing\Jobs;

use League\StatsD\Client as StatsD;
use Psr\Log\LoggerInterface;

final class AudioWaveformJob
{
    public function __construct(
        private StatsD $statsd,
        private AudioAnalyzer $audio,
        private WaveformStore $store,
        private LoggerInterface $log,
    ) {}

    public function run(string $audioId): void
    {
        $start = hrtime(true);
        $this->statsd->increment('job.waveform.started');
        try {
            $samples = $this->audio->loadSamples($audioId);
            $peaks   = $this->audio->computePeaks($samples, 1024);
            $this->store->save($audioId, $peaks);
            $this->statsd->increment('job.waveform.success');
        } catch (\Throwable $e) {
            $this->statsd->increment('job.waveform.failure');
            $this->log->error('waveform.failed', [
                'audio' => $audioId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->statsd->timing('job.waveform.duration_ms', $elapsedMs);
            $this->log->info('waveform.duration', [
                'audio' => $audioId,
                'ms'    => $elapsedMs,
            ]);
        }
    }
}
