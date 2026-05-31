<?php
declare(strict_types=1);

namespace App\Infrastructure\Invoice\Mapper;

use App\Domain\Entity\Order;
use App\Domain\Entity\OrderLineItem;
use App\Infrastructure\Invoice\DTO\OrderInvoiceDTO;
use App\Infrastructure\Invoice\DTO\InvoiceLineItemDTO;

final readonly class InvoiceOrderMapper
{
    public function mapToInvoice(Order $order): OrderInvoiceDTO
    {
        $dto = new OrderInvoiceDTO();
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
        $dto->invoiceNumber = $this->generateInvoiceNumber($order);
        $dto->dueDate = $order->getDueDate()?->format(\DateTimeInterface::ATOM);

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

    private function mapLineItem(OrderLineItem $item): InvoiceLineItemDTO
    {
        $dto = new InvoiceLineItemDTO();
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

    private function generateInvoiceNumber(Order $order): string
    {
        return 'INV-' . $order->getOrderNumber() . '-' . date('Ymd');
    }
}
