<?php

namespace App\Services\Ecommerce;

final class OrderConfig
{
    public readonly string $prefix;
    public readonly int $sequenceDigits;
    public readonly int $maxSequence;

    public function __construct(
        string $prefix = 'ORD',
        int $sequenceDigits = 5,
        int $maxSequence = 100000
    ) {
        $this->prefix = $prefix;
        $this->sequenceDigits = $sequenceDigits;
        $this->maxSequence = $maxSequence;
    }
}

final class OrderNumberService
{
    private OrderConfig $config;

    public function __construct(OrderConfig $config)
    {
        $this->config = $config;
    }

    public function generate(\DateTimeImmutable $date, int $sequence, ?int $contextId = null, ?string $source = null): string
    {
        $year = $date->format('y');
        $month = $date->format('m');
        $day = $date->format('d');
        $seq = str_pad((string) ($sequence % $this->config->maxSequence), $this->config->sequenceDigits, '0', STR_PAD_LEFT);

        if ($source !== null && $contextId !== null) {
            return $this->config->prefix . $this->sourceCode($source) . str_pad((string) ($contextId % 1000), 3, '0', STR_PAD_LEFT) . $year . $month . $day . '-' . $seq;
        }

        if ($contextId !== null) {
            return $this->config->prefix . str_pad((string) ($contextId % 1000), 3, '0', STR_PAD_LEFT) . $year . $month . $day . '-' . $seq;
        }

        return $this->config->prefix . $year . $month . $day . '-' . $seq;
    }

    public function parse(string $orderNumber): array
    {
        if (!preg_match('/^([A-Z]+)(\d{6})-(\d{' . $this->config->sequenceDigits . '})$/', $orderNumber, $matches)) {
            throw new \InvalidArgumentException("Invalid order number: {$orderNumber}");
        }

        $year = (int) substr($matches[2], 0, 2);
        $month = (int) substr($matches[2], 2, 2);
        $day = (int) substr($matches[2], 4, 2);

        return [
            'prefix' => $matches[1],
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'sequence' => (int) $matches[3],
        ];
    }

    public function getDate(string $orderNumber): \DateTimeImmutable
    {
        $parsed = $this->parse($orderNumber);

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', 2000 + $parsed['year'], $parsed['month'], $parsed['day']));
    }

    private function sourceCode(string $source): string
    {
        $codes = ['web' => 'WB', 'mobile' => 'MB', 'pos' => 'PS', 'api' => 'AP'];

        return $codes[strtolower($source)] ?? 'WB';
    }
}
