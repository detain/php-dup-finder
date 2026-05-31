<?php

declare(strict_types=1);

namespace App\Session;

class ShoppingCartSessionManager
{
    private SessionStore $session;

    public function __construct(SessionStore $session)
    {
        $this->session = $session;
    }

    public function saveCartState(ShoppingCart $cart): void
    {
        $state = [
            'id' => $cart->getId(),
            'user_id' => $cart->getUserId(),
            'items' => $this->serializeItems($cart->getItems()),
            'total_amount' => $cart->getTotalAmount(),
            'currency' => $cart->getCurrency(),
            'is_active' => $cart->isActive(),
            'created_at' => $cart->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $cart->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'saved_at' => time()
        ];

        $this->session->set('cart_state', $state);
        $this->session->set('cart_id', $cart->getId());
        $this->session->set('cart_item_count', count($cart->getItems()));
    }

    public function restoreCartState(): ?array
    {
        $state = $this->session->get('cart_state');

        if ($state === null) {
            return null;
        }

        $state['created_at'] = new \DateTimeImmutable($state['created_at']);

        if ($state['updated_at'] !== null) {
            $state['updated_at'] = new \DateTimeImmutable($state['updated_at']);
        }

        $state['items'] = $this->deserializeItems($state['items']);

        unset($state['saved_at']);

        return $state;
    }

    public function clearCartState(): void
    {
        $this->session->remove('cart_state');
        $this->session->remove('cart_id');
        $this->session->remove('cart_item_count');
    }

    public function hasCartState(): bool
    {
        return $this->session->has('cart_state');
    }

    public function getCartId(): ?string
    {
        return $this->session->get('cart_id');
    }

    public function getCartItemCount(): int
    {
        return (int)$this->session->get('cart_item_count', 0);
    }

    public function refreshCartState(ShoppingCart $cart): void
    {
        $this->saveCartState($cart);
    }

    public function getCartStateTimestamp(): ?int
    {
        $state = $this->session->get('cart_state');

        if ($state === null || !isset($state['saved_at'])) {
            return null;
        }

        return $state['saved_at'];
    }

    private function serializeItems(array $items): array
    {
        return array_map(fn($item) => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price']
        ], $items);
    }

    private function deserializeItems(array $items): array
    {
        return array_map(fn($item) => [
            'product_id' => $item['product_id'],
            'quantity' => (int)$item['quantity'],
            'unit_price' => (float)$item['unit_price']
        ], $items);
    }
}
