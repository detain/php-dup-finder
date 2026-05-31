<?php
declare(strict_types=1);

namespace App\Coupon\DTO;

final class CouponValidationResultDTO
{
    public function __construct(
        public readonly bool $isValid,
        public readonly ?string $couponId,
        public readonly string $code,
        public readonly string $couponType,
        public readonly float $discountValue,
        public readonly ?float $calculatedDiscount,
        public readonly ?float $maxDiscount,
        public readonly ?string $errorMessage,
        public readonly array $validationErrors,
        public readonly string $description,
        public readonly ?float $minOrderAmount,
        public readonly ?string $expiresAt
    ) {}

    public static function valid(Coupon $coupon, float $orderAmount): self
    {
        $discount = $coupon->calculateDiscount($orderAmount);

        return new self(
            isValid: true,
            couponId: $coupon->getId(),
            code: $coupon->getCode(),
            couponType: $coupon->getType(),
            discountValue: $coupon->getValue(),
            calculatedDiscount: $discount,
            maxDiscount: $coupon->getMaxDiscount(),
            errorMessage: null,
            validationErrors: [],
            description: $coupon->getDescription(),
            minOrderAmount: $coupon->getMinOrderAmount(),
            expiresAt: $coupon->getValidUntil()->format('c')
        );
    }

    public static function invalid(string $code, array $errors): self
    {
        return new self(
            isValid: false,
            couponId: null,
            code: $code,
            couponType: '',
            discountValue: 0,
            calculatedDiscount: null,
            maxDiscount: null,
            errorMessage: $errors[0] ?? 'Invalid coupon',
            validationErrors: $errors,
            description: '',
            minOrderAmount: null,
            expiresAt: null
        );
    }

    public function getDiscountDescription(): string
    {
        if (!$this->isValid) {
            return '';
        }

        if ($this->couponType === 'percentage') {
            return "{$this->discountValue}% off";
        }

        if ($this->couponType === 'fixed_amount') {
            return '$' . number_format($this->discountValue, 2) . ' off';
        }

        if ($this->couponType === 'free_shipping') {
            return 'Free shipping';
        }

        return '';
    }

    public function getFormattedDiscount(): string
    {
        if ($this->calculatedDiscount === null) {
            return '';
        }

        return '$' . number_format($this->calculatedDiscount, 2);
    }

    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'coupon_id' => $this->couponId,
            'code' => $this->code,
            'type' => $this->couponType,
            'discount_value' => $this->discountValue,
            'calculated_discount' => $this->calculatedDiscount,
            'max_discount' => $this->maxDiscount,
            'error_message' => $this->errorMessage,
            'validation_errors' => $this->validationErrors,
            'description' => $this->description,
            'min_order_amount' => $this->minOrderAmount,
            'expires_at' => $this->expiresAt
        ];
    }
}
