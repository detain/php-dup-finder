<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Hydrator;

use App\Domain\Entity\Order;
use Doctrine\DBAL\Result;
use App\Infrastructure\Persistence\Doctrine\Types\UlidType;

final readonly class OrderHydrator
{
    public function __construct(
        private OrderFactory $factory,
    ) {}

    public function hydrateOne(Result $result): ?Order
    {
        $row = $result->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    public function hydrateAll(Result $result): array
    {
        $orders = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $orders[] = $this->hydrateRow($row);
        }

        return $orders;
    }

    private function hydrateRow(array $row): Order
    {
        $order = $this->factory->create();
        $order->setId($this->factory->createUlid($row['id']));
        $order->setOrderNumber($row['order_number']);
        $order->setCustomerId($this->factory->createUlid($row['customer_id']));
        $order->setStatus($row['status']);
        $order->setSubtotal($this->factory->createMoney($row['subtotal'], $row['currency']));
        $order->setTaxAmount($this->factory->createMoney($row['tax_amount'], $row['currency']));
        $order->setShippingAmount($this->factory->createMoney($row['shipping_amount'], $row['currency']));
        $order->setDiscountAmount($this->factory->createMoney($row['discount_amount'], $row['currency']));
        $order->setTotalAmount($this->factory->createMoney($row['total_amount'], $row['currency']));
        $order->setCurrency($row['currency']);
        $order->setNotes($row['notes']);
        $order->setCreatedAt(new \DateTimeImmutable($row['created_at']));
        $order->setUpdatedAt(new \DateTimeImmutable($row['updated_at']));
        $order->setShippedAt($row['shipped_at'] ? new \DateTimeImmutable($row['shipped_at']) : null);
        $order->setDeliveredAt($row['delivered_at'] ? new \DateTimeImmutable($row['delivered_at']) : null);

        if (isset($row['shipping_address'])) {
            $order->setShippingAddress(json_decode($row['shipping_address'], true));
        }

        return $order;
    }
}
