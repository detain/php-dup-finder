<?php
declare(strict_types=1);

namespace App\Billing\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\PaymentMethodRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class PaymentMethodPermissionService
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private LoggerInterface $logger,
    ) {}

    public function canAddPaymentMethod(User $user, string $customerId): bool
    {
        if ($user === null) {
            $this->logger->warning('Add payment method permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Add payment method permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        if (!$user->getId()->toString() === $customerId && !$this->userHasElevatedBillingRole($user)) {
            $this->logger->info('Add payment method permission denied: access denied', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        $this->logger->debug('Add payment method permission granted', [
            'user_id' => $user->getId()->toString(),
            'customer_id' => $customerId,
        ]);

        return true;
    }

    public function canRemovePaymentMethod(User $user, string $paymentMethodId): bool
    {
        if ($user === null) {
            $this->logger->warning('Remove payment method permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Remove payment method permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'payment_method_id' => $paymentMethodId,
            ]);
            return false;
        }

        $paymentMethod = $this->paymentMethodRepository->findById($paymentMethodId);
        if ($paymentMethod === null) {
            $this->logger->info('Remove payment method permission denied: method not found', [
                'payment_method_id' => $paymentMethodId,
            ]);
            return false;
        }

        if ($paymentMethod->getCustomerId()->equals($user->getId())) {
            $this->logger->debug('Remove payment method permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'payment_method_id' => $paymentMethodId,
            ]);
            return true;
        }

        if ($this->userHasElevatedBillingRole($user)) {
            $this->logger->debug('Remove payment method permission granted: elevated role', [
                'user_id' => $user->getId()->toString(),
                'payment_method_id' => $paymentMethodId,
            ]);
            return true;
        }

        $this->logger->info('Remove payment method permission denied: access denied', [
            'user_id' => $user->getId()->toString(),
            'payment_method_id' => $paymentMethodId,
        ]);

        return false;
    }

    public function canSetDefaultPaymentMethod(User $user, string $customerId, string $paymentMethodId): bool
    {
        if ($user === null) {
            $this->logger->warning('Set default payment method permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Set default payment method permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        if (!$user->getId()->toString() === $customerId && !$this->userHasElevatedBillingRole($user)) {
            $this->logger->info('Set default payment method permission denied: access denied', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        $this->logger->debug('Set default payment method permission granted', [
            'user_id' => $user->getId()->toString(),
            'customer_id' => $customerId,
        ]);

        return true;
    }

    private function userHasElevatedBillingRole(User $user): bool
    {
        foreach ($user->getRoles() as $role) {
            if ($role->isAdmin() || $role->isBillingAdmin()) {
                return true;
            }
        }
        return false;
    }
}
