<?php
declare(strict_types=1);

namespace App\Coupon\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'coupons')]
#[ORM\Index(columns: ['code', 'is_active'], name: 'idx_coupons_code_active')]
class Coupon
{
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_FREE_SHIPPING = 'free_shipping';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DEPLETED = 'depleted';
    public const STATUS_DISABLED = 'disabled';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 255)]
    private string $description;

    #[ORM\Column(type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $value;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?float $maxDiscount = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?float $minOrderAmount = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $validUntil;

    #[ORM\Column(type: 'integer')]
    private int $usageLimit;

    #[ORM\Column(type: 'integer')]
    private int $usedCount = 0;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'boolean')]
    private bool $isSingleUse = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isApplicableToSaleItems = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $applicableCategories = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $applicableProducts = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $code,
        string $type,
        float $value,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validUntil
    ) {
        $this->id = $id;
        $this->code = strtoupper($code);
        $this->type = $type;
        $this->value = $value;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $this->status = self::STATUS_ACTIVE;
        $this->usageLimit = 100;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function getMaxDiscount(): ?float
    {
        return $this->maxDiscount;
    }

    public function getMinOrderAmount(): ?float
    {
        return $this->minOrderAmount;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getUsageLimit(): int
    {
        return $this->usageLimit;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isValid(\DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new \DateTimeImmutable();

        if (!$this->isActive()) {
            return false;
        }

        if ($now < $this->validFrom || $now > $this->validUntil) {
            return false;
        }

        if ($this->usedCount >= $this->usageLimit) {
            return false;
        }

        if ($this->isSingleUse && $this->usedCount > 0) {
            return false;
        }

        return true;
    }

    public function canApplyToOrder(float $orderAmount, array $itemIds, array $categoryIds): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if ($this->minOrderAmount !== null && $orderAmount < $this->minOrderAmount) {
            return false;
        }

        if ($this->applicableProducts !== null && count($this->applicableProducts) > 0) {
            $hasApplicableProduct = count(array_intersect($itemIds, $this->applicableProducts)) > 0;
            if (!$hasApplicableProduct) {
                return false;
            }
        }

        if ($this->applicableCategories !== null && count($this->applicableCategories) > 0) {
            $hasApplicableCategory = count(array_intersect($categoryIds, $this->applicableCategories)) > 0;
            if (!$hasApplicableCategory) {
                return false;
            }
        }

        return true;
    }

    public function calculateDiscount(float $orderAmount): float
    {
        if ($this->type === self::TYPE_PERCENTAGE) {
            $discount = $orderAmount * ($this->value / 100);
        } elseif ($this->type === self::TYPE_FIXED_AMOUNT) {
            $discount = min($this->value, $orderAmount);
        } else {
            $discount = 0;
        }

        if ($this->maxDiscount !== null) {
            $discount = min($discount, $this->maxDiscount);
        }

        return round($discount, 2);
    }

    public function incrementUsage(): void
    {
        $this->usedCount++;
    }

    public function markAsExpired(): void
    {
        $this->status = self::STATUS_EXPIRED;
    }

    public function markAsDepleted(): void
    {
        $this->status = self::STATUS_DEPLETED;
    }

    public function setMinOrderAmount(?float $amount): void
    {
        $this->minOrderAmount = $amount;
    }

    public function setMaxDiscount(?float $max): void
    {
        $this->maxDiscount = $max;
    }

    public function setUsageLimit(int $limit): void
    {
        $this->usageLimit = $limit;
    }

    public function setApplicableCategories(array $categoryIds): void
    {
        $this->applicableCategories = $categoryIds;
    }
}
