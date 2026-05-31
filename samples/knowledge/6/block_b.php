<?php

declare(strict_types=1);

namespace App\Reporting\Finance;

use App\Repositories\InvoiceRepository;
use DateTimeImmutable;

final class MonthlyTaxReport
{
    public function __construct(private InvoiceRepository $invoices) {}

    public function build(DateTimeImmutable $month): array
    {
        $rows = $this->invoices->paidInMonth($month);

        $byCountry = [];
        foreach ($rows as $row) {
            $country = $row['billing_country'];
            $byCountry[$country] ??= [
                'count' => 0,
                'subtotal_cents' => 0,
                'tax_cents' => 0,
                'expected_tax_cents' => 0,
            ];

            $byCountry[$country]['count']++;
            $byCountry[$country]['subtotal_cents'] += (int) $row['subtotal_cents'];
            $byCountry[$country]['tax_cents'] += (int) $row['tax_cents'];

            // Independently compute expected tax to flag drift / invoice bugs.
            $expectedRate = match ($country) {
                'US' => 0.0725,
                'CA' => 0.13,
                'GB' => 0.20,
                'DE' => 0.19,
                default => 0.0,
            };
            $byCountry[$country]['expected_tax_cents'] += (int) round(
                ((int) $row['subtotal_cents']) * $expectedRate
            );
        }

        foreach ($byCountry as $country => &$bucket) {
            $bucket['drift_cents'] = $bucket['tax_cents'] - $bucket['expected_tax_cents'];
            $bucket['drift_pct'] = $bucket['expected_tax_cents'] > 0
                ? round($bucket['drift_cents'] / $bucket['expected_tax_cents'] * 100, 4)
                : 0.0;
        }
        unset($bucket);

        return [
            'month' => $month->format('Y-m'),
            'by_country' => $byCountry,
        ];
    }
}
