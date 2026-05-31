<?php
declare(strict_types=1);

namespace Storefront;

use Experimentation\FlagClient;
use Experimentation\ExposureTracker;

final class FlagGate
{
    public function __construct(private FlagClient $flags, private ExposureTracker $tracker) {}

    /**
     * @template T
     * @param array<string,callable():T> $variants  variant name => handler
     * @return T
     */
    public function withFlag(string $flag, int $userId, array $variants)
    {
        $variant = $this->flags->evaluate($flag, $userId, ['default' => 'control']);
        try {
            $handler = $variants[$variant] ?? $variants['control'];
            return $handler();
        } finally {
            $this->tracker->record($flag, [
                'user_id' => $userId,
                'variant' => $variant,
                'at'      => date(DATE_ATOM),
            ]);
        }
    }
}

final class CheckoutPageController
{
    public function __construct(private FlagGate $gate, private CartService $cart, private OrderService $orders) {}

    public function placeOrder(int $userId, string $cartId): array
    {
        return $this->gate->withFlag('checkout.new_pipeline', $userId, [
            'treatment' => fn() => $this->orders->createFromOptimizedCart($userId, $this->cart->loadOptimized($cartId)),
            'control'   => fn() => $this->orders->createFromCart($userId, $this->cart->load($cartId)),
        ]);
    }
}
