<?php

declare(strict_types=1);

namespace App\Domain\Orders\Repository;

use App\Domain\Database\DatabaseConnection;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderItem;
use App\Domain\Orders\ValueObject\OrderStatus;

/**
 * Order repository implementation with manual database connection injection.
 * The DatabaseConnection is manually injected here, duplicated from
 * UserRepository and other repositories.
 */
class OrderRepository implements OrderRepositoryInterface
{
    private DatabaseConnection $db;
    private string $table = 'orders';
    private string $itemsTable = 'order_items';

    public function __construct(DatabaseConnection $db)
    {
        $this->db = $db;
    }

    public function findById(string $id): ?Order
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";

        $result = $this->db->query($sql, [$id]);

        if ($result->numRows() === 0) {
            return null;
        }

        $order = $this->hydrateOrder($result->fetch());
        $order->setItems($this->findItemsByOrderId($id));

        return $order;
    }

    public function findByOrderNumber(string $orderNumber): ?Order
    {
        $sql = "SELECT * FROM {$this->table} WHERE order_number = ? LIMIT 1";

        $result = $this->db->query($sql, [$orderNumber]);

        if ($result->numRows() === 0) {
            return null;
        }

        $order = $this->hydrateOrder($result->fetch());
        $order->setItems($this->findItemsByOrderId($order->getId()));

        return $order;
    }

    public function findByCustomerId(string $customerId, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM {$this->table}
            WHERE customer_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?";

        $result = $this->db->query($sql, [$customerId, $limit, $offset]);

        $orders = [];
        while ($row = $result->fetch()) {
            $orders[] = $this->hydrateOrder($row);
        }

        return $orders;
    }

    public function findByStatus(OrderStatus $status, int $limit = 100): array
    {
        $sql = "SELECT * FROM {$this->table}
            WHERE status = ?
            ORDER BY created_at DESC
            LIMIT ?";

        $result = $this->db->query($sql, [$status->getValue(), $limit]);

        $orders = [];
        while ($row = $result->fetch()) {
            $orders[] = $this->hydrateOrder($row);
        }

        return $orders;
    }

    public function save(Order $order): Order
    {
        if ($order->getId() === null) {
            return $this->insert($order);
        }

        return $this->update($order);
    }

    private function insert(Order $order): Order
    {
        $sql = "INSERT INTO {$this->table} (
            order_number, customer_id, status, subtotal, tax_amount,
            shipping_amount, discount_amount, total_amount, currency,
            shipping_address_id, billing_address_id, payment_method_id,
            notes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $params = [
            $order->getOrderNumber(),
            $order->getCustomerId(),
            $order->getStatus()->getValue(),
            $order->getSubtotal()->getAmount(),
            $order->getTaxAmount()->getAmount(),
            $order->getShippingCost()->getAmount(),
            $order->getDiscountAmount()->getAmount(),
            $order->getTotalAmount()->getAmount(),
            $order->getCurrency(),
            $order->getShippingAddressId(),
            $order->getBillingAddressId(),
            $order->getPaymentMethodId(),
            $order->getNotes(),
        ];

        $this->db->query($sql, $params);

        $id = $this->db->getLastInsertId();
        $order->setId($id);

        $this->saveItems($order);

        return $order;
    }

    private function update(Order $order): Order
    {
        $sql = "UPDATE {$this->table} SET
            status = ?,
            subtotal = ?,
            tax_amount = ?,
            shipping_amount = ?,
            discount_amount = ?,
            total_amount = ?,
            notes = ?,
            updated_at = NOW()
        WHERE id = ?";

        $params = [
            $order->getStatus()->getValue(),
            $order->getSubtotal()->getAmount(),
            $order->getTaxAmount()->getAmount(),
            $order->getShippingCost()->getAmount(),
            $order->getDiscountAmount()->getAmount(),
            $order->getTotalAmount()->getAmount(),
            $order->getNotes(),
            $order->getId(),
        ];

        $this->db->query($sql, $params);

        $this->saveItems($order);

        return $order;
    }

    private function saveItems(Order $order): void
    {
        $this->db->query(
            "DELETE FROM {$this->itemsTable} WHERE order_id = ?",
            [$order->getId()]
        );

        foreach ($order->getItems() as $item) {
            $sql = "INSERT INTO {$this->itemsTable} (
                order_id, product_id, product_name, quantity,
                unit_price, subtotal, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())";

            $this->db->query($sql, [
                $order->getId(),
                $item->getProductId(),
                $item->getProductName(),
                $item->getQuantity(),
                $item->getUnitPrice()->getAmount(),
                $item->getSubtotal()->getAmount(),
            ]);
        }
    }

    private function findItemsByOrderId(string $orderId): array
    {
        $sql = "SELECT * FROM {$this->itemsTable} WHERE order_id = ?";

        $result = $this->db->query($sql, [$orderId]);

        $items = [];
        while ($row = $result->fetch()) {
            $items[] = new OrderItem(
                productId: $row['product_id'],
                productName: $row['product_name'],
                quantity: (int) $row['quantity'],
                unitPrice: (float) $row['unit_price'],
            );
        }

        return $items;
    }

    private function hydrateOrder(array $row): Order
    {
        return new Order(
            id: $row['id'],
            orderNumber: $row['order_number'],
            customerId: $row['customer_id'],
            status: OrderStatus::from($row['status']),
            subtotal: (float) $row['subtotal'],
            taxAmount: (float) $row['tax_amount'],
            shippingAmount: (float) $row['shipping_amount'],
            discountAmount: (float) $row['discount_amount'],
            totalAmount: (float) $row['total_amount'],
            currency: $row['currency'],
            createdAt: new \DateTimeImmutable($row['created_at']),
        );
    }
}
