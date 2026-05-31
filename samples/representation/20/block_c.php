<?php
declare(strict_types=1);

namespace App\Coupon\Checkout;

final class CouponApplicationResult
{
    public string $couponCode;
    public string $couponType;
    public string $discountDescription;
    public float $originalAmount;
    public float $discountAmount;
    public float $finalAmount;
    public string $currency;
    public string $message;
    public bool $applied;
    public array $affectedItems;

    public static function success(
        Coupon $coupon,
        float $originalAmount,
        float $discountAmount,
        array $affectedItems = []
    ): self {
        $result = new self();
        $result->couponCode = $coupon->getCode();
        $result->couponType = $coupon->getType();
        $result->discountDescription = self::formatDiscountDescription($coupon);
        $result->originalAmount = $originalAmount;
        $result->discountAmount = $discountAmount;
        $result->finalAmount = $originalAmount - $discountAmount;
        $result->currency = 'USD';
        $result->message = "Coupon {$coupon->getCode()} applied successfully";
        $result->applied = true;
        $result->affectedItems = $affectedItems;

        return $result;
    }

    public static function failure(string $couponCode, string $message): self
    {
        $result = new self();
        $result->couponCode = $couponCode;
        $result->couponType = '';
        $result->discountDescription = '';
        $result->originalAmount = 0;
        $result->discountAmount = 0;
        $result->finalAmount = 0;
        $result->currency = 'USD';
        $result->message = $message;
        $result->applied = false;
        $result->affectedItems = [];

        return $result;
    }

    private static function formatDiscountDescription(Coupon $coupon): string
    {
        if ($coupon->getType() === 'percentage') {
            $desc = $coupon->getValue() . '% off';
            if ($coupon->getMaxDiscount() !== null) {
                $desc .= ' (up to $' . number_format($coupon->getMaxDiscount(), 2) . ')';
            }
            return $desc;
        }

        if ($coupon->getType() === 'fixed_amount') {
            return '$' . number_format($coupon->getValue(), 2) . ' off';
        }

        return 'Free shipping';
    }

    public function getSavings(): float
    {
        return $this->discountAmount;
    }

    public function getSavingsPercentage(): float
    {
        if ($this->originalAmount <= 0) {
            return 0;
        }

        return ($this->discountAmount / $this->originalAmount) * 100;
    }

    public function getFormattedDiscount(): string
    {
        return '$' . number_format($this->discountAmount, 2);
    }

    public function getFormattedFinalAmount(): string
    {
        return '$' . number_format($this->finalAmount, 2) . ' ' . $this->currency;
    }

    public function toArray(): array
    {
        return [
            'coupon_code' => $this->couponCode,
            'coupon_type' => $this->couponType,
            'discount_description' => $this->discountDescription,
            'original_amount' => $this->originalAmount,
            'discount_amount' => $this->discountAmount,
            'final_amount' => $this->finalAmount,
            'currency' => $this->currency,
            'message' => $this->message,
            'applied' => $this->applied,
            'affected_items' => $this->affectedItems,
            'savings' => $this->getSavings(),
            'savings_percentage' => $this->getSavingsPercentage()
        ];
    }
}
