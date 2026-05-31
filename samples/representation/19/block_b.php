<?php
declare(strict_types=1);

namespace App\Category\DTO;

final class CategoryDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?string $imageUrl,
        public readonly bool $isActive,
        public readonly bool $isFeatured,
        public readonly int $sortOrder,
        public readonly int $depth,
        public readonly string $path,
        public readonly array $ancestors,
        public readonly array $children,
        public readonly int $productCount
    ) {}

    public static function fromEntity(Category $category, bool $includeChildren = false): self
    {
        $ancestors = array_map(
            fn($a) => [
                'id' => $a->getId(),
                'name' => $a->getName(),
                'slug' => $a->getSlug()
            ],
            $category->getAncestors()
        );

        $children = [];
        if ($includeChildren) {
            foreach ($category->getChildren() as $child) {
                if ($child->isActive()) {
                    $children[] = self::fromEntity($child, false);
                }
            }
        }

        return new self(
            id: $category->getId(),
            name: $category->getName(),
            slug: $category->getSlug(),
            description: $category->getDescription(),
            imageUrl: $category->getImageUrl(),
            isActive: $category->isActive(),
            isFeatured: $category->isFeatured(),
            sortOrder: $category->getSortOrder(),
            depth: $category->getDepth(),
            path: $category->getPath(),
            ancestors: $ancestors,
            children: $children,
            productCount: $category->getProducts()->count()
        );
    }

    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    public function hasAncestors(): bool
    {
        return count($this->ancestors) > 0;
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [];

        foreach ($this->ancestors as $ancestor) {
            $breadcrumbs[] = [
                'id' => $ancestor['id'],
                'name' => $ancestor['name'],
                'slug' => $ancestor['slug']
            ];
        }

        $breadcrumbs[] = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug
        ];

        return $breadcrumbs;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => $this->imageUrl,
            'is_active' => $this->isActive,
            'is_featured' => $this->isFeatured,
            'sort_order' => $this->sortOrder,
            'depth' => $this->depth,
            'path' => $this->path,
            'ancestors' => $this->ancestors,
            'children' => array_map(fn($c) => $c->toArray(), $this->children),
            'product_count' => $this->productCount
        ];
    }
}
