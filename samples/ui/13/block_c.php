<?php

declare(strict_types=1);

namespace App\View\Card;

use App\Entity\Order;
use Psr\Log\LoggerInterface;

final class OrderCardRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderCard(Order $order, array $options = []): string
    {
        $showItems = $options['showItems'] ?? true;
        $showActions = $options['showActions'] ?? true;
        $compact = $options['compact'] ?? false;

        $statusClass = match ($order->getStatus()) {
            'completed' => 'order-status-completed',
            'processing' => 'order-status-processing',
            'shipped' => 'order-status-shipped',
            'cancelled' => 'order-status-cancelled',
            'pending' => 'order-status-pending',
            default => '',
        };

        $html = '<article class="order-card';
        if ($compact) {
            $html .= ' card-compact';
        }
        $html .= '" data-order-id="' . $order->getId() . '">';

        $html .= '<div class="card-header">';
        $html .= '<div class="order-number">#' . htmlspecialchars($order->getOrderNumber()) . '</div>';
        $html .= '<span class="order-status-badge ' . $statusClass . '">' . ucfirst($order->getStatus()) . '</span>';
        $html .= '</div>';

        $html .= '<div class="card-content">';
        $html .= '<div class="card-info-row">';
        $html .= '<span class="info-label">Customer:</span>';
        $html .= '<span class="info-value">' . htmlspecialchars($order->getCustomerName()) . '</span>';
        $html .= '</div>';

        $html .= '<div class="card-info-row">';
        $html .= '<span class="info-label">Date:</span>';
        $html .= '<span class="info-value">' . $order->getCreatedAt()->format('M d, Y') . '</span>';
        $html .= '</div>';

        if (!$compact) {
            $html .= '<div class="card-info-row">';
            $html .= '<span class="info-label">Items:</span>';
            $html .= '<span class="info-value">' . $order->getItemCount() . ' item(s)</span>';
            $html .= '</div>';
        }

        $html .= '<div class="card-total">';
        $html .= '<span class="total-label">Total:</span>';
        $html .= '<span class="total-amount">$' . number_format($order->getTotalAmount(), 2) . '</span>';
        $html .= '</div>';

        if ($showItems && !$compact) {
            $html .= '<div class="order-items-preview">';
            $items = $order->getItems();
            $previewItems = array_slice($items, 0, 3);
            foreach ($previewItems as $item) {
                $html .= '<div class="item-preview">';
                $html .= '<span class="item-qty">' . $item->getQuantity() . 'x</span>';
                $html .= '<span class="item-name">' . htmlspecialchars($item->getName()) . '</span>';
                $html .= '</div>';
            }
            if (count($items) > 3) {
                $html .= '<div class="items-more">+' . (count($items) - 3) . ' more items</div>';
            }
            $html .= '</div>';
        }

        if ($showActions) {
            $html .= '<div class="card-actions">';
            $html .= '<a href="/orders/' . $order->getId() . '" class="btn-view">View Order</a>';
            if ($order->getStatus() === 'completed') {
                $html .= '<a href="/orders/' . $order->getId() . '/reorder" class="btn-reorder">Reorder</a>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</article>';

        $this->logger->debug('Rendered order card', ['order_id' => $order->getId()]);

        return $html;
    }

    public function renderCardGrid(array $orders, array $options = []): string
    {
        $columns = $options['columns'] ?? 2;

        $html = '<div class="card-grid grid-cols-' . $columns . '">';

        if (empty($orders)) {
            $html .= '<div class="empty-state">No orders to display</div>';
        } else {
            foreach ($orders as $order) {
                $html .= $this->renderCard($order, $options);
            }
        }

        $html .= '</div>';

        return $html;
    }
}
