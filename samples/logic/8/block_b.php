<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Entity\Shipment;
use App\Repository\ShipmentRepository;
use App\Service\ShippingRateCalculator;
use App\Service\AddressValidator;
use Psr\Log\LoggerInterface;

final class ShipmentProcessingService
{
    public function __construct(
        private readonly ShipmentRepository $shipmentRepository,
        private readonly ShippingRateCalculator $rateCalculator,
        private readonly AddressValidator $addressValidator,
        private readonly LoggerInterface $logger,
    ) {}

    public function createShipment(int $orderId, array $addresses): Shipment
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Invalid order ID');
        }

        if (empty($addresses['shipping'])) {
            throw new \InvalidArgumentException('Shipping address is required');
        }

        if (empty($addresses['billing'])) {
            throw new \InvalidArgumentException('Billing address is required');
        }

        $shippingAddress = $addresses['shipping'];

        if (!$this->addressValidator->isValid($shippingAddress)) {
            throw new \InvalidArgumentException('Invalid shipping address');
        }

        if ($shippingAddress['postal_code'] ?? '') {
            $validPostalCodes = $this->getSupportedPostalCodes();
            $postalCode = strtoupper($shippingAddress['postal_code']);

            if (!in_array($postalCode, $validPostalCodes, true)) {
                throw new \InvalidArgumentException('Shipping address not in supported region');
            }
        }

        $order = $this->loadOrder($orderId);
        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if ($order->getStatus() !== 'paid' && $order->getStatus() !== 'processing') {
            throw new \InvalidArgumentException('Order must be paid before shipping');
        }

        $customer = $this->loadCustomer($order->getCustomerId());
        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Customer account is not active');
        }

        if ($customer->isBlocked()) {
            throw new \InvalidArgumentException('Customer is blocked from shipping');
        }

        $items = $order->getItems();
        if (empty($items)) {
            throw new \InvalidArgumentException('Order has no items to ship');
        }

        $totalWeight = $this->calculateTotalWeight($items);
        if ($totalWeight > 70000) {
            throw new \InvalidArgumentException('Shipment exceeds maximum weight limit (70kg)');
        }

        $shippingCost = $this->rateCalculator->calculate($addresses, $totalWeight);

        $shipment = new Shipment();
        $shipment->setOrderId($orderId);
        $shipment->setAddresses($addresses);
        $shipment->setTotalWeight($totalWeight);
        $shipment->setShippingCost($shippingCost);
        $shipment->setStatus('created');

        $this->shipmentRepository->save($shipment);

        $this->logger->info('Shipment created', [
            'shipment_id' => $shipment->getId(),
            'order_id' => $orderId,
            'cost' => $shippingCost,
        ]);

        return $shipment;
    }

    public function dispatchShipment(int $shipmentId): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new \RuntimeException('Shipment not found');
        }

        if ($shipment->getStatus() !== 'created') {
            throw new \InvalidArgumentException('Shipment is not ready for dispatch');
        }

        if ($shipment->getShippingCost() <= 0) {
            throw new \InvalidArgumentException('Shipping cost not calculated');
        }

        $trackingNumber = $this->generateTrackingNumber();

        $shipment->setStatus('dispatched');
        $shipment->setTrackingNumber($trackingNumber);
        $shipment->setDispatchedAt(new \DateTimeImmutable());

        $this->shipmentRepository->save($shipment);

        $this->logger->info('Shipment dispatched', [
            'shipment_id' => $shipmentId,
            'tracking_number' => $trackingNumber,
        ]);

        return $shipment;
    }

    public function cancelShipment(int $shipmentId): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new \RuntimeException('Shipment not found');
        }

        if (in_array($shipment->getStatus(), ['delivered', 'cancelled', 'returned'], true)) {
            throw new \InvalidArgumentException('Shipment cannot be cancelled in current status');
        }

        if ($shipment->getStatus() === 'in_transit') {
            throw new \InvalidArgumentException('Cannot cancel shipment in transit');
        }

        $shipment->setStatus('cancelled');
        $shipment->setCancelledAt(new \DateTimeImmutable());

        $this->shipmentRepository->save($shipment);

        $this->logger->info('Shipment cancelled', [
            'shipment_id' => $shipmentId,
        ]);

        return $shipment;
    }

    private function loadOrder(int $orderId): ?Order
    {
        return $this->orderRepository->findById($orderId);
    }

    private function loadCustomer(int $customerId): ?Customer
    {
        return $this->customerRepository->findById($customerId);
    }

    private function calculateTotalWeight(array $items): float
    {
        return array_reduce(
            $items,
            fn(float $carry, array $item) => $carry + ($item['weight'] ?? 0) * ($item['quantity'] ?? 1),
            0.0
        );
    }

    private function getSupportedPostalCodes(): array
    {
        return ['US', 'CA', 'GB', 'DE', 'FR'];
    }

    private function generateTrackingNumber(): string
    {
        return 'TRK' . strtoupper(bin2hex(random_bytes(8)));
    }
}
