<?php

declare(strict_types=1);

namespace App\Worker;

use App\Entity\Worker;
use App\Repository\WorkerSessionRepository;
use App\Repository\WorkerRepository;
use Psr\Log\LoggerInterface;

final class BackgroundJobSessionManager
{
    private const HEARTBEAT_TIMEOUT = 300;
    private const SESSION_MAX_DURATION = 43200;

    public function __construct(
        private readonly WorkerSessionRepository $sessionRepository,
        private readonly WorkerRepository $workerRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function validateWorkerSession(int $sessionId): ?Worker
    {
        $session = $this->sessionRepository->findActive($sessionId);

        if ($session === null) {
            $this->logger->debug('Worker session not found or expired', ['session_id' => $sessionId]);
            return null;
        }

        if ($session->isTerminated()) {
            $this->logger->info('Worker session terminated', ['session_id' => $sessionId]);
            $this->sessionRepository->markTerminated($session);
            return null;
        }

        $lastHeartbeat = $session->getLastHeartbeatAt();
        if ($lastHeartbeat !== null) {
            $secondsSinceHeartbeat = time() - $lastHeartbeat->getTimestamp();

            if ($secondsSinceHeartbeat > self::HEARTBEAT_TIMEOUT) {
                $this->logger->info('Worker session timed out - no heartbeat', [
                    'session_id' => $sessionId,
                    'seconds_idle' => $secondsSinceHeartbeat,
                ]);
                $this->sessionRepository->markTerminated($session);
                return null;
            }
        }

        $startedAt = $session->getStartedAt();
        $totalDuration = time() - $startedAt->getTimestamp();
        if ($totalDuration > self::SESSION_MAX_DURATION) {
            $this->logger->info('Worker session reached maximum duration', [
                'session_id' => $sessionId,
                'duration_seconds' => $totalDuration,
            ]);
            $this->sessionRepository->markTerminated($session);
            return null;
        }

        $worker = $this->workerRepository->find($session->getWorkerId());
        if ($worker === null || !$worker->isAvailable()) {
            $this->logger->warning('Worker not found or unavailable for session', [
                'session_id' => $sessionId,
                'worker_id' => $session->getWorkerId(),
            ]);
            return null;
        }

        $session->recordHeartbeat();
        $this->sessionRepository->save($session);

        $this->logger->debug('Worker session validated successfully', [
            'session_id' => $sessionId,
            'worker_id' => $worker->getId(),
        ]);

        return $worker;
    }

    public function createWorkerSession(Worker $worker): int
    {
        $session = new WorkerSession();
        $session->setWorkerId($worker->getId());
        $session->setStartedAt(new \DateTimeImmutable());
        $session->setLastHeartbeatAt(new \DateTimeImmutable());
        $session->setHostname(gethostname());
        $session->setPid(getmypid());

        $this->sessionRepository->save($session);

        $this->logger->info('Worker session created', [
            'worker_id' => $worker->getId(),
            'session_id' => $session->getId(),
            'hostname' => $session->getHostname(),
            'pid' => $session->getPid(),
        ]);

        return $session->getId();
    }

    public function terminateWorkerSession(int $sessionId): bool
    {
        $session = $this->sessionRepository->findActive($sessionId);

        if ($session === null) {
            return false;
        }

        $this->sessionRepository->markTerminated($session);

        $this->logger->info('Worker session terminated', [
            'session_id' => $sessionId,
            'worker_id' => $session->getWorkerId(),
        ]);

        return true;
    }
}
