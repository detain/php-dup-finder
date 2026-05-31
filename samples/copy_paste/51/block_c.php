<?php

declare(strict_types=1);

namespace App\Utilities;

use NumberFormatter;
use App\Exceptions\InvalidAmountException;

final class MoneyFormatter
{
    private const SYMBOL_USD = '$';
    private const SYMBOL_EUR = '€';
    private const SYMBOL_GBP = '£';
    private const SYMBOL_JPY = '¥';
    private const SYMBOL_CAD = 'C$';
    private const SYMBOL_AUD = 'A$';
    private const SYMBOL_CHF = 'CHF';
    private const SYMBOL_CNY = '¥';
    private const SYMBOL_INR = '₹';
    private const SYMBOL_BRL = 'R$';

    private const FRACTION_DIGITS_USD = 2;
    private const FRACTION_DIGITS_EUR = 2;
    private const FRACTION_DIGITS_GBP = 2;
    private const FRACTION_DIGITS_JPY = 0;
    private const FRACTION_DIGITS_CAD = 2;
    private const FRACTION_DIGITS_AUD = 2;
    private const FRACTION_DIGITS_CHF = 2;
    private const FRACTION_DIGITS_CNY = 2;
    private const FRACTION_DIGITS_INR = 2;
    private const FRACTION_DIGITS_BRL = 2;

    private const GROUP_SEP_USD = ',';
    private const GROUP_SEP_EUR = '.';
    private const GROUP_SEP_GBP = ',';
    private const GROUP_SEP_JPY = ',';
    private const GROUP_SEP_CAD = ',';
    private const GROUP_SEP_AUD = ',';
    private const GROUP_SEP_CHF = "'";
    private const GROUP_SEP_CNY = ',';
    private const GROUP_SEP_INR = ',';
    private const GROUP_SEP_BRL = '.';

    private const DECIMAL_SEP_USD = '.';
    private const DECIMAL_SEP_EUR = ',';
    private const DECIMAL_SEP_GBP = '.';
    private const DECIMAL_SEP_JPY = '.';
    private const DECIMAL_SEP_CAD = '.';
    private const DECIMAL_SEP_AUD = '.';
    private const DECIMAL_SEP_CHF = '.';
    private const DECIMAL_SEP_CNY = '.';
    private const DECIMAL_SEP_INR = '.';
    private const DECIMAL_SEP_BRL = ',';

    private const CURRENCY_POSTFIX = ['EUR', 'CHF', 'BRL'];

    public function formatAmount(float $amount, string $currency = 'USD'): string
    {
        $currency = strtoupper($currency);

        $symbol = $this->obtainSymbol($currency);
        $fractionDigits = $this->obtainFractionDigits($currency);
        $groupSeparator = $this->obtainGroupSeparator($currency);
        $decimalSeparator = $this->obtainDecimalSeparator($currency);

        $formatted = number_format(
            $amount,
            $fractionDigits,
            $decimalSeparator,
            $groupSeparator
        );

        return $this->prefixOrSuffix($currency, $symbol, $formatted);
    }

    public function formatWithCurrencyCode(float $amount, string $currency = 'USD'): string
    {
        $currency = strtoupper($currency);

        $fractionDigits = $this->obtainFractionDigits($currency);
        $groupSeparator = $this->obtainGroupSeparator($currency);
        $decimalSeparator = $this->obtainDecimalSeparator($currency);

        $formatted = number_format(
            $amount,
            $fractionDigits,
            $decimalSeparator,
            $groupSeparator
        );

        return $currency . ' ' . $formatted;
    }

    public function formatUsd(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_USD,
            self::DECIMAL_SEP_USD,
            self::GROUP_SEP_USD
        );

        return self::SYMBOL_USD . $formatted;
    }

    public function formatEur(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_EUR,
            self::DECIMAL_SEP_EUR,
            self::GROUP_SEP_EUR
        );

        return $formatted . ' ' . self::SYMBOL_EUR;
    }

    public function formatGbp(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_GBP,
            self::DECIMAL_SEP_GBP,
            self::GROUP_SEP_GBP
        );

        return self::SYMBOL_GBP . $formatted;
    }

    public function formatJpy(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_JPY,
            self::DECIMAL_SEP_JPY,
            self::GROUP_SEP_JPY
        );

        return self::SYMBOL_JPY . $formatted;
    }

    public function formatCad(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_CAD,
            self::DECIMAL_SEP_CAD,
            self::GROUP_SEP_CAD
        );

        return self::SYMBOL_CAD . $formatted;
    }

    public function formatAud(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_AUD,
            self::DECIMAL_SEP_AUD,
            self::GROUP_SEP_AUD
        );

        return self::SYMBOL_AUD . $formatted;
    }

    public function formatChf(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_CHF,
            self::DECIMAL_SEP_CHF,
            self::GROUP_SEP_CHF
        );

        return self::SYMBOL_CHF . ' ' . $formatted;
    }

    public function formatCny(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_CNY,
            self::DECIMAL_SEP_CNY,
            self::GROUP_SEP_CNY
        );

        return self::SYMBOL_CNY . $formatted;
    }

    public function formatInr(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_INR,
            self::DECIMAL_SEP_INR,
            self::GROUP_SEP_INR
        );

        return self::SYMBOL_INR . $formatted;
    }

    public function formatBrl(float $amount): string
    {
        $formatted = number_format(
            $amount,
            self::FRACTION_DIGITS_BRL,
            self::DECIMAL_SEP_BRL,
            self::GROUP_SEP_BRL
        );

        return self::SYMBOL_BRL . ' ' . $formatted;
    }

    private function obtainSymbol(string $currency): string
    {
        return match ($currency) {
            'USD' => self::SYMBOL_USD,
            'EUR' => self::SYMBOL_EUR,
            'GBP' => self::SYMBOL_GBP,
            'JPY' => self::SYMBOL_JPY,
            'CAD' => self::SYMBOL_CAD,
            'AUD' => self::SYMBOL_AUD,
            'CHF' => self::SYMBOL_CHF,
            'CNY' => self::SYMBOL_CNY,
            'INR' => self::SYMBOL_INR,
            'BRL' => self::SYMBOL_BRL,
            default => $currency . ' ',
        };
    }

    private function obtainFractionDigits(string $currency): int
    {
        return match ($currency) {
            'USD' => self::FRACTION_DIGITS_USD,
            'EUR' => self::FRACTION_DIGITS_EUR,
            'GBP' => self::FRACTION_DIGITS_GBP,
            'JPY' => self::FRACTION_DIGITS_JPY,
            'CAD' => self::FRACTION_DIGITS_CAD,
            'AUD' => self::FRACTION_DIGITS_AUD,
            'CHF' => self::FRACTION_DIGITS_CHF,
            'CNY' => self::FRACTION_DIGITS_CNY,
            'INR' => self::FRACTION_DIGITS_INR,
            'BRL' => self::FRACTION_DIGITS_BRL,
            default => 2,
        };
    }

    private function obtainGroupSeparator(string $currency): string
    {
        return match ($currency) {
            'USD' => self::GROUP_SEP_USD,
            'EUR' => self::GROUP_SEP_EUR,
            'GBP' => self::GROUP_SEP_GBP,
            'JPY' => self::GROUP_SEP_JPY,
            'CAD' => self::GROUP_SEP_CAD,
            'AUD' => self::GROUP_SEP_AUD,
            'CHF' => self::GROUP_SEP_CHF,
            'CNY' => self::GROUP_SEP_CNY,
            'INR' => self::GROUP_SEP_INR,
            'BRL' => self::GROUP_SEP_BRL,
            default => ',',
        };
    }

    private function obtainDecimalSeparator(string $currency): string
    {
        return match ($currency) {
            'USD' => self::DECIMAL_SEP_USD,
            'EUR' => self::DECIMAL_SEP_EUR,
            'GBP' => self::DECIMAL_SEP_GBP,
            'JPY' => self::DECIMAL_SEP_JPY,
            'CAD' => self::DECIMAL_SEP_CAD,
            'AUD' => self::DECIMAL_SEP_AUD,
            'CHF' => self::DECIMAL_SEP_CHF,
            'CNY' => self::DECIMAL_SEP_CNY,
            'INR' => self::DECIMAL_SEP_INR,
            'BRL' => self::DECIMAL_SEP_BRL,
            default => '.',
        };
    }

    private function prefixOrSuffix(string $currency, string $symbol, string $formattedValue): string
    {
        return in_array($currency, self::CURRENCY_POSTFIX, true)
            ? $formattedValue . ' ' . $symbol
            : $symbol . $formattedValue;
    }

    public function getAvailableCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'INR', 'BRL'];
    }
}
