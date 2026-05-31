<?php

declare(strict_types=1);

namespace App\Ecommerce\Pricing;

use NumberFormatter;
use App\Exceptions\PricingException;

final class ProductPriceFormatter
{
    private const PRICE_SYM = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'CAD' => 'CA$',
        'AUD' => 'A$',
    ];

    private const FRACTION_DIGITS = [
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'JPY' => 0,
        'CAD' => 2,
        'AUD' => 2,
    ];

    private const GROUPING_CHARS = [
        'USD' => ',',
        'EUR' => '.',
        'GBP' => ',',
        'JPY' => ',',
        'CAD' => ',',
        'AUD' => ',',
    ];

    private const FRACTION_CHARS = [
        'USD' => '.',
        'EUR' => ',',
        'GBP' => '.',
        'JPY' => '.',
        'CAD' => '.',
        'AUD' => '.',
    ];

    private const PREFIX_CODES = ['USD', 'GBP', 'JPY', 'CAD', 'AUD'];
    private const SUFFIX_CODES = ['EUR'];

    public function formatProductPrice(float $price, string $currencyCode): string
    {
        $code = strtoupper($currencyCode);
        $this->checkSupportedCurrency($code);

        $symbol = self::PRICE_SYM[$code];
        $decimals = self::FRACTION_DIGITS[$code];
        $groupChar = self::GROUPING_CHARS[$code];
        $fracChar = self::FRACTION_CHARS[$code];

        $formatted = number_format($price, $decimals, $fracChar, $groupChar);

        if (in_array($code, self::SUFFIX_CODES, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    public function formatDiscountedPrice(float $original, float $discount, string $currency): string
    {
        $code = strtoupper($currency);
        $discounted = max(0, $original - $discount);

        $symbol = self::PRICE_SYM[$code];
        $decimals = self::FRACTION_DIGITS[$code];
        $groupChar = self::GROUPING_CHARS[$code];
        $fracChar = self::FRACTION_CHARS[$code];

        $formatted = number_format($discounted, $decimals, $fracChar, $groupChar);

        if (in_array($code, self::SUFFIX_CODES, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    public function formatBulkPrice(float $unitPrice, int $quantity, string $currency): string
    {
        $code = strtoupper($currency);
        $bulkTotal = $unitPrice * $quantity;

        $symbol = self::PRICE_SYM[$code];
        $decimals = self::FRACTION_DIGITS[$code];
        $groupChar = self::GROUPING_CHARS[$code];
        $fracChar = self::FRACTION_CHARS[$code];

        $formatted = number_format($bulkTotal, $decimals, $fracChar, $groupChar);

        if (in_array($code, self::SUFFIX_CODES, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    public function formatTierPrice(array $tierBreakdown, string $currency): array
    {
        $code = strtoupper($currency);
        $formatted = [];

        foreach ($tierBreakdown as $tier => $amount) {
            $symbol = self::PRICE_SYM[$code];
            $decimals = self::FRACTION_DIGITS[$code];
            $groupChar = self::GROUPING_CHARS[$code];
            $fracChar = self::FRACTION_CHARS[$code];

            $formatted[$tier] = $symbol . number_format($amount, $decimals, $fracChar, $groupChar);
        }

        return $formatted;
    }

    private function checkSupportedCurrency(string $currencyCode): void
    {
        if (!isset(self::PRICE_SYM[$currencyCode])) {
            throw new PricingException("Currency {$currencyCode} is not supported");
        }
    }
}
