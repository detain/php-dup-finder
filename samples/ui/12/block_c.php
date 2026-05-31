<?php

declare(strict_types=1);

namespace App\View\Table;

use App\Entity\Product;
use Psr\Log\LoggerInterface;

final class ProductTableRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderHeaders(): string
    {
        $columns = [
            ['key' => 'sku', 'label' => 'SKU', 'sortable' => true, 'width' => 100],
            ['key' => 'name', 'label' => 'Product Name', 'sortable' => true, 'width' => 280],
            ['key' => 'price', 'label' => 'Price', 'sortable' => true, 'width' => 100],
            ['key' => 'stock', 'label' => 'Stock', 'sortable' => true, 'width' => 80],
            ['key' => 'category', 'label' => 'Category', 'sortable' => true, 'width' => 130],
            ['key' => 'actions', 'label' => '', 'sortable' => false, 'width' => 80],
        ];

        $html = '<thead><tr>';
        foreach ($columns as $column) {
            $sortIndicator = $column['sortable'] ? '<span class="sort-indicator">↕</span>' : '';
            $thClass = $column['sortable'] ? 'th-sortable' : '';
            $html .= '<th class="' . $thClass . '" data-column="' . $column['key'] . '" style="width:' . $column['width'] . 'px">';
            $html .= htmlspecialchars($column['label']) . $sortIndicator;
            $html .= '</th>';
        }
        $html .= '</tr></thead>';

        return $html;
    }

    public function renderRow(Product $product): string
    {
        $stockClass = match (true) {
            $product->getStockQuantity() > 10 => 'stock-ok',
            $product->getStockQuantity() > 0 => 'stock-low',
            default => 'stock-out',
        };

        $html = '<tr data-product-id="' . $product->getId() . '">';
        $html .= '<td class="cell-sku">' . htmlspecialchars($product->getSku()) . '</td>';
        $html .= '<td class="cell-name">' . htmlspecialchars($product->getName()) . '</td>';
        $html .= '<td class="cell-price">$' . number_format($product->getPrice(), 2) . '</td>';
        $html .= '<td class="cell-stock"><span class="stock-badge ' . $stockClass . '">' . $product->getStockQuantity() . '</span></td>';
        $html .= '<td class="cell-category">' . htmlspecialchars($product->getCategory()) . '</td>';
        $html .= '<td class="cell-actions">';
        $html .= '<a href="/products/' . $product->getId() . '/edit" class="action-edit" title="Edit">Edit</a>';
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    public function renderTable(array $products, array $options = []): string
    {
        $html = '<div class="table-container">';
        $html .= '<table class="data-table product-table"';

        if (!empty($options['sortColumn'])) {
            $html .= ' data-sort-column="' . htmlspecialchars($options['sortColumn']) . '"';
            $html .= ' data-sort-direction="' . htmlspecialchars($options['sortDirection'] ?? 'asc') . '"';
        }

        $html .= '>';
        $html .= $this->renderHeaders();

        $html .= '<tbody>';
        if (empty($products)) {
            $html .= '<tr><td colspan="6" class="empty-row">No products found</td></tr>';
        } else {
            foreach ($products as $product) {
                $html .= $this->renderRow($product);
            }
        }
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        $this->logger->debug('Rendered product table', ['row_count' => count($products)]);

        return $html;
    }

    public function renderPagination(int $currentPage, int $totalPages, int $perPage, int $totalCount): string
    {
        $html = '<div class="table-pagination">';
        $html .= '<span class="pagination-info">Showing ' . (($currentPage - 1) * $perPage + 1) . '-' . min($currentPage * $perPage, $totalCount) . ' of ' . $totalCount . '</span>';
        $html .= '<div class="pagination-links">';

        if ($currentPage > 1) {
            $html .= '<a href="?page=' . ($currentPage - 1) . '" class="page-link prev">Previous</a>';
        }

        for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
            $activeClass = $i === $currentPage ? ' active' : '';
            $html .= '<a href="?page=' . $i . '" class="page-link' . $activeClass . '">' . $i . '</a>';
        }

        if ($currentPage < $totalPages) {
            $html .= '<a href="?page=' . ($currentPage + 1) . '" class="page-link next">Next</a>';
        }

        $html .= '</div></div>';

        return $html;
    }
}
