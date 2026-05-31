<?php
declare(strict_types=1);

namespace Acme\Api\Resources;

use Acme\Catalog\Product;
use Acme\Locale\Money;
use Acme\Locale\Translator;

final class ProductResource
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Money $money,
        private readonly string $baseUrl
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(Product $product, string $locale): array
    {
        $payload = [
            'type' => 'products',
            'id'   => (string)$product->id,
            'attributes' => [
                'name'        => $this->translator->forKey('product.' . $product->slug, $locale, $product->name),
                'description' => $this->translator->forKey('product.desc.' . $product->slug, $locale, $product->description),
                'price'       => $this->money->format($product->priceCents, $product->currency, $locale),
                'in_stock'    => $product->stock > 0,
                'sku'         => $product->sku,
                'created_at'  => $product->createdAt->format(DATE_ATOM),
            ],
            'relationships' => [
                'category' => [
                    'data' => $product->categoryId
                        ? ['type' => 'categories', 'id' => (string)$product->categoryId]
                        : null,
                ],
                'brand' => [
                    'data' => $product->brandId
                        ? ['type' => 'brands', 'id' => (string)$product->brandId]
                        : null,
                ],
            ],
            'links' => [
                'self'    => $this->baseUrl . '/products/' . $product->id,
                'related' => $this->baseUrl . '/products/' . $product->id . '/reviews',
            ],
            'meta' => [
                'locale'    => $locale,
                'version'   => 'v2',
                'cacheable' => true,
            ],
        ];
        if ($product->discontinuedAt !== null) {
            $payload['attributes']['discontinued_at'] = $product->discontinuedAt->format(DATE_ATOM);
            $payload['meta']['active'] = false;
        }
        return $payload;
    }
}
