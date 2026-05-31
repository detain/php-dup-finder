<?php
declare(strict_types=1);

namespace Validation\Sanitization;

use Psr\Log\LoggerInterface;

final class ProductInputSanitizer
{
    private const MAX_PRODUCT_NAME_LENGTH = 200;
    private const MAX_DESCRIPTION_LENGTH = 5000;
    private const MAX_SKU_LENGTH = 50;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function sanitizeForCreate(array $input): SanitizedInput
    {
        $sanitized = [];

        $sanitized['sku'] = $this->sanitizeSku($input['sku'] ?? '');
        $sanitized['name'] = $this->sanitizeName($input['name'] ?? '');
        $sanitized['description'] = $this->sanitizeDescription($input['description'] ?? '');
        $sanitized['price'] = $this->sanitizePrice($input['price'] ?? 0);
        $sanitized['cost'] = $this->sanitizePrice($input['cost'] ?? 0);
        $sanitized['category'] = $this->sanitizeCategory($input['category'] ?? 'uncategorized');
        $sanitized['tags'] = $this->sanitizeTags($input['tags'] ?? []);
        $sanitized['inventory_count'] = $this->sanitizeInventoryCount($input['inventory_count'] ?? 0);
        $sanitized['is_active'] = $this->sanitizeBoolean($input['is_active'] ?? true);
        $sanitized['metadata'] = $this->sanitizeMetadata($input['metadata'] ?? []);

        $this->logger->debug('Product input sanitized for create', [
            'sku' => $sanitized['sku'],
        ]);

        return new SanitizedInput(
            data: $sanitized,
            violations: $this->validate($sanitized),
            sanitizedAt: new \DateTimeImmutable(),
        );
    }

    public function sanitizeForUpdate(array $input, Product $existingProduct): SanitizedInput
    {
        $sanitized = [];

        if (isset($input['name'])) {
            $sanitized['name'] = $this->sanitizeName($input['name']);
        }

        if (isset($input['description'])) {
            $sanitized['description'] = $this->sanitizeDescription($input['description']);
        }

        if (isset($input['price'])) {
            $sanitized['price'] = $this->sanitizePrice($input['price']);
        }

        if (isset($input['cost'])) {
            $sanitized['cost'] = $this->sanitizePrice($input['cost']);
        }

        if (isset($input['inventory_count'])) {
            $sanitized['inventory_count'] = $this->sanitizeInventoryCount($input['inventory_count']);
        }

        if (isset($input['is_active'])) {
            $sanitized['is_active'] = $this->sanitizeBoolean($input['is_active']);
        }

        $this->logger->debug('Product input sanitized for update', [
            'updated_fields' => array_keys($sanitized),
        ]);

        return new SanitizedInput(
            data: $sanitized,
            violations: $this->validatePartial($sanitized),
            sanitizedAt: new \DateTimeImmutable(),
        );
    }

    private function sanitizeSku(string $sku): string
    {
        $sku = strtoupper(trim($sku));
        $sku = preg_replace('/[^A-Z0-9_-]/', '', $sku);

        return substr($sku, 0, self::MAX_SKU_LENGTH);
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = $this->removeControlChars($name);
        $name = preg_replace('/\s+/', ' ', $name);

        return substr($name, 0, self::MAX_PRODUCT_NAME_LENGTH);
    }

    private function sanitizeDescription(string $description): string
    {
        $description = trim($description);
        $description = $this->removeControlChars($description);
        $description = strip_tags($description);

        return substr($description, 0, self::MAX_DESCRIPTION_LENGTH);
    }

    private function sanitizePrice(mixed $price): float
    {
        $price = (float)$price;

        return max(0, round($price, 2));
    }

    private function sanitizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        $category = preg_replace('/[^a-z0-9_-]/', '', $category);

        return substr($category, 0, 100);
    }

    private function sanitizeTags(array $tags): array
    {
        $sanitizedTags = [];

        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));
            $tag = preg_replace('/[^a-z0-9]/', '', $tag);

            if (strlen($tag) > 0 && strlen($tag) <= 50) {
                $sanitizedTags[] = $tag;
            }
        }

        return array_unique($sanitizedTags);
    }

    private function sanitizeInventoryCount(mixed $count): int
    {
        $count = (int)$count;

        return max(0, $count);
    }

    private function sanitizeBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);

            if (is_string($value)) {
                $sanitized[$key] = $this->removeControlChars($value);
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value;
            } elseif (is_bool($value)) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function validate(array $data): array
    {
        $violations = [];

        if (empty($data['sku'])) {
            $violations[] = 'sku_required';
        }

        if (strlen($data['name']) < 1) {
            $violations[] = 'name_required';
        }

        if ($data['price'] <= 0) {
            $violations[] = 'price_must_be_positive';
        }

        if ($data['cost'] < 0) {
            $violations[] = 'cost_cannot_be_negative';
        }

        return $violations;
    }

    private function validatePartial(array $data): array
    {
        $violations = [];

        if (isset($data['price']) && $data['price'] <= 0) {
            $violations[] = 'price_must_be_positive';
        }

        return $violations;
    }

    private function removeControlChars(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }
}
