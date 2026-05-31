<?php
declare(strict_types=1);

namespace App\Ecommerce\Handlers;

use App\Entity\Cart;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\PriceService;
use App\Service\InventoryService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class CartAbandonedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly PriceService $priceService,
        private readonly InventoryService $inventoryService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Cart $cart): void
    {
        $this->logger->info('Processing cart abandoned event', [
            'cart_id' => $cart->getId(),
            'customer_id' => $cart->getCustomerId(),
            'total_value' => $cart->getTotal()->getAmount(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->captureCartSnapshot($cart);
            $this->recordAbandonmentMetrics($cart);
            $this->checkInventoryAvailability($cart);
            $this->storeAbandonedCartItems($cart);
            $this->calculatePotentialRevenue($cart);
            $this->sendRecoveryEmail($cart);
            $this->recordAnalytics($cart);
            $this->createAuditEntry($cart);
            $this->triggerRecoveryWorkflow($cart);

            $this->entityManager->commit();

            $this->logger->info('Cart abandoned event processed', [
                'cart_id' => $cart->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process cart abandoned event', [
                'cart_id' => $cart->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function captureCartSnapshot(Cart $cart): void
    {
        $snapshot = new \App\Entity\CartSnapshot();
        $snapshot->setCartId($cart->getId());
        $snapshot->setCustomerId($cart->getCustomerId());
        $snapshot->setItems(json_encode($this->serializeCartItems($cart)));
        $snapshot->setSubtotal($cart->getSubtotal());
        $snapshot->setTax($cart->getTax());
        $snapshot->setTotal($cart->getTotal());
        $snapshot->setCurrency($cart->getCurrency());
        $snapshot->setItemsCount(count($cart->getItems()));
        $snapshot->setCapturedAt(new \DateTimeImmutable());
        $snapshot->setAbandonedAt($cart->getUpdatedAt());

        $this->entityManager->persist($snapshot);

        $this->logger->debug('Captured cart snapshot', [
            'cart_id' => $cart->getId(),
            'snapshot_id' => $snapshot->getId(),
        ]);
    }

    private function recordAbandonmentMetrics(Cart $cart): void
    {
        $metrics = new \App\Entity\CartMetrics();
        $metrics->setCartId($cart->getId());
        $metrics->setCustomerId($cart->getCustomerId());
        $metrics->setAbandonedAt(new \DateTimeImmutable());
        $metrics->setTotalValue($cart->getTotal()->getAmount());
        $metrics->setItemCount(count($cart->getItems()));
        $metrics->setItemsValue($this->calculateItemsValue($cart));
        $metrics->setPotentialDiscount($cart->getDiscountTotal());
        $metrics->setShippingMethod($cart->getShippingMethod());
        $metrics->setPaymentMethod($cart->getPaymentMethod());
        $metrics->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($metrics);

        $this->queueService->publish('metrics.cart_abandonment', [
            'cart_id' => $cart->getId(),
            'customer_id' => $cart->getCustomerId(),
            'total_value' => $cart->getTotal()->getAmount(),
            'item_count' => count($cart->getItems()),
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Recorded abandonment metrics', [
            'cart_id' => $cart->getId(),
        ]);
    }

    private function checkInventoryAvailability(Cart $cart): void
    {
        $unavailableItems = [];

        foreach ($cart->getItems() as $item) {
            $availableQty = $this->inventoryService->getAvailableQuantity($item->getProductId());

            if ($availableQty < $item->getQuantity()) {
                $unavailableItems[] = [
                    'product_id' => $item->getProductId(),
                    'requested' => $item->getQuantity(),
                    'available' => $availableQty,
                ];

                $item->setAvailabilityStatus('insufficient_stock');
                $item->setAvailableQuantity($availableQty);
                $this->entityManager->persist($item);
            } else {
                $item->setAvailabilityStatus('in_stock');
                $item->setAvailableQuantity($availableQty);
                $this->entityManager->persist($item);
            }
        }

        if (!empty($unavailableItems)) {
            $this->queueService->publish('cart.inventory_alert', [
                'cart_id' => $cart->getId(),
                'unavailable_items' => $unavailableItems,
                'priority' => 'normal',
            ]);
        }

        $this->logger->debug('Checked inventory availability', [
            'cart_id' => $cart->getId(),
            'unavailable_count' => count($unavailableItems),
        ]);
    }

    private function storeAbandonedCartItems(Cart $cart): void
    {
        foreach ($cart->getItems() as $item) {
            $abandonedItem = new \App\Entity\AbandonedCartItem();
            $abandonedItem->setCartId($cart->getId());
            $abandonedItem->setCustomerId($cart->getCustomerId());
            $abandonedItem->setProductId($item->getProductId());
            $abandonedItem->setQuantity($item->getQuantity());
            $abandonedItem->setUnitPrice($item->getUnitPrice());
            $abandonedItem->setTotalPrice($item->getTotalPrice());
            $abandonedItem->setAbandonedAt(new \DateTimeImmutable());
            $abandonedItem->setLastPriceKnown($this->priceService->getCurrentPrice($item->getProductId()));

            $this->entityManager->persist($abandonedItem);
        }

        $this->logger->debug('Stored abandoned cart items', [
            'cart_id' => $cart->getId(),
            'item_count' => count($cart->getItems()),
        ]);
    }

    private function calculatePotentialRevenue(Cart $cart): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($cart->getCustomerId());

        if ($customer === null) {
            return;
        }

        $customer->setLifetimeCartValue(
            $customer->getLifetimeCartValue() + $cart->getTotal()->getAmount()
        );
        $customer->setAbandonedCartCount($customer->getAbandonedCartCount() + 1);
        $customer->setLastCartAbandonedAt(new \DateTimeImmutable());

        $totalAbandonedValue = $this->entityManager
            ->getRepository(\App\Entity\CartMetrics::class)
            ->getTotalAbandonedValue($customer->getId());

        $customer->setTotalAbandonedValue($totalAbandonedValue);

        $this->entityManager->persist($customer);

        $this->logger->debug('Calculated potential revenue', [
            'customer_id' => $customer->getId(),
            'cart_total' => $cart->getTotal()->getAmount(),
        ]);
    }

    private function sendRecoveryEmail(Cart $cart): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($cart->getCustomerId());

        if ($customer === null || !$customer->getEmailMarketingConsent()) {
            return;
        }

        $emailLog = new \App\Entity\AbandonedCartEmailLog();
        $emailLog->setCartId($cart->getId());
        $emailLog->setCustomerId($cart->getCustomerId());
        $emailLog->setEmailType('abandonment_1');
        $emailLog->setSentAt(new \DateTimeImmutable());
        $emailLog->setStatus('sent');

        $this->entityManager->persist($emailLog);

        $this->queueService->publish('email.abandoned_cart', [
            'template' => 'cart_abandonment_1',
            'recipient' => $customer->getEmail(),
            'variables' => [
                'customer_name' => $customer->getFirstName(),
                'cart_total' => number_format($cart->getTotal()->getAmount() / 100, 2),
                'currency' => $cart->getCurrency(),
                'item_count' => count($cart->getItems()),
                'recovery_url' => '/cart/recover/' . $cart->getRecoveryToken(),
                'cart_id' => $cart->getId(),
            ],
            'priority' => 'normal',
        ]);

        $this->logger->debug('Sent recovery email', [
            'cart_id' => $cart->getId(),
            'customer_email' => $customer->getEmail(),
        ]);
    }

    private function recordAnalytics(Cart $cart): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('cart_abandoned');
        $analyticsEvent->setCustomerId($cart->getCustomerId());
        $analyticsEvent->setPayload([
            'cart_id' => $cart->getId(),
            'total_value' => $cart->getTotal()->getAmount(),
            'item_count' => count($cart->getItems()),
            'currency' => $cart->getCurrency(),
            'steps_completed' => $cart->getCheckoutStep(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded analytics', [
            'cart_id' => $cart->getId(),
        ]);
    }

    private function createAuditEntry(Cart $cart): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('CART_ABANDONED');
        $auditEntry->setEntityType('cart');
        $auditEntry->setEntityId($cart->getId());
        $auditEntry->setUserId($cart->getCustomerId());
        $auditEntry->setMetadata([
            'total_value' => $cart->getTotal()->getAmount(),
            'item_count' => count($cart->getItems()),
            'last_activity' => $cart->getUpdatedAt()->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'cart_id' => $cart->getId(),
        ]);
    }

    private function triggerRecoveryWorkflow(Cart $cart): void
    {
        $workflow = new \App\Entity\RecoveryWorkflow();
        $workflow->setCartId($cart->getId());
        $workflow->setCustomerId($cart->getCustomerId());
        $workflow->setStatus('active');
        $workflow->setCurrentStep(1);
        $workflow->setTotalValue($cart->getTotal()->getAmount());
        $workflow->setCreatedAt(new \DateTimeImmutable());

        $emailSteps = [
            ['step' => 1, 'delay_hours' => 1, 'template' => 'cart_abandonment_1'],
            ['step' => 2, 'delay_hours' => 24, 'template' => 'cart_abandonment_2'],
            ['step' => 3, 'delay_hours' => 72, 'template' => 'cart_abandonment_3'],
        ];

        foreach ($emailSteps as $stepConfig) {
            $step = new \App\Entity\RecoveryWorkflowStep();
            $step->setWorkflow($workflow);
            $step->setStepNumber($stepConfig['step']);
            $step->setStepType('email');
            $step->setTemplate($stepConfig['template']);
            $step->setScheduledFor(
                (new \DateTimeImmutable())->modify("+{$stepConfig['delay_hours']} hours")
            );
            $step->setStatus('pending');
            $step->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($step);
        }

        $this->entityManager->persist($workflow);

        $this->logger->debug('Triggered recovery workflow', [
            'cart_id' => $cart->getId(),
            'workflow_id' => $workflow->getId(),
        ]);
    }

    private function serializeCartItems(Cart $cart): array
    {
        $items = [];
        foreach ($cart->getItems() as $item) {
            $items[] = [
                'product_id' => $item->getProductId(),
                'name' => $item->getProductName(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'total' => $item->getTotalPrice(),
            ];
        }
        return $items;
    }

    private function calculateItemsValue(Cart $cart): int
    {
        $total = 0;
        foreach ($cart->getItems() as $item) {
            $total += $item->getTotalPrice();
        }
        return $total;
    }
}
