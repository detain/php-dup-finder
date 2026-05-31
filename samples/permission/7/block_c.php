<?php
declare(strict_types=1);

namespace App\Shipping\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\CarrierAccountRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class CarrierAccountPermissionService
{
    public function __construct(
        private CarrierAccountRepositoryInterface $carrierAccountRepository,
        private LoggerInterface $logger,
    ) {}

    public function canAddCarrierAccount(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Carrier account add permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Carrier account add permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('carrier_accounts', 'add')) {
            $this->logger->info('Carrier account add permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $existingCount = $this->carrierAccountRepository->countByUser($user->getId());
        if ($existingCount >= $user->getMaxCarrierAccounts()) {
            $this->logger->info('Carrier account add permission denied: max accounts reached', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Carrier account add permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canRemoveCarrierAccount(User $user, string $accountId): bool
    {
        if ($user === null) {
            $this->logger->warning('Carrier account remove permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Carrier account remove permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'account_id' => $accountId,
            ]);
            return false;
        }

        $account = $this->carrierAccountRepository->findById($accountId);
        if ($account === null) {
            $this->logger->info('Carrier account remove permission denied: account not found', [
                'account_id' => $accountId,
            ]);
            return false;
        }

        if (!$account->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('carrier_accounts', 'remove_others')) {
                $this->logger->info('Carrier account remove permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'account_id' => $accountId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Carrier account remove permission granted', [
            'user_id' => $user->getId()->toString(),
            'account_id' => $accountId,
        ]);

        return true;
    }

    public function canUpdateCarrierAccount(User $user, string $accountId): bool
    {
        if ($user === null) {
            $this->logger->warning('Carrier account update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Carrier account update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'account_id' => $accountId,
            ]);
            return false;
        }

        $account = $this->carrierAccountRepository->findById($accountId);
        if ($account === null) {
            $this->logger->info('Carrier account update permission denied: account not found', [
                'account_id' => $accountId,
            ]);
            return false;
        }

        if (!$account->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('carrier_accounts', 'update_others')) {
                $this->logger->info('Carrier account update permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'account_id' => $accountId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Carrier account update permission granted', [
            'user_id' => $user->getId()->toString(),
            'account_id' => $accountId,
        ]);

        return true;
    }

    public function canSetDefaultCarrierAccount(User $user, string $accountId): bool
    {
        if ($user === null) {
            $this->logger->warning('Carrier account set default permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Carrier account set default permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'account_id' => $accountId,
            ]);
            return false;
        }

        $account = $this->carrierAccountRepository->findById($accountId);
        if ($account === null) {
            $this->logger->info('Carrier account set default permission denied: account not found', [
                'account_id' => $accountId,
            ]);
            return false;
        }

        if (!$account->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('carrier_accounts', 'set_default_others')) {
                $this->logger->info('Carrier account set default permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'account_id' => $accountId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Carrier account set default permission granted', [
            'user_id' => $user->getId()->toString(),
            'account_id' => $accountId,
        ]);

        return true;
    }
}
