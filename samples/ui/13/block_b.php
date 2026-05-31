<?php

declare(strict_types=1);

namespace App\View\Card;

use App\Entity\Product;
use Psr\Log\LoggerInterface;

final class ProductCardRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderCard(Product $product, array $options = []): string
    {
        $showImage = $options['showImage'] ?? true;
        $showActions = $options['showActions'] ?? true;
        $compact = $options['compact'] ?? false;

        $stockClass = match (true) {
            $product->getStockQuantity() > 10 => 'stock-ok',
            $product->getStockQuantity() > 0 => 'stock-low',
            default => 'stock-out',
        };

        $onSale = $product->getOriginalPrice() !== null && $product->getOriginalPrice() > $product->getPrice();

        $html = '<article class="product-card';
        if ($compact) {
            $html .= ' card-compact';
        }
        $html .= '" data-product-id="' . $product->getId() . '">';

        if ($showImage) {
            $html .= '<div class="card-image-container">';
            $imageUrl = $product->getImageUrl() ?? 'https://via.placeholder.com/300x200?text=No+Image';
            $html .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="' . htmlspecialchars($product->getName()) . '" class="card-image" loading="lazy" />';
            if ($onSale) {
                $html .= '<span class="sale-badge">Sale</span>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="card-content">';
        $html .= '<span class="card-category">' . htmlspecialchars($product->getCategory()) . '</span>';
        $html .= '<h3 class="card-title">' . htmlspecialchars($product->getName()) . '</h3>';

        $html .= '<div class="card-pricing">';
        $html .= '<span class="price-current">$' . number_format($product->getPrice(), 2) . '</span>';
        if ($onSale) {
            $html .= '<span class="price-original">$' . number_format($product->getOriginalPrice(), 2) . '</span>';
        }
        $html .= '</div>';

        if (!$compact) {
            $html .= '<div class="card-meta">';
            $html .= '<span class="stock-indicator ' . $stockClass . '">';
            $html .= $product->getStockQuantity() . ' in stock';
            $html .= '</span>';
            $html .= '</div>';
        }

        if ($showActions) {
            $html .= '<div class="card-actions">';
            $html .= '<a href="/products/' . $product->getId() . '" class="btn-view">View Details</a>';
            $html .= '<button type="button" class="btn-add-cart" data-product-id="' . $product->getId() . '">Add to Cart</button>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</article>';

        $this->logger->debug('Rendered product card', ['product_id' => $product->getId()]);

        return $html;
    }

    public function renderCardGrid(array $products, array $options = []): string
    {
        $columns = $options['columns'] ?? 4;

        $html = '<div class="card-grid grid-cols-' . $columns . '">';

        if (empty($products)) {
            $html .= '<div class="empty-state">No products to display</div>';
        } else {
            foreach ($products as $product) {
                $html .= $this->renderCard($product, $options);
            }
        }

        $html .= '</div>';

        return $html;
    }
}
