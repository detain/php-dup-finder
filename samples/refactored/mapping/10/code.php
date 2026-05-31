<?php
declare(strict_types=1);

namespace App\Core\Billing\Payment\Mapper;

use App\Domain\Entity\PaymentTransaction;
use App\Core\DTO\DTOInterface;

interface PaymentMappingStrategy
{
    public function getExtraFields(): array;
    public function includeCardDetails(): bool;
    public function includeFailureDetails(): bool;
}

abstract class BasePaymentTransactionMapper
{
    public function map(PaymentTransaction $tx, DTOInterface $dto, ?PaymentMappingStrategy $strategy = null): DTOInterface
    {
        $dto->id = $tx->getId()->toString();
        $dto->transactionId = $tx->getTransactionId();
        $dto->orderId = $tx->getOrderId()->toString();
        $dto->customerId = $tx->getCustomerId()->toString();
        $dto->customerEmail = $tx->getCustomerEmail();
        $dto->customerName = $tx->getCustomerName();
        $dto->paymentMethod = $tx->getPaymentMethod();
        $dto->paymentMethodType = $tx->getPaymentMethodType();
        $dto->amount = $tx->getAmount()->getAmount();
        $dto->currency = $tx->getCurrency()->code();
        $dto->status = $tx->getStatus()->value;
        $dto->gateway = $tx->getGateway();
        $dto->gatewayTransactionId = $tx->getGatewayTransactionId();
        $dto->createdAt = $tx->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->processedAt = $tx->getProcessedAt()?->format(\DateTimeInterface::ATOM);
        $dto->metadata = $tx->getMetadata();

        if ($strategy === null || $strategy->includeCardDetails()) {
            $dto->cardBrand = $tx->getCardBrand();
            $dto->cardLast4 = $tx->getCardLast4();
            $dto->cardExpiryMonth = $tx->getCardExpiryMonth();
            $dto->cardExpiryYear = $tx->getCardExpiryYear();
        }

        if ($strategy === null || $strategy->includeFailureDetails()) {
            $dto->failureCode = $tx->getFailureCode();
            $dto->failureMessage = $tx->getFailureMessage();
        }

        $dto->authorizationCode = $tx->getAuthorizationCode();

        if ($strategy !== null) {
            foreach ($strategy->getExtraFields() as $field => $value) {
                $dto->{$field} = $value;
            }
        }

        return $dto;
    }
}

final class PaymentTransactionMapper extends BasePaymentTransactionMapper {}
final class PaymentApiMapper extends BasePaymentTransactionMapper {}
final class PaymentAuditMapper extends BasePaymentTransactionMapper {}
