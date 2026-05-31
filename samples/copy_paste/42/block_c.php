<?php

declare(strict_types=1);

namespace App\Jobs;

final class TaskBatchIdentifierBuilder
{
    public function buildBatchId(string $taskType, int $batchNum, \DateTimeImmutable $when): string
    {
        $dateToken = $when->format('Ymd');
        $timeToken = $when->format('His');
        $numToken = str_pad((string) ($batchNum % 10000), 4, '0', STR_PAD_LEFT);
        $typeToken = strtoupper(substr($taskType, 0, 3));

        return $typeToken . $dateToken . $timeToken . '-' . $numToken;
    }

    public function buildBatchIdWithTier(string $taskType, int $batchNum, int $tier, \DateTimeImmutable $when): string
    {
        $dateToken = $when->format('Ymd');
        $timeToken = $when->format('His');
        $tierToken = str_pad((string) ($tier % 100), 2, '0', STR_PAD_LEFT);
        $numToken = str_pad((string) ($batchNum % 10000), 4, '0', STR_PAD_LEFT);
        $typeToken = strtoupper(substr($taskType, 0, 3));

        return $typeToken . $tierToken . $dateToken . $timeToken . '-' . $numToken;
    }

    public function buildBatchIdWithCluster(string $taskType, int $batchNum, string $cluster, \DateTimeImmutable $when): string
    {
        $dateToken = $when->format('Ymd');
        $timeToken = $when->format('His');
        $numToken = str_pad((string) ($batchNum % 10000), 4, '0', STR_PAD_LEFT);
        $typeToken = strtoupper(substr($taskType, 0, 3));
        $clusterToken = strtoupper(substr($cluster, 0, 2));

        return $clusterToken . $typeToken . $dateToken . $timeToken . '-' . $numToken;
    }

    public function extractFromBatchId(string $id): array
    {
        $this->assertValid($id);

        $typeToken = substr($id, 0, 3);
        $dateToken = substr($id, 3, 8);
        $timeToken = substr($id, 11, 6);
        $numToken = substr($id, 18);

        return [
            'type' => $typeToken,
            'date' => $dateToken,
            'time' => $timeToken,
            'batch_number' => (int) $numToken,
        ];
    }

    public function extractFromExtendedBatchId(string $id): array
    {
        $this->assertValid($id);

        if (strlen($id) === 24) {
            $clusterToken = substr($id, 0, 2);
            $typeToken = substr($id, 2, 3);
            $dateToken = substr($id, 5, 8);
            $timeToken = substr($id, 13, 6);
            $numToken = substr($id, 20);

            return [
                'cluster' => $clusterToken,
                'type' => $typeToken,
                'date' => $dateToken,
                'time' => $timeToken,
                'batch_number' => (int) $numToken,
            ];
        }

        if (strlen($id) === 23) {
            $typeToken = substr($id, 0, 3);
            $tierToken = substr($id, 3, 2);
            $dateToken = substr($id, 5, 8);
            $timeToken = substr($id, 13, 6);
            $numToken = substr($id, 20);

            return [
                'tier' => (int) $tierToken,
                'type' => $typeToken,
                'date' => $dateToken,
                'time' => $timeToken,
                'batch_number' => (int) $numToken,
            ];
        }

        return $this->extractFromBatchId($id);
    }

    public function isValidBatchId(string $id): bool
    {
        if (!preg_match('/^[A-Z]{3}\d{8}\d{6}-\d{4}$/', $id)) {
            return false;
        }

        $dateToken = substr($id, 3, 8);
        $month = (int) substr($dateToken, 4, 2);
        $day = (int) substr($dateToken, 6, 2);

        if ($month < 1 || $month > 12) {
            return false;
        }

        if ($day < 1 || $day > 31) {
            return false;
        }

        return true;
    }

    public function isValidExtendedBatchId(string $id): bool
    {
        if (strlen($id) === 24) {
            if (!preg_match('/^[A-Z]{2}[A-Z]{3}\d{8}\d{6}-\d{4}$/', $id)) {
                return false;
            }
        }

        if (strlen($id) === 23) {
            if (!preg_match('/^[A-Z]{3}\d{2}\d{8}\d{6}-\d{4}$/', $id)) {
                return false;
            }
        }

        return $this->isValidBatchId($id);
    }

    public function getBatchTimestamp(string $id): \DateTimeImmutable
    {
        $this->assertValid($id);

        $dateToken = substr($id, 3, 8);
        $timeToken = substr($id, 11, 6);

        return new \DateTimeImmutable(
            substr($dateToken, 0, 4) . '-' . substr($dateToken, 4, 2) . '-' . substr($dateToken, 6, 2)
            . ' ' . substr($timeToken, 0, 2) . ':' . substr($timeToken, 2, 2) . ':' . substr($timeToken, 4, 2)
        );
    }

    public function getBatchNumber(string $id): int
    {
        $this->assertValid($id);

        return (int) substr($id, 18);
    }

    public function compareBatches(string $batch1, string $batch2): int
    {
        return strcmp($batch1, $batch2);
    }

    public function hasSameType(string $batch1, string $batch2): bool
    {
        return substr($batch1, 0, 3) === substr($batch2, 0, 3);
    }

    private function assertValid(string $id): void
    {
        if (!$this->isValidBatchId($id)) {
            throw new \InvalidArgumentException("Invalid batch ID: {$id}");
        }
    }
}
