<?php
declare(strict_types=1);

namespace Acme\Common\Lookup;

/**
 * @template TEntity
 * @template TDto
 */
final class EntityLookup
{
    /**
     * @param callable(string): ?TEntity $finder
     * @param callable(TEntity): TDto    $mapper
     * @param callable(string): \Throwable $missing
     */
    public function __construct(
        private readonly mixed $finder,
        private readonly mixed $mapper,
        private readonly mixed $missing,
    ) {
    }

    /**
     * @return TDto
     */
    public function require(string $id): mixed
    {
        $entity = ($this->finder)($id);
        if ($entity === null) {
            throw ($this->missing)($id);
        }
        return ($this->mapper)($entity);
    }

    public function exists(string $id): bool
    {
        return ($this->finder)($id) !== null;
    }
}

// usage at each call site
// $customerLookup = new EntityLookup(
//     fn($id) => $this->customers->findById($id),
//     fn($e)  => new CustomerDto($e->getId(), $e->getFullName(), $e->getEmail(), $e->getCreatedAt()),
//     fn($id) => new CustomerNotFoundException("customer not found: {$id}"),
// );
// return $customerLookup->require($customerId);
