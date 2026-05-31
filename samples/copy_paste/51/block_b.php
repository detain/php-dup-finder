<?php

declare(strict_types=1);

namespace App\Services\Formatting;

use IntlDateFormatter;
use IntlCalendar;
use DateTime;
use DateTimeZone;

final class PriceDisplayFormatter
{
    private const PRICE_USD_SYMBOL = '$';
    private const PRICE_EUR_SYMBOL = '€';
    private const PRICE_GBP_SYMBOL = '£';
    private const PRICE_JPY_SYMBOL = '¥';
    private const PRICE_CAD_SYMBOL = 'CA$';
    private const PRICE_AUD_SYMBOL = 'A$';
    private const PRICE_CHF_SYMBOL = 'CHF';
    private const PRICE_CNY_SYMBOL = 'CNY';
    private const PRICE_INR_SYMBOL = '₹';
    private const PRICE_BRL_SYMBOL = 'R$';

    private const USD_PRICE_DECIMALS = 2;
    private const EUR_PRICE_DECIMALS = 2;
    private const GBP_PRICE_DECIMALS = 2;
    private const JPY_PRICE_DECIMALS = 0;
    private const CAD_PRICE_DECIMALS = 2;
    private const AUD_PRICE_DECIMALS = 2;
    private const CHF_PRICE_DECIMALS = 2;
    private const CNY_PRICE_DECIMALS = 2;
    private const INR_PRICE_DECIMALS = 2;
    private const BRL_PRICE_DECIMALS = 2;

    private const USD_THOUSAND_SEP = ',';
    private const EUR_THOUSAND_SEP = '.';
    private const GBP_THOUSAND_SEP = ',';
    private const JPY_THOUSAND_SEP = ',';
    private const CAD_THOUSAND_SEP = ',';
    private const AUD_THOUSAND_SEP = ',';
    private const CHF_THOUSAND_SEP = "'";
    private const CNY_THOUSAND_SEP = ',';
    private const INR_THOUSAND_SEP = ',';
    private const BRL_THOUSAND_SEP = '.';

    private const USD_DECIMAL_SEP = '.';
    private const EUR_DECIMAL_SEP = ',';
    private const GBP_DECIMAL_SEP = '.';
    private const JPY_DECIMAL_SEP = '.';
    private const CAD_DECIMAL_SEP = '.';
    private const AUD_DECIMAL_SEP = '.';
    private const CHF_DECIMAL_SEP = '.';
    private const CNY_DECIMAL_SEP = '.';
    private const INR_DECIMAL_SEP = '.';
    private const BRL_DECIMAL_SEP = ',';

    private const DEFAULT_CURRENCY = 'USD';
    private const DEFAULT_LOCALE = 'en_US';

    public function formatPrice(float $value, string $currencyCode = self::DEFAULT_CURRENCY): string
    {
        $currencyCode = strtoupper($currencyCode);

        $symbol = $this->fetchCurrencySymbol($currencyCode);
        $decimals = $this->fetchDecimalCount($currencyCode);
        $thousandSep = $this->fetchThousandSeparator($currencyCode);
        $decimalSep = $this->fetchDecimalSeparator($currencyCode);

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return $this->applySymbolPosition($currencyCode, $symbol, $output);
    }

    public function formatPriceWithCode(float $value, string $currencyCode = self::DEFAULT_CURRENCY): string
    {
        $currencyCode = strtoupper($currencyCode);

        $decimals = $this->fetchDecimalCount($currencyCode);
        $thousandSep = $this->fetchThousandSeparator($currencyCode);
        $decimalSep = $this->fetchDecimalSeparator($currencyCode);

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return $currencyCode . ' ' . $output;
    }

    public function formatUsdPrice(float $value): string
    {
        $decimals = self::USD_PRICE_DECIMALS;
        $thousandSep = self::USD_THOUSAND_SEP;
        $decimalSep = self::USD_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_USD_SYMBOL . $output;
    }

    public function formatEurPrice(float $value): string
    {
        $decimals = self::EUR_PRICE_DECIMALS;
        $thousandSep = self::EUR_THOUSAND_SEP;
        $decimalSep = self::EUR_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return $output . ' ' . self::PRICE_EUR_SYMBOL;
    }

    public function formatGbpPrice(float $value): string
    {
        $decimals = self::GBP_PRICE_DECIMALS;
        $thousandSep = self::GBP_THOUSAND_SEP;
        $decimalSep = self::GBP_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_GBP_SYMBOL . $output;
    }

    public function formatJpyPrice(float $value): string
    {
        $decimals = self::JPY_PRICE_DECIMALS;
        $thousandSep = self::JPY_THOUSAND_SEP;
        $decimalSep = self::JPY_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_JPY_SYMBOL . $output;
    }

    public function formatCadPrice(float $value): string
    {
        $decimals = self::CAD_PRICE_DECIMALS;
        $thousandSep = self::CAD_THOUSAND_SEP;
        $decimalSep = self::CAD_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_CAD_SYMBOL . $output;
    }

    public function formatAudPrice(float $value): string
    {
        $decimals = self::AUD_PRICE_DECIMALS;
        $thousandSep = self::AUD_THOUSAND_SEP;
        $decimalSep = self::AUD_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_AUD_SYMBOL . $output;
    }

    public function formatChfPrice(float $value): string
    {
        $decimals = self::CHF_PRICE_DECIMALS;
        $thousandSep = self::CHF_THOUSAND_SEP;
        $decimalSep = self::CHF_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_CHF_SYMBOL . ' ' . $output;
    }

    public function formatCnyPrice(float $value): string
    {
        $decimals = self::CNY_PRICE_DECIMALS;
        $thousandSep = self::CNY_THOUSAND_SEP;
        $decimalSep = self::CNY_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_CNY_SYMBOL . $output;
    }

    public function formatInrPrice(float $value): string
    {
        $decimals = self::INR_PRICE_DECIMALS;
        $thousandSep = self::INR_THOUSAND_SEP;
        $decimalSep = self::INR_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_INR_SYMBOL . $output;
    }

    public function formatBrlPrice(float $value): string
    {
        $decimals = self::BRL_PRICE_DECIMALS;
        $thousandSep = self::BRL_THOUSAND_SEP;
        $decimalSep = self::BRL_DECIMAL_SEP;

        $output = number_format($value, $decimals, $decimalSep, $thousandSep);

        return self::PRICE_BRL_SYMBOL . ' ' . $output;
    }

    private function fetchCurrencySymbol(string $currency): string
    {
        return match ($currency) {
            'USD' => self::PRICE_USD_SYMBOL,
            'EUR' => self::PRICE_EUR_SYMBOL,
            'GBP' => self::PRICE_GBP_SYMBOL,
            'JPY' => self::PRICE_JPY_SYMBOL,
            'CAD' => self::PRICE_CAD_SYMBOL,
            'AUD' => self::PRICE_AUD_SYMBOL,
            'CHF' => self::PRICE_CHF_SYMBOL,
            'CNY' => self::PRICE_CNY_SYMBOL,
            'INR' => self::PRICE_INR_SYMBOL,
            'BRL' => self::PRICE_BRL_SYMBOL,
            default => $currency . ' ',
        };
    }

    private function fetchDecimalCount(string $currency): int
    {
        return match ($currency) {
            'USD' => self::USD_PRICE_DECIMALS,
            'EUR' => self::EUR_PRICE_DECIMALS,
            'GBP' => self::GBP_PRICE_DECIMALS,
            'JPY' => self::JPY_PRICE_DECIMALS,
            'CAD' => self::CAD_PRICE_DECIMALS,
            'AUD' => self::AUD_PRICE_DECIMALS,
            'CHF' => self::CHF_PRICE_DECIMALS,
            'CNY' => self::CNY_PRICE_DECIMALS,
            'INR' => self::INR_PRICE_DECIMALS,
            'BRL' => self::BRL_PRICE_DECIMALS,
            default => 2,
        };
    }

    private function fetchThousandSeparator(string $currency): string
    {
        return match ($currency) {
            'USD' => self::USD_THOUSAND_SEP,
            'EUR' => self::EUR_THOUSAND_SEP,
            'GBP' => self::GBP_THOUSAND_SEP,
            'JPY' => self::JPY_THOUSAND_SEP,
            'CAD' => self::CAD_THOUSAND_SEP,
            'AUD' => self::AUD_THOUSAND_SEP,
            'CHF' => self::CHF_THOUSAND_SEP,
            'CNY' => self::CNY_THOUSAND_SEP,
            'INR' => self::INR_THOUSAND_SEP,
            'BRL' => self::BRL_THOUSAND_SEP,
            default => ',',
        };
    }

    private function fetchDecimalSeparator(string $currency): string
    {
        return match ($currency) {
            'USD' => self::USD_DECIMAL_SEP,
            'EUR' => self::EUR_DECIMAL_SEP,
            'GBP' => self::GBP_DECIMAL_SEP,
            'JPY' => self::JPY_DECIMAL_SEP,
            'CAD' => self::CAD_DECIMAL_SEP,
            'AUD' => self::AUD_DECIMAL_SEP,
            'CHF' => self::CHF_DECIMAL_SEP,
            'CNY' => self::CNY_DECIMAL_SEP,
            'INR' => self::INR_DECIMAL_SEP,
            'BRL' => self::BRL_DECIMAL_SEP,
            default => '.',
        };
    }

    private function applySymbolPosition(string $currency, string $symbol, string $formattedValue): string
    {
        if (in_array($currency, ['EUR'], true)) {
            return $formattedValue . ' ' . $symbol;
        }

        return $symbol . $formattedValue;
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'INR', 'BRL'];
    }
}
