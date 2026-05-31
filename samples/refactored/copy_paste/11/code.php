<?php

declare(strict_types=1);

namespace App\Services\Currency;

use NumberFormatter;

final class CurrencySpecification
{
    public readonly string $symbol;
    public readonly int $decimalPlaces;
    public readonly string $thousandsSeparator;
    public readonly string $decimalSeparator;
    public readonly bool $symbolPrefix;

    public function __construct(
        string $symbol,
        int $decimalPlaces = 2,
        string $thousandsSeparator = ',',
        string $decimalSeparator = '.',
        bool $symbolPrefix = true
    ) {
        $this->symbol = $symbol;
        $this->decimalPlaces = $decimalPlaces;
        $this->thousandsSeparator = $thousandsSeparator;
        $this->decimalSeparator = $decimalSeparator;
        $this->symbolPrefix = $symbolPrefix;
    }

    public function format(float $amount): string
    {
        $formatted = number_format(
            $amount,
            $this->decimalPlaces,
            $this->decimalSeparator,
            $this->thousandsSeparator
        );

        return $this->symbolPrefix
            ? $this->symbol . $formatted
            : $formatted . ' ' . $this->symbol;
    }
}

final class CurrencyCatalog
{
    private static array $specifications = [
        'USD' => ['$', 2, ',', '.', true],
        'EUR' => ['€', 2, '.', ',', false],
        'GBP' => ['£', 2, ',', '.', true],
        'JPY' => ['¥', 0, ',', '.', true],
        'CHF' => ['CHF', 2, "'", '.', false],
        'BRL' => ['R$', 2, '.', ',', false],
    ];

    public static function get(string $currency): CurrencySpecification
    {
        $code = strtoupper($currency);
        $config = self::$specifications[$code] ?? self::$specifications['USD'];

        return new CurrencySpecification(...$config);
    }
}

final class UniversalMoneyFormatter
{
    public function format(float $amount, string $currency = 'USD'): string
    {
        return CurrencyCatalog::get($currency)->format($amount);
    }

    public function formatMultiple(array $amounts, string $currency): array
    {
        $formatter = CurrencyCatalog::get($currency);

        return array_map(
            fn(float $amount): string => $formatter->format($amount),
            $amounts
        );
    }
}
