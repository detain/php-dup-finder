<?php
declare(strict_types=1);

namespace App\Category\Model;

use App\Category\Entity\Category;

final class CategoryModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly bool $isActive,
        public readonly int $sortOrder,
        public readonly int $depth,
        public readonly ?string $imageUrl = null,
        public readonly array $ancestors = [],
        public readonly array $children = []
    ) {}

    public static function fromEntity(Category $category): self
    {
        return new self(
            id: $category->getId(),
            name: $category->getName(),
            slug: $category->getSlug(),
            description: $category->getDescription(),
            isActive: $category->isActive(),
            sortOrder: $category->getSortOrder(),
            depth: $category->getDepth(),
            imageUrl: $category->getImageUrl(),
            ancestors: array_map(
                fn($a) => ['id' => $a->getId(), 'name' => $a->getName(), 'slug' => $a->getSlug()],
                $category->getAncestors()
            ),
            children: []
        );
    }

    public function getPath(): string
    {
        $names = array_column($this->ancestors, 'name');
        $names[] = $this->name;
        return implode(' > ', $names);
    }

    public function toTreeNode(array $selectedIds = []): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'depth' => str_repeat('--', $this->depth),
            'is_selected' => in_array($this->id, $selectedIds)
        ];
    }
}
