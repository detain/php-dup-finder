<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\OrderCreatedEvent;
use App\Event\OrderPaidEvent;
use App\Event\OrderShippedEvent;
use App\Event\OrderDeliveredEvent;
use App\Service\NotificationService;
use App\Service\InventoryService;
use App\Service\AnalyticsService;
use Psr\Log\LoggerInterface;

final class OrderEventSubscriber
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly InventoryService $inventoryService,
        private readonly AnalyticsService $analyticsService,
        private readonly LoggerInterface $logger,
    ) {}

    public function onOrderCreated(OrderCreatedEvent $event): void
    {
        $order = $event->getOrder();

        $this->notificationService->sendOrderConfirmation($order);
        $this->inventoryService->reserveStockForOrder($order);

        $this->analyticsService->trackEvent('order_created', [
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'total' => $order->getTotal(),
        ]);

        $this->logger->info('Order created event processed', [
            'order_id' => $order->getId(),
        ]);
    }

    public function onOrderPaid(OrderPaidEvent $event): void
    {
        $order = $event->getOrder();

        $this->inventoryService->confirmStockReservation($order);
        $this->notificationService->sendPaymentConfirmation($order);

        $this->analyticsService->trackEvent('order_paid', [
            'order_id' => $order->getId(),
            'payment_method' => $event->getPaymentMethod(),
        ]);

        $this->logger->info('Order paid event processed', [
            'order_id' => $order->getId(),
            'payment_method' => $event->getPaymentMethod(),
        ]);
    }

    public function onOrderShipped(OrderShippedEvent $event): void
    {
        $order = $event->getOrder();
        $trackingNumber = $event->getTrackingNumber();
        $carrier = $event->getCarrier();

        $this->inventoryService->reduceStock($order);
        $this->notificationService->sendShippingNotification($order, $trackingNumber, $carrier);

        $this->analyticsService->trackEvent('order_shipped', [
            'order_id' => $order->getId(),
            'tracking_number' => $trackingNumber,
            'carrier' => $carrier,
        ]);

        $this->logger->info('Order shipped event processed', [
            'order_id' => $order->getId(),
            'tracking_number' => $trackingNumber,
        ]);
    }

    public function onOrderDelivered(OrderDeliveredEvent $event): void
    {
        $order = $event->getOrder();
        $deliveryDate = $event->getDeliveryDate();

        $this->notificationService->sendDeliveryConfirmation($order);
        $this->analyticsService->trackEvent('order_delivered', [
            'order_id' => $order->getId(),
            'delivery_date' => $deliveryDate->format('Y-m-d'),
        ]);

        $this->logger->info('Order delivered event processed', [
            'order_id' => $order->getId(),
            'delivery_date' => $deliveryDate->format('Y-m-d'),
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderCreatedEvent::class => 'onOrderCreated',
            OrderPaidEvent::class => 'onOrderPaid',
            OrderShippedEvent::class => 'onOrderShipped',
            OrderDeliveredEvent::class => 'onOrderDelivered',
        ];
    }
}
