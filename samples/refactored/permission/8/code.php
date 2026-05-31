<?php
declare(strict_types=1);

namespace App\Core\Search\Security;

use App\Domain\Entity\User;
use App\Domain\ValueObject\SearchQuery;
use Psr\Log\LoggerInterface;

enum SearchPermission: string
{
    case Basic = 'basic';
    case Filtered = 'filtered';
    case Advanced = 'advanced';
    case Export = 'export';
    case Suggestions = 'suggestions';
}

interface SearchPermissionStrategy
{
    public function getPermission(): SearchPermission;
    public function getPermissionString(): string;
    public function validatePreconditions(User $user): bool;
    public function validateQuery(User $user, SearchQuery $query): bool;
}

abstract class BaseSearchPermissionService
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function canSearch(User $user, SearchQuery $query, SearchPermissionStrategy $strategy): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $strategy);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('inactive user', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        if (!$user->hasPermission('search', $strategy->getPermissionString())) {
            $this->logFailure('missing permission', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        if (!$strategy->validatePreconditions($user)) {
            $this->logFailure('preconditions failed', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        if (!$strategy->validateQuery($user, $query)) {
            $this->logFailure('query validation failed', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $this->logSuccess($strategy, ['user_id' => $user->getId()->toString()]);
        return true;
    }

    private function logFailure(string $reason, SearchPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->warning("Search permission denied: {$reason}", array_merge(
            ['permission' => $strategy->getPermission()->value],
            $context
        ));
    }

    private function logSuccess(SearchPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->debug('Search permission granted', array_merge(
            ['permission' => $strategy->getPermission()->value],
            $context
        ));
    }
}

final class SearchPermissionService extends BaseSearchPermissionService {}
final class IndexPermissionService extends BaseSearchPermissionService {}
final class SearchAnalyticsPermissionService extends BaseSearchPermissionService {}
