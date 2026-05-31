<?php

namespace App\Services\Accounting;

final class InvoiceConfig
{
    public readonly string $prefix;
    public readonly string $separator;
    public readonly int $sequenceDigits;

    public function __construct(
        string $prefix = 'INV',
        string $separator = '-',
        int $sequenceDigits = 6
    ) {
        $this->prefix = $prefix;
        $this->separator = $separator;
        $this->sequenceDigits = $sequenceDigits;
    }
}

final class InvoiceService
{
    private InvoiceConfig $config;

    private const TYPE_PREFIXES = [
        'invoice' => 'INV',
        'credit_note' => 'CR',
        'debit_note' => 'DB',
    ];

    public function __construct(InvoiceConfig $config)
    {
        $this->config = $config;
    }

    public function generate(\DateTimeImmutable $date, int $sequence, string $type = 'invoice'): string
    {
        $prefix = self::TYPE_PREFIXES[$type] ?? self::TYPE_PREFIXES['invoice'];
        $year = $date->format('Y');
        $month = $date->format('m');
        $seq = str_pad((string) $sequence, $this->config->sequenceDigits, '0', STR_PAD_LEFT);

        return $prefix . $year . $month . $this->config->separator . $seq;
    }

    public function generateWithCode(\DateTimeImmutable $date, int $sequence, string $code, string $type = 'invoice'): string
    {
        $prefix = self::TYPE_PREFIXES[$type] ?? self::TYPE_PREFIXES['invoice'];
        $year = $date->format('Y');
        $month = $date->format('m');
        $codePart = strtoupper(substr($code, 0, 4));
        $seq = str_pad((string) $sequence, $this->config->sequenceDigits, '0', STR_PAD_LEFT);

        return $prefix . $codePart . $year . $month . $this->config->separator . $seq;
    }

    public function parse(string $invoiceNumber): array
    {
        if (!preg_match('/^(INV|CR|DB)(\d{6})' . preg_quote($this->config->separator, '/') . '(\d{' . $this->config->sequenceDigits . '})$/', $invoiceNumber, $matches)) {
            throw new \InvalidArgumentException("Invalid invoice number: {$invoiceNumber}");
        }

        $typeMap = ['INV' => 'invoice', 'CR' => 'credit_note', 'DB' => 'debit_note'];

        return [
            'type' => $typeMap[$matches[1]] ?? 'unknown',
            'year' => (int) substr($matches[2], 0, 4),
            'month' => (int) substr($matches[2], 4, 2),
            'sequence' => (int) $matches[3],
        ];
    }

    public function getDate(string $invoiceNumber): \DateTimeImmutable
    {
        $parsed = $this->parse($invoiceNumber);

        return new \DateTimeImmutable(sprintf('%04d-%02d-01', $parsed['year'], $parsed['month']));
    }

    public function getType(string $invoiceNumber): string
    {
        $typeMap = [
            'INV' => 'invoice',
            'CR' => 'credit_note',
            'DB' => 'debit_note',
        ];

        $prefix = substr($invoiceNumber, 0, 2);

        return $typeMap[$prefix] ?? 'unknown';
    }
}
