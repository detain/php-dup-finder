<?php
declare(strict_types=1);

namespace Acme\Api\Resources;

use Acme\Catalog\Category;
use Acme\Locale\Translator;

final class CategoryResource
{
    public function __construct(
        private readonly Translator $translator,
        private readonly string $baseUrl
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(Category $category, string $locale): array
    {
        $payload = [
            'type' => 'categories',
            'id'   => (string)$category->id,
            'attributes' => [
                'name'        => $this->translator->forKey('category.' . $category->slug, $locale, $category->name),
                'description' => $this->translator->forKey('category.desc.' . $category->slug, $locale, $category->description),
                'sort_order'  => $category->sortOrder,
                'visible'     => $category->isVisible,
                'slug'        => $category->slug,
                'created_at'  => $category->createdAt->format(DATE_ATOM),
            ],
            'relationships' => [
                'parent' => [
                    'data' => $category->parentId
                        ? ['type' => 'categories', 'id' => (string)$category->parentId]
                        : null,
                ],
                'products' => [
                    'links' => [
                        'related' => $this->baseUrl . '/categories/' . $category->id . '/products',
                    ],
                ],
            ],
            'links' => [
                'self'    => $this->baseUrl . '/categories/' . $category->id,
                'related' => $this->baseUrl . '/categories/' . $category->id . '/children',
            ],
            'meta' => [
                'locale'    => $locale,
                'version'   => 'v2',
                'cacheable' => true,
            ],
        ];
        if ($category->archivedAt !== null) {
            $payload['attributes']['archived_at'] = $category->archivedAt->format(DATE_ATOM);
            $payload['meta']['active'] = false;
        }
        return $payload;
    }
}
