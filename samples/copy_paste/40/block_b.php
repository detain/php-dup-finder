<?php

declare(strict_types=1);

namespace App\Warehouse;

final class PurchaseOrderIdBuilder
{
    private const PO_PREFIX = 'PO';
    private const BASE_YEAR = 2000;

    public function createPoNumber(int $sequence, \DateTimeImmutable $date): string
    {
        $yr = $date->format('y');
        $mo = $date->format('m');
        $dy = $date->format('d');
        $num = str_pad((string) ($sequence % 100000), 5, '0', STR_PAD_LEFT);

        return self::PO_PREFIX . $yr . $mo . $dy . '-' . $num;
    }

    public function createPoNumberWithDepartment(int $sequence, int $deptId, \DateTimeImmutable $date): string
    {
        $yr = $date->format('y');
        $mo = $date->format('m');
        $dy = $date->format('d');
        $dept = str_pad((string) ($deptId % 1000), 3, '0', STR_PAD_LEFT);
        $num = str_pad((string) ($sequence % 100000), 5, '0', STR_PAD_LEFT);

        return self::PO_PREFIX . $dept . $yr . $mo . $dy . '-' . $num;
    }

    public function createPoNumberWithVendor(
        int $sequence,
        int $deptId,
        string $vendorCode,
        \DateTimeImmutable $date
    ): string {
        $yr = $date->format('y');
        $mo = $date->format('m');
        $dy = $date->format('d');
        $dept = str_pad((string) ($deptId % 1000), 3, '0', STR_PAD_LEFT);
        $vendor = $this->resolveVendorCode($vendorCode);
        $num = str_pad((string) ($sequence % 100000), 5, '0', STR_PAD_LEFT);

        return self::PO_PREFIX . $vendor . $dept . $yr . $mo . $dy . '-' . $num;
    }

    public function breakDownPoNumber(string $poNumber): array
    {
        $this->assertValidPoNumber($poNumber);

        $px = substr($poNumber, 0, 2);
        $dt = substr($poNumber, 2, 6);
        $seq = substr($poNumber, 9);

        return [
            'prefix' => $px,
            'year' => (int) substr($dt, 0, 2),
            'month' => (int) substr($dt, 2, 2),
            'day' => (int) substr($dt, 4, 2),
            'sequence' => (int) $seq,
        ];
    }

    public function breakDownExtendedPoNumber(string $poNumber): array
    {
        $this->assertValidPoNumber($poNumber);

        $px = substr($poNumber, 0, 2);

        if (strlen($poNumber) === 16) {
            $vendorCode = substr($poNumber, 2, 2);
            $deptId = (int) substr($poNumber, 4, 3);
            $dt = substr($poNumber, 7, 6);
            $seq = substr($poNumber, 14);

            return [
                'prefix' => $px,
                'vendor' => $this->getVendorFromCode($vendorCode),
                'department_id' => $deptId,
                'year' => (int) substr($dt, 0, 2),
                'month' => (int) substr($dt, 2, 2),
                'day' => (int) substr($dt, 4, 2),
                'sequence' => (int) $seq,
            ];
        }

        return $this->breakDownPoNumber($poNumber);
    }

    public function isValidPoNumber(string $poNumber): bool
    {
        if (!preg_match('/^PO\d{6}-\d{5}$/', $poNumber)) {
            return false;
        }

        $dt = substr($poNumber, 2, 6);
        $mo = (int) substr($dt, 2, 2);
        $dy = (int) substr($dt, 4, 2);

        if ($mo < 1 || $mo > 12) {
            return false;
        }

        if ($dy < 1 || $dy > 31) {
            return false;
        }

        return true;
    }

    public function isValidExtendedPoNumber(string $poNumber): bool
    {
        if (strlen($poNumber) === 16) {
            if (!preg_match('/^PO[A-Z0-9]{2}\d{3}\d{6}-\d{5}$/', $poNumber)) {
                return false;
            }
        }

        return $this->isValidPoNumber($poNumber);
    }

    public function extractDateFromPo(string $poNumber): \DateTimeImmutable
    {
        $this->assertValidPoNumber($poNumber);

        $dt = substr($poNumber, 2, 6);
        $yr = self::BASE_YEAR + (int) substr($dt, 0, 2);
        $mo = (int) substr($dt, 2, 2);
        $dy = (int) substr($dt, 4, 2);

        return new \DateTimeImmutable("{$yr}-{$mo}-{$dy}");
    }

    public function extractSequenceFromPo(string $poNumber): int
    {
        $this->assertValidPoNumber($poNumber);

        return (int) substr($poNumber, 9);
    }

    public function comparePoNumbers(string $po1, string $po2): int
    {
        return strcmp($po1, $po2);
    }

    public function isAfter(string $po1, string $po2): bool
    {
        return strcmp($po1, $po2) > 0;
    }

    private function resolveVendorCode(string $vendor): string
    {
        $codes = [
            'acme' => 'AC',
            'global' => 'GB',
            'premier' => 'PR',
            'express' => 'EX',
            'direct' => 'DR',
        ];

        return $codes[strtolower($vendor)] ?? 'AC';
    }

    private function getVendorFromCode(string $code): string
    {
        $vendors = [
            'AC' => 'acme',
            'GB' => 'global',
            'PR' => 'premier',
            'EX' => 'express',
            'DR' => 'direct',
        ];

        return $vendors[strtoupper($code)] ?? 'unknown';
    }

    private function assertValidPoNumber(string $poNumber): void
    {
        if (!$this->isValidPoNumber($poNumber)) {
            throw new \InvalidArgumentException("Invalid PO number: {$poNumber}");
        }
    }
}
