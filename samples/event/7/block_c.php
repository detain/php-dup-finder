<?php
declare(strict_types=1);

namespace App\Ecommerce\Handlers;

use App\Entity\Order;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\PaymentService;
use App\Service\InventoryService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OrderCompletedEventHandler
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

    public function handle(Order $order): void
    {
        $this->logger->info('Processing order completed event', [
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'total' => $order->getTotal()->getAmount(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->confirmPayment($order);
            $this->convertReservedToSold($order);
            $this->createFulfillmentOrder($order);
            $this->updateCustomerOrderStats($order);
            $this->sendOrderConfirmation($order);
            $this->recordOrderAnalytics($order);
            $this->createAuditEntry($order);
            $this->startPostPurchaseWorkflows($order);
            $this->issueloyaltyPoints($order);

            $this->entityManager->commit();

            $this->logger->info('Order completed event processed', [
                'order_id' => $order->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process order completed event', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function confirmPayment(Order $order): void
    {
        $paymentIntent = $this->paymentService->confirmPaymentIntent(
            $order->getPaymentIntentId()
        );

        if (!$paymentIntent->isSuccessful()) {
            throw new \DomainException('Payment confirmation failed');
        }

        $order->setPaymentStatus('paid');
        $order->setPaidAt(new \DateTimeImmutable());
        $order->setStatus('confirmed');

        $this->entityManager->persist($order);

        $this->logger->debug('Confirmed payment', [
            'order_id' => $order->getId(),
            'payment_intent_id' => $order->getPaymentIntentId(),
        ]);
    }

    private function convertReservedToSold(Order $order): void
    {
        $reservations = $this->entityManager
            ->getRepository(\App\Entity\InventoryReservation::class)
            ->findByReference($order->getId(), 'checkout');

        foreach ($reservations as $reservation) {
            $reservation->setStatus('converted');
            $reservation->setConvertedAt(new \DateTimeImmutable());
            $reservation->setOrderId($order->getId());

            $inventory = $this->entityManager
                ->getRepository(\App\Entity\Inventory::class)
                ->findOneBy(['productId' => $reservation->getProductId()]);

            if ($inventory !== null) {
                $inventory->setReservedQuantity(
                    max(0, $inventory->getReservedQuantity() - $reservation->getQuantity())
                );
                $inventory->setSoldQuantity(
                    $inventory->getSoldQuantity() + $reservation->getQuantity()
                );
                $inventory->setLastUpdated(new \DateTimeImmutable());

                $this->entityManager->persist($inventory);
            }

            $this->entityManager->persist($reservation);
        }

        $this->logger->debug('Converted reserved inventory to sold', [
            'order_id' => $order->getId(),
            'reservation_count' => count($reservations),
        ]);
    }

    private function createFulfillmentOrder(Order $order): void
    {
        $fulfillmentOrder = new \App\Entity\FulfillmentOrder();
        $fulfillmentOrder->setOrder($order);
        $fulfillmentOrder->setStatus('pending');
        $fulfillmentOrder->setPriority($this->calculateFulfillmentPriority($order));
        $fulfillmentOrder->setShippingMethod($order->getShippingMethod());
        $fulfillmentOrder->setShippingAddress($order->getShippingAddress());
        $fulfillmentOrder->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($fulfillmentOrder);

        $this->queueService->publish('fulfillment.order_created', [
            'fulfillment_order_id' => $fulfillmentOrder->getId(),
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'priority' => $fulfillmentOrder->getPriority(),
        ]);

        $this->logger->debug('Created fulfillment order', [
            'order_id' => $order->getId(),
            'fulfillment_order_id' => $fulfillmentOrder->getId(),
        ]);
    }

    private function updateCustomerOrderStats(Order $order): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($order->getCustomerId());

        if ($customer === null) {
            return;
        }

        $customer->setTotalOrders($customer->getTotalOrders() + 1);
        $customer->setTotalSpent($customer->getTotalSpent() + $order->getTotal()->getAmount());
        $customer->setAverageOrderValue(
            $customer->getTotalSpent() / $customer->getTotalOrders()
        );
        $customer->setLastOrderAt(new \DateTimeImmutable());
        $customer->setLastOrderId($order->getId());

        if ($customer->getFirstOrderAt() === null) {
            $customer->setFirstOrderAt(new \DateTimeImmutable());
            $customer->setFirstOrderId($order->getId());
        }

        $this->entityManager->persist($customer);

        $this->logger->debug('Updated customer order stats', [
            'customer_id' => $customer->getId(),
            'total_orders' => $customer->getTotalOrders(),
        ]);
    }

    private function sendOrderConfirmation(Order $order): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($order->getCustomerId());

        if ($customer === null) {
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'order_confirmation']);

        if ($template !== null) {
            $this->queueService->publish('email.outbound', [
                'template_id' => $template->getId(),
                'recipient' => $customer->getEmail(),
                'variables' => [
                    'customer_name' => $customer->getFirstName(),
                    'order_number' => $order->getOrderNumber(),
                    'order_total' => number_format($order->getTotal()->getAmount() / 100, 2),
                    'currency' => $order->getTotal()->getCurrency(),
                    'item_count' => count($order->getItems()),
                    'estimated_delivery' => $this->calculateDeliveryDate($order),
                    'tracking_url' => '/orders/' . $order->getId() . '/track',
                ],
                'priority' => 'high',
            ]);
        }

        $this->logger->debug('Sent order confirmation', [
            'order_id' => $order->getId(),
            'customer_email' => $customer->getEmail(),
        ]);
    }

    private function recordOrderAnalytics(Order $order): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('order_completed');
        $analyticsEvent->setCustomerId($order->getCustomerId());
        $analyticsEvent->setPayload([
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'total' => $order->getTotal()->getAmount(),
            'currency' => $order->getTotal()->getCurrency(),
            'item_count' => count($order->getItems()),
            'shipping_method' => $order->getShippingMethod(),
            'payment_method' => $order->getPaymentMethod(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->queueService->publish('analytics.order_completed', [
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'total' => $order->getTotal()->getAmount(),
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Recorded order analytics', [
            'order_id' => $order->getId(),
        ]);
    }

    private function createAuditEntry(Order $order): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('ORDER_COMPLETED');
        $auditEntry->setEntityType('order');
        $auditEntry->setEntityId($order->getId());
        $auditEntry->setUserId($order->getCustomerId());
        $auditEntry->setMetadata([
            'order_number' => $order->getOrderNumber(),
            'total' => $order->getTotal()->getAmount(),
            'currency' => $order->getTotal()->getCurrency(),
            'item_count' => count($order->getItems()),
            'shipping_method' => $order->getShippingMethod(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'order_id' => $order->getId(),
        ]);
    }

    private function startPostPurchaseWorkflows(Order $order): void
    {
        $workflows = $this->entityManager
            ->getRepository(\App\Entity\PostPurchaseWorkflow::class)
            ->findActiveByTrigger('order_completed');

        foreach ($workflows as $workflow) {
            $enrollment = new \App\Entity\WorkflowEnrollment();
            $enrollment->setWorkflow($workflow);
            $enrollment->setOrder($order);
            $enrollment->setCustomerId($order->getCustomerId());
            $enrollment->setStatus('active');
            $enrollment->setCurrentStep(1);
            $enrollment->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($enrollment);

            $this->queueService->publish('workflow.enrolled', [
                'workflow_id' => $workflow->getId(),
                'order_id' => $order->getId(),
                'customer_id' => $order->getCustomerId(),
            ]);
        }

        $this->logger->debug('Started post-purchase workflows', [
            'order_id' => $order->getId(),
            'workflow_count' => count($workflows),
        ]);
    }

    private function issueLoyaltyPoints(Order $order): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($order->getCustomerId());

        if ($customer === null) {
            return;
        }

        $pointsPerDollar = $this->entityManager
            ->getRepository(\App\Entity\SystemSetting::class)
            ->findOneBy(['key' => 'loyalty_points_per_dollar'])?->getValue() ?? 1;

        $points = (int) floor($order->getTotal()->getAmount() / 100 * $pointsPerDollar);

        if ($points <= 0) {
            return;
        }

        $pointsTransaction = new \App\Entity\LoyaltyPointsTransaction();
        $pointsTransaction->setCustomer($customer);
        $pointsTransaction->setType('order_reward');
        $pointsTransaction->setPoints($points);
        $pointsTransaction->setReferenceType('order');
        $pointsTransaction->setReferenceId($order->getId());
        $pointsTransaction->setDescription('Points earned from order ' . $order->getOrderNumber());
        $pointsTransaction->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($pointsTransaction);

        $customer->setLoyaltyPoints($customer->getLoyaltyPoints() + $points);
        $customer->setLifetimePoints($customer->getLifetimePoints() + $points);
        $this->entityManager->persist($customer);

        $this->logger->debug('Issued loyalty points', [
            'order_id' => $order->getId(),
            'points_issued' => $points,
        ]);
    }

    private function calculateFulfillmentPriority(Order $order): string
    {
        if ($order->getShippingMethod() === 'express') {
            return 'high';
        }

        if ($order->getTotal()->getAmount() > 50000) {
            return 'high';
        }

        return 'normal';
    }

    private function calculateDeliveryDate(Order $order): string
    {
        $days = match ($order->getShippingMethod()) {
            'express' => 2,
            'priority' => 5,
            default => 7,
        };

        return (new \DateTimeImmutable())->modify("+{$days} days")->format('Y-m-d');
    }
}
