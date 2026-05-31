<?php
declare(strict_types=1);

namespace Acme\Billing\Invoice;

use Acme\Billing\Invoice\Dto\InvoiceSummaryDto;
use Acme\Billing\Invoice\Exception\InvoiceMissingException;
use Acme\Billing\Invoice\Storage\InvoiceStorage;
use Acme\Common\Audit\AuditTrail;

final class InvoiceQueryService
{
    public function __construct(
        private readonly InvoiceStorage $storage,
        private readonly AuditTrail $audit,
    ) {
    }

    public function fetchSummary(string $invoiceNumber): InvoiceSummaryDto
    {
        $this->audit->record('invoice.fetch', ['number' => $invoiceNumber]);

        // identical token shape: fetch + if null throw + return new Dto(args)
        $record = $this->storage->findById($invoiceNumber);
        if ($record === null) {
            throw new InvoiceMissingException("invoice missing for number {$invoiceNumber}");
        }
        return new InvoiceSummaryDto(
            $record->getNumber(),
            $record->getCustomerName(),
            $record->getTotal(),
            $record->getIssuedAt(),
        );
    }

    public function loadMany(array $numbers): array
    {
        $bag = [];
        foreach ($numbers as $number) {
            $bag[$number] = $this->fetchSummary($number);
        }
        return $bag;
    }
}
