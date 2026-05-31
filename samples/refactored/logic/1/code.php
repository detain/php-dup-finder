<?php

declare(strict_types=1);

namespace App\Shared;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use Psr\Log\LoggerInterface;

interface CustomerValidationRuleInterface
{
    public function validate(Customer $customer): ?string;
    public function getErrorMessage(): string;
}

final class ActiveStatusRule implements CustomerValidationRuleInterface
{
    public function validate(Customer $customer): ?string
    {
        if ($customer->getStatus() !== 'active') {
            return $this->getErrorMessage();
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Customer is not active';
    }
}

final class NonSuspendedTierRule implements CustomerValidationRuleInterface
{
    public function validate(Customer $customer): ?string
    {
        if ($customer->getTier() === 'suspended') {
            return $this->getErrorMessage();
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Customer account is suspended';
    }
}

final class CreditLimitRule implements CustomerValidationRuleInterface
{
    private const MAX_DEBT = 1000;

    public function validate(Customer $customer): ?string
    {
        if ($customer->getAccountBalance() < 0 && abs($customer->getAccountBalance()) > self::MAX_DEBT) {
            return $this->getErrorMessage();
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Customer has exceeded credit limit';
    }
}

final class CustomerValidator
{
    /** @var CustomerValidationRuleInterface[] */
    private array $rules = [];

    public function __construct(
        private readonly CustomerRepository $customerRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function registerRule(CustomerValidationRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function validateForTransaction(int $customerId): Customer
    {
        $customer = $this->customerRepository->findById($customerId);

        if ($customer === null) {
            throw new \InvalidArgumentException('Customer not found');
        }

        foreach ($this->rules as $rule) {
            $error = $rule->validate($customer);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }

        return $customer;
    }

    public function validateCustomer(Customer $customer): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($customer);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}
