<?php

declare(strict_types=1);

namespace App\Logging\File;

use App\Entity\LogEntry;
use App\Repository\LogEntryRepository;
use App\Service\FileWriter;
use Psr\Log\LoggerInterface;

final class FileLogService
{
    public function __construct(
        private readonly LogEntryRepository $logEntryRepository,
        private readonly FileWriter $fileWriter,
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

        $filename = $this->generateFilename($logEntry);
        $content = $this->formatLogEntry($logEntry);

        try {
            $this->fileWriter->write($filename, $content);

            $logEntry->setWrittenAt(new \DateTimeImmutable());
            $logEntry->setFilePath($filename);
            $this->logEntryRepository->save($logEntry);

            $this->logger->info('Log entry written to file', [
                'log_entry_id' => $logEntryId,
                'filename' => $filename,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to write log entry to file', [
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

    private function generateFilename(LogEntry $logEntry): string
    {
        $date = $logEntry->getCreatedAt()->format('Y-m-d');
        $level = strtoupper($logEntry->getLevel());

        return "/var/logs/app/{$date}_{$level}_{$logEntry->getId()}.log";
    }

    private function formatLogEntry(LogEntry $logEntry): string
    {
        $timestamp = $logEntry->getCreatedAt()->format('Y-m-d H:i:s.u');
        $level = strtoupper($logEntry->getLevel());
        $message = $logEntry->getMessage();
        $context = json_encode($logEntry->getContext());

        return "[{$timestamp}] {$level}: {$message} | Context: {$context}\n";
    }
}
