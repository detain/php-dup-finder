<?php

declare(strict_types=1);

namespace App\Logging\Database;

use App\Entity\LogEntry;
use App\Repository\LogEntryRepository;
use App\Service\DatabaseWriter;
use Psr\Log\LoggerInterface;

final class DatabaseLogService
{
    public function __construct(
        private readonly LogEntryRepository $logEntryRepository,
        private readonly DatabaseWriter $databaseWriter,
        private readonly LoggerInterface $logger,
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

        $tableName = $this->getTableName($logEntry);
        $data = $this->prepareData($logEntry);

        try {
            $this->databaseWriter->write($tableName, $data);

            $logEntry->setWrittenAt(new \DateTimeImmutable());
            $this->logEntryRepository->save($logEntry);

            $this->logger->info('Log entry written to database', [
                'log_entry_id' => $logEntryId,
                'table' => $tableName,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to write log entry to database', [
                'log_entry_id' => $logEntryId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function validateLogEntry(LogEntry $logEntry): bool
    {
        if ($logEntry->getLevel() === null) {
            return false;
        }

        if ($logEntry->getMessage() === '') {
            return false;
        }

        if ($logEntry->getContext() === []) {
            return false;
        }

        return true;
    }

    private function getTableName(LogEntry $logEntry): string
    {
        $date = $logEntry->getCreatedAt()->format('Y_m');
        $level = strtolower($logEntry->getLevel());

        return "logs_{$level}_{$date}";
    }

    private function prepareData(LogEntry $logEntry): array
    {
        return [
            'timestamp' => $logEntry->getCreatedAt()->format('Y-m-d H:i:s.u'),
            'level' => $logEntry->getLevel(),
            'message' => $logEntry->getMessage(),
            'context' => json_encode($logEntry->getContext()),
            'source' => $logEntry->getSource(),
        ];
    }
}
