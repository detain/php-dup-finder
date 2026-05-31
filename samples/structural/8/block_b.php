<?php

declare(strict_types=1);

namespace Acme\Media\Coordinator;

use Acme\Media\Gateway\RenderingGateway;
use Acme\Media\Repository\RenderJobRepository;
use Psr\Log\LoggerInterface;

final class ImageRenderCoordinator
{
    private const POLL_INTERVAL = 3;
    private const TIMEOUT = 300;

    public function __construct(
        private readonly RenderingGateway $gateway,
        private readonly RenderJobRepository $repo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(int $sceneId, array $renderOpts): string
    {
        $jobId = $this->gateway->enqueue($sceneId, $renderOpts);
        $this->repo->open($sceneId, $jobId);
        $this->logger->info('render dispatched', ['scene_id' => $sceneId, 'job_id' => $jobId]);

        $start = time();
        while (true) {
            sleep(self::POLL_INTERVAL);
            $status = $this->gateway->poll($jobId);

            if ($status['phase'] === 'ready') {
                $this->repo->complete($sceneId, $status['output_path']);
                $this->logger->info('render ready', ['scene_id' => $sceneId, 'path' => $status['output_path']]);
                return $status['output_path'];
            }

            if ($status['phase'] === 'error') {
                $this->repo->fail($sceneId, $status['message']);
                throw new \RuntimeException("render failed: {$status['message']}");
            }

            if ((time() - $start) > self::TIMEOUT) {
                $this->repo->fail($sceneId, 'timeout');
                throw new \RuntimeException('render timed out');
            }
        }
    }
}
