<?php
declare(strict_types=1);

namespace App\Order\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[ORM\Index(columns: ['customer_id', 'created_at'], name: 'idx_orders_customer_date')]
#[ORM\Index(columns: ['status'], name: 'idx_orders_status')]
class Order
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 20, unique: true)]
    private string $orderNumber;

    #[ORM\Column(type: 'string', length: 36)]
    private string $customerId;

    #[ORM\Column(type: 'string', length: 254)]
    private string $currency;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $subtotal;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $taxAmount;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $shippingAmount;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $totalAmount;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $shippedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** @var OrderLineItem[] */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderLineItem::class, cascade: ['persist'])]
    private array $lineItems = [];

    public function __construct(
        string $id,
        string $orderNumber,
        string $customerId,
        string $currency
    ) {
        $this->id = $id;
        $this->orderNumber = $orderNumber;
        $this->customerId = $customerId;
        $this->currency = $currency;
        $this->subtotal = 0.0;
        $this->taxAmount = 0.0;
        $this->shippingAmount = 0.0;
        $this->totalAmount = 0.0;
        $this->status = self::STATUS_PENDING;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function getShippingAmount(): float
    {
        return $this->shippingAmount;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getShippedAt(): ?\DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getLineItems(): array
    {
        return $this->lineItems;
    }

    public function getTotalItemCount(): int
    {
        return array_reduce(
            $this->lineItems,
            fn($sum, $item) => $sum + $item->getQuantity(),
            0
        );
    }

    public function calculateTotals(): void
    {
        $this->subtotal = array_reduce(
            $this->lineItems,
            fn($sum, $item) => $sum + $item->getTotal(),
            0.0
        );

        $this->totalAmount = $this->subtotal + $this->taxAmount + $this->shippingAmount;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function markAsShipped(): void
    {
        $this->status = self::STATUS_SHIPPED;
        $this->shippedAt = new \DateTimeImmutable();
    }

    public function markAsDelivered(): void
    {
        $this->status = self::STATUS_DELIVERED;
        $this->deliveredAt = new \DateTimeImmutable();
    }

    public function addLineItem(OrderLineItem $item): void
    {
        $this->lineItems[] = $item;
        $this->calculateTotals();
    }
}
