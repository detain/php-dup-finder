<?php
declare(strict_types=1);

namespace App\Billing\Authorization;

use App\Domain\Entity\User;
use App\Domain\Entity\Invoice;
use App\Domain\Repository\InvoiceRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class BillingPermissionService
{
    public function __construct(
        private InvoiceRepositoryInterface $invoiceRepository,
        private LoggerInterface $logger,
    ) {}

    public function canViewInvoice(User $user, string $invoiceId): bool
    {
        if ($user === null) {
            $this->logger->warning('View invoice permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('View invoice permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'invoice_id' => $invoiceId,
            ]);
            return false;
        }

        $invoice = $this->invoiceRepository->findById($invoiceId);
        if ($invoice === null) {
            $this->logger->info('View invoice permission denied: invoice not found', [
                'invoice_id' => $invoiceId,
            ]);
            return false;
        }

        if ($invoice->getCustomerId()->equals($user->getId())) {
            $this->logger->debug('View invoice permission granted: customer', [
                'user_id' => $user->getId()->toString(),
                'invoice_id' => $invoiceId,
            ]);
            return true;
        }

        if ($this->userHasElevatedBillingRole($user)) {
            $this->logger->debug('View invoice permission granted: elevated role', [
                'user_id' => $user->getId()->toString(),
                'invoice_id' => $invoiceId,
            ]);
            return true;
        }

        $this->logger->info('View invoice permission denied: access denied', [
            'user_id' => $user->getId()->toString(),
            'invoice_id' => $invoiceId,
        ]);

        return false;
    }

    public function canRefund(User $user, string $transactionId): bool
    {
        if ($user === null) {
            $this->logger->warning('Refund permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Refund permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'transaction_id' => $transactionId,
            ]);
            return false;
        }

        if (!$this->userHasElevatedBillingRole($user)) {
            $this->logger->info('Refund permission denied: insufficient role', [
                'user_id' => $user->getId()->toString(),
                'transaction_id' => $transactionId,
            ]);
            return false;
        }

        $this->logger->debug('Refund permission granted', [
            'user_id' => $user->getId()->toString(),
            'transaction_id' => $transactionId,
        ]);

        return true;
    }

    public function canUpdatePaymentMethod(User $user, string $customerId): bool
    {
        if ($user === null) {
            $this->logger->warning('Update payment method permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Update payment method permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        if (!$user->getId()->toString() === $customerId && !$this->userHasElevatedBillingRole($user)) {
            $this->logger->info('Update payment method permission denied: not owner or elevated', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        $this->logger->debug('Update payment method permission granted', [
            'user_id' => $user->getId()->toString(),
            'customer_id' => $customerId,
        ]);

        return true;
    }

    public function canViewBillingHistory(User $user, string $customerId): bool
    {
        if ($user === null) {
            $this->logger->warning('View billing history permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('View billing history permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        if (!$user->getId()->toString() === $customerId && !$this->userHasElevatedBillingRole($user)) {
            $this->logger->info('View billing history permission denied: access denied', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        $this->logger->debug('View billing history permission granted', [
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
