<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use Psr\Log\LoggerInterface;

/**
 * Customer account management service.
 * The LoggerInterface is manually injected here, duplicated from
 * InventoryService, FulfillmentService, and other services.
 */
class CustomerAccountService
{
    private LoggerInterface $logger;
    private CustomerRepositoryInterface $customerRepository;
    private AddressService $addressService;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        AddressService $addressService,
        LoggerInterface $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->addressService = $addressService;
        $this->logger = $logger;
    }

    public function createAccount(array $accountData): CustomerAccount
    {
        $this->logger->info('Creating customer account', [
            'email' => $accountData['email'],
            'source' => $accountData['source'] ?? 'direct',
        ]);

        $existingAccount = $this->customerRepository->findByEmail($accountData['email']);

        if ($existingAccount !== null) {
            $this->logger->warning('Account already exists', [
                'email' => $accountData['email'],
            ]);
            throw new AccountAlreadyExistsException(
                "An account with email {$accountData['email']} already exists"
            );
        }

        $account = new CustomerAccount(
            email: $accountData['email'],
            firstName: $accountData['first_name'],
            lastName: $accountData['last_name'],
            phone: $accountData['phone'] ?? null,
            source: $accountData['source'] ?? 'direct',
            createdAt: new \DateTimeImmutable(),
        );

        $savedAccount = $this->customerRepository->save($account);

        $this->logger->info('Customer account created successfully', [
            'account_id' => $savedAccount->getId(),
            'email' => $accountData['email'],
        ]);

        return $savedAccount;
    }

    public function verifyAccount(string $accountId, string $verificationToken): void
    {
        $this->logger->info('Verifying customer account', [
            'account_id' => $accountId,
        ]);

        $account = $this->customerRepository->findById($accountId);

        if ($account === null) {
            $this->logger->error('Account not found for verification', [
                'account_id' => $accountId,
            ]);
            throw new AccountNotFoundException("Account not found: {$accountId}");
        }

        if ($account->isVerified()) {
            $this->logger->warning('Account already verified', [
                'account_id' => $accountId,
            ]);
            return;
        }

        if (!$account->verifyToken($verificationToken)) {
            $this->logger->warning('Invalid verification token', [
                'account_id' => $accountId,
            ]);
            throw new InvalidVerificationTokenException("Invalid verification token");
        }

        $account->markAsVerified();
        $this->customerRepository->save($account);

        $this->logger->info('Account verified successfully', [
            'account_id' => $accountId,
        ]);
    }

    public function updateAccountInfo(string $accountId, array $updates): CustomerAccount
    {
        $this->logger->info('Updating customer account', [
            'account_id' => $accountId,
            'updates' => array_keys($updates),
        ]);

        $account = $this->customerRepository->findById($accountId);

        if ($account === null) {
            throw new AccountNotFoundException("Account not found: {$accountId}");
        }

        if (isset($updates['first_name'])) {
            $account->setFirstName($updates['first_name']);
        }

        if (isset($updates['last_name'])) {
            $account->setLastName($updates['last_name']);
        }

        if (isset($updates['phone'])) {
            $account->setPhone($updates['phone']);
        }

        $updatedAccount = $this->customerRepository->save($account);

        $this->logger->info('Account updated successfully', [
            'account_id' => $accountId,
        ]);

        return $updatedAccount;
    }

    public function suspendAccount(string $accountId, string $reason): void
    {
        $this->logger->info('Suspending customer account', [
            'account_id' => $accountId,
            'reason' => $reason,
        ]);

        $account = $this->customerRepository->findById($accountId);

        if ($account === null) {
            throw new AccountNotFoundException("Account not found: {$accountId}");
        }

        if ($account->isSystemAdmin()) {
            $this->logger->warning('Cannot suspend admin account', [
                'account_id' => $accountId,
            ]);
            throw new CannotSuspendAdminException("Cannot suspend administrator account");
        }

        $account->suspend($reason);
        $this->customerRepository->save($account);

        $this->logger->info('Account suspended successfully', [
            'account_id' => $accountId,
        ]);
    }

    public function reactivateAccount(string $accountId): CustomerAccount
    {
        $this->logger->info('Reactivating customer account', [
            'account_id' => $accountId,
        ]);

        $account = $this->customerRepository->findById($accountId);

        if ($account === null) {
            throw new AccountNotFoundException("Account not found: {$accountId}");
        }

        if (!$account->isSuspended()) {
            $this->logger->warning('Account is not suspended', [
                'account_id' => $accountId,
            ]);
            throw new AccountNotSuspendedException("Account is not suspended");
        }

        $account->reactivate();
        $updatedAccount = $this->customerRepository->save($account);

        $this->logger->info('Account reactivated successfully', [
            'account_id' => $accountId,
        ]);

        return $updatedAccount;
    }
}
