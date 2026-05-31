<?php

declare(strict_types=1);

namespace App\Billing;

final class BillingDocumentNumberManager
{
    public function createInvoiceNumber(int $index, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $seq = str_pad((string) $index, 6, '0', STR_PAD_LEFT);

        return 'INV' . $yr . $mo . '-' . $seq;
    }

    public function createCreditMemoNumber(int $index, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $seq = str_pad((string) $index, 6, '0', STR_PAD_LEFT);

        return 'CR' . $yr . $mo . '-' . $seq;
    }

    public function createDebitMemoNumber(int $index, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $seq = str_pad((string) $index, 6, '0', STR_PAD_LEFT);

        return 'DB' . $yr . $mo . '-' . $seq;
    }

    public function createNumberWithAccount(int $index, string $accountCode, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $account = strtoupper(substr($accountCode, 0, 4));
        $seq = str_pad((string) $index, 6, '0', STR_PAD_LEFT);

        return 'INV' . $account . $yr . $mo . '-' . $seq;
    }

    public function createNumberWithDivision(int $index, string $divisionCode, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $division = strtoupper(substr($divisionCode, 0, 4));
        $seq = str_pad((string) $index, 6, '0', STR_PAD_LEFT);

        return 'INV' . $division . $yr . $mo . '-' . $seq;
    }

    public function decodeDocumentNumber(string $number): array
    {
        $this->verifyFormat($number);

        $kind = $this->determineKind($number);
        $dateComponent = $this->pullDateComponent($number, $kind);
        $seqComponent = $this->pullSeqComponent($number);

        return [
            'kind' => $kind,
            'year' => (int) substr($dateComponent, 0, 4),
            'month' => (int) substr($dateComponent, 4, 2),
            'sequence' => (int) $seqComponent,
        ];
    }

    public function decodeExtendedDocumentNumber(string $number): array
    {
        $this->verifyFormat($number);

        $kind = $this->determineKind($number);

        if (strlen($number) === 18) {
            $codeComponent = substr($number, 3, 4);
            $dateComponent = substr($number, 7, 6);
            $seqComponent = substr($number, 14);

            return [
                'kind' => $kind,
                'code' => $codeComponent,
                'year' => (int) substr($dateComponent, 0, 4),
                'month' => (int) substr($dateComponent, 4, 2),
                'sequence' => (int) $seqComponent,
            ];
        }

        return $this->decodeDocumentNumber($number);
    }

    public function isValidDocumentNumber(string $number): bool
    {
        return (bool) preg_match('/^INV\d{6}-\d{6}$/', $number)
            || (bool) preg_match('/^CR\d{6}-\d{6}$/', $number)
            || (bool) preg_match('/^DB\d{6}-\d{6}$/', $number);
    }

    public function isValidExtendedDocumentNumber(string $number): bool
    {
        if (strlen($number) === 18) {
            if (!preg_match('/^(INV|CR|DB)[A-Z]{4}\d{6}-\d{6}$/', $number)) {
                return false;
            }
        }

        return $this->isValidDocumentNumber($number);
    }

    public function getDateFromNumber(string $number): \DateTimeImmutable
    {
        $this->verifyFormat($number);

        $kind = $this->determineKind($number);
        $dateComponent = $this->pullDateComponent($number, $kind);

        return new \DateTimeImmutable(substr($dateComponent, 0, 4) . '-' . substr($dateComponent, 4, 2) . '-01');
    }

    public function getSequenceFromNumber(string $number): int
    {
        $this->verifyFormat($number);

        return (int) $this->pullSeqComponent($number);
    }

    public function determineKind(string $number): string
    {
        if (str_starts_with($number, 'INV')) {
            return 'invoice';
        }

        if (str_starts_with($number, 'CR')) {
            return 'credit_memo';
        }

        if (str_starts_with($number, 'DB')) {
            return 'debit_memo';
        }

        return 'unknown';
    }

    public function isInvoice(string $number): bool
    {
        return str_starts_with($number, 'INV');
    }

    public function isCreditMemo(string $number): bool
    {
        return str_starts_with($number, 'CR');
    }

    public function isDebitMemo(string $number): bool
    {
        return str_starts_with($number, 'DB');
    }

    private function verifyFormat(string $number): void
    {
        if (!$this->isValidDocumentNumber($number)) {
            throw new \InvalidArgumentException("Invalid document number: {$number}");
        }
    }

    private function pullDateComponent(string $number, string $kind): string
    {
        return match ($kind) {
            'invoice' => substr($number, 3, 6),
            'credit_memo' => substr($number, 2, 6),
            'debit_memo' => substr($number, 2, 6),
            default => throw new \InvalidArgumentException("Cannot extract date from: {$number}"),
        };
    }

    private function pullSeqComponent(string $number): string
    {
        return substr($number, strpos($number, '-') + 1);
    }
}
