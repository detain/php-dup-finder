<?php

declare(strict_types=1);

namespace App\Services\Currency;

use NumberFormatter;
use IntlDateFormatter;
use App\Exceptions\CurrencyFormattingException;

final class CurrencyFormatter
{
    private const USD_SYMBOL = '$';
    private const EUR_SYMBOL = '€';
    private const GBP_SYMBOL = '£';
    private const JPY_SYMBOL = '¥';
    private const CAD_SYMBOL = 'CA$';
    private const AUD_SYMBOL = 'A$';
    private const CHF_SYMBOL = 'CHF';
    private const CNY_SYMBOL = '¥';
    private const INR_SYMBOL = '₹';
    private const BRL_SYMBOL = 'R$';

    private const USD_DECIMAL_PLACES = 2;
    private const EUR_DECIMAL_PLACES = 2;
    private const GBP_DECIMAL_PLACES = 2;
    private const JPY_DECIMAL_PLACES = 0;
    private const CAD_DECIMAL_PLACES = 2;
    private const AUD_DECIMAL_PLACES = 2;
    private const CHF_DECIMAL_PLACES = 2;
    private const CNY_DECIMAL_PLACES = 2;
    private const INR_DECIMAL_PLACES = 2;
    private const BRL_DECIMAL_PLACES = 2;

    private const USD_THOUSANDS_SEPARATOR = ',';
    private const EUR_THOUSANDS_SEPARATOR = '.';
    private const GBP_THOUSANDS_SEPARATOR = ',';
    private const JPY_THOUSANDS_SEPARATOR = ',';
    private const CAD_THOUSANDS_SEPARATOR = ',';
    private const AUD_THOUSANDS_SEPARATOR = ',';
    private const CHF_THOUSANDS_SEPARATOR = "'";
    private const CNY_THOUSANDS_SEPARATOR = ',';
    private const INR_THOUSANDS_SEPARATOR = ',';
    private const BRL_THOUSANDS_SEPARATOR = '.';

    private const USD_DECIMAL_SEPARATOR = '.';
    private const EUR_DECIMAL_SEPARATOR = ',';
    private const GBP_DECIMAL_SEPARATOR = '.';
    private const JPY_DECIMAL_SEPARATOR = '.';
    private const CAD_DECIMAL_SEPARATOR = '.';
    private const AUD_DECIMAL_SEPARATOR = '.';
    private const CHF_DECIMAL_SEPARATOR = '.';
    private const CNY_DECIMAL_SEPARATOR = '.';
    private const INR_DECIMAL_SEPARATOR = '.';
    private const BRL_DECIMAL_SEPARATOR = ',';

    private const DEFAULT_CURRENCY = 'USD';

    public function format(float $amount, string $currency = 'USD'): string
    {
        $currency = strtoupper($currency);

        $symbol = $this->getCurrencySymbol($currency);
        $decimalPlaces = $this->getDecimalPlaces($currency);
        $thousandsSeparator = $this->getThousandsSeparator($currency);
        $decimalSeparator = $this->getDecimalSeparator($currency);

        $formattedNumber = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return $symbol . $formattedNumber;
    }

    public function formatWithCode(float $amount, string $currency = 'USD'): string
    {
        $currency = strtoupper($currency);

        $decimalPlaces = $this->getDecimalPlaces($currency);
        $thousandsSeparator = $this->getThousandsSeparator($currency);
        $decimalSeparator = $this->getDecimalSeparator($currency);

        $formattedNumber = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return $currency . ' ' . $formattedNumber;
    }

    public function formatUSD(float $amount): string
    {
        $decimalPlaces = self::USD_DECIMAL_PLACES;
        $thousandsSeparator = self::USD_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::USD_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::USD_SYMBOL . $formatted;
    }

    public function formatEUR(float $amount): string
    {
        $decimalPlaces = self::EUR_DECIMAL_PLACES;
        $thousandsSeparator = self::EUR_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::EUR_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return $formatted . ' ' . self::EUR_SYMBOL;
    }

    public function formatGBP(float $amount): string
    {
        $decimalPlaces = self::GBP_DECIMAL_PLACES;
        $thousandsSeparator = self::GBP_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::GBP_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::GBP_SYMBOL . $formatted;
    }

    public function formatJPY(float $amount): string
    {
        $decimalPlaces = self::JPY_DECIMAL_PLACES;
        $thousandsSeparator = self::JPY_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::JPY_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::JPY_SYMBOL . $formatted;
    }

    public function formatCAD(float $amount): string
    {
        $decimalPlaces = self::CAD_DECIMAL_PLACES;
        $thousandsSeparator = self::CAD_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::CAD_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::CAD_SYMBOL . $formatted;
    }

    public function formatAUD(float $amount): string
    {
        $decimalPlaces = self::AUD_DECIMAL_PLACES;
        $thousandsSeparator = self::AUD_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::AUD_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::AUD_SYMBOL . $formatted;
    }

    public function formatCHF(float $amount): string
    {
        $decimalPlaces = self::CHF_DECIMAL_PLACES;
        $thousandsSeparator = self::CHF_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::CHF_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::CHF_SYMBOL . ' ' . $formatted;
    }

    public function formatCNY(float $amount): string
    {
        $decimalPlaces = self::CNY_DECIMAL_PLACES;
        $thousandsSeparator = self::CNY_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::CNY_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::CNY_SYMBOL . $formatted;
    }

    public function formatINR(float $amount): string
    {
        $decimalPlaces = self::INR_DECIMAL_PLACES;
        $thousandsSeparator = self::INR_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::INR_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::INR_SYMBOL . $formatted;
    }

    public function formatBRL(float $amount): string
    {
        $decimalPlaces = self::BRL_DECIMAL_PLACES;
        $thousandsSeparator = self::BRL_THOUSANDS_SEPARATOR;
        $decimalSeparator = self::BRL_DECIMAL_SEPARATOR;

        $formatted = number_format(
            $amount,
            $decimalPlaces,
            $decimalSeparator,
            $thousandsSeparator
        );

        return self::BRL_SYMBOL . ' ' . $formatted;
    }

    private function getCurrencySymbol(string $currency): string
    {
        return match ($currency) {
            'USD' => self::USD_SYMBOL,
            'EUR' => self::EUR_SYMBOL,
            'GBP' => self::GBP_SYMBOL,
            'JPY' => self::JPY_SYMBOL,
            'CAD' => self::CAD_SYMBOL,
            'AUD' => self::AUD_SYMBOL,
            'CHF' => self::CHF_SYMBOL,
            'CNY' => self::CNY_SYMBOL,
            'INR' => self::INR_SYMBOL,
            'BRL' => self::BRL_SYMBOL,
            default => $currency . ' ',
        };
    }

    private function getDecimalPlaces(string $currency): int
    {
        return match ($currency) {
            'USD' => self::USD_DECIMAL_PLACES,
            'EUR' => self::EUR_DECIMAL_PLACES,
            'GBP' => self::GBP_DECIMAL_PLACES,
            'JPY' => self::JPY_DECIMAL_PLACES,
            'CAD' => self::CAD_DECIMAL_PLACES,
            'AUD' => self::AUD_DECIMAL_PLACES,
            'CHF' => self::CHF_DECIMAL_PLACES,
            'CNY' => self::CNY_DECIMAL_PLACES,
            'INR' => self::INR_DECIMAL_PLACES,
            'BRL' => self::BRL_DECIMAL_PLACES,
            default => 2,
        };
    }

    private function getThousandsSeparator(string $currency): string
    {
        return match ($currency) {
            'USD' => self::USD_THOUSANDS_SEPARATOR,
            'EUR' => self::EUR_THOUSANDS_SEPARATOR,
            'GBP' => self::GBP_THOUSANDS_SEPARATOR,
            'JPY' => self::JPY_THOUSANDS_SEPARATOR,
            'CAD' => self::CAD_THOUSANDS_SEPARATOR,
            'AUD' => self::AUD_THOUSANDS_SEPARATOR,
            'CHF' => self::CHF_THOUSANDS_SEPARATOR,
            'CNY' => self::CNY_THOUSANDS_SEPARATOR,
            'INR' => self::INR_THOUSANDS_SEPARATOR,
            'BRL' => self::BRL_THOUSANDS_SEPARATOR,
            default => ',',
        };
    }

    private function getDecimalSeparator(string $currency): string
    {
        return match ($currency) {
            'USD' => self::USD_DECIMAL_SEPARATOR,
            'EUR' => self::EUR_DECIMAL_SEPARATOR,
            'GBP' => self::GBP_DECIMAL_SEPARATOR,
            'JPY' => self::JPY_DECIMAL_SEPARATOR,
            'CAD' => self::CAD_DECIMAL_SEPARATOR,
            'AUD' => self::AUD_DECIMAL_SEPARATOR,
            'CHF' => self::CHF_DECIMAL_SEPARATOR,
            'CNY' => self::CNY_DECIMAL_SEPARATOR,
            'INR' => self::INR_DECIMAL_SEPARATOR,
            'BRL' => self::BRL_DECIMAL_SEPARATOR,
            default => '.',
        };
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'INR', 'BRL'];
    }
}
