<?php
declare(strict_types=1);

namespace App\Billing\Payment\Application\Mapper;

use App\Domain\Entity\PaymentTransaction;
use App\Billing\Payment\Application\DTO\TransactionEntityDTO;
use App\Billing\Payment\Application\DTO\TransactionReceiptDTO;
use App\Billing\Payment\Application\DTO\TransactionSummaryDTO;

final readonly class PaymentTransactionMapper
{
    public function toEntityDTO(PaymentTransaction $tx): TransactionEntityDTO
    {
        $dto = new TransactionEntityDTO();
        $dto->id = $tx->getId()->toString();
        $dto->transactionId = $tx->getTransactionId();
        $dto->orderId = $tx->getOrderId()->toString();
        $dto->customerId = $tx->getCustomerId()->toString();
        $dto->customerEmail = $tx->getCustomerEmail();
        $dto->customerName = $tx->getCustomerName();
        $dto->paymentMethod = $tx->getPaymentMethod();
        $dto->paymentMethodType = $tx->getPaymentMethodType();
        $dto->cardBrand = $tx->getCardBrand();
        $dto->cardLast4 = $tx->getCardLast4();
        $dto->cardExpiryMonth = $tx->getCardExpiryMonth();
        $dto->cardExpiryYear = $tx->getCardExpiryYear();
        $dto->amount = $tx->getAmount()->getAmount();
        $dto->currency = $tx->getCurrency()->code();
        $dto->status = $tx->getStatus()->value;
        $dto->gateway = $tx->getGateway();
        $dto->gatewayTransactionId = $tx->getGatewayTransactionId();
        $dto->authorizationCode = $tx->getAuthorizationCode();
        $dto->failureCode = $tx->getFailureCode();
        $dto->failureMessage = $tx->getFailureMessage();
        $dto->createdAt = $tx->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->processedAt = $tx->getProcessedAt()?->format(\DateTimeInterface::ATOM);
        $dto->metadata = $tx->getMetadata();

        return $dto;
    }

    public function toReceiptDTO(PaymentTransaction $tx): TransactionReceiptDTO
    {
        $dto = new TransactionReceiptDTO();
        $dto->id = $tx->getId()->toString();
        $dto->transactionId = $tx->getTransactionId();
        $dto->orderId = $tx->getOrderId()->toString();
        $dto->customerId = $tx->getCustomerId()->toString();
        $dto->customerEmail = $tx->getCustomerEmail();
        $dto->customerName = $tx->getCustomerName();
        $dto->paymentMethod = $tx->getPaymentMethod();
        $dto->paymentMethodType = $tx->getPaymentMethodType();
        $dto->cardBrand = $tx->getCardBrand();
        $dto->cardLast4 = $tx->getCardLast4();
        $dto->cardExpiryMonth = $tx->getCardExpiryMonth();
        $dto->cardExpiryYear = $tx->getCardExpiryYear();
        $dto->amount = $tx->getAmount()->getAmount();
        $dto->currency = $tx->getCurrency()->code();
        $dto->status = $tx->getStatus()->value;
        $dto->gateway = $tx->getGateway();
        $dto->gatewayTransactionId = $tx->getGatewayTransactionId();
        $dto->authorizationCode = $tx->getAuthorizationCode();
        $dto->failureCode = $tx->getFailureCode();
        $dto->failureMessage = $tx->getFailureMessage();
        $dto->createdAt = $tx->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->processedAt = $tx->getProcessedAt()?->format(\DateTimeInterface::ATOM);
        $dto->metadata = $tx->getMetadata();
        $dto->merchantName = $this->getMerchantName();
        $dto->receiptNumber = $this->generateReceiptNumber($tx);

        return $dto;
    }

    public function toSummaryDTO(PaymentTransaction $tx): TransactionSummaryDTO
    {
        $dto = new TransactionSummaryDTO();
        $dto->id = $tx->getId()->toString();
        $dto->transactionId = $tx->getTransactionId();
        $dto->orderId = $tx->getOrderId()->toString();
        $dto->customerId = $tx->getCustomerId()->toString();
        $dto->customerEmail = $tx->getCustomerEmail();
        $dto->customerName = $tx->getCustomerName();
        $dto->paymentMethod = $tx->getPaymentMethod();
        $dto->paymentMethodType = $tx->getPaymentMethodType();
        $dto->cardBrand = $tx->getCardBrand();
        $dto->cardLast4 = $tx->getCardLast4();
        $dto->cardExpiryMonth = $tx->getCardExpiryMonth();
        $dto->cardExpiryYear = $tx->getCardExpiryYear();
        $dto->amount = $tx->getAmount()->getAmount();
        $dto->currency = $tx->getCurrency()->code();
        $dto->status = $tx->getStatus()->value;
        $dto->gateway = $tx->getGateway();
        $dto->gatewayTransactionId = $tx->getGatewayTransactionId();
        $dto->authorizationCode = $tx->getAuthorizationCode();
        $dto->failureCode = $tx->getFailureCode();
        $dto->failureMessage = $tx->getFailureMessage();
        $dto->createdAt = $tx->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->processedAt = $tx->getProcessedAt()?->format(\DateTimeInterface::ATOM);
        $dto->metadata = $tx->getMetadata();

        return $dto;
    }

    private function getMerchantName(): string
    {
        return 'Acme Corporation';
    }

    private function generateReceiptNumber(PaymentTransaction $tx): string
    {
        return 'RCP-' . $tx->getTransactionId() . '-' . date('Ymd');
    }
}
