<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedTableRenderer
{
    /** @var array<string, TableColumnConfig> */
    private array $columnConfigs = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeConfigs();
    }

    private function initializeConfigs(): void
    {
        $this->columnConfigs['user'] = [
            new TableColumnConfig('id', 'ID', true, 60),
            new TableColumnConfig('name', 'Name', true, 200),
            new TableColumnConfig('email', 'Email', true, 250),
            new TableColumnConfig('status', 'Status', true, 100),
            new TableColumnConfig('created', 'Created', true, 120),
            new TableColumnConfig('actions', '', false, 80),
        ];

        $this->columnConfigs['order'] = [
            new TableColumnConfig('id', 'Order #', true, 100),
            new TableColumnConfig('customer', 'Customer', true, 180),
            new TableColumnConfig('total', 'Total', true, 100),
            new TableColumnConfig('status', 'Status', true, 110),
            new TableColumnConfig('date', 'Date', true, 120),
            new TableColumnConfig('actions', '', false, 80),
        ];

        $this->columnConfigs['product'] = [
            new TableColumnConfig('sku', 'SKU', true, 100),
            new TableColumnConfig('name', 'Product Name', true, 280),
            new TableColumnConfig('price', 'Price', true, 100),
            new TableColumnConfig('stock', 'Stock', true, 80),
            new TableColumnConfig('category', 'Category', true, 130),
            new TableColumnConfig('actions', '', false, 80),
        ];
    }

    public function renderTable(string $entityType, array $entities, array $options = []): string
    {
        $columns = $this->columnConfigs[$entityType] ?? [];
        $tableClass = $entityType . '-table';

        $html = '<div class="table-container">';
        $html .= '<table class="data-table ' . htmlspecialchars($tableClass) . '"';

        if (!empty($options['sortColumn'])) {
            $html .= ' data-sort-column="' . htmlspecialchars($options['sortColumn']) . '"';
            $html .= ' data-sort-direction="' . htmlspecialchars($options['sortDirection'] ?? 'asc') . '"';
        }

        $html .= '>';
        $html .= $this->renderHeaders($columns);
        $html .= '<tbody>';

        if (empty($entities)) {
            $html .= '<tr><td colspan="' . count($columns) . '" class="empty-row">No ' . htmlspecialchars($entityType) . 's found</td></tr>';
        } else {
            foreach ($entities as $entity) {
                $html .= $this->renderRow($entity, $entityType);
            }
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        $this->logger->debug("Rendered {$entityType} table", ['row_count' => count($entities)]);

        return $html;
    }

    private function renderHeaders(array $columns): string
    {
        $html = '<thead><tr>';

        foreach ($columns as $column) {
            $sortIndicator = $column->sortable ? '<span class="sort-indicator">↕</span>' : '';
            $thClass = $column->sortable ? 'th-sortable' : '';

            $html .= '<th class="' . $thClass . '" data-column="' . $column->key . '" style="width:' . $column->width . 'px">';
            $html .= htmlspecialchars($column->label) . $sortIndicator;
            $html .= '</th>';
        }

        $html .= '</tr></thead>';
        return $html;
    }

    private function renderRow(object $entity, string $entityType): string
    {
        $html = '<tr';

        if (method_exists($entity, 'getId')) {
            $html .= ' data-' . $entityType . '-id="' . $entity->getId() . '"';
        }

        $html .= '>';

        $html .= match ($entityType) {
            'user' => $this->renderUserRow($entity),
            'order' => $this->renderOrderRow($entity),
            'product' => $this->renderProductRow($entity),
            default => '',
        };

        $html .= '</tr>';
        return $html;
    }

    private function renderUserRow(\App\Entity\User $user): string
    {
        $statusClass = match ($user->getStatus()) {
            'active' => 'status-active',
            'inactive' => 'status-inactive',
            'pending' => 'status-pending',
            default => '',
        };

        $html = '';
        $html .= '<td class="cell-id">' . $user->getId() . '</td>';
        $html .= '<td class="cell-name">' . htmlspecialchars($user->getFullName()) . '</td>';
        $html .= '<td class="cell-email">' . htmlspecialchars($user->getEmail()) . '</td>';
        $html .= '<td class="cell-status"><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($user->getStatus()) . '</span></td>';
        $html .= '<td class="cell-date">' . $user->getCreatedAt()->format('Y-m-d') . '</td>';
        $html .= '<td class="cell-actions"><a href="/users/' . $user->getId() . '/edit" class="action-edit">Edit</a></td>';

        return $html;
    }

    private function renderOrderRow(\App\Entity\Order $order): string
    {
        $statusClass = match ($order->getStatus()) {
            'completed' => 'status-completed',
            'processing' => 'status-processing',
            'shipped' => 'status-shipped',
            'cancelled' => 'status-cancelled',
            default => '',
        };

        $html = '';
        $html .= '<td class="cell-order-id">' . htmlspecialchars($order->getOrderNumber()) . '</td>';
        $html .= '<td class="cell-customer">' . htmlspecialchars($order->getCustomerName()) . '</td>';
        $html .= '<td class="cell-total">$' . number_format($order->getTotalAmount(), 2) . '</td>';
        $html .= '<td class="cell-status"><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($order->getStatus()) . '</span></td>';
        $html .= '<td class="cell-date">' . $order->getCreatedAt()->format('Y-m-d') . '</td>';
        $html .= '<td class="cell-actions"><a href="/orders/' . $order->getId() . '" class="action-view">View</a></td>';

        return $html;
    }

    private function renderProductRow(\App\Entity\Product $product): string
    {
        $stockClass = match (true) {
            $product->getStockQuantity() > 10 => 'stock-ok',
            $product->getStockQuantity() > 0 => 'stock-low',
            default => 'stock-out',
        };

        $html = '';
        $html .= '<td class="cell-sku">' . htmlspecialchars($product->getSku()) . '</td>';
        $html .= '<td class="cell-name">' . htmlspecialchars($product->getName()) . '</td>';
        $html .= '<td class="cell-price">$' . number_format($product->getPrice(), 2) . '</td>';
        $html .= '<td class="cell-stock"><span class="stock-badge ' . $stockClass . '">' . $product->getStockQuantity() . '</span></td>';
        $html .= '<td class="cell-category">' . htmlspecialchars($product->getCategory()) . '</td>';
        $html .= '<td class="cell-actions"><a href="/products/' . $product->getId() . '/edit" class="action-edit">Edit</a></td>';

        return $html;
    }

    public function renderPagination(int $currentPage, int $totalPages, int $perPage, int $totalCount): string
    {
        $html = '<div class="table-pagination">';
        $html .= '<span class="pagination-info">';
        $html .= 'Showing ' . (($currentPage - 1) * $perPage + 1) . '-' . min($currentPage * $perPage, $totalCount) . ' of ' . $totalCount;
        $html .= '</span>';
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

final class TableColumnConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $sortable,
        public readonly int $width,
    ) {}
}
