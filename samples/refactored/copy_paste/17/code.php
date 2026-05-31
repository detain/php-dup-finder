<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class CardNetworkSpec
{
    public readonly string $pattern;
    public readonly array $panLengths;
    public readonly int $cvcLength;

    public function __construct(string $pattern, array $panLengths, int $cvcLength)
    {
        $this->pattern = $pattern;
        $this->panLengths = $panLengths;
        $this->cvcLength = $cvcLength;
    }
}

final class CardValidationConfig
{
    public readonly array $networks;

    public function __construct()
    {
        $this->networks = [
            'visa' => new CardNetworkSpec('/^4[0-9]{12}(?:[0-9]{3})?$/', [13, 16], 3),
            'mastercard' => new CardNetworkSpec('/^5[1-5][0-9]{14}$/', [16], 3),
            'amex' => new CardNetworkSpec('/^3[47][0-9]{13}$/', [15], 4),
            'discover' => new CardNetworkSpec('/^6(?:011|5[0-9]{2})[0-9]{12}$/', [16], 3),
        ];
    }
}

final class CardValidationService
{
    private CardValidationConfig $config;

    public function __construct(CardValidationConfig $config)
    {
        $this->config = $config;
    }

    public function validateNumber(string $pan, ?string $network = null): void
    {
        $clean = preg_replace('/\D/', '', $pan);

        if (empty($clean)) {
            throw new \InvalidArgumentException('Card number cannot be empty');
        }

        if (!ctype_digit($clean)) {
            throw new \InvalidArgumentException('Card number must contain only digits');
        }

        if ($network !== null) {
            $this->validateForNetwork($clean, $network);
        } else {
            $this->validateLengthAnyNetwork($clean);
        }

        if (!$this->passesLuhn($clean)) {
            throw new \InvalidArgumentException('Card number failed checksum validation');
        }
    }

    private function validateForNetwork(string $pan, string $network): void
    {
        $spec = $this->config->networks[$network] ?? null;

        if ($spec === null) {
            throw new \InvalidArgumentException("Unknown network: {$network}");
        }

        if (!in_array(strlen($pan), $spec->panLengths, true)) {
            throw new \InvalidArgumentException(
                "Invalid length for {$network}: " . strlen($pan)
            );
        }

        if (!preg_match($spec->pattern, $pan)) {
            throw new \InvalidArgumentException("Number does not match {$network} pattern");
        }
    }

    private function validateLengthAnyNetwork(string $pan): void
    {
        $length = strlen($pan);
        $validLengths = array_merge(...array_column($this->config->networks, 'panLengths'));

        if (!in_array($length, $validLengths, true)) {
            throw new \InvalidArgumentException("Invalid card number length: {$length}");
        }
    }

    private function passesLuhn(string $pan): bool
    {
        $sum = 0;
        $length = strlen($pan);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $pan[$i];

            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
