<?php

declare(strict_types=1);

namespace App\Ecommerce;

final class OrderNumberGenerator
{
    private const ORDER_PREFIX = 'ORD';
    private const YEAR_OFFSET = 2000;

    public function generateOrderNumber(int $counter, \DateTimeImmutable $timestamp): string
    {
        $year = $timestamp->format('y');
        $month = $timestamp->format('m');
        $day = $timestamp->format('d');
        $seq = str_pad((string) ($counter % 100000), 5, '0', STR_PAD_LEFT);

        return self::ORDER_PREFIX . $year . $month . $day . '-' . $seq;
    }

    public function generateOrderNumberWithStore(int $counter, int $storeId, \DateTimeImmutable $timestamp): string
    {
        $year = $timestamp->format('y');
        $month = $timestamp->format('m');
        $day = $timestamp->format('d');
        $store = str_pad((string) ($storeId % 1000), 3, '0', STR_PAD_LEFT);
        $seq = str_pad((string) ($counter % 100000), 5, '0', STR_PAD_LEFT);

        return self::ORDER_PREFIX . $store . $year . $month . $day . '-' . $seq;
    }

    public function generateOrderNumberWithChannel(
        int $counter,
        int $storeId,
        string $channel,
        \DateTimeImmutable $timestamp
    ): string {
        $year = $timestamp->format('y');
        $month = $timestamp->format('m');
        $day = $timestamp->format('d');
        $store = str_pad((string) ($storeId % 1000), 3, '0', STR_PAD_LEFT);
        $channelCode = $this->getChannelCode($channel);
        $seq = str_pad((string) ($counter % 100000), 5, '0', STR_PAD_LEFT);

        return self::ORDER_PREFIX . $channelCode . $store . $year . $month . $day . '-' . $seq;
    }

    public function parseOrderNumber(string $orderNumber): array
    {
        $this->ensureValidOrderNumber($orderNumber);

        $prefix = substr($orderNumber, 0, 3);
        $datePart = substr($orderNumber, 3, 6);
        $sequencePart = substr($orderNumber, 10);

        return [
            'prefix' => $prefix,
            'year' => (int) substr($datePart, 0, 2),
            'month' => (int) substr($datePart, 2, 2),
            'day' => (int) substr($datePart, 4, 2),
            'sequence' => (int) $sequencePart,
        ];
    }

    public function parseExtendedOrderNumber(string $orderNumber): array
    {
        $this->ensureValidOrderNumber($orderNumber);

        $prefix = substr($orderNumber, 0, 3);

        if (strlen($orderNumber) === 16) {
            $channelCode = substr($orderNumber, 3, 2);
            $storeId = (int) substr($orderNumber, 5, 3);
            $datePart = substr($orderNumber, 8, 6);
            $sequencePart = substr($orderNumber, 15);

            return [
                'prefix' => $prefix,
                'channel' => $this->getChannelFromCode($channelCode),
                'store_id' => $storeId,
                'year' => (int) substr($datePart, 0, 2),
                'month' => (int) substr($datePart, 2, 2),
                'day' => (int) substr($datePart, 4, 2),
                'sequence' => (int) $sequencePart,
            ];
        }

        return $this->parseOrderNumber($orderNumber);
    }

    public function isValidOrderNumber(string $orderNumber): bool
    {
        if (!preg_match('/^ORD\d{6}-\d{5}$/', $orderNumber)) {
            return false;
        }

        $datePart = substr($orderNumber, 3, 6);
        $month = (int) substr($datePart, 2, 2);
        $day = (int) substr($datePart, 4, 2);

        if ($month < 1 || $month > 12) {
            return false;
        }

        if ($day < 1 || $day > 31) {
            return false;
        }

        return true;
    }

    public function isValidExtendedOrderNumber(string $orderNumber): bool
    {
        if (strlen($orderNumber) === 16) {
            if (!preg_match('/^ORD[A-Z0-9]{2}\d{3}\d{6}-\d{5}$/', $orderNumber)) {
                return false;
            }
        }

        return $this->isValidOrderNumber($orderNumber);
    }

    public function extractDate(string $orderNumber): \DateTimeImmutable
    {
        $this->ensureValidOrderNumber($orderNumber);

        $datePart = substr($orderNumber, 3, 6);
        $year = self::YEAR_OFFSET + (int) substr($datePart, 0, 2);
        $month = (int) substr($datePart, 2, 2);
        $day = (int) substr($datePart, 4, 2);

        return new \DateTimeImmutable("{$year}-{$month}-{$day}");
    }

    public function extractSequence(string $orderNumber): int
    {
        $this->ensureValidOrderNumber($orderNumber);

        return (int) substr($orderNumber, 10);
    }

    public function compareOrderNumbers(string $order1, string $order2): int
    {
        return strcmp($order1, $order2);
    }

    public function isSequentialAfter(string $order1, string $order2): bool
    {
        return strcmp($order1, $order2) > 0;
    }

    private function getChannelCode(string $channel): string
    {
        $codes = [
            'web' => 'WB',
            'mobile' => 'MB',
            'pos' => 'PS',
            'api' => 'AP',
            'marketplace' => 'MP',
        ];

        return $codes[strtolower($channel)] ?? 'WB';
    }

    private function getChannelFromCode(string $code): string
    {
        $channels = [
            'WB' => 'web',
            'MB' => 'mobile',
            'PS' => 'pos',
            'AP' => 'api',
            'MP' => 'marketplace',
        ];

        return $channels[strtoupper($code)] ?? 'unknown';
    }

    private function ensureValidOrderNumber(string $orderNumber): void
    {
        if (!$this->isValidOrderNumber($orderNumber)) {
            throw new \InvalidArgumentException("Invalid order number: {$orderNumber}");
        }
    }
}
