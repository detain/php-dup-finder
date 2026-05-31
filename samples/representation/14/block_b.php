<?php
declare(strict_types=1);

namespace App\Payment\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payments')]
#[ORM\Index(columns: ['customer_id', 'created_at'], name: 'idx_payments_customer')]
#[ORM\Index(columns: ['order_id'], name: 'idx_payments_order')]
#[ORM\Index(columns: ['status'], name: 'idx_payments_status')]
class Payment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $orderId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $customerId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $paymentMethodId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $paymentMethodType;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $providerReference;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refundedAt;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata;

    public function __construct(
        string $id,
        string $orderId,
        string $customerId,
        string $paymentMethodId,
        float $amount,
        string $currency
    ) {
        $this->id = $id;
        $this->orderId = $orderId;
        $this->customerId = $customerId;
        $this->paymentMethodId = $paymentMethodId;
        $this->paymentMethodType = 'card';
        $this->amount = $amount;
        $this->currency = $currency;
        $this->status = self::STATUS_PENDING;
        $this->createdAt = new \DateTimeImmutable();
        $this->metadata = [];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getPaymentMethodId(): string
    {
        return $this->paymentMethodId;
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

    public function getProviderReference(): ?string
    {
        return $this->providerReference;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function getMetadata(): array
    {
        return $this->metadata ?? [];
    }

    public function markAsProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
    }

    public function markAsSucceeded(string $providerReference): void
    {
        $this->status = self::STATUS_SUCCEEDED;
        $this->providerReference = $providerReference;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markAsFailed(string $reason): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markAsRefunded(): void
    {
        $this->status = self::STATUS_REFUNDED;
        $this->refundedAt = new \DateTimeImmutable();
    }

    public function setMetadataValue(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }
}
