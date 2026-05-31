<?php
declare(strict_types=1);

namespace Acme\Api\Resources;

use Acme\Catalog\Brand;
use Acme\Locale\Translator;

final class BrandResource
{
    public function __construct(
        private readonly Translator $translator,
        private readonly string $baseUrl
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(Brand $brand, string $locale): array
    {
        $payload = [
            'type' => 'brands',
            'id'   => (string)$brand->id,
            'attributes' => [
                'name'        => $this->translator->forKey('brand.' . $brand->slug, $locale, $brand->name),
                'description' => $this->translator->forKey('brand.desc.' . $brand->slug, $locale, $brand->description),
                'country'     => $brand->countryCode,
                'website'     => $brand->website,
                'founded'     => $brand->foundedYear,
                'created_at'  => $brand->createdAt->format(DATE_ATOM),
            ],
            'relationships' => [
                'products' => [
                    'links' => [
                        'related' => $this->baseUrl . '/brands/' . $brand->id . '/products',
                    ],
                ],
                'owner' => [
                    'data' => $brand->ownerId
                        ? ['type' => 'users', 'id' => (string)$brand->ownerId]
                        : null,
                ],
            ],
            'links' => [
                'self'    => $this->baseUrl . '/brands/' . $brand->id,
                'related' => $this->baseUrl . '/brands/' . $brand->id . '/campaigns',
            ],
            'meta' => [
                'locale'    => $locale,
                'version'   => 'v2',
                'cacheable' => true,
            ],
        ];
        if ($brand->retiredAt !== null) {
            $payload['attributes']['retired_at'] = $brand->retiredAt->format(DATE_ATOM);
            $payload['meta']['active'] = false;
        }
        return $payload;
    }
}
