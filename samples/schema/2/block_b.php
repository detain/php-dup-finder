<?php

declare(strict_types=1);

namespace App\Application\DTOs\Order;

/**
 * API request DTO for creating an order.
 * This DTO definition is duplicated from:
 * - Eloquent model: src/Domain/Orders/Entity/Order.php
 * - Database table: orders, order_items
 * - API response DTO: OrderResponse
 *
 * @OA\RequestBody(
 *   required=true,
 *   @OA\JsonContent(
 *     required={"customerId", "items", "shippingAddressId", "billingAddressId", "paymentMethodId", "shippingMethod"},
 *     @OA\Property(property="customerId", type="string", format="uuid"),
 *     @OA\Property(property="items", type="array",
 *       @OA\Items(
 *         @OA\Property(property="productId", type="string", format="uuid"),
 *         @OA\Property(property="quantity", type="integer", minimum=1),
 *         @OA\Property(property="unitPrice", type="number")
 *       )
 *     ),
 *     @OA\Property(property="shippingAddressId", type="string", format="uuid"),
 *     @OA\Property(property="billingAddressId", type="string", format="uuid"),
 *     @OA\Property(property="paymentMethodId", type="string", format="uuid"),
 *     @OA\Property(property="shippingMethod", type="string", enum={"standard","express","overnight","freight"}),
 *     @OA\Property(property="couponCode", type="string"),
 *     @OA\Property(property="notes", type="string")
 *   )
 * )
 */
class OrderCreateRequest
{
    public function __construct(
        public readonly string $customerId,
        public readonly array $items,
        public readonly string $shippingAddressId,
        public readonly string $billingAddressId,
        public readonly string $paymentMethodId,
        public readonly string $shippingMethod,
        public readonly ?string $couponCode = null,
        public readonly ?string $notes = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            customerId: $data['customerId'],
            items: array_map(
                fn($item) => new OrderItemData(
                    productId: $item['productId'],
                    quantity: (int) $item['quantity'],
                    unitPrice: (float) $item['unitPrice']
                ),
                $data['items'] ?? []
            ),
            shippingAddressId: $data['shippingAddressId'],
            billingAddressId: $data['billingAddressId'],
            paymentMethodId: $data['paymentMethodId'],
            shippingMethod: $data['shippingMethod'],
            couponCode: $data['couponCode'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->customerId)) {
            $errors['customerId'] = 'Customer ID is required';
        }

        if (empty($this->items)) {
            $errors['items'] = 'At least one item is required';
        } else {
            foreach ($this->items as $index => $item) {
                if ($item->quantity < 1) {
                    $errors["items.{$index}.quantity"] = 'Quantity must be at least 1';
                }
                if ($item->unitPrice < 0) {
                    $errors["items.{$index}.unitPrice"] = 'Unit price cannot be negative';
                }
            }
        }

        if (empty($this->shippingAddressId)) {
            $errors['shippingAddressId'] = 'Shipping address is required';
        }

        if (empty($this->billingAddressId)) {
            $errors['billingAddressId'] = 'Billing address is required';
        }

        if (empty($this->paymentMethodId)) {
            $errors['paymentMethodId'] = 'Payment method is required';
        }

        $validShippingMethods = ['standard', 'express', 'overnight', 'freight'];
        if (!in_array($this->shippingMethod, $validShippingMethods)) {
            $errors['shippingMethod'] = 'Invalid shipping method';
        }

        return $errors;
    }

    public function toDomainCommand(): array
    {
        return [
            'customer_id' => $this->customerId,
            'items' => array_map(fn($i) => [
                'product_id' => $i->productId,
                'quantity' => $i->quantity,
                'unit_price' => $i->unitPrice,
            ], $this->items),
            'shipping_address_id' => $this->shippingAddressId,
            'billing_address_id' => $this->billingAddressId,
            'payment_method_id' => $this->paymentMethodId,
            'shipping_method' => $this->shippingMethod,
            'coupon_code' => $this->couponCode,
            'notes' => $this->notes,
        ];
    }
}

class OrderItemData
{
    public function __construct(
        public readonly string $productId,
        public readonly int $quantity,
        public readonly float $unitPrice,
    ) {}
}

/**
 * API response DTO for order.
 * Duplicated from: Order model, OrderCreateRequest, database table
 *
 * @OA\Response(
 *   response=201,
 *   description="Order created successfully",
 *   @OA\JsonContent(
 *     @OA\Property(property="id", type="string"),
 *     @OA\Property(property="orderNumber", type="string"),
 *     @OA\Property(property="customerId", type="string"),
 *     @OA\Property(property="status", type="string"),
 *     @OA\Property(property="subtotal", type="number"),
 *     @OA\Property(property="taxAmount", type="number"),
 *     @OA\Property(property="shippingAmount", type="number"),
 *     @OA\Property(property="discountAmount", type="number"),
 *     @OA\Property(property="totalAmount", type="number"),
 *     @OA\Property(property="currency", type="string"),
 *     @OA\Property(property="items", type="array",
 *       @OA\Items(ref="#/components/schemas/OrderItem")
 *     ),
 *     @OA\Property(property="createdAt", type="string", format="date-time")
 *   )
 * )
 */
class OrderResponse
{
    public static function fromEntity(\App\Domain\Orders\Entity\Order $order): array
    {
        return [
            'id' => $order->getId()->toString(),
            'orderNumber' => $order->getOrderNumber(),
            'customerId' => $order->getCustomerId(),
            'status' => $order->getStatus()->getValue(),
            'subtotal' => $order->getSubtotal()->getAmount(),
            'taxAmount' => $order->getTaxAmount()->getAmount(),
            'shippingAmount' => $order->getShippingCost()->getAmount(),
            'discountAmount' => $order->getDiscountAmount()->getAmount(),
            'totalAmount' => $order->getTotalAmount()->getAmount(),
            'currency' => $order->getCurrency(),
            'shippingMethod' => $order->getShippingMethod(),
            'notes' => $order->getNotes(),
            'items' => array_map(
                fn($item) => [
                    'productId' => $item->getProductId(),
                    'productName' => $item->getProductName(),
                    'quantity' => $item->getQuantity(),
                    'unitPrice' => $item->getUnitPrice()->getAmount(),
                    'subtotal' => $item->getSubtotal()->getAmount(),
                ],
                $order->getItems()
            ),
            'createdAt' => $order->getCreatedAt()->format(\DateTimeImmutable::ATOM),
            'updatedAt' => $order->getUpdatedAt()->format(\DateTimeImmutable::ATOM),
        ];
    }
}
