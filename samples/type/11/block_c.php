<?php
declare(strict_types=1);

namespace RetailPlatform\API\Formatter;

use Psr\Log\LoggerInterface;

final class CustomerResponseFormatter
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function format(array $customerData): array
    {
        $this->logger->debug('Formatting customer response', ['customer_id' => $customerData['id'] ?? 'unknown']);

        if (!isset($customerData['id'])) {
            throw new \InvalidArgumentException('Customer ID is required');
        }

        if (!isset($customerData['email']) || empty(trim($customerData['email']))) {
            throw new \InvalidArgumentException('Customer email is required');
        }

        if (!isset($customerData['first_name']) || empty(trim($customerData['first_name']))) {
            throw new \InvalidArgumentException('First name is required');
        }

        if (!isset($customerData['last_name']) || empty(trim($customerData['last_name']))) {
            throw new \InvalidArgumentException('Last name is required');
        }

        if (!is_numeric($customerData['credit_limit'] ?? null)) {
            throw new \InvalidArgumentException('Credit limit must be numeric');
        }

        if (($customerData['credit_limit'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Credit limit cannot be negative');
        }

        if (isset($customerData['loyalty_points']) && !is_int($customerData['loyalty_points'])) {
            throw new \InvalidArgumentException('Loyalty points must be an integer');
        }

        if (isset($customerData['loyalty_points']) && $customerData['loyalty_points'] < 0) {
            throw new \InvalidArgumentException('Loyalty points cannot be negative');
        }

        if (isset($customerData['addresses']) && !is_array($customerData['addresses'])) {
            throw new \InvalidArgumentException('Addresses must be an array');
        }

        if (isset($customerData['phone_numbers']) && !is_array($customerData['phone_numbers'])) {
            throw new \InvalidArgumentException('Phone numbers must be an array');
        }

        return $this->buildResponse($customerData);
    }

    public function formatList(array $customers): array
    {
        return array_map(fn($customer) => $this->format($customer), $customers);
    }

    private function buildResponse(array $customerData): array
    {
        return [
            'id' => (int)$customerData['id'],
            'email' => strtolower(trim($customerData['email'])),
            'first_name' => trim($customerData['first_name']),
            'last_name' => trim($customerData['last_name']),
            'full_name' => trim($customerData['first_name']) . ' ' . trim($customerData['last_name']),
            'credit_limit' => (float)($customerData['credit_limit'] ?? 0),
            'currency' => $customerData['currency'] ?? 'USD',
            'loyalty_points' => isset($customerData['loyalty_points'])
                ? (int)$customerData['loyalty_points']
                : 0,
            'tier' => $this->calculateTier($customerData['loyalty_points'] ?? 0),
            'addresses' => $this->formatAddresses($customerData['addresses'] ?? []),
            'phone_numbers' => $this->formatPhones($customerData['phone_numbers'] ?? []),
            'metadata' => $this->formatMetadata($customerData['metadata'] ?? []),
            'formatted_credit_limit' => $this->formatPrice($customerData['credit_limit'] ?? 0, $customerData['currency'] ?? 'USD'),
            'is_active' => (bool)($customerData['is_active'] ?? true),
            'created_at' => $customerData['created_at'] ?? null,
            'updated_at' => $customerData['updated_at'] ?? null,
        ];
    }

    private function formatAddresses(array $addresses): array
    {
        return array_map(function ($address) {
            return [
                'type' => $address['type'] ?? 'shipping',
                'street' => trim($address['street'] ?? ''),
                'city' => trim($address['city'] ?? ''),
                'state' => trim($address['state'] ?? ''),
                'postal_code' => trim($address['postal_code'] ?? ''),
                'country' => trim($address['country'] ?? ''),
                'is_default' => (bool)($address['is_default'] ?? false),
            ];
        }, $addresses);
    }

    private function formatPhones(array $phones): array
    {
        return array_map(function ($phone) {
            if (is_string($phone)) {
                return ['number' => $phone, 'type' => 'mobile', 'is_verified' => false];
            }
            return [
                'number' => $phone['number'] ?? '',
                'type' => $phone['type'] ?? 'mobile',
                'is_verified' => (bool)($phone['is_verified'] ?? false),
            ];
        }, $phones);
    }

    private function calculateTier(int $points): string
    {
        if ($points >= 10000) {
            return 'platinum';
        }
        if ($points >= 5000) {
            return 'gold';
        }
        if ($points >= 1000) {
            return 'silver';
        }
        return 'bronze';
    }

    private function formatMetadata(array $metadata): array
    {
        $formatted = [];
        foreach ($metadata as $key => $value) {
            $formatted[$key] = is_scalar($value) ? (string)$value : json_encode($value);
        }
        return $formatted;
    }

    private function formatPrice(float $price, string $currency): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($price, 2);
    }
}
