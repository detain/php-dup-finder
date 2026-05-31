<?php
declare(strict_types=1);

namespace App\Search\Security;

use App\Domain\Entity\User;
use App\Domain\ValueObject\SearchQuery;
use Psr\Log\LoggerInterface;

final readonly class SearchPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function canPerformBasicSearch(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Basic search permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Basic search permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search', 'basic')) {
            $this->logger->info('Basic search permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if ($this->isSearchRateLimited($user)) {
            $this->logger->info('Basic search permission denied: rate limited', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Basic search permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canPerformFilteredSearch(User $user, SearchQuery $query): bool
    {
        if ($user === null) {
            $this->logger->warning('Filtered search permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Filtered search permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search', 'filtered')) {
            $this->logger->info('Filtered search permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$this->validateFilters($user, $query)) {
            $this->logger->info('Filtered search permission denied: invalid filters', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if ($this->isSearchRateLimited($user)) {
            $this->logger->info('Filtered search permission denied: rate limited', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Filtered search permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canPerformAdvancedSearch(User $user, SearchQuery $query): bool
    {
        if ($user === null) {
            $this->logger->warning('Advanced search permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Advanced search permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search', 'advanced')) {
            $this->logger->info('Advanced search permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$this->validateAdvancedFilters($user, $query)) {
            $this->logger->info('Advanced search permission denied: invalid advanced filters', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if ($this->isSearchRateLimited($user)) {
            $this->logger->info('Advanced search permission denied: rate limited', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Advanced search permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canExportSearchResults(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Search export permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Search export permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search', 'export')) {
            $this->logger->info('Search export permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search', 'filtered')) {
            $this->logger->info('Search export permission denied: export requires filtered permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Search export permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canUseSearchSuggestions(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Search suggestions permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Search suggestions permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search', 'suggestions')) {
            $this->logger->info('Search suggestions permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Search suggestions permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    private function isSearchRateLimited(User $user): bool
    {
        return false;
    }

    private function validateFilters(User $user, SearchQuery $query): bool
    {
        return true;
    }

    private function validateAdvancedFilters(User $user, SearchQuery $query): bool
    {
        return true;
    }
}
