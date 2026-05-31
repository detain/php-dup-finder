<?php

declare(strict_types=1);

namespace Acme\Docs\Coordinator;

use Acme\Docs\Gateway\ConversionGateway;
use Acme\Docs\Repository\ConversionRepository;
use Psr\Log\LoggerInterface;

final class DocumentConversionCoordinator
{
    private const POLL_INTERVAL = 2;
    private const TIMEOUT = 120;

    public function __construct(
        private readonly ConversionGateway $gateway,
        private readonly ConversionRepository $repo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(int $documentId, string $targetFormat): string
    {
        $jobId = $this->gateway->submit($documentId, $targetFormat);
        $this->repo->markPending($documentId, $jobId);
        $this->logger->info('conversion dispatched', ['doc_id' => $documentId, 'job_id' => $jobId]);

        $start = time();
        while (true) {
            sleep(self::POLL_INTERVAL);
            $status = $this->gateway->checkStatus($jobId);

            if ($status['state'] === 'done') {
                $this->repo->markComplete($documentId, $status['result_url']);
                $this->logger->info('conversion done', ['doc_id' => $documentId, 'url' => $status['result_url']]);
                return $status['result_url'];
            }

            if ($status['state'] === 'failed') {
                $this->repo->markFailed($documentId, $status['error']);
                throw new \RuntimeException("conversion failed: {$status['error']}");
            }

            if ((time() - $start) > self::TIMEOUT) {
                $this->repo->markFailed($documentId, 'timeout');
                throw new \RuntimeException('conversion timed out');
            }
        }
    }
}
