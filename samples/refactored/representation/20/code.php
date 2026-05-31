<?php
declare(strict_types=1);

namespace App\Coupon\Model;

use App\Coupon\Entity\Coupon;

final class CouponModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $type,
        public readonly float $value,
        public readonly ?float $maxDiscount,
        public readonly ?float $minOrderAmount,
        public readonly \DateTimeImmutable $validUntil,
        public readonly int $usageLimit,
        public readonly int $usedCount
    ) {}

    public static function fromEntity(Coupon $coupon): self
    {
        return new self(
            id: $coupon->getId(),
            code: $coupon->getCode(),
            type: $coupon->getType(),
            value: $coupon->getValue(),
            maxDiscount: $coupon->getMaxDiscount(),
            minOrderAmount: $coupon->getMinOrderAmount(),
            validUntil: $coupon->getValidUntil(),
            usageLimit: $coupon->getUsageLimit(),
            usedCount: $coupon->getUsedCount()
        );
    }

    public function isValid(): bool
    {
        $now = new \DateTimeImmutable();
        return $this->usedCount < $this->usageLimit && $now <= $this->validUntil;
    }

    public function calculateDiscount(float $orderAmount): float
    {
        if (!$this->isValid()) {
            return 0;
        }

        $discount = match ($this->type) {
            'percentage' => $orderAmount * ($this->value / 100),
            'fixed_amount' => min($this->value, $orderAmount),
            default => 0
        };

        if ($this->maxDiscount !== null) {
            $discount = min($discount, $this->maxDiscount);
        }

        return round($discount, 2);
    }
}
