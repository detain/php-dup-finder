<?php
declare(strict_types=1);

namespace App\Search\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class SearchAnalyticsPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function canViewSearchAnalytics(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Search analytics view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Search analytics view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search_analytics', 'view')) {
            $this->logger->info('Search analytics view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Search analytics view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canViewSearchTrends(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Search trends view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Search trends view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search_analytics', 'view_trends')) {
            $this->logger->info('Search trends view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Search trends view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canViewPopularSearches(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Popular searches view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Popular searches view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search_analytics', 'view_popular')) {
            $this->logger->info('Popular searches view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Popular searches view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canViewSearchQualityMetrics(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Search quality metrics view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Search quality metrics view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search_analytics', 'view_quality')) {
            $this->logger->info('Search quality metrics view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Search quality metrics view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canExportSearchAnalytics(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Search analytics export permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Search analytics export permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search_analytics', 'export')) {
            $this->logger->info('Search analytics export permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Search analytics export permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }
}
