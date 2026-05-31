<?php

declare(strict_types=1);

namespace App\Services\Currency;

use NumberFormatter;

final class CurrencyConfig
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
}

final class CurrencyRegistry
{
    private static array $currencies = [
        'USD' => ['$', 2, ',', '.', true],
        'EUR' => ['€', 2, '.', ',', false],
        'GBP' => ['£', 2, ',', '.', true],
        'JPY' => ['¥', 0, ',', '.', true],
        'CHF' => ['CHF', 2, "'", '.', false],
        'BRL' => ['R$', 2, '.', ',', false],
    ];

    public static function get(string $currency): CurrencyConfig
    {
        $config = self::$currencies[strtoupper($currency)] ?? ['$', 2, ',', '.', true];

        return new CurrencyConfig(...$config);
    }
}

final class UnifiedCurrencyFormatter
{
    public function format(float $amount, string $currency = 'USD'): string
    {
        $config = CurrencyRegistry::get($currency);

        $formatted = number_format(
            $amount,
            $config->decimalPlaces,
            $config->decimalSeparator,
            $config->thousandsSeparator
        );

        return $config->symbolPrefix
            ? $config->symbol . $formatted
            : $formatted . ' ' . $config->symbol;
    }
}
