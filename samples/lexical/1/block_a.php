<?php
declare(strict_types=1);

namespace Acme\Crm\Customer;

use Acme\Crm\Customer\Dto\CustomerDto;
use Acme\Crm\Customer\Exception\CustomerNotFoundException;
use Acme\Crm\Customer\Repository\CustomerRepository;
use Psr\Log\LoggerInterface;

final class CustomerLookupService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function lookupActive(string $customerId): CustomerDto
    {
        $this->logger->debug('looking up customer', ['id' => $customerId]);

        // canonical fetch + null-guard + map
        $entity = $this->customers->findById($customerId);
        if ($entity === null) {
            throw new CustomerNotFoundException("customer not found: {$customerId}");
        }
        return new CustomerDto(
            $entity->getId(),
            $entity->getFullName(),
            $entity->getEmail(),
            $entity->getCreatedAt(),
        );
    }

    public function batchLookup(array $ids): array
    {
        $results = [];
        foreach ($ids as $id) {
            $results[$id] = $this->lookupActive($id);
        }
        return $results;
    }

    public function exists(string $customerId): bool
    {
        return $this->customers->findById($customerId) !== null;
    }
}
