<?php

declare(strict_types=1);

namespace App\Integrations\Quickbooks;

use App\Domain\Invoice;
use App\Integrations\Quickbooks\Client as QbClient;

final class QuickbooksExporter
{
    public function __construct(private QbClient $client) {}

    public function export(Invoice $invoice): string
    {
        $taxCode = $this->resolveTaxCode($invoice->billingCountry);
        $taxRate = $this->resolveTaxRate($invoice->billingCountry);

        $payload = [
            'TxnDate' => $invoice->issuedAt->format('Y-m-d'),
            'CustomerRef' => ['value' => $invoice->customer->quickbooksId],
            'CurrencyRef' => ['value' => 'USD'],
            'Line' => [],
            'TxnTaxDetail' => [
                'TxnTaxCodeRef' => ['value' => $taxCode],
                'TotalTax' => round($invoice->taxCents / 100, 2),
                'TaxLine' => [[
                    'Amount' => round($invoice->taxCents / 100, 2),
                    'DetailType' => 'TaxLineDetail',
                    'TaxLineDetail' => [
                        'TaxRateRef' => ['value' => $taxCode],
                        'PercentBased' => true,
                        'TaxPercent' => $taxRate * 100,
                        'NetAmountTaxable' => round($invoice->subtotalCents / 100, 2),
                    ],
                ]],
            ],
        ];

        foreach ($invoice->lines as $line) {
            $payload['Line'][] = [
                'Amount' => round(($line->unitPriceCents * $line->quantity) / 100, 2),
                'DetailType' => 'SalesItemLineDetail',
                'Description' => $line->description,
            ];
        }

        return $this->client->postInvoice($payload);
    }

    private function resolveTaxRate(string $country): float
    {
        return match ($country) {
            'US' => 0.0725,
            'CA' => 0.13,
            'GB' => 0.20,
            'DE' => 0.19,
            default => 0.0,
        };
    }

    private function resolveTaxCode(string $country): string
    {
        return match ($country) {
            'US' => 'TAX_US',
            'CA' => 'TAX_CA_HST',
            'GB' => 'TAX_GB_VAT',
            'DE' => 'TAX_DE_VAT',
            default => 'TAX_NONE',
        };
    }
}
