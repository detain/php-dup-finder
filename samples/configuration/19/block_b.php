<?php

declare(strict_types=1);

namespace App\Http\Validation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;

final class ProductValidationService
{
    private const MIN_NAME_LENGTH = 3;
    private const MAX_NAME_LENGTH = 255;
    private const MIN_DESCRIPTION_LENGTH = 10;
    private const MAX_DESCRIPTION_LENGTH = 5000;
    private const MIN_PRICE = 0.01;
    private const MAX_PRICE = 999999.99;
    private const PRICE_DECIMAL_PLACES = 2;
    private const MIN_QUANTITY = 0;
    private const MAX_QUANTITY = 1000000;
    private const MIN_WEIGHT = 0.01;
    private const MAX_WEIGHT = 10000;
    private const MIN_DIMENSION = 0.1;
    private const MAX_DIMENSION = 1000;
    private const SKU_PATTERN = '/^[A-Z0-9]{3,}-[A-Z0-9]{3,}$/';
    private const BARCODE_PATTERN = '/^[0-9]{8,14}$/';
    private const ALLOWED_CATEGORIES = ['electronics', 'clothing', 'food', 'furniture', 'books', 'toys'];
    private const ALLOWED_TAGS = ['new', 'sale', 'featured', 'bestseller', 'limited', 'eco'];
    private const MAX_IMAGES = 10;
    private const MAX_IMAGE_SIZE = 5242880;
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const VALIDATION_STRICT_MODE = true;
    private const VALIDATION_COERCE_VALUES = false;
    private const VALIDATION_ADD_DEFAULTS = true;

    public function validate(Request $request): array
    {
        $rules = $this->getRules();
        $messages = $this->getMessages();

        $validator = validator($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->toArray(),
            ];
        }

        return [
            'valid' => true,
            'data' => $validator->validated(),
        ];
    }

    public function validateProductData(array $data): array
    {
        $errors = [];

        if (!$this->validateName($data['name'] ?? '')) {
            $errors['name'][] = 'Invalid product name';
        }

        if (!$this->validateDescription($data['description'] ?? '')) {
            $errors['description'][] = 'Invalid product description';
        }

        if (!$this->validatePrice($data['price'] ?? null)) {
            $errors['price'][] = 'Invalid price value';
        }

        if (!$this->validateQuantity($data['quantity'] ?? null)) {
            $errors['quantity'][] = 'Invalid quantity value';
        }

        if (!$this->validateSku($data['sku'] ?? '')) {
            $errors['sku'][] = 'Invalid SKU format';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function validateName(string $name): bool
    {
        $length = strlen($name);

        if ($length < self::MIN_NAME_LENGTH || $length > self::MAX_NAME_LENGTH) {
            return false;
        }

        return true;
    }

    public function validateDescription(string $description): bool
    {
        $length = strlen($description);

        if ($length < self::MIN_DESCRIPTION_LENGTH || $length > self::MAX_DESCRIPTION_LENGTH) {
            return false;
        }

        return true;
    }

    public function validatePrice(?float $price): bool
    {
        if ($price === null) {
            return false;
        }

        if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
            return false;
        }

        $decimalPlaces = strlen(substr(strrchr((string) $price, '.'), 1));

        if ($decimalPlaces > self::PRICE_DECIMAL_PLACES) {
            return false;
        }

        return true;
    }

    public function validateQuantity(?int $quantity): bool
    {
        if ($quantity === null) {
            return false;
        }

        return $quantity >= self::MIN_QUANTITY && $quantity <= self::MAX_QUANTITY;
    }

    public function validateSku(string $sku): bool
    {
        if (empty($sku)) {
            return false;
        }

        return preg_match(self::SKU_PATTERN, $sku) === 1;
    }

    public function validateBarcode(string $barcode): bool
    {
        if (empty($barcode)) {
            return false;
        }

        return preg_match(self::BARCODE_PATTERN, $barcode) === 1;
    }

    public function validateCategory(string $category): bool
    {
        return in_array($category, self::ALLOWED_CATEGORIES, true);
    }

    public function validateTags(array $tags): array
    {
        $errors = [];
        $validTags = [];

        foreach ($tags as $tag) {
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $errors['tags'][] = sprintf('Tag "%s" is not allowed', $tag);
            } else {
                $validTags[] = $tag;
            }
        }

        return [
            'valid' => empty($errors),
            'tags' => $validTags,
            'errors' => $errors,
        ];
    }

    public function validateImage(array $image): array
    {
        $errors = [];

        if (($image['size'] ?? 0) > self::MAX_IMAGE_SIZE) {
            $errors['image'][] = sprintf(
                'Image size must not exceed %d MB',
                self::MAX_IMAGE_SIZE / 1048576
            );
        }

        $mimeType = $image['type'] ?? '';

        if (!in_array($mimeType, self::ALLOWED_IMAGE_TYPES, true)) {
            $errors['image'][] = sprintf(
                'Image type "%s" is not allowed. Allowed: %s',
                $mimeType,
                implode(', ', self::ALLOWED_IMAGE_TYPES)
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function getRules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:' . self::MIN_NAME_LENGTH,
                'max:' . self::MAX_NAME_LENGTH,
            ],
            'description' => [
                'required',
                'string',
                'min:' . self::MIN_DESCRIPTION_LENGTH,
                'max:' . self::MAX_DESCRIPTION_LENGTH,
            ],
            'price' => [
                'required',
                'numeric',
                'min:' . self::MIN_PRICE,
                'max:' . self::MAX_PRICE,
            ],
            'quantity' => [
                'required',
                'integer',
                'min:' . self::MIN_QUANTITY,
                'max:' . self::MAX_QUANTITY,
            ],
            'sku' => [
                'required',
                'string',
                'regex:' . self::SKU_PATTERN,
                'unique:products,sku',
            ],
            'category' => [
                'required',
                'string',
                Rule::in(self::ALLOWED_CATEGORIES),
            ],
            'weight' => [
                'nullable',
                'numeric',
                'min:' . self::MIN_WEIGHT,
                'max:' . self::MAX_WEIGHT,
            ],
            'dimensions' => [
                'nullable',
                'string',
            ],
            'images' => [
                'nullable',
                'array',
                'max:' . self::MAX_IMAGES,
            ],
        ];
    }

    private function getMessages(): array
    {
        return [
            'name.required' => 'Product name is required',
            'name.min' => sprintf('Product name must be at least %d characters', self::MIN_NAME_LENGTH),
            'price.min' => sprintf('Price must be at least %.2f', self::MIN_PRICE),
            'price.max' => sprintf('Price must not exceed %.2f', self::MAX_PRICE),
            'sku.regex' => 'SKU must be in format XXX-XXX',
            'category.in' => 'Invalid product category',
        ];
    }

    public function getAllowedCategories(): array
    {
        return self::ALLOWED_CATEGORIES;
    }

    public function getAllowedTags(): array
    {
        return self::ALLOWED_TAGS;
    }

    public function getPriceRange(): array
    {
        return [
            'min' => self::MIN_PRICE,
            'max' => self::MAX_PRICE,
            'decimal_places' => self::PRICE_DECIMAL_PLACES,
        ];
    }
}
