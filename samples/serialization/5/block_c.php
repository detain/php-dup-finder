<?php

declare(strict_types=1);

namespace App\Session;

class CheckoutSessionManager
{
    private SessionStore $session;

    public function __construct(SessionStore $session)
    {
        $this->session = $session;
    }

    public function saveCheckoutState(Checkout $checkout): void
    {
        $state = [
            'id' => $checkout->getId(),
            'cart_id' => $checkout->getCartId(),
            'user_id' => $checkout->getUserId(),
            'shipping_address' => $checkout->getShippingAddress(),
            'billing_address' => $checkout->getBillingAddress(),
            'payment_method' => $checkout->getPaymentMethod(),
            'status' => $checkout->getStatus(),
            'created_at' => $checkout->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $checkout->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'saved_at' => time()
        ];

        $this->session->set('checkout_state', $state);
        $this->session->set('checkout_id', $checkout->getId());
        $this->session->set('checkout_status', $checkout->getStatus());
    }

    public function restoreCheckoutState(): ?array
    {
        $state = $this->session->get('checkout_state');

        if ($state === null) {
            return null;
        }

        $state['created_at'] = new \DateTimeImmutable($state['created_at']);

        if ($state['updated_at'] !== null) {
            $state['updated_at'] = new \DateTimeImmutable($state['updated_at']);
        }

        unset($state['saved_at']);

        return $state;
    }

    public function clearCheckoutState(): void
    {
        $this->session->remove('checkout_state');
        $this->session->remove('checkout_id');
        $this->session->remove('checkout_status');
    }

    public function hasCheckoutState(): bool
    {
        return $this->session->has('checkout_state');
    }

    public function getCheckoutId(): ?string
    {
        return $this->session->get('checkout_id');
    }

    public function getCheckoutStatus(): ?string
    {
        return $this->session->get('checkout_status');
    }

    public function refreshCheckoutState(Checkout $checkout): void
    {
        $this->saveCheckoutState($checkout);
    }

    public function getCheckoutStateTimestamp(): ?int
    {
        $state = $this->session->get('checkout_state');

        if ($state === null || !isset($state['saved_at'])) {
            return null;
        }

        return $state['saved_at'];
    }
}
