<?php

declare(strict_types=1);

namespace App\Etl;

final class DataBatchIdBuilder
{
    public function createBatchId(string $processName, int $iteration, \DateTimeImmutable $ts): string
    {
        $dateSegment = $ts->format('Ymd');
        $timeSegment = $ts->format('His');
        $iterSegment = str_pad((string) ($iteration % 10000), 4, '0', STR_PAD_LEFT);
        $nameSegment = strtoupper(substr($processName, 0, 3));

        return $nameSegment . $dateSegment . $timeSegment . '-' . $iterSegment;
    }

    public function createBatchIdWithWeight(string $processName, int $iteration, int $weight, \DateTimeImmutable $ts): string
    {
        $dateSegment = $ts->format('Ymd');
        $timeSegment = $ts->format('His');
        $weightSegment = str_pad((string) ($weight % 100), 2, '0', STR_PAD_LEFT);
        $iterSegment = str_pad((string) ($iteration % 10000), 4, '0', STR_PAD_LEFT);
        $nameSegment = strtoupper(substr($processName, 0, 3));

        return $nameSegment . $weightSegment . $dateSegment . $timeSegment . '-' . $iterSegment;
    }

    public function createBatchIdWithZone(string $processName, int $iteration, string $zone, \DateTimeImmutable $ts): string
    {
        $dateSegment = $ts->format('Ymd');
        $timeSegment = $ts->format('His');
        $iterSegment = str_pad((string) ($iteration % 10000), 4, '0', STR_PAD_LEFT);
        $nameSegment = strtoupper(substr($processName, 0, 3));
        $zoneSegment = strtoupper(substr($zone, 0, 2));

        return $zoneSegment . $nameSegment . $dateSegment . $timeSegment . '-' . $iterSegment;
    }

    public function interpretBatchId(string $id): array
    {
        $this->validateFormat($id);

        $nameComponent = substr($id, 0, 3);
        $dateComponent = substr($id, 3, 8);
        $timeComponent = substr($id, 11, 6);
        $iterComponent = substr($id, 18);

        return [
            'name' => $nameComponent,
            'date' => $dateComponent,
            'time' => $timeComponent,
            'iteration' => (int) $iterComponent,
        ];
    }

    public function interpretExtendedBatchId(string $id): array
    {
        $this->validateFormat($id);

        if (strlen($id) === 24) {
            $zoneComponent = substr($id, 0, 2);
            $nameComponent = substr($id, 2, 3);
            $dateComponent = substr($id, 5, 8);
            $timeComponent = substr($id, 13, 6);
            $iterComponent = substr($id, 20);

            return [
                'zone' => $zoneComponent,
                'name' => $nameComponent,
                'date' => $dateComponent,
                'time' => $timeComponent,
                'iteration' => (int) $iterComponent,
            ];
        }

        if (strlen($id) === 23) {
            $nameComponent = substr($id, 0, 3);
            $weightComponent = substr($id, 3, 2);
            $dateComponent = substr($id, 5, 8);
            $timeComponent = substr($id, 13, 6);
            $iterComponent = substr($id, 20);

            return [
                'weight' => (int) $weightComponent,
                'name' => $nameComponent,
                'date' => $dateComponent,
                'time' => $timeComponent,
                'iteration' => (int) $iterComponent,
            ];
        }

        return $this->interpretBatchId($id);
    }

    public function isValidFormat(string $id): bool
    {
        if (!preg_match('/^[A-Z]{3}\d{8}\d{6}-\d{4}$/', $id)) {
            return false;
        }

        $dateComponent = substr($id, 3, 8);
        $monthDigit = (int) substr($dateComponent, 4, 2);
        $dayDigit = (int) substr($dateComponent, 6, 2);

        if ($monthDigit < 1 || $monthDigit > 12) {
            return false;
        }

        if ($dayDigit < 1 || $dayDigit > 31) {
            return false;
        }

        return true;
    }

    public function isValidExtendedFormat(string $id): bool
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

        return $this->isValidFormat($id);
    }

    public function getTimestampFromId(string $id): \DateTimeImmutable
    {
        $this->validateFormat($id);

        $dateComponent = substr($id, 3, 8);
        $timeComponent = substr($id, 11, 6);

        return new \DateTimeImmutable(
            substr($dateComponent, 0, 4) . '-' . substr($dateComponent, 4, 2) . '-' . substr($dateComponent, 6, 2)
            . ' ' . substr($timeComponent, 0, 2) . ':' . substr($timeComponent, 2, 2) . ':' . substr($timeComponent, 4, 2)
        );
    }

    public function getIterationFromId(string $id): int
    {
        $this->validateFormat($id);

        return (int) substr($id, 18);
    }

    public function compareIds(string $id1, string $id2): int
    {
        return strcmp($id1, $id2);
    }

    public function isSameJobType(string $id1, string $id2): bool
    {
        return substr($id1, 0, 3) === substr($id2, 0, 3);
    }

    private function validateFormat(string $id): void
    {
        if (!$this->isValidFormat($id)) {
            throw new \InvalidArgumentException("Invalid batch ID format: {$id}");
        }
    }
}
