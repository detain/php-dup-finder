<?php
declare(strict_types=1);

namespace Billing\Serialization;

use Psr\Log\LoggerInterface;

final class QuoteSerializer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function toArray(Quote $quote): array
    {
        $this->logger->debug('Serializing quote', ['quote_id' => $quote->getId()]);

        return [
            'id' => $quote->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'customer' => $this->serializeCustomer($quote->getCustomer()),
            'line_items' => $this->serializeLineItems($quote->getLineItems()),
            'subtotal' => $quote->getSubtotal(),
            'tax_amount' => $quote->getTaxAmount(),
            'total_amount' => $quote->getTotalAmount(),
            'currency' => $quote->getCurrency(),
            'status' => $quote->getStatus(),
            'valid_until' => $this->formatDate($quote->getValidUntil()),
            'created_date' => $this->formatDate($quote->getCreatedDate()),
            'accepted_date' => $this->formatDate($quote->getAcceptedDate()),
            'notes' => $this->nullableString($quote->getNotes()),
            'metadata' => $this->serializeMetadata($quote->getMetadata()),
        ];
    }

    public function toJson(Quote $quote): string
    {
        return json_encode($this->toArray($quote), JSON_PRETTY_PRINT);
    }

    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'address' => $this->serializeAddress($customer->getBillingAddress()),
        ];
    }

    private function serializeAddress(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'state' => $address->getState(),
            'postal_code' => $address->getPostalCode(),
            'country' => $address->getCountry(),
        ];
    }

    private function serializeLineItems(array $lineItems): array
    {
        return array_map(function (LineItem $item) {
            return [
                'id' => $item->getId(),
                'description' => $item->getDescription(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'amount' => $item->getAmount(),
                'tax_rate' => $this->nullableFloat($item->getTaxRate()),
                'discount_amount' => $this->nullableFloat($item->getDiscountAmount()),
            ];
        }, $lineItems);
    }

    private function serializeMetadata(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $key => $value) {
            $result[$key] = $this->serializeValue($value);
        }
        return $result;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(fn($v) => $this->serializeValue($v), $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->formatDate($value);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return '[serialized]';
    }

    private function formatDate(?\DateTimeInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim($value);
    }

    private function nullableFloat(?float $value): ?float
    {
        return $value;
    }
}
