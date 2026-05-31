<?php

declare(strict_types=1);

namespace App\Reporting\Sales;

use NumberFormatter;
use IntlDateFormatter;
use App\Exceptions\ReportRenderingException;

final class SalesReportRenderer
{
    private const CURRENCY_DISPLAY = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'CAD' => 'CA$',
        'AUD' => 'A$',
    ];

    private const PRECISION_LEVELS = [
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'JPY' => 0,
        'CAD' => 2,
        'AUD' => 2,
    ];

    private const GROUPING_SIGNS = [
        'USD' => ',',
        'EUR' => '.',
        'GBP' => ',',
        'JPY' => ',',
        'CAD' => ',',
        'AUD' => ',',
    ];

    private const FRACTIONAL_SIGNS = [
        'USD' => '.',
        'EUR' => ',',
        'GBP' => '.',
        'JPY' => '.',
        'CAD' => '.',
        'AUD' => '.',
    ];

    private const PREFIX_TYPES = ['USD', 'GBP', 'JPY', 'CAD', 'AUD'];
    private const SUFFIX_TYPES = ['EUR'];

    public function formatRevenue(float $revenue, string $currencyCode): string
    {
        $code = strtoupper($currencyCode);
        $this->ensureValidCurrency($code);

        $symbol = self::CURRENCY_DISPLAY[$code];
        $decimals = self::PRECISION_LEVELS[$code];
        $groupSep = self::GROUPING_SIGNS[$code];
        $fracSep = self::FRACTIONAL_SIGNS[$code];

        $formatted = number_format($revenue, $decimals, $fracSep, $groupSep);

        if (in_array($code, self::SUFFIX_TYPES, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    public function formatExpense(float $expense, string $currencyCode): string
    {
        $code = strtoupper($currencyCode);
        $decimals = self::PRECISION_LEVELS[$code];
        $groupSep = self::GROUPING_SIGNS[$code];
        $fracSep = self::FRACTIONAL_SIGNS[$code];
        $symbol = self::CURRENCY_DISPLAY[$code];

        $formatted = number_format($expense, $decimals, $fracSep, $groupSep);

        if (in_array($code, self::SUFFIX_TYPES, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    public function formatMargin(float $revenue, float $expenses, string $currencyCode): string
    {
        $code = strtoupper($currencyCode);
        $margin = $revenue - $expenses;
        $marginPercent = $revenue > 0 ? ($margin / $revenue) * 100 : 0;

        $symbol = self::CURRENCY_DISPLAY[$code];
        $decimals = self::PRECISION_LEVELS[$code];
        $groupSep = self::GROUPING_SIGNS[$code];
        $fracSep = self::FRACTIONAL_SIGNS[$code];

        $formatted = number_format($margin, $decimals, $fracSep, $groupSep);

        return $symbol . $formatted . ' (' . number_format($marginPercent, 1) . '%)';
    }

    public function formatRegionTotals(array $regionData, string $currencyCode): array
    {
        $code = strtoupper($currencyCode);
        $results = [];

        foreach ($regionData as $region => $data) {
            $total = $data['sales'] - $data['returns'];
            $results[$region] = $this->formatRevenue($total, $code);
        }

        return $results;
    }

    public function formatTotalSales(array $dailySales, string $currencyCode): string
    {
        $code = strtoupper($currencyCode);
        $total = array_sum($dailySales);

        $symbol = self::CURRENCY_DISPLAY[$code];
        $decimals = self::PRECISION_LEVELS[$code];
        $groupSep = self::GROUPING_SIGNS[$code];
        $fracSep = self::FRACTIONAL_SIGNS[$code];

        $formatted = number_format($total, $decimals, $fracSep, $groupSep);

        if (in_array($code, self::SUFFIX_TYPES, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    private function ensureValidCurrency(string $currencyCode): void
    {
        if (!isset(self::CURRENCY_DISPLAY[$currencyCode])) {
            throw new ReportRenderingException("Currency {$currencyCode} is not supported");
        }
    }
}
