<?php
declare(strict_types=1);

namespace App\Search\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class IndexPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function canCreateIndex(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Index create permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Index create permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('search_index', 'create')) {
            $this->logger->info('Index create permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Index create permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canUpdateIndex(User $user, string $indexName): bool
    {
        if ($user === null) {
            $this->logger->warning('Index update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Index update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'index_name' => $indexName,
            ]);
            return false;
        }

        if (!$user->hasPermission('search_index', 'update')) {
            $this->logger->info('Index update permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Index update permission granted', [
            'user_id' => $user->getId()->toString(),
            'index_name' => $indexName,
        ]);

        return true;
    }

    public function canDeleteIndex(User $user, string $indexName): bool
    {
        if ($user === null) {
            $this->logger->warning('Index delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Index delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'index_name' => $indexName,
            ]);
            return false;
        }

        if (!$user->hasPermission('search_index', 'delete')) {
            $this->logger->info('Index delete permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if ($this->isProtectedIndex($indexName)) {
            $this->logger->info('Index delete permission denied: protected index', [
                'user_id' => $user->getId()->toString(),
                'index_name' => $indexName,
            ]);
            return false;
        }

        $this->logger->debug('Index delete permission granted', [
            'user_id' => $user->getId()->toString(),
            'index_name' => $indexName,
        ]);

        return true;
    }

    public function canRebuildIndex(User $user, string $indexName): bool
    {
        if ($user === null) {
            $this->logger->warning('Index rebuild permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Index rebuild permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'index_name' => $indexName,
            ]);
            return false;
        }

        if (!$user->hasPermission('search_index', 'rebuild')) {
            $this->logger->info('Index rebuild permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Index rebuild permission granted', [
            'user_id' => $user->getId()->toString(),
            'index_name' => $indexName,
        ]);

        return true;
    }

    public function canViewIndexStats(User $user, string $indexName): bool
    {
        if ($user === null) {
            $this->logger->warning('Index stats view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Index stats view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'index_name' => $indexName,
            ]);
            return false;
        }

        if (!$user->hasPermission('search_index', 'view_stats')) {
            $this->logger->info('Index stats view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Index stats view permission granted', [
            'user_id' => $user->getId()->toString(),
            'index_name' => $indexName,
        ]);

        return true;
    }

    private function isProtectedIndex(string $indexName): bool
    {
        $protectedIndices = ['users', 'orders', 'products'];
        return in_array($indexName, $protectedIndices, true);
    }
}
