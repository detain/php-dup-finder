<?php
declare(strict_types=1);

namespace App\Ecommerce\Handlers;

use App\Entity\Checkout;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\PaymentService;
use App\Service\InventoryService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class CheckoutStartedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly PaymentService $paymentService,
        private readonly InventoryService $inventoryService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Checkout $checkout): void
    {
        $this->logger->info('Processing checkout started event', [
            'checkout_id' => $checkout->getId(),
            'customer_id' => $checkout->getCustomerId(),
            'cart_id' => $checkout->getCartId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->reserveInventory($checkout);
            $this->calculateFinalTotals($checkout);
            $this->createCheckoutSession($checkout);
            $this->initializePaymentIntent($checkout);
            $this->recordCheckoutMetrics($checkout);
            $this->sendCheckoutStartedNotification($checkout);
            $this->recordAnalytics($checkout);
            $this->createAuditEntry($checkout);
            $this->startCheckoutTimeoutTimer($checkout);

            $this->entityManager->commit();

            $this->logger->info('Checkout started event processed', [
                'checkout_id' => $checkout->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process checkout started event', [
                'checkout_id' => $checkout->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function reserveInventory(Checkout $checkout): void
    {
        $cart = $this->entityManager
            ->getRepository(\App\Entity\Cart::class)
            ->find($checkout->getCartId());

        if ($cart === null) {
            throw new \RuntimeException('Cart not found');
        }

        foreach ($cart->getItems() as $item) {
            $reservation = $this->inventoryService->reserveQuantity(
                $item->getProductId(),
                $item->getQuantity(),
                $checkout->getId(),
                'checkout'
            );

            if (!$reservation) {
                throw new \DomainException(sprintf(
                    'Unable to reserve %d units of product %d',
                    $item->getQuantity(),
                    $item->getProductId()
                ));
            }

            $this->logger->debug('Reserved inventory', [
                'product_id' => $item->getProductId(),
                'quantity' => $item->getQuantity(),
                'reservation_id' => $reservation->getId(),
            ]);
        }

        $checkout->setInventoryReserved(true);
        $checkout->setReservationExpiresAt(
            (new \DateTimeImmutable())->modify('+15 minutes')
        );
        $this->entityManager->persist($checkout);
    }

    private function calculateFinalTotals(Checkout $checkout): void
    {
        $cart = $this->entityManager
            ->getRepository(\App\Entity\Cart::class)
            ->find($checkout->getCartId());

        if ($cart === null) {
            return;
        }

        $subtotal = 0;
        foreach ($cart->getItems() as $item) {
            $subtotal += $item->getTotalPrice();
        }

        $shippingCost = $this->calculateShippingCost($checkout);
        $taxAmount = $this->calculateTax($subtotal + $shippingCost, $checkout);
        $discountAmount = $this->applyDiscounts($cart, $checkout);
        $total = $subtotal + $shippingCost + $taxAmount - $discountAmount;

        $checkout->setSubtotal($subtotal);
        $checkout->setShippingCost($shippingCost);
        $checkout->setTaxAmount($taxAmount);
        $checkout->setDiscountAmount($discountAmount);
        $checkout->setTotal($total);
        $checkout->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($checkout);

        $this->logger->debug('Calculated final totals', [
            'checkout_id' => $checkout->getId(),
            'subtotal' => $subtotal,
            'total' => $total,
        ]);
    }

    private function createCheckoutSession(Checkout $checkout): void
    {
        $session = new \App\Entity\CheckoutSession();
        $session->setCheckout($checkout);
        $session->setSessionToken(bin2hex(random_bytes(32)));
        $session->setCurrentStep('shipping');
        $session->setStepData(json_encode([
            'shipping_address' => $checkout->getShippingAddress(),
            'billing_address' => $checkout->getBillingAddress(),
            'shipping_method' => $checkout->getShippingMethod(),
        ]));
        $session->setExpiresAt(
            (new \DateTimeImmutable())->modify('+30 minutes')
        );
        $session->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($session);

        $checkout->setSessionToken($session->getSessionToken());
        $this->entityManager->persist($checkout);

        $this->logger->debug('Created checkout session', [
            'checkout_id' => $checkout->getId(),
            'session_token' => substr($session->getSessionToken(), 0, 8) . '...',
        ]);
    }

    private function initializePaymentIntent(Checkout $checkout): void
    {
        $paymentIntent = $this->paymentService->createPaymentIntent([
            'amount' => $checkout->getTotal(),
            'currency' => strtolower($checkout->getCurrency()),
            'customer_id' => $checkout->getCustomerId(),
            'checkout_id' => $checkout->getId(),
            'payment_method_types' => ['card'],
            'metadata' => [
                'cart_id' => $checkout->getCartId(),
                'shipping_method' => $checkout->getShippingMethod(),
            ],
        ]);

        $checkout->setPaymentIntentId($paymentIntent->getId());
        $checkout->setPaymentIntentStatus('requires_payment_method');
        $this->entityManager->persist($checkout);

        $this->logger->debug('Initialized payment intent', [
            'checkout_id' => $checkout->getId(),
            'payment_intent_id' => $paymentIntent->getId(),
        ]);
    }

    private function recordCheckoutMetrics(Checkout $checkout): void
    {
        $cart = $this->entityManager
            ->getRepository(\App\Entity\Cart::class)
            ->find($checkout->getCartId());

        $metrics = new \App\Entity\CheckoutMetrics();
        $metrics->setCheckoutId($checkout->getId());
        $metrics->setCustomerId($checkout->getCustomerId());
        $metrics->setStartedAt(new \DateTimeImmutable());
        $metrics->setTotalValue($checkout->getTotal());
        $metrics->setItemCount($cart ? count($cart->getItems()) : 0);
        $metrics->setShippingMethod($checkout->getShippingMethod());
        $metrics->setPaymentMethod($checkout->getPreferredPaymentMethod());
        $metrics->setHasDiscount($checkout->getDiscountAmount() > 0);
        $metrics->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($metrics);

        $this->queueService->publish('metrics.checkout_started', [
            'checkout_id' => $checkout->getId(),
            'customer_id' => $checkout->getCustomerId(),
            'total_value' => $checkout->getTotal(),
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Recorded checkout metrics', [
            'checkout_id' => $checkout->getId(),
        ]);
    }

    private function sendCheckoutStartedNotification(Checkout $checkout): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($checkout->getCustomerId());

        if ($customer === null) {
            return;
        }

        $cart = $this->entityManager
            ->getRepository(\App\Entity\Cart::class)
            ->find($checkout->getCartId());

        $this->queueService->publish('notifications.checkout', [
            'type' => 'checkout_started',
            'customer_id' => $checkout->getCustomerId(),
            'variables' => [
                'customer_name' => $customer->getFirstName(),
                'item_count' => $cart ? count($cart->getItems()) : 0,
                'total' => number_format($checkout->getTotal() / 100, 2),
                'currency' => $checkout->getCurrency(),
            ],
        ]);

        $this->logger->debug('Sent checkout started notification', [
            'checkout_id' => $checkout->getId(),
        ]);
    }

    private function recordAnalytics(Checkout $checkout): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('checkout_started');
        $analyticsEvent->setCustomerId($checkout->getCustomerId());
        $analyticsEvent->setPayload([
            'checkout_id' => $checkout->getId(),
            'cart_id' => $checkout->getCartId(),
            'total' => $checkout->getTotal(),
            'currency' => $checkout->getCurrency(),
            'shipping_method' => $checkout->getShippingMethod(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded analytics', [
            'checkout_id' => $checkout->getId(),
        ]);
    }

    private function createAuditEntry(Checkout $checkout): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('CHECKOUT_STARTED');
        $auditEntry->setEntityType('checkout');
        $auditEntry->setEntityId($checkout->getId());
        $auditEntry->setUserId($checkout->getCustomerId());
        $auditEntry->setMetadata([
            'cart_id' => $checkout->getCartId(),
            'total' => $checkout->getTotal(),
            'currency' => $checkout->getCurrency(),
            'shipping_method' => $checkout->getShippingMethod(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'checkout_id' => $checkout->getId(),
        ]);
    }

    private function startCheckoutTimeoutTimer(Checkout $checkout): void
    {
        $timeout = new \App\Entity\CheckoutTimeout();
        $timeout->setCheckout($checkout);
        $timeout->setExpiresAt(
            (new \DateTimeImmutable())->modify('+30 minutes')
        );
        $timeout->setTimerType('checkout_expiration');
        $timeout->setStatus('active');
        $timeout->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($timeout);

        $this->queueService->publish('checkout.timeout_scheduled', [
            'checkout_id' => $checkout->getId(),
            'expires_at' => $timeout->getExpiresAt()->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Started checkout timeout timer', [
            'checkout_id' => $checkout->getId(),
        ]);
    }

    private function calculateShippingCost(Checkout $checkout): int
    {
        return match ($checkout->getShippingMethod()) {
            'express' => 1499,
            'priority' => 999,
            default => 599,
        };
    }

    private function calculateTax(int $subtotal, Checkout $checkout): int
    {
        $taxRate = $this->entityManager
            ->getRepository(\App\Entity\TaxRate::class)
            ->findByRegion($checkout->getShippingAddress()?->getState());

        $rate = $taxRate?->getRate() ?? 0.0825;

        return (int) round($subtotal * $rate);
    }

    private function applyDiscounts(\App\Entity\Cart $cart, Checkout $checkout): int
    {
        $discount = 0;

        if ($cart->getCouponCode() !== null) {
            $coupon = $this->entityManager
                ->getRepository(\App\Entity\Coupon::class)
                ->findOneBy(['code' => $cart->getCouponCode()]);

            if ($coupon?->isValid()) {
                $discount = $coupon->calculateDiscount($cart->getSubtotal());
            }
        }

        return $discount;
    }
}
