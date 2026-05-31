<?php
declare(strict_types=1);

namespace Storefront\Listing;

final class StorefrontInventoryListing
{
    public string $sku;
    public string $headline;
    public string $marketingBlurb;
    public bool $inStock;
    public int $publishedQty;
    public array $badges;
    public int $supplierId;
    public float $listPrice;

    public function fromCatalog(array $catalog): void
    {
        if (empty($catalog['sku'])) {
            throw new \InvalidArgumentException('SKU required');
        }
        if ((float)($catalog['list_price'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Negative price');
        }
        if ((int)($catalog['available'] ?? 0) < 0) {
            throw new \InvalidArgumentException('Negative qty');
        }
        $this->sku = (string)$catalog['sku'];
        $this->headline = (string)$catalog['name'];
        $this->marketingBlurb = (string)($catalog['description'] ?? '');
        $available = (int)($catalog['available'] ?? 0);
        $this->inStock = $available > 0;
        $this->publishedQty = $available > 10 ? 10 : $available; // hide exact counts
        $this->badges = [];
        if ($available > 0 && $available < 5) {
            $this->badges[] = 'low-stock';
        }
        if (!empty($catalog['new'])) {
            $this->badges[] = 'new';
        }
        $this->supplierId = (int)$catalog['supplier_id'];
        $this->listPrice = (float)$catalog['list_price'];
    }

    public function priceDisplay(): string
    {
        return '$' . number_format($this->listPrice, 2);
    }
}

final class StorefrontController
{
    public function listing(array $row): StorefrontInventoryListing
    {
        $l = new StorefrontInventoryListing();
        $l->fromCatalog($row);
        return $l;
    }
}
