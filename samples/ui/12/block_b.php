<?php

declare(strict_types=1);

namespace App\View\Table;

use App\Entity\Order;
use Psr\Log\LoggerInterface;

final class OrderTableRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderHeaders(): string
    {
        $columns = [
            ['key' => 'id', 'label' => 'Order #', 'sortable' => true, 'width' => 100],
            ['key' => 'customer', 'label' => 'Customer', 'sortable' => true, 'width' => 180],
            ['key' => 'total', 'label' => 'Total', 'sortable' => true, 'width' => 100],
            ['key' => 'status', 'label' => 'Status', 'sortable' => true, 'width' => 110],
            ['key' => 'date', 'label' => 'Date', 'sortable' => true, 'width' => 120],
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

    public function renderRow(Order $order): string
    {
        $statusClass = match ($order->getStatus()) {
            'completed' => 'status-completed',
            'processing' => 'status-processing',
            'shipped' => 'status-shipped',
            'cancelled' => 'status-cancelled',
            'pending' => 'status-pending',
            default => '',
        };

        $html = '<tr data-order-id="' . $order->getId() . '">';
        $html .= '<td class="cell-order-id">' . htmlspecialchars($order->getOrderNumber()) . '</td>';
        $html .= '<td class="cell-customer">' . htmlspecialchars($order->getCustomerName()) . '</td>';
        $html .= '<td class="cell-total">$' . number_format($order->getTotalAmount(), 2) . '</td>';
        $html .= '<td class="cell-status"><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($order->getStatus()) . '</span></td>';
        $html .= '<td class="cell-date">' . $order->getCreatedAt()->format('Y-m-d') . '</td>';
        $html .= '<td class="cell-actions">';
        $html .= '<a href="/orders/' . $order->getId() . '" class="action-view" title="View">View</a>';
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    public function renderTable(array $orders, array $options = []): string
    {
        $html = '<div class="table-container">';
        $html .= '<table class="data-table order-table"';

        if (!empty($options['sortColumn'])) {
            $html .= ' data-sort-column="' . htmlspecialchars($options['sortColumn']) . '"';
            $html .= ' data-sort-direction="' . htmlspecialchars($options['sortDirection'] ?? 'asc') . '"';
        }

        $html .= '>';
        $html .= $this->renderHeaders();

        $html .= '<tbody>';
        if (empty($orders)) {
            $html .= '<tr><td colspan="6" class="empty-row">No orders found</td></tr>';
        } else {
            foreach ($orders as $order) {
                $html .= $this->renderRow($order);
            }
        }
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        $this->logger->debug('Rendered order table', ['row_count' => count($orders)]);

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
