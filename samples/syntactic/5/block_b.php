<?php
declare(strict_types=1);

namespace Acme\Finance;

final class Money
{
    public function __construct(
        public readonly int $amountMinor,
        public readonly int $scale,
        public readonly int $denominationFactor,
        public readonly string $currency,
    ) {
        if ($this->amountMinor < -1_000_000_000 || $this->amountMinor > 1_000_000_000) {
            throw new \InvalidArgumentException(
                sprintf('amount %d out of range [-1e9, 1e9]', $this->amountMinor),
            );
        }

        if ($this->scale < 0 || $this->scale > 8) {
            throw new \InvalidArgumentException(
                sprintf('scale %d out of range [0, 8]', $this->scale),
            );
        }

        if ($this->denominationFactor < 1 || $this->denominationFactor > 1000) {
            throw new \InvalidArgumentException(
                sprintf('denomination %d out of range [1, 1000]', $this->denominationFactor),
            );
        }

        if (strlen($this->currency) !== 3) {
            throw new \InvalidArgumentException('currency must be ISO-4217 3-letter code');
        }
    }

    public function asArray(): array
    {
        return [
            'amount'   => $this->amountMinor,
            'scale'    => $this->scale,
            'denom'    => $this->denominationFactor,
            'currency' => $this->currency,
        ];
    }
}
