<?php

declare(strict_types=1);

namespace App\Logging\Syslog;

use App\Entity\LogEntry;
use App\Repository\LogEntryRepository;
use App\Service\SyslogWriter;
use Psr\Log\LoggerInterface;

final class SyslogLogService
{
    public function __construct(
        private readonly LogEntryRepository $logEntryRepository,
        private readonly SyslogWriter $syslogWriter,
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

        $facility = $this->getFacility($logEntry);
        $priority = $this->calculatePriority($logEntry);
        $message = $this->formatMessage($logEntry);

        try {
            $this->syslogWriter->write($facility, $priority, $message);

            $logEntry->setWrittenAt(new \DateTimeImmutable());
            $this->logEntryRepository->save($logEntry);

            $this->logger->info('Log entry written to syslog', [
                'log_entry_id' => $logEntryId,
                'facility' => $facility,
                'priority' => $priority,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to write log entry to syslog', [
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

    private function getFacility(LogEntry $logEntry): int
    {
        return LOG_USER;
    }

    private function calculatePriority(LogEntry $logEntry): int
    {
        $levelMap = [
            'emergency' => LOG_EMERG,
            'alert' => LOG_ALERT,
            'critical' => LOG_CRIT,
            'error' => LOG_ERR,
            'warning' => LOG_WARNING,
            'notice' => LOG_NOTICE,
            'info' => LOG_INFO,
            'debug' => LOG_DEBUG,
        ];

        return $levelMap[$logEntry->getLevel()] ?? LOG_INFO;
    }

    private function formatMessage(LogEntry $logEntry): string
    {
        $timestamp = $logEntry->getCreatedAt()->format('Y-m-d H:i:s.u');
        $message = $logEntry->getMessage();
        $context = json_encode($logEntry->getContext());

        return "[{$timestamp}] {$message} | {$context}";
    }
}
