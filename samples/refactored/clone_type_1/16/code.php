<?php

declare(strict_types=1);

namespace App\Logging;

use App\Entity\LogEntry;
use App\Repository\LogEntryRepository;
use Psr\Log\LoggerInterface;

interface LogWriterInterface
{
    public function write(LogEntry $logEntry): bool;
    public function supports(LogEntry $logEntry): bool;
}

abstract class AbstractLogService
{
    public function __construct(
        protected readonly LogEntryRepository $logEntryRepository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function writeLog(int $logEntryId): bool
    {
        $logEntry = $this->logEntryRepository->findById($logEntryId);

        if ($logEntry === null) {
            $this->logger->error('Cannot write log entry - not found', [
                'log_entry_id' => $logEntryId,
            ]);
            return false;
        }

        if (!$this->validateLogEntry($logEntry)) {
            $this->logger->warning('Invalid log entry, cannot write', [
                'log_entry_id' => $logEntryId,
            ]);
            return false;
        }

        try {
            $result = $this->doWrite($logEntry);

            if ($result) {
                $logEntry->setWrittenAt(new \DateTimeImmutable());
                $this->logEntryRepository->save($logEntry);

                $this->logger->info('Log entry written', [
                    'log_entry_id' => $logEntryId,
                    'service' => static::class,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to write log entry', [
                'log_entry_id' => $logEntryId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function validateLogEntry(LogEntry $logEntry): bool
    {
        return $logEntry->getLevel() !== null
            && $logEntry->getMessage() !== ''
            && $logEntry->getContext() !== [];
    }

    abstract protected function doWrite(LogEntry $logEntry): bool;
}
