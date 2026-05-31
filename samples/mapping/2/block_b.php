<?php
declare(strict_types=1);

namespace App\Infrastructure\Api\Resource;

use App\Domain\Entity\Order;
use App\Domain\Entity\OrderLineItem;
use App\Infrastructure\Api\DTO\OrderSummaryDTO;
use App\Infrastructure\Api\DTO\OrderLineItemDTO;

final readonly class OrderSummaryMapper
{
    public function mapToSummary(Order $order): OrderSummaryDTO
    {
        $dto = new OrderSummaryDTO();
        $dto->id = $order->getId()->toString();
        $dto->orderNumber = $order->getOrderNumber();
        $dto->customerId = $order->getCustomerId()->toString();
        $dto->customerName = $order->getCustomer()->getFullName();
        $dto->customerEmail = $order->getCustomer()->getEmail();
        $dto->status = $order->getStatus()->value;
        $dto->subtotal = $order->getSubtotal()->getAmount();
        $dto->taxAmount = $order->getTaxAmount()->getAmount();
        $dto->shippingAmount = $order->getShippingAmount()->getAmount();
        $dto->discountAmount = $order->getDiscountAmount()->getAmount();
        $dto->totalAmount = $order->getTotalAmount()->getAmount();
        $dto->currency = $order->getCurrency()->code();
        $dto->shippingAddress = $this->mapAddress($order->getShippingAddress());
        $dto->billingAddress = $this->mapAddress($order->getBillingAddress());
        $dto->lineItems = array_map(
            fn($item) => $this->mapLineItem($item),
            $order->getLineItems()
        );
        $dto->notes = $order->getNotes();
        $dto->createdAt = $order->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $order->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        return $dto;
    }

    private function mapAddress(Address $address): array
    {
        return [
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'state' => $address->getState(),
            'postalCode' => $address->getPostalCode(),
            'country' => $address->getCountry()->getCode(),
        ];
    }

    private function mapLineItem(OrderLineItem $item): OrderLineItemDTO
    {
        $dto = new OrderLineItemDTO();
        $dto->id = $item->getId()->toString();
        $dto->productId = $item->getProductId()->toString();
        $dto->productName = $item->getProductName();
        $dto->sku = $item->getSku();
        $dto->quantity = $item->getQuantity();
        $dto->unitPrice = $item->getUnitPrice()->getAmount();
        $dto->totalPrice = $item->getTotalPrice()->getAmount();
        $dto->taxRate = $item->getTaxRate();
        $dto->discountAmount = $item->getDiscountAmount()->getAmount();

        return $dto;
    }
}
