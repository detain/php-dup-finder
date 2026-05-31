<?php

declare(strict_types=1);

namespace App\Billing\Invoice;

use NumberFormatter;
use IntlDateFormatter;
use App\Exceptions\InvalidCurrencyException;

final class InvoiceCurrencyRenderer
{
    private const CURRENCY_SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'CAD' => 'CA$',
        'AUD' => 'A$',
    ];

    private const DECIMAL_PLACES = [
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'JPY' => 0,
        'CAD' => 2,
        'AUD' => 2,
    ];

    private const THOUSANDS_SEPARATORS = [
        'USD' => ',',
        'EUR' => '.',
        'GBP' => ',',
        'JPY' => ',',
        'CAD' => ',',
        'AUD' => ',',
    ];

    private const DECIMAL_SEPARATORS = [
        'USD' => '.',
        'EUR' => ',',
        'GBP' => '.',
        'JPY' => '.',
        'CAD' => '.',
        'AUD' => '.',
    ];

    private const PREFIX_CURRENCIES = ['USD', 'GBP', 'JPY', 'CAD', 'AUD'];
    private const SUFFIX_CURRENCIES = ['EUR'];

    public function renderLineItem(float $amount, string $currency): string
    {
        $currency = strtoupper($currency);
        $this->validateCurrency($currency);

        $symbol = self::CURRENCY_SYMBOLS[$currency];
        $decimals = self::DECIMAL_PLACES[$currency];
        $thousandsSep = self::THOUSANDS_SEPARATORS[$currency];
        $decimalSep = self::DECIMAL_SEPARATORS[$currency];

        $formatted = number_format($amount, $decimals, $decimalSep, $thousandsSep);

        if (in_array($currency, self::SUFFIX_CURRENCIES, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    public function renderSubtotal(array $lineItems, string $currency): string
    {
        $total = array_reduce(
            $lineItems,
            fn(float $sum, array $item) => $sum + ($item['quantity'] * $item['unit_price']),
            0.0
        );

        return $this->renderLineItem($total, $currency);
    }

    public function renderTax(float $amount, string $currency): string
    {
        $currency = strtoupper($currency);
        $decimals = self::DECIMAL_PLACES[$currency];
        $thousandsSep = self::THOUSANDS_SEPARATORS[$currency];
        $decimalSep = self::DECIMAL_SEPARATORS[$currency];

        $formatted = number_format($amount, $decimals, $decimalSep, $thousandsSep);
        $symbol = self::CURRENCY_SYMBOLS[$currency];

        return $symbol . $formatted;
    }

    public function renderTotal(float $subtotal, float $tax, float $discount, string $currency): string
    {
        $currency = strtoupper($currency);
        $total = max(0.0, $subtotal + $tax - $discount);

        $symbol = self::CURRENCY_SYMBOLS[$currency];
        $decimals = self::DECIMAL_PLACES[$currency];
        $thousandsSep = self::THOUSANDS_SEPARATORS[$currency];
        $decimalSep = self::DECIMAL_SEPARATORS[$currency];

        $formatted = number_format($total, $decimals, $decimalSep, $thousandsSep);

        if (in_array($currency, self::SUFFIX_CURRENCIES, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    public function renderWithCode(float $amount, string $currency): string
    {
        $currency = strtoupper($currency);
        $this->validateCurrency($currency);

        $decimals = self::DECIMAL_PLACES[$currency];
        $thousandsSep = self::THOUSANDS_SEPARATORS[$currency];
        $decimalSep = self::DECIMAL_SEPARATORS[$currency];

        $formatted = number_format($amount, $decimals, $decimalSep, $thousandsSep);

        return $currency . ' ' . $formatted;
    }

    private function validateCurrency(string $currency): void
    {
        if (!isset(self::CURRENCY_SYMBOLS[$currency])) {
            throw new InvalidCurrencyException("Unsupported currency: {$currency}");
        }
    }
}
