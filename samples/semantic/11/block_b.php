<?php
declare(strict_types=1);

namespace Commerce\Rules;

final class MembershipAgeValidator
{
    private const AGE_THRESHOLD_STANDARD = 18;
    private const AGE_THRESHOLD_RESTRICTED = 21;
    private const AGE_THRESHOLD_YOUTH = 13;

    public function canAccessRestrictedContent(int $memberAge, string $contentRating): bool
    {
        if ($contentRating === 'everyone') {
            return true;
        }

        if ($contentRating === 'teen') {
            return $memberAge >= self::AGE_THRESHOLD_YOUTH;
        }

        if ($contentRating === 'mature') {
            return $memberAge >= self::AGE_THRESHOLD_STANDARD;
        }

        if ($contentRating === 'adults_only') {
            return $memberAge >= self::AGE_THRESHOLD_RESTRICTED;
        }

        return false;
    }

    public function isAdultMember(int $memberAge): bool
    {
        return $memberAge >= self::AGE_THRESHOLD_STANDARD;
    }

    public function canUpgradeToPremium(int $memberAge): bool
    {
        return $memberAge >= self::AGE_THRESHOLD_RESTRICTED;
    }

    public function canSubscribe(int $memberAge): bool
    {
        return $memberAge >= self::AGE_THRESHOLD_YOUTH;
    }

    public function getContentRatingThreshold(string $rating): int
    {
        return match ($rating) {
            'everyone' => 0,
            'teen' => self::AGE_THRESHOLD_YOUTH,
            'mature' => self::AGE_THRESHOLD_STANDARD,
            'adults_only' => self::AGE_THRESHOLD_RESTRICTED,
            default => PHP_INT_MAX,
        };
    }

    public function requiresAgeGate(int $memberAge, string $contentRating): bool
    {
        $requiredAge = $this->getContentRatingThreshold($contentRating);

        return $memberAge < $requiredAge;
    }
}
