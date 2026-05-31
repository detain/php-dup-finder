<?php
declare(strict_types=1);

namespace App\Shipping\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\ReturnRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class ReturnPermissionService
{
    public function __construct(
        private ReturnRepositoryInterface $returnRepository,
        private LoggerInterface $logger,
    ) {}

    public function canCreateReturn(User $user, string $orderId, string $shipmentId): bool
    {
        if ($user === null) {
            $this->logger->warning('Return create permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Return create permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('returns', 'create')) {
            $this->logger->info('Return create permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Return create permission granted', [
            'user_id' => $user->getId()->toString(),
            'order_id' => $orderId,
        ]);

        return true;
    }

    public function canCancelReturn(User $user, string $returnId): bool
    {
        if ($user === null) {
            $this->logger->warning('Return cancel permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Return cancel permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'return_id' => $returnId,
            ]);
            return false;
        }

        $return = $this->returnRepository->findById($returnId);
        if ($return === null) {
            $this->logger->info('Return cancel permission denied: return not found', [
                'return_id' => $returnId,
            ]);
            return false;
        }

        if (!$return->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('returns', 'cancel_others')) {
                $this->logger->info('Return cancel permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'return_id' => $returnId,
                ]);
                return false;
            }
        }

        if (!$return->isCancellable()) {
            $this->logger->info('Return cancel permission denied: not cancellable', [
                'return_id' => $returnId,
            ]);
            return false;
        }

        $this->logger->debug('Return cancel permission granted', [
            'user_id' => $user->getId()->toString(),
            'return_id' => $returnId,
        ]);

        return true;
    }

    public function canApproveReturn(User $user, string $returnId): bool
    {
        if ($user === null) {
            $this->logger->warning('Return approve permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Return approve permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'return_id' => $returnId,
            ]);
            return false;
        }

        if (!$user->hasPermission('returns', 'approve')) {
            $this->logger->info('Return approve permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'return_id' => $returnId,
            ]);
            return false;
        }

        $this->logger->debug('Return approve permission granted', [
            'user_id' => $user->getId()->toString(),
            'return_id' => $returnId,
        ]);

        return true;
    }

    public function canInspectReturn(User $user, string $returnId): bool
    {
        if ($user === null) {
            $this->logger->warning('Return inspect permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Return inspect permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'return_id' => $returnId,
            ]);
            return false;
        }

        if (!$user->hasPermission('returns', 'inspect')) {
            $this->logger->info('Return inspect permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'return_id' => $returnId,
            ]);
            return false;
        }

        $this->logger->debug('Return inspect permission granted', [
            'user_id' => $user->getId()->toString(),
            'return_id' => $returnId,
        ]);

        return true;
    }

    public function canProcessRefund(User $user, string $returnId): bool
    {
        if ($user === null) {
            $this->logger->warning('Return refund permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Return refund permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'return_id' => $returnId,
            ]);
            return false;
        }

        if (!$user->hasPermission('returns', 'refund')) {
            $this->logger->info('Return refund permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'return_id' => $returnId,
            ]);
            return false;
        }

        $this->logger->debug('Return refund permission granted', [
            'user_id' => $user->getId()->toString(),
            'return_id' => $returnId,
        ]);

        return true;
    }
}
