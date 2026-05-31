<?php

declare(strict_types=1);

namespace App\Accounting;

final class InvoiceNumberGenerator
{
    private const INV_PREFIX = 'INV';
    private const CREDIT_PREFIX = 'CR';
    private const DEBIT_PREFIX = 'DB';

    public function generateInvoiceNumber(int $sequence, \DateTimeImmutable $date): string
    {
        $year = $date->format('Y');
        $month = $date->format('m');
        $seq = str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

        return self::INV_PREFIX . $year . $month . '-' . $seq;
    }

    public function generateCreditNoteNumber(int $sequence, \DateTimeImmutable $date): string
    {
        $year = $date->format('Y');
        $month = $date->format('m');
        $seq = str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

        return self::CREDIT_PREFIX . $year . $month . '-' . $seq;
    }

    public function generateDebitNoteNumber(int $sequence, \DateTimeImmutable $date): string
    {
        $year = $date->format('Y');
        $month = $date->format('m');
        $seq = str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

        return self::DEBIT_PREFIX . $year . $month . '-' . $seq;
    }

    public function generateWithCustomerCode(int $sequence, string $customerCode, \DateTimeImmutable $date): string
    {
        $year = $date->format('Y');
        $month = $date->format('m');
        $customer = strtoupper(substr($customerCode, 0, 4));
        $seq = str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

        return self::INV_PREFIX . $customer . $year . $month . '-' . $seq;
    }

    public function generateWithProjectCode(int $sequence, string $projectCode, \DateTimeImmutable $date): string
    {
        $year = $date->format('Y');
        $month = $date->format('m');
        $project = strtoupper(substr($projectCode, 0, 4));
        $seq = str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

        return self::INV_PREFIX . $project . $year . $month . '-' . $seq;
    }

    public function parseInvoiceNumber(string $invoiceNumber): array
    {
        $this->ensureValid($invoiceNumber);

        $type = $this->detectType($invoiceNumber);
        $datePart = $this->extractDatePart($invoiceNumber, $type);
        $seqPart = $this->extractSequencePart($invoiceNumber);

        return [
            'type' => $type,
            'year' => (int) substr($datePart, 0, 4),
            'month' => (int) substr($datePart, 4, 2),
            'sequence' => (int) $seqPart,
        ];
    }

    public function parseExtendedInvoiceNumber(string $invoiceNumber): array
    {
        $this->ensureValid($invoiceNumber);

        $type = $this->detectType($invoiceNumber);

        if (strlen($invoiceNumber) === 18) {
            $codePart = substr($invoiceNumber, 3, 4);
            $datePart = substr($invoiceNumber, 7, 6);
            $seqPart = substr($invoiceNumber, 14);

            return [
                'type' => $type,
                'code' => $codePart,
                'year' => (int) substr($datePart, 0, 4),
                'month' => (int) substr($datePart, 4, 2),
                'sequence' => (int) $seqPart,
            ];
        }

        return $this->parseInvoiceNumber($invoiceNumber);
    }

    public function isValidInvoiceNumber(string $invoiceNumber): bool
    {
        return (bool) preg_match('/^INV\d{6}-\d{6}$/', $invoiceNumber)
            || (bool) preg_match('/^CR\d{6}-\d{6}$/', $invoiceNumber)
            || (bool) preg_match('/^DB\d{6}-\d{6}$/', $invoiceNumber);
    }

    public function isValidExtendedInvoiceNumber(string $invoiceNumber): bool
    {
        if (strlen($invoiceNumber) === 18) {
            if (!preg_match('/^(INV|CR|DB)[A-Z]{4}\d{6}-\d{6}$/', $invoiceNumber)) {
                return false;
            }
        }

        return $this->isValidInvoiceNumber($invoiceNumber);
    }

    public function extractDate(string $invoiceNumber): \DateTimeImmutable
    {
        $this->ensureValid($invoiceNumber);

        $type = $this->detectType($invoiceNumber);
        $datePart = $this->extractDatePart($invoiceNumber, $type);

        return new \DateTimeImmutable(substr($datePart, 0, 4) . '-' . substr($datePart, 4, 2) . '-01');
    }

    public function extractSequence(string $invoiceNumber): int
    {
        $this->ensureValid($invoiceNumber);

        return (int) $this->extractSequencePart($invoiceNumber);
    }

    public function detectType(string $invoiceNumber): string
    {
        if (str_starts_with($invoiceNumber, 'INV')) {
            return 'invoice';
        }

        if (str_starts_with($invoiceNumber, 'CR')) {
            return 'credit_note';
        }

        if (str_starts_with($invoiceNumber, 'DB')) {
            return 'debit_note';
        }

        return 'unknown';
    }

    public function isInvoice(string $invoiceNumber): bool
    {
        return str_starts_with($invoiceNumber, 'INV');
    }

    public function isCreditNote(string $invoiceNumber): bool
    {
        return str_starts_with($invoiceNumber, 'CR');
    }

    public function isDebitNote(string $invoiceNumber): bool
    {
        return str_starts_with($invoiceNumber, 'DB');
    }

    private function ensureValid(string $invoiceNumber): void
    {
        if (!$this->isValidInvoiceNumber($invoiceNumber)) {
            throw new \InvalidArgumentException("Invalid invoice number: {$invoiceNumber}");
        }
    }

    private function extractDatePart(string $invoiceNumber, string $type): string
    {
        return match ($type) {
            'invoice' => substr($invoiceNumber, 3, 6),
            'credit_note' => substr($invoiceNumber, 2, 6),
            'debit_note' => substr($invoiceNumber, 2, 6),
            default => throw new \InvalidArgumentException("Unknown type: {$type}"),
        };
    }

    private function extractSequencePart(string $invoiceNumber): string
    {
        return substr($invoiceNumber, strpos($invoiceNumber, '-') + 1);
    }
}
