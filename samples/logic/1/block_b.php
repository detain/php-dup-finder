<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Entity\Shipment;
use App\Repository\ShipmentRepository;
use App\Service\ShippingCalculator;
use Psr\Log\LoggerInterface;

final class ShipmentService
{
    public function __construct(
        private readonly ShipmentRepository $shipmentRepository,
        private readonly ShippingCalculator $shippingCalculator,
        private readonly LoggerInterface $logger,
    ) {}

    public function createShipment(int $orderId, array $addresses): Shipment
    {
        $order = $this->loadOrder($orderId);

        if ($order === null) {
            throw new \InvalidArgumentException('Order not found');
        }

        if ($order->getStatus() !== 'paid' && $order->getStatus() !== 'processing') {
            throw new \InvalidArgumentException('Order must be paid before shipping');
        }

        $customer = $this->loadCustomer($order->getCustomerId());

        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Cannot ship to inactive customer');
        }

        if ($customer->getTier() === 'suspended') {
            throw new \InvalidArgumentException('Customer account is suspended');
        }

        if ($customer->getAccountBalance() < 0 && abs($customer->getAccountBalance()) > 1000) {
            throw new \InvalidArgumentException('Customer has exceeded credit limit');
        }

        if (!$this->validateAddresses($addresses)) {
            throw new \InvalidArgumentException('Invalid shipping addresses');
        }

        $shipment = new Shipment();
        $shipment->setOrderId($orderId);
        $shipment->setAddresses($addresses);
        $shipment->setCost($this->shippingCalculator->calculate($addresses));

        $this->shipmentRepository->save($shipment);

        $this->logger->info('Shipment created successfully', [
            'shipment_id' => $shipment->getId(),
            'order_id' => $orderId,
        ]);

        return $shipment;
    }

    public function updateShipment(int $shipmentId, array $updates): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new \RuntimeException('Shipment not found');
        }

        if ($shipment->getStatus() === 'delivered') {
            throw new \InvalidArgumentException('Cannot update delivered shipment');
        }

        if ($shipment->getStatus() === 'cancelled') {
            throw new \InvalidArgumentException('Cannot update cancelled shipment');
        }

        $order = $this->loadOrder($shipment->getOrderId());
        $customer = $this->loadCustomer($order->getCustomerId());

        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Cannot update shipment for inactive customer');
        }

        if ($customer->getTier() === 'suspended') {
            throw new \InvalidArgumentException('Customer account is suspended');
        }

        if ($customer->getAccountBalance() < 0 && abs($customer->getAccountBalance()) > 1000) {
            throw new \InvalidArgumentException('Customer has exceeded credit limit');
        }

        $this->applyShipmentUpdates($shipment, $updates);
        $this->shipmentRepository->save($shipment);

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

    private function validateAddresses(array $addresses): bool
    {
        if (empty($addresses['shipping']) || empty($addresses['billing'])) {
            return false;
        }

        return true;
    }

    private function applyShipmentUpdates(Shipment $shipment, array $updates): void
    {
        if (isset($updates['addresses'])) {
            if (!$this->validateAddresses($updates['addresses'])) {
                throw new \InvalidArgumentException('Invalid shipping addresses');
            }
            $shipment->setAddresses($updates['addresses']);
            $shipment->setCost($this->shippingCalculator->calculate($updates['addresses']));
        }
    }
}
