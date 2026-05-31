<?php

declare(strict_types=1);

namespace App\Fulfillment;

final class SalesOrderIdGenerator
{
    private const SALES_PREFIX = 'SO';
    private const EPOCH = 2000;

    public function makeSalesOrderNumber(int $counter, \DateTimeImmutable $dateTime): string
    {
        $yr = $dateTime->format('y');
        $mo = $dateTime->format('m');
        $dy = $dateTime->format('d');
        $seq = str_pad((string) ($counter % 100000), 5, '0', STR_PAD_LEFT);

        return self::SALES_PREFIX . $yr . $mo . $dy . '-' . $seq;
    }

    public function makeSalesOrderWithRegion(int $counter, int $regionId, \DateTimeImmutable $dateTime): string
    {
        $yr = $dateTime->format('y');
        $mo = $dateTime->format('m');
        $dy = $dateTime->format('d');
        $region = str_pad((string) ($regionId % 1000), 3, '0', STR_PAD_LEFT);
        $seq = str_pad((string) ($counter % 100000), 5, '0', STR_PAD_LEFT);

        return self::SALES_PREFIX . $region . $yr . $mo . $dy . '-' . $seq;
    }

    public function makeSalesOrderWithSource(
        int $counter,
        int $regionId,
        string $source,
        \DateTimeImmutable $dateTime
    ): string {
        $yr = $dateTime->format('y');
        $mo = $dateTime->format('m');
        $dy = $dateTime->format('d');
        $region = str_pad((string) ($regionId % 1000), 3, '0', STR_PAD_LEFT);
        $sourceCode = $this->mapSourceCode($source);
        $seq = str_pad((string) ($counter % 100000), 5, '0', STR_PAD_LEFT);

        return self::SALES_PREFIX . $sourceCode . $region . $yr . $mo . $dy . '-' . $seq;
    }

    public function analyzeOrderNumber(string $orderNumber): array
    {
        $this->validateOrderNumberFormat($orderNumber);

        $px = substr($orderNumber, 0, 2);
        $dt = substr($orderNumber, 2, 6);
        $sq = substr($orderNumber, 9);

        return [
            'prefix' => $px,
            'year' => (int) substr($dt, 0, 2),
            'month' => (int) substr($dt, 2, 2),
            'day' => (int) substr($dt, 4, 2),
            'sequence' => (int) $sq,
        ];
    }

    public function analyzeFullOrderNumber(string $orderNumber): array
    {
        $this->validateOrderNumberFormat($orderNumber);

        $px = substr($orderNumber, 0, 2);

        if (strlen($orderNumber) === 16) {
            $sourceCode = substr($orderNumber, 2, 2);
            $regionId = (int) substr($orderNumber, 4, 3);
            $dt = substr($orderNumber, 7, 6);
            $sq = substr($orderNumber, 14);

            return [
                'prefix' => $px,
                'source' => $this->getSourceFromCode($sourceCode),
                'region_id' => $regionId,
                'year' => (int) substr($dt, 0, 2),
                'month' => (int) substr($dt, 2, 2),
                'day' => (int) substr($dt, 4, 2),
                'sequence' => (int) $sq,
            ];
        }

        return $this->analyzeOrderNumber($orderNumber);
    }

    public function checkOrderNumber(string $orderNumber): bool
    {
        if (!preg_match('/^SO\d{6}-\d{5}$/', $orderNumber)) {
            return false;
        }

        $dt = substr($orderNumber, 2, 6);
        $month = (int) substr($dt, 2, 2);
        $day = (int) substr($dt, 4, 2);

        if ($month < 1 || $month > 12) {
            return false;
        }

        if ($day < 1 || $day > 31) {
            return false;
        }

        return true;
    }

    public function checkExtendedOrderNumber(string $orderNumber): bool
    {
        if (strlen($orderNumber) === 16) {
            if (!preg_match('/^SO[A-Z0-9]{2}\d{3}\d{6}-\d{5}$/', $orderNumber)) {
                return false;
            }
        }

        return $this->checkOrderNumber($orderNumber);
    }

    public function getDateFromOrder(string $orderNumber): \DateTimeImmutable
    {
        $this->validateOrderNumberFormat($orderNumber);

        $dt = substr($orderNumber, 2, 6);
        $yr = self::EPOCH + (int) substr($dt, 0, 2);
        $mo = (int) substr($dt, 2, 2);
        $dy = (int) substr($dt, 4, 2);

        return new \DateTimeImmutable("{$yr}-{$mo}-{$dy}");
    }

    public function getSequenceFromOrder(string $orderNumber): int
    {
        $this->validateOrderNumberFormat($orderNumber);

        return (int) substr($orderNumber, 9);
    }

    public function sortOrders(string $order1, string $order2): int
    {
        return strcmp($order1, $order2);
    }

    public function isNewer(string $order1, string $order2): bool
    {
        return strcmp($order1, $order2) > 0;
    }

    private function mapSourceCode(string $source): string
    {
        $map = [
            'retail' => 'RT',
            'wholesale' => 'WS',
            'dropship' => 'DS',
            'internal' => 'IT',
            'affiliate' => 'AF',
        ];

        return $map[strtolower($source)] ?? 'RT';
    }

    private function getSourceFromCode(string $code): string
    {
        $sources = [
            'RT' => 'retail',
            'WS' => 'wholesale',
            'DS' => 'dropship',
            'IT' => 'internal',
            'AF' => 'affiliate',
        ];

        return $sources[strtoupper($code)] ?? 'unknown';
    }

    private function validateOrderNumberFormat(string $orderNumber): void
    {
        if (!$this->checkOrderNumber($orderNumber)) {
            throw new \InvalidArgumentException("Invalid order number format: {$orderNumber}");
        }
    }
}
