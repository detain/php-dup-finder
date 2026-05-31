<?php

declare(strict_types=1);

namespace App\DataProcessing;

final class BatchIdentifierGenerator
{
    public function generateBatchId(string $jobType, int $runNumber, \DateTimeImmutable $timestamp): string
    {
        $datePart = $timestamp->format('Ymd');
        $timePart = $timestamp->format('His');
        $runPart = str_pad((string) ($runNumber % 10000), 4, '0', STR_PAD_LEFT);
        $typePart = strtoupper(substr($jobType, 0, 3));

        return $typePart . $datePart . $timePart . '-' . $runPart;
    }

    public function generateBatchIdWithPriority(string $jobType, int $runNumber, int $priority, \DateTimeImmutable $timestamp): string
    {
        $datePart = $timestamp->format('Ymd');
        $timePart = $timestamp->format('His');
        $priorityPart = str_pad((string) ($priority % 100), 2, '0', STR_PAD_LEFT);
        $runPart = str_pad((string) ($runNumber % 10000), 4, '0', STR_PAD_LEFT);
        $typePart = strtoupper(substr($jobType, 0, 3));

        return $typePart . $priorityPart . $datePart . $timePart . '-' . $runPart;
    }

    public function generateBatchIdWithEnvironment(string $jobType, int $runNumber, string $environment, \DateTimeImmutable $timestamp): string
    {
        $datePart = $timestamp->format('Ymd');
        $timePart = $timestamp->format('His');
        $runPart = str_pad((string) ($runNumber % 10000), 4, '0', STR_PAD_LEFT);
        $typePart = strtoupper(substr($jobType, 0, 3));
        $envPart = strtoupper(substr($environment, 0, 2));

        return $envPart . $typePart . $datePart . $timePart . '-' . $runPart;
    }

    public function parseBatchId(string $batchId): array
    {
        $this->ensureValid($batchId);

        $typePart = substr($batchId, 0, 3);
        $datePart = substr($batchId, 3, 8);
        $timePart = substr($batchId, 11, 6);
        $runPart = substr($batchId, 18);

        return [
            'type' => $typePart,
            'date' => $datePart,
            'time' => $timePart,
            'run_number' => (int) $runPart,
        ];
    }

    public function parseExtendedBatchId(string $batchId): array
    {
        $this->ensureValid($batchId);

        if (strlen($batchId) === 24) {
            $envPart = substr($batchId, 0, 2);
            $typePart = substr($batchId, 2, 3);
            $datePart = substr($batchId, 5, 8);
            $timePart = substr($batchId, 13, 6);
            $runPart = substr($batchId, 20);

            return [
                'environment' => $envPart,
                'type' => $typePart,
                'date' => $datePart,
                'time' => $timePart,
                'run_number' => (int) $runPart,
            ];
        }

        if (strlen($batchId) === 23) {
            $typePart = substr($batchId, 0, 3);
            $priorityPart = substr($batchId, 3, 2);
            $datePart = substr($batchId, 5, 8);
            $timePart = substr($batchId, 13, 6);
            $runPart = substr($batchId, 20);

            return [
                'priority' => (int) $priorityPart,
                'type' => $typePart,
                'date' => $datePart,
                'time' => $timePart,
                'run_number' => (int) $runPart,
            ];
        }

        return $this->parseBatchId($batchId);
    }

    public function isValidBatchId(string $batchId): bool
    {
        if (!preg_match('/^[A-Z]{3}\d{8}\d{6}-\d{4}$/', $batchId)) {
            return false;
        }

        $datePart = substr($batchId, 3, 8);
        $month = (int) substr($datePart, 4, 2);
        $day = (int) substr($datePart, 6, 2);

        if ($month < 1 || $month > 12) {
            return false;
        }

        if ($day < 1 || $day > 31) {
            return false;
        }

        return true;
    }

    public function isValidExtendedBatchId(string $batchId): bool
    {
        if (strlen($batchId) === 24) {
            if (!preg_match('/^[A-Z]{2}[A-Z]{3}\d{8}\d{6}-\d{4}$/', $batchId)) {
                return false;
            }
        }

        if (strlen($batchId) === 23) {
            if (!preg_match('/^[A-Z]{3}\d{2}\d{8}\d{6}-\d{4}$/', $batchId)) {
                return false;
            }
        }

        return $this->isValidBatchId($batchId);
    }

    public function extractTimestamp(string $batchId): \DateTimeImmutable
    {
        $this->ensureValid($batchId);

        $datePart = substr($batchId, 3, 8);
        $timePart = substr($batchId, 11, 6);

        return new \DateTimeImmutable(
            substr($datePart, 0, 4) . '-' . substr($datePart, 4, 2) . '-' . substr($datePart, 6, 2)
            . ' ' . substr($timePart, 0, 2) . ':' . substr($timePart, 2, 2) . ':' . substr($timePart, 4, 2)
        );
    }

    public function extractRunNumber(string $batchId): int
    {
        $this->ensureValid($batchId);

        return (int) substr($batchId, 18);
    }

    public function isProcessed(string $batchId, string $currentBatchId): bool
    {
        return strcmp($batchId, $currentBatchId) < 0;
    }

    public function isSameBatchType(string $batchId1, string $batchId2): bool
    {
        return substr($batchId1, 0, 3) === substr($batchId2, 0, 3);
    }

    private function ensureValid(string $batchId): void
    {
        if (!$this->isValidBatchId($batchId)) {
            throw new \InvalidArgumentException("Invalid batch ID: {$batchId}");
        }
    }
}
