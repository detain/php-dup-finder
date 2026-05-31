<?php
declare(strict_types=1);

namespace App\Billing\Payment\Api\Mapper;

use App\Domain\Entity\PaymentTransaction;
use App\Billing\Payment\Api\DTO\PaymentApiDTO;

final readonly class PaymentApiMapper
{
    public function toApiDTO(PaymentTransaction $tx): PaymentApiDTO
    {
        $dto = new PaymentApiDTO();
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
}
