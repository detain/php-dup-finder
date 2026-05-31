<?php
declare(strict_types=1);

namespace Storefront\Checkout;

use Experimentation\FlagClient;
use Experimentation\ExposureTracker;
use Psr\Log\LoggerInterface;

final class CheckoutPageController
{
    public function __construct(
        private FlagClient $flags,
        private ExposureTracker $tracker,
        private CartService $cart,
        private OrderService $orders,
        private LoggerInterface $log,
    ) {}

    public function placeOrder(int $userId, string $cartId): array
    {
        $variant = $this->flags->evaluate('checkout.new_pipeline', $userId, ['default' => 'control']);
        try {
            if ($variant === 'treatment') {
                $cart = $this->cart->loadOptimized($cartId);
                $order = $this->orders->createFromOptimizedCart($userId, $cart);
                $this->log->info('checkout.treatment.ok', ['user' => $userId, 'order' => $order['id']]);
                return $order;
            }
            $cart = $this->cart->load($cartId);
            $order = $this->orders->createFromCart($userId, $cart);
            $this->log->info('checkout.control.ok', ['user' => $userId, 'order' => $order['id']]);
            return $order;
        } finally {
            $this->tracker->record('checkout.new_pipeline', [
                'user_id' => $userId,
                'variant' => $variant,
                'at'      => date(DATE_ATOM),
            ]);
        }
    }
}
