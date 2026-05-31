<?php

declare(strict_types=1);

namespace Acme\Reports\Runner;

use Acme\Locking\LockManager;
use Acme\Reports\Repository\ReportRunRepository;
use Acme\Reports\Engine\ReportEngine;
use Psr\Log\LoggerInterface;

final class ReportRunExecutor
{
    public function __construct(
        private readonly LockManager $locks,
        private readonly ReportRunRepository $runs,
        private readonly ReportEngine $engine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(int $runId): bool
    {
        $lockKey = "report-run:{$runId}";
        $lock = $this->locks->acquire($lockKey, 1800);
        if ($lock === null) {
            $this->logger->info('report-run lock contended', ['run_id' => $runId]);
            return false;
        }

        try {
            $run = $this->runs->find($runId);
            if ($run === null || $run->state() !== 'scheduled') {
                $this->logger->debug('report-run not eligible', ['id' => $runId, 'state' => $run?->state()]);
                return false;
            }

            $this->runs->updateState($runId, 'running');
            $artifactPath = $this->engine->generate($run->definitionId(), $run->parameters());
            $this->runs->setArtifact($runId, $artifactPath);
            $this->runs->updateState($runId, 'completed');

            $this->logger->info('report-run finished', ['id' => $runId, 'artifact' => $artifactPath]);
            return true;
        } finally {
            $this->locks->release($lock);
        }
    }
}
