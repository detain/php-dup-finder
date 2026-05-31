<?php

declare(strict_types=1);

namespace App\Finance;

final class TransactionReferenceGenerator
{
    public function buildInvoiceRef(int $counter, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $idx = str_pad((string) $counter, 6, '0', STR_PAD_LEFT);

        return 'INV' . $yr . $mo . '-' . $idx;
    }

    public function buildCreditRef(int $counter, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $idx = str_pad((string) $counter, 6, '0', STR_PAD_LEFT);

        return 'CR' . $yr . $mo . '-' . $idx;
    }

    public function buildDebitRef(int $counter, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $idx = str_pad((string) $counter, 6, '0', STR_PAD_LEFT);

        return 'DB' . $yr . $mo . '-' . $idx;
    }

    public function buildRefWithEntity(int $counter, string $entityId, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $entity = strtoupper(substr($entityId, 0, 4));
        $idx = str_pad((string) $counter, 6, '0', STR_PAD_LEFT);

        return 'INV' . $entity . $yr . $mo . '-' . $idx;
    }

    public function buildRefWithDepartment(int $counter, string $deptId, \DateTimeImmutable $when): string
    {
        $yr = $when->format('Y');
        $mo = $when->format('m');
        $dept = strtoupper(substr($deptId, 0, 4));
        $idx = str_pad((string) $counter, 6, '0', STR_PAD_LEFT);

        return 'INV' . $dept . $yr . $mo . '-' . $idx;
    }

    public function decomposeRef(string $ref): array
    {
        $this->checkValidity($ref);

        $category = $this->classifyRef($ref);
        $dateComponent = $this->getDateComponent($ref, $category);
        $seqComponent = $this->getSeqComponent($ref);

        return [
            'category' => $category,
            'year' => (int) substr($dateComponent, 0, 4),
            'month' => (int) substr($dateComponent, 4, 2),
            'sequence' => (int) $seqComponent,
        ];
    }

    public function decomposeExtendedRef(string $ref): array
    {
        $this->checkValidity($ref);

        $category = $this->classifyRef($ref);

        if (strlen($ref) === 18) {
            $codeComponent = substr($ref, 3, 4);
            $dateComponent = substr($ref, 7, 6);
            $seqComponent = substr($ref, 14);

            return [
                'category' => $category,
                'code' => $codeComponent,
                'year' => (int) substr($dateComponent, 0, 4),
                'month' => (int) substr($dateComponent, 4, 2),
                'sequence' => (int) $seqComponent,
            ];
        }

        return $this->decomposeRef($ref);
    }

    public function isValidRef(string $ref): bool
    {
        return (bool) preg_match('/^INV\d{6}-\d{6}$/', $ref)
            || (bool) preg_match('/^CR\d{6}-\d{6}$/', $ref)
            || (bool) preg_match('/^DB\d{6}-\d{6}$/', $ref);
    }

    public function isValidExtendedRef(string $ref): bool
    {
        if (strlen($ref) === 18) {
            if (!preg_match('/^(INV|CR|DB)[A-Z]{4}\d{6}-\d{6}$/', $ref)) {
                return false;
            }
        }

        return $this->isValidRef($ref);
    }

    public function getPeriodFromRef(string $ref): \DateTimeImmutable
    {
        $this->checkValidity($ref);

        $category = $this->classifyRef($ref);
        $dateComponent = $this->getDateComponent($ref, $category);

        return new \DateTimeImmutable(substr($dateComponent, 0, 4) . '-' . substr($dateComponent, 4, 2) . '-01');
    }

    public function getIndexFromRef(string $ref): int
    {
        $this->checkValidity($ref);

        return (int) $this->getSeqComponent($ref);
    }

    public function classifyRef(string $ref): string
    {
        if (str_starts_with($ref, 'INV')) {
            return 'invoice';
        }

        if (str_starts_with($ref, 'CR')) {
            return 'credit';
        }

        if (str_starts_with($ref, 'DB')) {
            return 'debit';
        }

        return 'undefined';
    }

    public function isInvoice(string $ref): bool
    {
        return str_starts_with($ref, 'INV');
    }

    public function isCredit(string $ref): bool
    {
        return str_starts_with($ref, 'CR');
    }

    public function isDebit(string $ref): bool
    {
        return str_starts_with($ref, 'DB');
    }

    private function checkValidity(string $ref): void
    {
        if (!$this->isValidRef($ref)) {
            throw new \InvalidArgumentException("Invalid reference: {$ref}");
        }
    }

    private function getDateComponent(string $ref, string $category): string
    {
        return match ($category) {
            'invoice' => substr($ref, 3, 6),
            'credit' => substr($ref, 2, 6),
            'debit' => substr($ref, 2, 6),
            default => throw new \InvalidArgumentException("Unknown category: {$category}"),
        };
    }

    private function getSeqComponent(string $ref): string
    {
        return substr($ref, strpos($ref, '-') + 1);
    }
}
