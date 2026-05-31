<?php
declare(strict_types=1);

namespace Orders\Processing;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrderProcessingService
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly InventoryChecker $inventoryChecker,
        private readonly PriceCalculator $priceCalculator,
        private readonly TaxService $taxService
    ) {}

    public function processNewOrder(Request $request): OrderResult
    {
        $customerId = $request->request->getInt('customer_id');
        $items = json_decode($request->request->get('items', '[]'), true);

        $this->logger->info('Processing new order', [
            'customer_id' => $customerId,
            'item_count' => count($items)
        ]);

        $customer = $this->entityManager->find(Customer::class, $customerId);
        if ($customer === null) {
            $this->logger->error('Customer not found', ['customer_id' => $customerId]);
            return OrderResult::failure('Customer not found');
        }

        // Validate items and check inventory
        $validItems = [];
        $unavailableItems = [];

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];

            $available = $this->inventoryChecker->checkAvailability($productId, $quantity);

            if ($available) {
                $validItems[] = $item;
            } else {
                $unavailableItems[] = $productId;
                $this->logger->warning('Item unavailable', [
                    'product_id' => $productId,
                    'requested' => $quantity
                ]);
            }
        }

        if (empty($validItems)) {
            $this->logger->info('No items available, order cannot proceed', [
                'customer_id' => $customerId
            ]);
            return OrderResult::failure('No items available', $unavailableItems);
        }

        // Calculate pricing
        $subtotal = $this->priceCalculator->calculateSubtotal($validItems);
        $taxAmount = $this->taxService->calculateTax($customer, $subtotal);
        $shippingCost = $this->calculateShipping($customer, $validItems);
        $total = $subtotal + $taxAmount + $shippingCost;

        $this->logger->info('Order pricing calculated', [
            'customer_id' => $customerId,
            'subtotal' => $subtotal,
            'tax' => $taxAmount,
            'shipping' => $shippingCost,
            'total' => $total
        ]);

        // Create order
        $order = new Order();
        $order->setCustomer($customer);
        $order->setSubtotal($subtotal);
        $order->setTaxAmount($taxAmount);
        $order->setShippingCost($shippingCost);
        $order->setTotal($total);
        $order->setStatus('pending');
        $order->setCreatedAt(new \DateTimeImmutable());

        foreach ($validItems as $item) {
            $orderItem = new OrderItem();
            $orderItem->setProduct($this->entityManager->find(Product::class, $item['product_id']));
            $orderItem->setQuantity($item['quantity']);
            $orderItem->setUnitPrice($this->priceCalculator->getUnitPrice($item['product_id']));
            $order->addItem($orderItem);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->logger->info('Order created', [
            'order_id' => $order->getId(),
            'customer_id' => $customerId,
            'total' => $total
        ]);

        // Dispatch events for async processing
        $this->messageBus->dispatch(new OrderCreatedEvent($order->getId()));
        $this->messageBus->dispatch(new InventoryReservationEvent($order->getId()));

        return OrderResult::success($order->getId(), $total);
    }

    public function confirmOrder(int $orderId): OrderResult
    {
        $order = $this->entityManager->find(Order::class, $orderId);

        if ($order === null) {
            $this->logger->error('Order not found for confirmation', [
                'order_id' => $orderId
            ]);
            return OrderResult::failure('Order not found');
        }

        if ($order->getStatus() !== 'pending') {
            $this->logger->warning('Order cannot be confirmed - invalid status', [
                'order_id' => $orderId,
                'status' => $order->getStatus()
            ]);
            return OrderResult::failure('Order cannot be confirmed');
        }

        // Reserve inventory
        $reservationResult = $this->inventoryChecker->reserveInventory($order);

        if (!$reservationResult->isSuccessful()) {
            $this->logger->warning('Inventory reservation failed', [
                'order_id' => $orderId
            ]);
            return OrderResult::failure('Could not reserve inventory');
        }

        // Update order status
        $order->setStatus('confirmed');
        $order->setConfirmedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->logger->info('Order confirmed', [
            'order_id' => $orderId,
            'customer_id' => $order->getCustomer()->getId()
        ]);

        // Dispatch confirmation event
        $this->messageBus->dispatch(new OrderConfirmedEvent($orderId));

        return OrderResult::success($orderId);
    }

    public function cancelOrder(int $orderId, string $reason): OrderResult
    {
        $order = $this->entityManager->find(Order::class, $orderId);

        if ($order === null) {
            $this->logger->error('Order not found for cancellation', [
                'order_id' => $orderId
            ]);
            return OrderResult::failure('Order not found');
        }

        if (!in_array($order->getStatus(), ['pending', 'confirmed'])) {
            $this->logger->warning('Order cannot be cancelled - invalid status', [
                'order_id' => $orderId,
                'status' => $order->getStatus()
            ]);
            return OrderResult::failure('Order cannot be cancelled in current status');
        }

        // Release reserved inventory
        $this->inventoryChecker->releaseInventory($orderId);

        // Update status
        $order->setStatus('cancelled');
        $order->setCancellationReason($reason);
        $order->setCancelledAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->logger->info('Order cancelled', [
            'order_id' => $orderId,
            'reason' => $reason
        ]);

        // Dispatch cancellation event
        $this->messageBus->dispatch(new OrderCancelledEvent($orderId, $reason));

        return OrderResult::success($orderId);
    }

    private function calculateShipping(Customer $customer, array $items): int
    {
        // Shipping calculation logic
        $baseRate = 500; // $5.00 base

        if ($customer->isPremiumMember()) {
            return 0; // Free shipping for premium
        }

        $totalWeight = 0;
        foreach ($items as $item) {
            $product = $this->entityManager->find(Product::class, $item['product_id']);
            if ($product) {
                $totalWeight += $product->getWeight() * $item['quantity'];
            }
        }

        if ($totalWeight > 1000) { // Over 10kg
            $baseRate += 500; // Extra $5.00
        }

        return $baseRate;
    }
}
