<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedCardRenderer
{
    /** @var array<string, CardRendererInterface> */
    private array $renderers = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->registerRenderers();
    }

    private function registerRenderers(): void
    {
        $this->renderers['user'] = new UserCardComponent();
        $this->renderers['product'] = new ProductCardComponent();
        $this->renderers['order'] = new OrderCardComponent();
    }

    public function renderCard(string $entityType, object $entity, array $options = []): string
    {
        $renderer = $this->renderers[$entityType] ?? null;

        if ($renderer === null) {
            $this->logger->warning('Unknown card entity type', ['type' => $entityType]);
            return '<div class="card-error">Unknown entity type: ' . htmlspecialchars($entityType) . '</div>';
        }

        $this->logger->debug("Rendered {$entityType} card", ['id' => $entity->getId()]);

        return $renderer->render($entity, $options);
    }

    public function renderCardGrid(string $entityType, array $entities, array $options = []): string
    {
        $columns = $options['columns'] ?? 3;

        $html = '<div class="card-grid grid-cols-' . $columns . '">';

        if (empty($entities)) {
            $html .= '<div class="empty-state">No ' . htmlspecialchars($entityType) . 's to display</div>';
        } else {
            foreach ($entities as $entity) {
                $html .= $this->renderCard($entityType, $entity, $options);
            }
        }

        $html .= '</div>';

        return $html;
    }
}

interface CardRendererInterface
{
    public function render(object $entity, array $options = []): string;
}

final class UserCardComponent implements CardRendererInterface
{
    public function render(object $entity, array $options = []): string
    {
        /** @var \App\Entity\User $user */
        $user = $entity;
        $showAvatar = $options['showAvatar'] ?? true;
        $showActions = $options['showActions'] ?? true;
        $compact = $options['compact'] ?? false;

        $statusClass = $user->getStatus() === 'active' ? 'user-status-active' : 'user-status-inactive';

        $html = '<article class="user-card' . ($compact ? ' card-compact' : '') . '" data-user-id="' . $user->getId() . '">';

        if ($showAvatar) {
            $html .= '<div class="card-avatar">';
            $html .= '<img src="https://ui-avatars.com/api/?name=' . urlencode($user->getFullName()) . '" alt="" class="avatar-image" />';
            $html .= '<span class="status-indicator ' . $statusClass . '"></span>';
            $html .= '</div>';
        }

        $html .= '<div class="card-content">';
        $html .= '<h3 class="card-title">' . htmlspecialchars($user->getFullName()) . '</h3>';
        $html .= '<p class="card-subtitle">' . htmlspecialchars($user->getEmail()) . '</p>';

        if (!$compact) {
            $html .= '<div class="card-meta">';
            $html .= '<span class="meta-item">Joined: ' . $user->getCreatedAt()->format('M Y') . '</span>';
            $html .= '</div>';
        }

        if ($showActions) {
            $html .= '<div class="card-actions">';
            $html .= '<a href="/users/' . $user->getId() . '" class="btn-view">View Profile</a>';
            $html .= '</div>';
        }

        $html .= '</div></article>';

        return $html;
    }
}

final class ProductCardComponent implements CardRendererInterface
{
    public function render(object $entity, array $options = []): string
    {
        /** @var \App\Entity\Product $product */
        $product = $entity;
        $showActions = $options['showActions'] ?? true;

        $html = '<article class="product-card" data-product-id="' . $product->getId() . '">';
        $html .= '<img src="' . htmlspecialchars($product->getImageUrl() ?? '') . '" alt="" class="card-image" loading="lazy" />';
        $html .= '<div class="card-content">';
        $html .= '<span class="card-category">' . htmlspecialchars($product->getCategory()) . '</span>';
        $html .= '<h3 class="card-title">' . htmlspecialchars($product->getName()) . '</h3>';
        $html .= '<div class="card-pricing">';
        $html .= '<span class="price-current">$' . number_format($product->getPrice(), 2) . '</span>';
        $html .= '</div>';

        if ($showActions) {
            $html .= '<div class="card-actions">';
            $html .= '<a href="/products/' . $product->getId() . '" class="btn-view">View</a>';
            $html .= '</div>';
        }

        $html .= '</div></article>';

        return $html;
    }
}

final class OrderCardComponent implements CardRendererInterface
{
    public function render(object $entity, array $options = []): string
    {
        /** @var \App\Entity\Order $order */
        $order = $entity;
        $showActions = $options['showActions'] ?? true;

        $statusClass = 'order-status-' . $order->getStatus();

        $html = '<article class="order-card" data-order-id="' . $order->getId() . '">';
        $html .= '<div class="card-header">';
        $html .= '<span class="order-number">#' . htmlspecialchars($order->getOrderNumber()) . '</span>';
        $html .= '<span class="order-status-badge ' . $statusClass . '">' . ucfirst($order->getStatus()) . '</span>';
        $html .= '</div>';
        $html .= '<div class="card-content">';
        $html .= '<div class="card-info-row">';
        $html .= '<span class="info-label">Customer:</span>';
        $html .= '<span class="info-value">' . htmlspecialchars($order->getCustomerName()) . '</span>';
        $html .= '</div>';
        $html .= '<div class="card-total">';
        $html .= '<span class="total-amount">$' . number_format($order->getTotalAmount(), 2) . '</span>';
        $html .= '</div>';

        if ($showActions) {
            $html .= '<div class="card-actions">';
            $html .= '<a href="/orders/' . $order->getId() . '" class="btn-view">View Order</a>';
            $html .= '</div>';
        }

        $html .= '</div></article>';

        return $html;
    }
}
