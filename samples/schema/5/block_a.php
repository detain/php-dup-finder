<?php

declare(strict_types=1);

namespace App\Domain\Payment\Entity;

use App\Domain\Payment\ValueObject\TransactionId;
use App\Domain\Payment\ValueObject\Money;

/**
 * Doctrine entity for payment transactions.
 * This entity is duplicated in:
 * - Payment gateway API schemas
 * - Database table: payment_transactions
 * - Webhook payload schemas
 * - Reporting database schemas
 *
 * @ORM\Entity(repositoryClass=PaymentTransactionRepository::class)
 * @ORM\Table(name="payment_transactions")
 * @ORM\Index(name="idx_status", columns={"status"})
 * @ORM\Index(name="idx_customer_id", columns={"customer_id"})
 * @ORM\Index(name="idx_order_id", columns={"order_id"})
 * @ORM\Index(name="idx_gateway_transaction_id", columns={"gateway_transaction_id"})
 * @ORM\Index(name="idx_created_at", columns={"created_at"})
 */
class PaymentTransaction
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=36)
     */
    private string $customerId;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $orderId = null;

    /**
     * @ORM\Column(type="decimal", precision=12, scale=2)
     */
    private float $amount;

    /**
     * @ORM\Column(type="char", length=3)
     */
    private string $currency;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private string $status;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private string $paymentMethod;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $gatewayTransactionId = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private ?string $gatewayResponseCode = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $gatewayResponseMessage = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $gatewayResponse = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $metadata = null;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    private ?string $parentTransactionId = null;

    public function __construct(
        string $customerId,
        float $amount,
        string $currency,
        string $paymentMethod,
        ?string $orderId = null
    ) {
        $this->id = TransactionId::generate()->toString();
        $this->customerId = $customerId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->paymentMethod = $paymentMethod;
        $this->orderId = $orderId;
        $this->status = 'pending';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function markAsProcessing(): void
    {
        $this->status = 'processing';
    }

    public function markAsSuccessful(string $gatewayTransactionId, array $response = []): void
    {
        $this->status = 'successful';
        $this->gatewayTransactionId = $gatewayTransactionId;
        $this->processedAt = new \DateTimeImmutable();
        $this->gatewayResponse = $response;
    }

    public function markAsFailed(string $responseCode, string $responseMessage, array $response = []): void
    {
        $this->status = 'failed';
        $this->gatewayResponseCode = $responseCode;
        $this->gatewayResponseMessage = $responseMessage;
        $this->processedAt = new \DateTimeImmutable();
        $this->gatewayResponse = $response;
    }

    public function markAsRefunded(float $refundAmount, ?string $parentRefundId = null): void
    {
        $this->status = 'refunded';
        $this->parentTransactionId = $parentRefundId;
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'successful';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRefundable(): bool
    {
        return $this->status === 'successful';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_method' => $this->paymentMethod,
            'gateway_transaction_id' => $this->gatewayTransactionId,
            'gateway_response_code' => $this->gatewayResponseCode,
            'gateway_response_message' => $this->gatewayResponseMessage,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
            'processed_at' => $this->processedAt?->format(\DateTimeImmutable::ATOM),
        ];
    }
}
