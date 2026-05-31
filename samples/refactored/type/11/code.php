<?php
declare(strict_types=1);

namespace RetailPlatform\API\Shared;

interface ResponseFormatterInterface
{
    public function format(array $data): array;
    public function formatList(array $dataList): array;
}

abstract class AbstractResponseFormatter implements ResponseFormatterInterface
{
    protected LoggerInterface $logger;

    public function formatList(array $dataList): array
    {
        return array_map(fn($data) => $this->format($data), $dataList);
    }

    protected function requireField(array $data, string $fieldName, ?string $customMessage = null): mixed
    {
        if (!isset($data[$fieldName])) {
            throw new \InvalidArgumentException($customMessage ?? "{$fieldName} is required");
        }
        return $data[$fieldName];
    }

    protected function requireNonEmpty(array $data, string $fieldName): mixed
    {
        $value = $data[$fieldName] ?? null;
        if (empty(trim((string)$value))) {
            throw new \InvalidArgumentException("{$fieldName} cannot be empty");
        }
        return $value;
    }

    protected function validateNumeric(array $data, string $fieldName, bool $allowNegative = false): float
    {
        if (!isset($data[$fieldName]) || !is_numeric($data[$fieldName])) {
            throw new \InvalidArgumentException("{$fieldName} must be numeric");
        }
        $value = (float)$data[$fieldName];
        if (!$allowNegative && $value < 0) {
            throw new \InvalidArgumentException("{$fieldName} cannot be negative");
        }
        return $value;
    }

    protected function validateInteger(array $data, string $fieldName, bool $allowNegative = false): int
    {
        if (!isset($data[$fieldName]) || !is_int($data[$fieldName])) {
            throw new \InvalidArgumentException("{$fieldName} must be an integer");
        }
        $value = (int)$data[$fieldName];
        if (!$allowNegative && $value < 0) {
            throw new \InvalidArgumentException("{$fieldName} cannot be negative");
        }
        return $value;
    }

    protected function validateArray(array $data, string $fieldName): array
    {
        if (isset($data[$fieldName]) && !is_array($data[$fieldName])) {
            throw new \InvalidArgumentException("{$fieldName} must be an array");
        }
        return $data[$fieldName] ?? [];
    }

    protected function formatMetadata(array $metadata): array
    {
        $formatted = [];
        foreach ($metadata as $key => $value) {
            $formatted[$key] = is_scalar($value) ? (string)$value : json_encode($value);
        }
        return $formatted;
    }

    protected function formatPrice(float $price, string $currency): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($price, 2);
    }

    abstract protected function buildResponse(array $data): array;
}

final class ProductResponseFormatter extends AbstractResponseFormatter
{
    public function format(array $productData): array
    {
        $this->requireField($productData, 'id');
        $this->requireNonEmpty($productData, 'sku');
        $this->requireNonEmpty($productData, 'name');
        $this->validateNumeric($productData, 'price');
        $this->validateInteger($productData, 'stock_quantity', allowNegative: false);
        $this->validateArray($productData, 'categories');
        $this->validateArray($productData, 'tags');

        return $this->buildResponse($productData);
    }

    protected function buildResponse(array $data): array
    {
        return [
            'id' => (int)$data['id'],
            'sku' => trim($data['sku']),
            'name' => trim($data['name']),
            'price' => (float)$data['price'],
            'stock_quantity' => (int)($data['stock_quantity'] ?? 0),
            'formatted_price' => $this->formatPrice($data['price'], $data['currency'] ?? 'USD'),
            'in_stock' => ($data['stock_quantity'] ?? 0) > 0,
        ];
    }
}
