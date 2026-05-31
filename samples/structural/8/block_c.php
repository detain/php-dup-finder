<?php

declare(strict_types=1);

namespace Acme\Reports\Coordinator;

use Acme\Reports\Gateway\CompilerGateway;
use Acme\Reports\Repository\CompilationRepository;
use Psr\Log\LoggerInterface;

final class ReportCompilationCoordinator
{
    private const POLL_INTERVAL = 5;
    private const TIMEOUT = 600;

    public function __construct(
        private readonly CompilerGateway $gateway,
        private readonly CompilationRepository $repo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(int $reportId, array $parameters): string
    {
        $jobId = $this->gateway->queue($reportId, $parameters);
        $this->repo->start($reportId, $jobId);
        $this->logger->info('report compilation dispatched', ['report_id' => $reportId, 'job_id' => $jobId]);

        $start = time();
        while (true) {
            sleep(self::POLL_INTERVAL);
            $status = $this->gateway->describe($jobId);

            if ($status['status'] === 'succeeded') {
                $this->repo->finish($reportId, $status['artifact_uri']);
                $this->logger->info('compilation succeeded', ['report_id' => $reportId, 'uri' => $status['artifact_uri']]);
                return $status['artifact_uri'];
            }

            if ($status['status'] === 'errored') {
                $this->repo->error($reportId, $status['detail']);
                throw new \RuntimeException("compilation errored: {$status['detail']}");
            }

            if ((time() - $start) > self::TIMEOUT) {
                $this->repo->error($reportId, 'timeout');
                throw new \RuntimeException('compilation timed out');
            }
        }
    }
}
