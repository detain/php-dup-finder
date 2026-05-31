<?php

declare(strict_types=1);

namespace App\Domain\Tax;

final class RegionalTaxTable
{
    /** @var array<string, array{rate: float, code: string, label: string}> */
    public const RATES = [
        'US' => ['rate' => 0.0725, 'code' => 'TAX_US',        'label' => 'US Sales Tax'],
        'CA' => ['rate' => 0.13,   'code' => 'TAX_CA_HST',    'label' => 'CA HST'],
        'GB' => ['rate' => 0.20,   'code' => 'TAX_GB_VAT',    'label' => 'GB VAT'],
        'DE' => ['rate' => 0.19,   'code' => 'TAX_DE_VAT',    'label' => 'DE VAT'],
    ];

    public static function rateFor(string $country): float
    {
        return self::RATES[$country]['rate'] ?? 0.0;
    }

    public static function codeFor(string $country): string
    {
        return self::RATES[$country]['code'] ?? 'TAX_NONE';
    }

    public static function labelFor(string $country): string
    {
        return self::RATES[$country]['label'] ?? 'No tax';
    }
}

// Invoice calculator:
// $rate = RegionalTaxTable::rateFor($invoice->billingCountry);
// $invoice->taxDescription = RegionalTaxTable::labelFor($invoice->billingCountry);

// Monthly report:
// $expectedRate = RegionalTaxTable::rateFor($country);

// Quickbooks exporter:
// $payload['TxnTaxDetail']['TaxLine'][0]['TaxLineDetail']['TaxPercent'] = RegionalTaxTable::rateFor($invoice->billingCountry) * 100;
// $taxCode = RegionalTaxTable::codeFor($invoice->billingCountry);
