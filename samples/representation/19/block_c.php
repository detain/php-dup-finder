<?php
declare(strict_types=1);

namespace App\Category\Tree;

final class CategoryTreeNode
{
    public string $id;
    public string $name;
    public string $slug;
    public string $depth;
    public int $productCount;
    public bool $isActive;
    public bool $isExpanded;
    public bool $isSelected;
    public array $children;
    public string $parentId;

    public static function fromCategory(Category $category, array $selectedIds = []): self
    {
        $node = new self();
        $node->id = $category->getId();
        $node->name = $category->getName();
        $node->slug = $category->getSlug();
        $node->depth = str_repeat('--', $category->getDepth()) . ' ';
        $node->productCount = $category->getProducts()->count();
        $node->isActive = $category->isActive();
        $node->isExpanded = true;
        $node->isSelected = in_array($category->getId(), $selectedIds, true);
        $node->parentId = $category->getParent()?->getId() ?? '';

        $node->children = [];
        foreach ($category->getChildren() as $child) {
            if ($child->isActive()) {
                $node->children[] = self::fromCategory($child, $selectedIds);
            }
        }

        return $node;
    }

    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    public function getFullPath(): string
    {
        return $this->depth . $this->name;
    }

    public function toSelectOption(): array
    {
        return [
            'value' => $this->id,
            'label' => $this->getFullPath(),
            'product_count' => $this->productCount,
            'is_selected' => $this->isSelected,
            'has_children' => $this->hasChildren()
        ];
    }

    public function flatten(): array
    {
        $result = [$this->toSelectOption()];

        foreach ($this->children as $child) {
            $result = array_merge($result, $child->flatten());
        }

        return $result;
    }

    public function findById(string $id): ?self
    {
        if ($this->id === $id) {
            return $this;
        }

        foreach ($this->children as $child) {
            $found = $child->findById($id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function getAncestorIds(): array
    {
        $ids = [];
        foreach ($this->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getAncestorIds());
        }

        return $ids;
    }
}
