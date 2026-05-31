<?php
declare(strict_types=1);

namespace Commerce\Rules;

final class AccessControlByAge
{
    private const AGE_LIMIT_GENERAL = 18;
    private const AGE_LIMIT_ADULT_SERVICES = 21;
    private const AGE_LIMIT_YOUTH_SERVICES = 13;

    public function evaluateAccessRequest(AgeGatedRequest $request): AccessDecision
    {
        $userAge = $request->getUserAge();
        $requiredAge = $this->determineRequiredAge($request->getServiceType());

        if ($userAge >= $requiredAge) {
            return AccessDecision::allowed();
        }

        $blockedReasons = [];

        if ($userAge < self::AGE_LIMIT_GENERAL) {
            $blockedReasons[] = 'Below general access age';
        }

        if ($this->isAdultRestrictedService($request->getServiceType()) && $userAge < self::AGE_LIMIT_ADULT_SERVICES) {
            $blockedReasons[] = 'Adult verification required';
        }

        if ($this->isYouthRestrictedService($request->getServiceType()) && $userAge < self::AGE_LIMIT_YOUTH_SERVICES) {
            $blockedReasons[] = 'Parental consent required';
        }

        return AccessDecision::denied($blockedReasons);
    }

    public function checkGeneralAccess(int $age): bool
    {
        return $age >= self::AGE_LIMIT_GENERAL;
    }

    public function checkAdultAccess(int $age): bool
    {
        return $age >= self::AGE_LIMIT_ADULT_SERVICES;
    }

    public function checkYouthAccess(int $age): bool
    {
        return $age >= self::AGE_LIMIT_YOUTH_SERVICES;
    }

    private function determineRequiredAge(string $serviceType): int
    {
        if ($this->isAdultRestrictedService($serviceType)) {
            return self::AGE_LIMIT_ADULT_SERVICES;
        }

        if ($this->isYouthRestrictedService($serviceType)) {
            return self::AGE_LIMIT_YOUTH_SERVICES;
        }

        return self::AGE_LIMIT_GENERAL;
    }

    private function isAdultRestrictedService(string $serviceType): bool
    {
        $restrictedServices = ['adult_entertainment', 'gambling', 'alcohol_sales', 'tobacco_products'];

        return in_array($serviceType, $restrictedServices, true);
    }

    private function isYouthRestrictedService(string $serviceType): bool
    {
        $youthServices = ['social_media', 'gaming_online', 'streaming_music'];

        return in_array($serviceType, $youthServices, true);
    }
}
