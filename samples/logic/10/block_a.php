<?php

declare(strict_types=1);

namespace App\FeatureFlags;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\FeatureFlagStore;
use Psr\Log\LoggerInterface;

final class FeatureGateService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly FeatureFlagStore $flagStore,
        private readonly LoggerInterface $logger,
    ) {}

    public function canAccessFeature(int $userId, string $feature): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return false;
        }

        if ($user->getStatus() !== 'active') {
            return false;
        }

        if ($user->isBetaTester()) {
            return true;
        }

        if ($user->getSubscriptionTier() === 'enterprise' && $this->isEnterpriseFeature($feature)) {
            return true;
        }

        if ($user->getSubscriptionTier() === 'premium' && $this->isPremiumFeature($feature)) {
            return true;
        }

        if ($user->getSubscriptionTier() === 'basic' && $this->isBasicFeature($feature)) {
            return true;
        }

        if ($user->getSubscriptionTier() === 'free' && $this->isFreeFeature($feature)) {
            return true;
        }

        $overrides = $this->flagStore->getFeatureOverrides($user->getId());
        if (isset($overrides[$feature])) {
            return $overrides[$feature];
        }

        return false;
    }

    public function isFeatureEnabled(string $feature): bool
    {
        $globalEnabled = $this->flagStore->isFeatureGloballyEnabled($feature);

        if (!$globalEnabled) {
            return false;
        }

        $rolloutPercentage = $this->flagStore->getRolloutPercentage($feature);

        if ($rolloutPercentage >= 100) {
            return true;
        }

        if ($rolloutPercentage <= 0) {
            return false;
        }

        return false;
    }

    public function getAccessibleFeatures(int $userId): array
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null || $user->getStatus() !== 'active') {
            return [];
        }

        $allFeatures = $this->flagStore->getAllFeatures();
        $accessible = [];

        foreach ($allFeatures as $feature) {
            if ($this->canAccessFeature($userId, $feature)) {
                $accessible[] = $feature;
            }
        }

        return $accessible;
    }

    private function isEnterpriseFeature(string $feature): bool
    {
        $enterpriseFeatures = [
            'advanced_analytics',
            'custom_integrations',
            'api_access',
            'unlimited_users',
            'priority_support',
            'custom_branding',
            'audit_logs',
            'sso',
        ];

        return in_array($feature, $enterpriseFeatures, true);
    }

    private function isPremiumFeature(string $feature): bool
    {
        $premiumFeatures = [
            'advanced_analytics',
            'api_access',
            'priority_support',
            'export_data',
            'custom_reports',
        ];

        return in_array($feature, $premiumFeatures, true);
    }

    private function isBasicFeature(string $feature): bool
    {
        $basicFeatures = [
            'basic_analytics',
            'email_support',
            'create_projects',
            'invite_team_members',
        ];

        return in_array($feature, $basicFeatures, true);
    }

    private function isFreeFeature(string $feature): bool
    {
        $freeFeatures = [
            'basic_analytics',
            'email_support',
            'create_projects',
        ];

        return in_array($feature, $freeFeatures, true);
    }
}
