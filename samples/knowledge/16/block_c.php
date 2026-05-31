<?php
declare(strict_types=1);

namespace App\Tax\Entity;

final class TaxRate
{
    public const RATE_STANDARD = 0.20;
    public const RATE_REDUCED = 0.10;
    public const RATE_ZERO = 0.0;

    public const TYPE_STANDARD = 'standard';
    public const TYPE_REDUCED = 'reduced';
    public const TYPE_ZERO = 'zero';
    public const TYPE_EXEMPT = 'exempt';

    private const EXEMPT_CATEGORIES = [
        'food',
        'medicine',
        'books',
        'children_clothing'
    ];

    private const DIGITAL_CATEGORIES = [
        'software',
        'ebooks',
        'music',
        'video',
        'subscriptions'
    ];

    private const REDUCED_RATE_CATEGORIES = [
        'books',
        'newspapers',
        'cultural_events'
    ];

    private function __construct(
        public readonly float $rate,
        public readonly string $type,
        public readonly string $category
    ) {}

    public static function standard(): self
    {
        return new self(self::RATE_STANDARD, self::TYPE_STANDARD, 'standard');
    }

    public static function reduced(string $category = 'standard'): self
    {
        return new self(self::RATE_REDUCED, self::TYPE_REDUCED, $category);
    }

    public static function zero(): self
    {
        return new self(self::RATE_ZERO, self::TYPE_ZERO, 'standard');
    }

    public static function forCategory(string $category, string $countryCode = 'GB'): self
    {
        if (in_array($category, self::EXEMPT_CATEGORIES, true)) {
            return new self(self::RATE_ZERO, self::TYPE_EXEMPT, $category);
        }

        if (in_array($category, self::DIGITAL_CATEGORIES, true)) {
            if ($countryCode === 'US') {
                return new self(self::RATE_ZERO, self::TYPE_ZERO, $category);
            }
        }

        if (in_array($category, self::REDUCED_RATE_CATEGORIES, true)) {
            return new self(self::RATE_REDUCED, self::TYPE_REDUCED, $category);
        }

        return self::standard();
    }

    public static function forCountry(string $countryCode): array
    {
        $countryDefaults = [
            'GB' => self::RATE_STANDARD,
            'DE' => self::RATE_STANDARD,
            'FR' => self::RATE_STANDARD,
            'US' => self::RATE_ZERO,
            'CA' => self::RATE_STANDARD,
            'AU' => self::RATE_STANDARD,
        ];

        $default = $countryDefaults[$countryCode] ?? self::RATE_STANDARD;

        return [
            'standard' => $default,
            'reduced' => $default * 0.5,
            'zero' => self::RATE_ZERO,
        ];
    }

    public function isZeroRated(): bool
    {
        return $this->rate === self::RATE_ZERO;
    }

    public function isExempt(): bool
    {
        return $this->type === self::TYPE_EXEMPT;
    }

    public function apply(float $amount): float
    {
        return round($amount * $this->rate, 2);
    }

    public function getTaxType(): string
    {
        if ($this->isZeroRated()) {
            return 'zero_rated';
        }

        if ($this->isExempt()) {
            return 'exempt';
        }

        if ($this->type === self::TYPE_REDUCED) {
            return 'reduced_rate';
        }

        return 'standard_rate';
    }
}
