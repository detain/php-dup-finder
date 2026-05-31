<?php
declare(strict_types=1);

namespace Commerce\Rules;

final class AgeVerificationRule
{
    private const MINIMUM_AGE_PURCHASE = 18;
    private const MINIMUM_AGE_PREMIUM = 21;
    private const MINIMUM_AGE_SUBSCRIPTION = 13;

    public function verifyPurchaseEligibility(int $customerAge, string $productCategory): bool
    {
        if ($customerAge < self::MINIMUM_AGE_PURCHASE) {
            return false;
        }

        if ($productCategory === 'adult' && $customerAge < 21) {
            return false;
        }

        if ($productCategory === 'tobacco' && $customerAge < 21) {
            return false;
        }

        if ($productCategory === 'alcohol' && $customerAge < 21) {
            return false;
        }

        if ($productCategory === 'gambling' && $customerAge < 21) {
            return false;
        }

        return true;
    }

    public function verifyPremiumAccess(int $customerAge): bool
    {
        return $customerAge >= self::MINIMUM_AGE_PREMIUM;
    }

    public function verifySubscriptionEligibility(int $customerAge): bool
    {
        return $customerAge >= self::MINIMUM_AGE_SUBSCRIPTION;
    }

    public function getRestrictedCategories(): array
    {
        return ['adult', 'tobacco', 'alcohol', 'gambling'];
    }

    public function requiresAdultVerification(int $customerAge, string $category): bool
    {
        if ($customerAge >= 21) {
            return false;
        }

        return in_array($category, $this->getRestrictedCategories(), true);
    }
}
